<?php

namespace App\Http\Requests;

use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\SaleCalculator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A customer's return, entered against the bill it came off.
 *
 * Quantities are typed in the size the bill was rung up in — "1" against a
 * line that says "2 packets" means one packet — and turned into base units
 * here, because the rest of the application counts in base units and a
 * cashier should never have to.
 */
class StoreSaleReturnRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(SaleReturnReason::class)],
            'settlement' => ['required', Rule::enum(RefundMethod::class)],
            'register_id' => ['nullable', 'integer', Rule::exists('registers', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'settlement' => 'how the refund is given',
            'register_id' => 'counter',
            'items.*.sale_item_id' => 'line',
            'items.*.qty' => 'quantity',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => __('Enter how many of at least one item are coming back.'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkSomethingIsComingBack($validator),
            fn (Validator $validator) => $this->checkTheLinesAreOnThisBill($validator),
            fn (Validator $validator) => $this->checkNothingIsListedTwice($validator),
            fn (Validator $validator) => $this->checkTheQuantitiesCanComeBack($validator),
            fn (Validator $validator) => $this->checkTheRefundCanBeGiven($validator),
        ];
    }

    /**
     * The bill being returned against, resolved from the route.
     */
    public function sale(): Sale
    {
        return $this->route('sale');
    }

    public function reason(): SaleReturnReason
    {
        return SaleReturnReason::from((string) $this->validated('reason'));
    }

    public function settlement(): RefundMethod
    {
        return RefundMethod::from((string) $this->validated('settlement'));
    }

    /**
     * The counter the cash comes out of. With one counter in the shop there
     * is nothing to choose, so it is not asked for.
     */
    public function register(): ?Register
    {
        $id = $this->validated('register_id');

        if ($id) {
            return Register::query()->find($id);
        }

        $active = Register::query()->active()->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    /**
     * The lines with something on them, in base units.
     *
     * @return array<int, array{sale_item_id: int, qty_base: int}>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->soldLines() as [$item, $qtyBase]) {
            if ($qtyBase > 0) {
                $lines[] = ['sale_item_id' => $item->getKey(), 'qty_base' => $qtyBase];
            }
        }

        return $lines;
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }

    /**
     * Each typed line paired with the bill line it names and the base
     * quantity it comes to. Lines naming something that is not on this bill
     * are left out; the validator reports those separately.
     *
     * @return array<int, array{0: SaleItem, 1: int}>
     */
    private function soldLines(): array
    {
        $items = SaleItem::query()
            ->with('productUnit')
            ->where('sale_id', $this->sale()->getKey())
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ((array) $this->input('items', []) as $index => $line) {
            $item = $items->get((int) ($line['sale_item_id'] ?? 0));

            if (! $item) {
                continue;
            }

            $lines[$index] = [$item, $this->qtyBase($item, $line['qty'] ?? null)];
        }

        return $lines;
    }

    /**
     * A typed quantity turned into base units. Negative or unreadable reads
     * as nothing, which the other checks report.
     */
    private function qtyBase(SaleItem $item, mixed $qty): int
    {
        $factor = max(1, (int) ($item->productUnit?->conversion_factor ?? 1));

        return SaleCalculator::qtyBase(SaleCalculator::qtyMilli($qty), $factor);
    }

    private function checkSomethingIsComingBack(Validator $validator): void
    {
        foreach ($this->soldLines() as [, $qtyBase]) {
            if ($qtyBase > 0) {
                return;
            }
        }

        $validator->errors()->add('items', __('Every line is empty. Enter how many are coming back.'));
    }

    private function checkTheLinesAreOnThisBill(Validator $validator): void
    {
        $ids = SaleItem::query()
            ->where('sale_id', $this->sale()->getKey())
            ->pluck('id')
            ->all();

        foreach ((array) $this->input('items', []) as $index => $line) {
            if (! in_array((int) ($line['sale_item_id'] ?? 0), $ids, true)) {
                $validator->errors()->add("items.{$index}.sale_item_id", __('That line is not on this bill.'));
            }
        }
    }

    private function checkNothingIsListedTwice(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('items', []) as $index => $line) {
            $id = (int) ($line['sale_item_id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            if (isset($seen[$id])) {
                $validator->errors()->add("items.{$index}.sale_item_id", __('That line is entered twice. Put the whole quantity on one row.'));

                continue;
            }

            $seen[$id] = true;
        }
    }

    /**
     * A sachet cannot be returned in halves, and nothing can come back that
     * was not sold or has already been returned.
     */
    private function checkTheQuantitiesCanComeBack(Validator $validator): void
    {
        foreach ($this->soldLines() as $index => [$item, $qtyBase]) {
            $typed = $this->input("items.{$index}.qty");
            $factor = max(1, (int) ($item->productUnit?->conversion_factor ?? 1));

            if (SaleCalculator::qtyMilli($typed) > 0 && ! SaleCalculator::isWholeInBase(SaleCalculator::qtyMilli($typed), $factor)) {
                $validator->errors()->add("items.{$index}.qty", __(':name cannot come back in part-:unit.', [
                    'name' => $item->name,
                    'unit' => strtolower((string) $item->unit_name),
                ]));

                continue;
            }

            if ($qtyBase > $item->returnableQtyBase()) {
                $validator->errors()->add("items.{$index}.qty", __('Only :qty of :name is still to come back.', [
                    'qty' => rtrim(rtrim(number_format($item->returnableQtyBase() / $factor, 3, '.', ''), '0'), '.') ?: '0',
                    'name' => $item->name,
                ]));
            }
        }
    }

    /**
     * Khata credit needs a khata, and cash needs a counter to come out of.
     */
    private function checkTheRefundCanBeGiven(Validator $validator): void
    {
        $settlement = RefundMethod::tryFrom((string) $this->input('settlement'));

        if (! $settlement) {
            return;
        }

        if ($settlement->needsCustomer() && $this->sale()->customer_id === null) {
            $validator->errors()->add('settlement', __('This bill has no customer, so there is no khata to put the refund on. Hand the cash back instead.'));

            return;
        }

        if ($settlement->isCash() && ! $this->register() && ! $this->sale()->register) {
            $validator->errors()->add('register_id', __('Say which counter the cash is coming out of.'));
        }
    }
}
