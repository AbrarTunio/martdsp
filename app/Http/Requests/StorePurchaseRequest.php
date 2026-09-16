<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A delivery, as typed and scanned from the supplier's bill.
 *
 * Used for creating and for editing a draft. Checks that only matter once the
 * goods are actually taken in — an expiry date on a tracked item — are applied
 * only when the form was sent with the receive button.
 */
class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('supervise');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'invoice_no' => ['nullable', 'string', 'max:40'],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'paid' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', Rule::in(['draft', 'receive'])],

            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'items.*.qty' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'items.*.bonus_qty' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items.*.batch_no' => ['nullable', 'string', 'max:40'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'invoice_no' => 'bill number',
            'purchase_date' => 'bill date',
            'paid' => 'amount paid',
            'payment_method' => 'how it was paid',
            'items.*.product_id' => 'item',
            'items.*.product_unit_id' => 'size',
            'items.*.qty' => 'quantity',
            'items.*.bonus_qty' => 'free quantity',
            'items.*.unit_cost' => 'price',
            'items.*.batch_no' => 'batch',
            'items.*.expiry_date' => 'expiry date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => __('Scan or add at least one item before saving.'),
            'purchase_date.before_or_equal' => __('A bill cannot be dated in the future.'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkQuantities($validator),
            fn (Validator $validator) => $this->checkUnitsBelongToTheirProducts($validator),
            fn (Validator $validator) => $this->checkNothingIsListedTwice($validator),
            fn (Validator $validator) => $this->checkTheBillIsNotAlreadyEntered($validator),
            fn (Validator $validator) => $this->checkTheFiguresAddUp($validator),
            fn (Validator $validator) => $this->checkExpiryDates($validator),
        ];
    }

    public function wantsToReceive(): bool
    {
        return $this->input('action') === 'receive';
    }

    /**
     * The purchase's own columns, in paisa.
     *
     * A purchase with no supplier was paid for there and then, so it is
     * always marked as paid in full — there is nobody to owe the rest to.
     *
     * @return array<string, mixed>
     */
    public function purchaseAttributes(): array
    {
        $supplierId = $this->validated('supplier_id');
        $total = $this->totalPaisa();
        $paid = $supplierId === null ? $total : Money::parse($this->validated('paid'));

        return [
            'supplier_id' => $supplierId,
            'invoice_no' => $this->validated('invoice_no') ?: null,
            'purchase_date' => $this->validated('purchase_date'),
            'discount_paisa' => $this->discountPaisa(),
            'tax_paisa' => Money::parse($this->validated('tax')),
            'paid_paisa' => $paid,
            'payment_method' => $paid > 0
                ? (PaymentMethod::tryFrom((string) $this->validated('payment_method')) ?? PaymentMethod::Cash)
                : null,
            'note' => $this->validated('note'),
        ];
    }

    /**
     * Lines ready to save, with the pack price in paisa. Lines where nothing
     * arrived are dropped: they are usually a scan that was undone by hand.
     *
     * @return array<int, array{product_id: int, product_unit_id: int, qty: int, bonus_qty: int, unit_cost_paisa: int, batch_no: string|null, expiry_date: string|null}>
     */
    public function items(): array
    {
        $items = [];

        foreach ((array) $this->validated('items') as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $bonus = (int) ($item['bonus_qty'] ?? 0);

            if ($qty + $bonus === 0) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => (int) $item['product_unit_id'],
                'qty' => $qty,
                'bonus_qty' => $bonus,
                'unit_cost_paisa' => Money::parse($item['unit_cost'] ?? 0),
                'batch_no' => ($item['batch_no'] ?? null) ?: null,
                'expiry_date' => ($item['expiry_date'] ?? null) ?: null,
            ];
        }

        return $items;
    }

    /**
     * What the lines come to before discount and tax, from the raw input so
     * it can be checked before validation has finished.
     */
    private function subtotalPaisa(): int
    {
        $subtotal = 0;

        foreach ((array) $this->input('items', []) as $item) {
            $subtotal += (int) ($item['qty'] ?? 0) * Money::parse($item['unit_cost'] ?? 0);
        }

        return $subtotal;
    }

    private function discountPaisa(): int
    {
        return Money::parse($this->input('discount'));
    }

    private function totalPaisa(): int
    {
        return $this->subtotalPaisa() - $this->discountPaisa() + Money::parse($this->input('tax'));
    }

    /**
     * Every line may be blank while a delivery is being scanned in, but not
     * all of them at once.
     */
    private function checkQuantities(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $item) {
            if ((int) ($item['qty'] ?? 0) + (int) ($item['bonus_qty'] ?? 0) > 0) {
                return;
            }
        }

        $validator->errors()->add('items', __('Every line is empty. Enter how many arrived of at least one item.'));
    }

    /**
     * A size belonging to a different product would multiply the quantity by
     * the wrong conversion factor and put the wrong amount on the shelf.
     */
    private function checkUnitsBelongToTheirProducts(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $index => $item) {
            $productUnit = ProductUnit::find($item['product_unit_id'] ?? 0);

            if ($productUnit && (int) $productUnit->product_id !== (int) ($item['product_id'] ?? 0)) {
                $validator->errors()->add("items.{$index}.product_unit_id", __('That size does not belong to this item.'));
            }
        }
    }

    /**
     * The same item may arrive as cartons and as loose boxes on one bill, but
     * the same size twice is a double scan.
     */
    private function checkNothingIsListedTwice(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('items', []) as $index => $item) {
            $unitId = (int) ($item['product_unit_id'] ?? 0);

            if ($unitId === 0) {
                continue;
            }

            if (isset($seen[$unitId])) {
                $name = Product::find($item['product_id'] ?? 0)?->name ?? __('That item');

                $validator->errors()->add("items.{$index}.product_unit_id", __(':name in this size is on the bill twice. Put the whole quantity on one line.', [
                    'name' => $name,
                ]));

                continue;
            }

            $seen[$unitId] = true;
        }
    }

    /**
     * The commonest way stock and a supplier's account go wrong is one paper
     * bill entered twice, by two people or on two days.
     */
    private function checkTheBillIsNotAlreadyEntered(Validator $validator): void
    {
        $supplierId = $this->input('supplier_id');
        $invoice = trim((string) $this->input('invoice_no'));

        if (blank($supplierId) || $invoice === '') {
            return;
        }

        /** @var Purchase|null $current */
        $current = $this->route('purchase');

        $existing = Purchase::query()
            ->where('supplier_id', $supplierId)
            ->where('invoice_no', $invoice)
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->first();

        if ($existing) {
            $validator->errors()->add('invoice_no', __('Bill :number from this supplier is already entered as :reference.', [
                'number' => $invoice,
                'reference' => $existing->reference,
            ]));
        }
    }

    private function checkTheFiguresAddUp(Validator $validator): void
    {
        $subtotal = $this->subtotalPaisa();

        if ($this->discountPaisa() > $subtotal) {
            $validator->errors()->add('discount', __('The discount is more than the items come to.'));

            return;
        }

        if (filled($this->input('supplier_id')) && Money::parse($this->input('paid')) > $this->totalPaisa()) {
            $validator->errors()->add('paid', __('More was paid than this bill comes to. Record the extra as a payment to the supplier instead.'));
        }
    }

    /**
     * An item that is tracked by expiry cannot be taken in without its date,
     * and a date before the delivery means the goods arrived already expired.
     */
    private function checkExpiryDates(Validator $validator): void
    {
        $items = (array) $this->input('items', []);
        $tracked = Product::query()
            ->whereKey(collect($items)->pluck('product_id')->filter()->all())
            ->where('track_expiry', true)
            ->pluck('name', 'id');

        $delivered = strtotime((string) $this->input('purchase_date')) ?: null;

        foreach ($items as $index => $item) {
            $expiry = $item['expiry_date'] ?? null;
            $productId = (int) ($item['product_id'] ?? 0);

            if (blank($expiry)) {
                if ($this->wantsToReceive() && $tracked->has($productId)) {
                    $validator->errors()->add("items.{$index}.expiry_date", __(':name needs its expiry date before it can be taken in.', [
                        'name' => $tracked->get($productId),
                    ]));
                }

                continue;
            }

            $expiresOn = strtotime((string) $expiry);

            if ($expiresOn !== false && $delivered !== null && $expiresOn < $delivered) {
                $validator->errors()->add("items.{$index}.expiry_date", __('This expired on :date, before the delivery. Check the date, or do not accept it.', [
                    'date' => Carbon::createFromTimestamp($expiresOn)->format('d M Y'),
                ]));
            }
        }
    }
}
