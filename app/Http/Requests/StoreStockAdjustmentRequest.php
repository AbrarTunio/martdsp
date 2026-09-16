<?php

namespace App\Http\Requests;

use App\Enums\AdjustmentReason;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStockAdjustmentRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'adjusted_at' => ['required', 'date', 'before_or_equal:now'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:9999999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'adjusted_at' => 'date',
            'items' => 'items',
            'items.*.product_id' => 'item',
            'items.*.qty' => 'quantity',
            'items.*.unit_cost' => 'cost',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => __('Add at least one item before saving.'),
            'adjusted_at.before_or_equal' => __('Stock cannot be corrected for a date that has not happened yet.'),
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
        ];
    }

    public function reason(): AdjustmentReason
    {
        return AdjustmentReason::from($this->validated('reason'));
    }

    /**
     * The adjustment's own columns.
     *
     * @return array<string, mixed>
     */
    public function adjustmentAttributes(): array
    {
        return [
            'reason' => $this->reason(),
            'note' => $this->validated('note'),
            'adjusted_at' => $this->validated('adjusted_at'),
        ];
    }

    /**
     * Lines ready for the adjustment, with the cost turned into paisa per
     * base unit. A shopkeeper enters what a carton cost, so the per-base
     * figure is derived rather than typed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        $items = [];

        /* A recount of zero is a statement in itself — the shelf is empty —
           so only the add-and-remove reasons drop their empty lines. */
        $keepEmpty = $this->reason()->isRecount();

        foreach ((array) $this->validated('items') as $item) {
            if ((int) $item['qty'] <= 0 && ! $keepEmpty) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => $item['product_unit_id'] ?? null,
                'qty' => (int) $item['qty'],
                'unit_cost_base_paisa' => $this->costPerBasePaisa($item),
                'note' => $item['note'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function costPerBasePaisa(array $item): int
    {
        if (blank($item['unit_cost'] ?? null)) {
            return 0;
        }

        $packCost = Money::parse($item['unit_cost']);
        $factor = max(1, (int) (ProductUnit::find($item['product_unit_id'] ?? 0)?->conversion_factor ?? 1));

        return (int) round($packCost / $factor);
    }

    /**
     * A quantity of nothing on every line means an empty adjustment, which
     * would post and change nothing at all.
     */
    private function checkQuantities(Validator $validator): void
    {
        $items = (array) $this->input('items', []);
        $anything = false;

        foreach ($items as $index => $item) {
            $qty = (int) ($item['qty'] ?? 0);

            if ($qty > 0) {
                $anything = true;
            }

            /* A recount of zero is a real statement — the shelf is empty — so
               it only needs a quantity when stock is being added or removed. */
            if ($qty === 0 && $this->input('reason') === AdjustmentReason::Recount->value) {
                $anything = true;
            }

            if ($qty < 0) {
                $validator->errors()->add("items.{$index}.qty", __('Enter how many, as a plain number. The reason decides whether it is added or removed.'));
            }
        }

        if (! $anything) {
            $validator->errors()->add('items', __('Every line is empty. Enter how many of at least one item.'));
        }
    }

    /**
     * A size belonging to a different product would silently multiply the
     * quantity by the wrong conversion factor.
     */
    private function checkUnitsBelongToTheirProducts(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $index => $item) {
            $unitId = $item['product_unit_id'] ?? null;

            if (blank($unitId)) {
                continue;
            }

            $productUnit = ProductUnit::find($unitId);

            if ($productUnit && (int) $productUnit->product_id !== (int) ($item['product_id'] ?? 0)) {
                $validator->errors()->add("items.{$index}.product_unit_id", __('That size does not belong to this item.'));
            }
        }
    }

    /**
     * One shelf, one line. Two lines for the same item would each be settled
     * against the same balance and the second would undo the first.
     */
    private function checkNothingIsListedTwice(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('items', []) as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);

            if ($productId === 0) {
                continue;
            }

            if (isset($seen[$productId])) {
                $name = Product::find($productId)?->name ?? __('That item');

                $validator->errors()->add("items.{$index}.product_id", __(':name is on this correction twice. Put the whole quantity on one line.', [
                    'name' => $name,
                ]));

                continue;
            }

            $seen[$productId] = true;
        }
    }
}
