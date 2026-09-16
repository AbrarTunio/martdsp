<?php

namespace App\Http\Requests;

use App\Enums\PurchaseStatus;
use App\Enums\ReturnReason;
use App\Enums\ReturnSettlement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseReturnRequest extends FormRequest
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
            'purchase_id' => ['nullable', 'integer', Rule::exists('purchases', 'id')],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'settlement' => ['required', Rule::enum(ReturnSettlement::class)],
            'returned_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'items.*.qty' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'items.*.unit_credit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'purchase_id' => 'bill',
            'settlement' => 'how it is settled',
            'returned_at' => 'date',
            'items.*.product_id' => 'item',
            'items.*.product_unit_id' => 'size',
            'items.*.qty' => 'quantity',
            'items.*.unit_credit' => 'amount back',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => __('Scan or add at least one item that is going back.'),
            'returned_at.before_or_equal' => __('A return cannot be dated in the future.'),
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
            fn (Validator $validator) => $this->checkSettlement($validator),
            fn (Validator $validator) => $this->checkTheBillBelongsToTheSupplier($validator),
        ];
    }

    /**
     * The return's own columns, in the shape PurchaseReturnService takes.
     *
     * @return array{supplier_id: int|null, purchase_id: int|null, reason: ReturnReason, settlement: ReturnSettlement, note: string|null, returned_at: mixed}
     */
    public function returnAttributes(): array
    {
        return [
            'supplier_id' => $this->validated('supplier_id') !== null ? (int) $this->validated('supplier_id') : null,
            'purchase_id' => $this->validated('purchase_id') !== null ? (int) $this->validated('purchase_id') : null,
            'reason' => ReturnReason::from($this->validated('reason')),
            'settlement' => ReturnSettlement::from($this->validated('settlement')),
            'note' => $this->validated('note'),
            'returned_at' => $this->validated('returned_at'),
        ];
    }

    /**
     * @return array<int, array{product_id: int, product_unit_id: int, qty: int, unit_credit_paisa: int}>
     */
    public function items(): array
    {
        $items = [];

        foreach ((array) $this->validated('items') as $item) {
            if ((int) ($item['qty'] ?? 0) <= 0) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => (int) $item['product_unit_id'],
                'qty' => (int) $item['qty'],
                'unit_credit_paisa' => Money::parse($item['unit_credit'] ?? 0),
            ];
        }

        return $items;
    }

    private function checkQuantities(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $item) {
            if ((int) ($item['qty'] ?? 0) > 0) {
                return;
            }
        }

        $validator->errors()->add('items', __('Every line is empty. Enter how many are going back.'));
    }

    private function checkUnitsBelongToTheirProducts(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $index => $item) {
            $productUnit = ProductUnit::find($item['product_unit_id'] ?? 0);

            if ($productUnit && (int) $productUnit->product_id !== (int) ($item['product_id'] ?? 0)) {
                $validator->errors()->add("items.{$index}.product_unit_id", __('That size does not belong to this item.'));
            }
        }
    }

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

                $validator->errors()->add("items.{$index}.product_unit_id", __(':name in this size is listed twice. Put the whole quantity on one line.', [
                    'name' => $name,
                ]));

                continue;
            }

            $seen[$unitId] = true;
        }
    }

    /**
     * Goods from the market, bought for cash with no supplier, have no
     * account to take a return off.
     */
    private function checkSettlement(Validator $validator): void
    {
        if (blank($this->input('supplier_id')) && $this->input('settlement') !== ReturnSettlement::Cash->value) {
            $validator->errors()->add('settlement', __('Goods bought with no supplier can only be returned for cash — there is no account to take it off.'));
        }
    }

    private function checkTheBillBelongsToTheSupplier(Validator $validator): void
    {
        if (blank($this->input('purchase_id'))) {
            return;
        }

        $purchase = Purchase::find($this->input('purchase_id'));

        if (! $purchase) {
            return;
        }

        if ($purchase->status !== PurchaseStatus::Received) {
            $validator->errors()->add('purchase_id', __(':reference was never received, so nothing from it can go back.', [
                'reference' => $purchase->reference,
            ]));

            return;
        }

        if ((int) $purchase->supplier_id !== (int) $this->input('supplier_id')) {
            $validator->errors()->add('purchase_id', __(':reference is from a different supplier.', [
                'reference' => $purchase->reference,
            ]));
        }
    }
}
