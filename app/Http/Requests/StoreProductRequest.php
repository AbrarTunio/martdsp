<?php

namespace App\Http\Requests;

use App\Models\Barcode;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'name_ur' => ['nullable', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:32', 'unique:products,sku'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_weighted' => ['boolean'],
            'track_batches' => ['boolean'],
            'track_expiry' => ['boolean'],
            'is_active' => ['boolean'],
            'reorder_level_base' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'reorder_qty_base' => ['nullable', 'integer', 'min:0', 'max:99999999'],

            'levels' => ['required', 'array', 'min:1', 'max:6'],
            'levels.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'levels.*.parent_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            /* The smallest size holds nothing, so the form shows it no box to
               fill in. Whether a pack said how much it holds is checked
               below, where the base row can be told apart. */
            'levels.*.qty_per_parent' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'levels.*.sale_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'levels.*.mrp' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'levels.*.barcodes' => ['nullable', 'array', 'max:6'],
            'levels.*.barcodes.*' => ['nullable', 'string', 'max:64'],

            'default_sale' => ['nullable', 'integer'],
            'default_purchase' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'base_unit_id' => 'smallest unit',
            'levels' => 'packaging',
            'levels.*.unit_id' => 'packaging size',
            'levels.*.parent_unit_id' => 'contained size',
            'levels.*.qty_per_parent' => 'quantity per pack',
            'levels.*.sale_price' => 'sale price',
            'levels.*.mrp' => 'printed price',
        ];
    }

    /**
     * Everything the packaging form can get wrong in a way the database would
     * only report afterwards, in language nobody outside this file would
     * understand.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkPackagingChain($validator),
            fn (Validator $validator) => $this->checkBarcodes($validator),
        ];
    }

    /**
     * Levels the way PackagingService wants them: prices in paisa, one default
     * of each kind, barcodes as a clean list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function levels(): array
    {
        $defaultSale = $this->input('default_sale');
        $defaultPurchase = $this->input('default_purchase');
        $baseUnitId = (int) $this->validated('base_unit_id');

        $levels = [];

        foreach ((array) $this->validated('levels') as $index => $level) {
            $isBase = (int) $level['unit_id'] === $baseUnitId;

            $levels[] = [
                'unit_id' => (int) $level['unit_id'],
                'parent_unit_id' => $isBase ? null : ($level['parent_unit_id'] ?? null),
                'qty_per_parent' => $isBase ? 1 : (int) ($level['qty_per_parent'] ?? 1),
                'sale_price_paisa' => Money::parse($level['sale_price'] ?? 0),
                'mrp_paisa' => blank($level['mrp'] ?? null) ? null : Money::parse($level['mrp']),
                'is_default_sale' => (string) $index === (string) $defaultSale,
                'is_default_purchase' => (string) $index === (string) $defaultPurchase,
                'barcodes' => array_values($this->cleanBarcodes($level['barcodes'] ?? [])),
            ];
        }

        return $levels;
    }

    /**
     * The product's own columns, without the packaging.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'name_ur' => $this->validated('name_ur'),
            'sku' => $this->validated('sku') ?: null,
            'category_id' => $this->validated('category_id'),
            'brand_id' => $this->validated('brand_id'),
            'base_unit_id' => (int) $this->validated('base_unit_id'),
            'tax_rate' => $this->validated('tax_rate'),
            'is_weighted' => $this->boolean('is_weighted'),
            'track_batches' => $this->boolean('track_batches'),
            'track_expiry' => $this->boolean('track_expiry'),
            'is_active' => $this->boolean('is_active'),
            'reorder_level_base' => (int) ($this->validated('reorder_level_base') ?? 0),
            'reorder_qty_base' => (int) ($this->validated('reorder_qty_base') ?? 0),
        ];
    }

    /**
     * Each size must be described in terms of another size on the same form,
     * no size may appear twice, and the chain must not loop.
     */
    private function checkPackagingChain(Validator $validator): void
    {
        /** @var array<int, array<string, mixed>> $levels */
        $levels = (array) $this->input('levels', []);
        $baseUnitId = (int) $this->input('base_unit_id');

        $unitIds = [];

        foreach ($levels as $index => $level) {
            $unitId = (int) ($level['unit_id'] ?? 0);

            if (in_array($unitId, $unitIds, true)) {
                $validator->errors()->add("levels.{$index}.unit_id", __('This size is already listed. Each size can only appear once.'));
            }

            $unitIds[] = $unitId;
        }

        if (! in_array($baseUnitId, $unitIds, true)) {
            $validator->errors()->add('base_unit_id', __('The smallest unit must be one of the packaging sizes listed.'));

            return;
        }

        foreach ($levels as $index => $level) {
            $unitId = (int) ($level['unit_id'] ?? 0);

            if ($unitId === $baseUnitId) {
                continue;
            }

            if (blank($level['qty_per_parent'] ?? null)) {
                $validator->errors()->add("levels.{$index}.qty_per_parent", __('Say how many this pack holds, for example 24.'));
            }

            $parentId = (int) ($level['parent_unit_id'] ?? 0);

            if ($parentId === 0) {
                $validator->errors()->add("levels.{$index}.parent_unit_id", __('Say what this pack is made of, for example 24 sachets.'));

                continue;
            }

            if ($parentId === $unitId) {
                $validator->errors()->add("levels.{$index}.parent_unit_id", __('A pack cannot be made of itself. Pick a smaller size.'));

                continue;
            }

            if (! in_array($parentId, $unitIds, true)) {
                $validator->errors()->add("levels.{$index}.parent_unit_id", __('That size is not on this form. Add it first, or pick one that is.'));
            }
        }
    }

    /**
     * A code has to mean one thing. Two products sharing one is a till that
     * rings up the wrong item, so it is refused here rather than by a unique
     * index the shopkeeper would never be able to read.
     */
    private function checkBarcodes(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('levels', []) as $index => $level) {
            foreach ($this->cleanBarcodes($level['barcodes'] ?? []) as $position => $code) {
                $key = "levels.{$index}.barcodes.{$position}";

                if (isset($seen[$code])) {
                    $validator->errors()->add($key, __('This barcode is already used on another size of this item.'));

                    continue;
                }

                $seen[$code] = true;

                $owner = $this->productUsing($code);

                if ($owner !== null && ! $owner->is($this->route('product'))) {
                    $validator->errors()->add($key, __('Barcode :code already belongs to :name.', [
                        'code' => $code,
                        'name' => $owner->name,
                    ]));
                }
            }
        }
    }

    private function productUsing(string $code): ?Product
    {
        return Barcode::where('code', $code)->first()?->productUnit?->product;
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return array<int, string>
     */
    private function cleanBarcodes(array $codes): array
    {
        $clean = [];

        foreach ($codes as $position => $code) {
            $code = trim((string) $code);

            if ($code !== '') {
                $clean[$position] = $code;
            }
        }

        return $clean;
    }
}
