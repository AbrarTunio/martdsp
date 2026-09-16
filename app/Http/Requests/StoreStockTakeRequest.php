<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductUnit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A count sheet, saved part-way or in full.
 *
 * An empty sheet can be saved — starting a count and naming it before the
 * first scan is how it begins — but it cannot be posted with nothing on it
 * unless the section is being swept, which the service decides.
 */
class StoreStockTakeRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'missing_are_zero' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],

            'items' => ['nullable', 'array', 'max:1000'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:9999999'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'items.*.counted_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'section',
            'items.*.product_id' => 'item',
            'items.*.qty' => 'count',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.qty.min' => __('A count cannot be less than nothing.'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkSweepingNeedsASection($validator),
            fn (Validator $validator) => $this->checkUnitsBelongToTheirProducts($validator),
            fn (Validator $validator) => $this->checkNothingIsListedTwice($validator),
        ];
    }

    /**
     * The count's own columns.
     *
     * @return array<string, mixed>
     */
    public function takeAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'category_id' => $this->validated('category_id'),
            'missing_are_zero' => $this->boolean('missing_are_zero'),
            'note' => $this->validated('note'),
        ];
    }

    /**
     * The lines as counted. A zero stays: "the shelf is empty" is exactly
     * the kind of thing a count is for.
     *
     * The moment each item was counted comes from the shop's own clock, via
     * the scan, and is only ever kept between the start of the count and
     * now: it chooses which of the books' past balances the count is held
     * against, so it must not be able to reach outside the count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(CarbonInterface $notBefore): array
    {
        $now = now();

        return collect((array) $this->validated('items', []))
            ->map(function (array $item) use ($notBefore, $now): array {
                $factor = max(1, (int) (ProductUnit::find($item['product_unit_id'] ?? 0)?->conversion_factor ?? 1));

                $countedAt = filled($item['counted_at'] ?? null)
                    ? now()->parse($item['counted_at'])->setTimezone($now->getTimezone())
                    : $now->copy();

                return [
                    'product_id' => (int) $item['product_id'],
                    'product_unit_id' => $item['product_unit_id'] ?? null,
                    'counted_qty' => (int) $item['qty'],
                    'counted_base' => (int) $item['qty'] * $factor,
                    'was_counted' => true,
                    'counted_at' => $countedAt->max($notBefore)->min($now),
                    'note' => $item['note'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Treating unscanned items as gone across the whole shop would zero every
     * shelf nobody reached before closing. It is only allowed for a section,
     * where "everything in the dairy fridge" has an edge.
     */
    private function checkSweepingNeedsASection(Validator $validator): void
    {
        if ($this->boolean('missing_are_zero') && blank($this->input('category_id'))) {
            $validator->errors()->add('missing_are_zero', __('Choose a section first. Treating everything not scanned as gone is only safe for one part of the shop.'));
        }
    }

    /**
     * A size belonging to a different product would silently multiply the
     * count by the wrong conversion factor.
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
     * One item, one line. Two would each be settled against the same books
     * and the second would undo the first.
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
                $validator->errors()->add("items.{$index}.product_id", __(':name is on this count twice. Add the two together on one line.', [
                    'name' => Product::find($productId)?->name ?? __('That item'),
                ]));

                continue;
            }

            $seen[$productId] = true;
        }
    }
}
