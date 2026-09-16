<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Register;
use App\Support\SaleCalculator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A basket as the till sends it: which sizes, how many, and any discounts
 * typed against them. Prices are deliberately absent — the server looks them
 * up itself.
 */
class HoldSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'register_id' => ['required', 'integer', Rule::exists('registers', 'id')->where('is_active', true)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'bill_discount' => ['nullable', 'string', 'max:20', 'regex:'.SaleCalculator::DISCOUNT_PATTERN],
            'note' => ['nullable', 'string', 'max:255'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'lines.*.qty' => ['required', 'regex:/^\d{1,5}(\.\d{1,3})?$/'],
            'lines.*.discount' => ['nullable', 'string', 'max:20', 'regex:'.SaleCalculator::DISCOUNT_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'register_id' => 'counter',
            'customer_id' => 'customer',
            'bill_discount' => 'bill discount',
            'lines.*.product_unit_id' => 'item',
            'lines.*.qty' => 'quantity',
            'lines.*.discount' => 'discount',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('The basket is empty.'),
            'register_id.exists' => __('This counter has been switched off. Choose another counter.'),
            'customer_id.exists' => __('That customer has been switched off.'),
            'bill_discount.regex' => __('Type the discount as rupees, like 50, or a percentage, like 5%.'),
            'lines.*.discount.regex' => __('Type the discount as rupees, like 50, or a percentage, like 5%.'),
            'lines.*.qty.regex' => __('Quantities are numbers, like 2 or 0.5.'),
        ];
    }

    public function register(): Register
    {
        return Register::query()->findOrFail((int) $this->validated('register_id'));
    }

    public function customer(): ?Customer
    {
        $id = $this->validated('customer_id');

        return $id === null ? null : Customer::query()->find((int) $id);
    }

    /**
     * @return list<array{product_unit_id: int, qty: string, discount: string|null}>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line): array => [
            'product_unit_id' => (int) $line['product_unit_id'],
            'qty' => (string) $line['qty'],
            'discount' => $line['discount'] ?? null,
        ], (array) $this->validated('lines')));
    }

    public function billDiscount(): ?string
    {
        return $this->validated('bill_discount');
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }
}
