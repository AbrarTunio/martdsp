<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The same rules as creating, except that the product's own SKU is not a
 * clash with itself, and the base unit is fixed for the life of the product:
 * stock is counted in it, so changing it would silently reinterpret every
 * number already recorded.
 */
class UpdateProductRequest extends StoreProductRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->product();

        return array_merge(parent::rules(), [
            'sku' => ['nullable', 'string', 'max:32', Rule::unique('products', 'sku')->ignore($product->getKey())],
            'base_unit_id' => ['required', 'integer', Rule::in([$product->base_unit_id])],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_unit_id.in' => __('The smallest unit cannot be changed, because your stock count is measured in it.'),
        ];
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }
}
