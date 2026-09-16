<?php

namespace App\Http\Requests;

use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
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
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:'.PhoneNumber::PATTERN,
                Rule::unique('suppliers', 'phone')->ignore($this->route('supplier')),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'opening_balance' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            'opening_is_advance' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company' => 'company',
            'payment_terms_days' => 'days to pay',
            'opening_balance' => 'amount already owed',
        ];
    }

    /**
     * The number is taken as it is typed into the box beside the +92 and put
     * into the one shape the whole application stores, so that the same
     * salesman written three ways is still one supplier.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalise($this->input('phone'))]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => __('A phone number is needed. It is what keeps the same supplier from being added twice.'),
            'phone.regex' => __('Type the number without the 0 in front, like 3001234567.'),
            'phone.unique' => __('That number is already on another supplier. Search for them instead of adding them again.'),
        ];
    }

    /**
     * The supplier's own columns.
     *
     * @return array<string, mixed>
     */
    public function supplierAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'company' => $this->validated('company'),
            'phone' => $this->validated('phone'),
            'address' => $this->validated('address'),
            'payment_terms_days' => (int) ($this->validated('payment_terms_days') ?? 0),
            'notes' => $this->validated('notes'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /**
     * What was owed before the shop started using the system, in paisa.
     * Negative when the shop had paid the supplier in advance.
     */
    public function openingBalancePaisa(): int
    {
        $paisa = abs(Money::parse($this->validated('opening_balance')));

        return $this->boolean('opening_is_advance') ? -$paisa : $paisa;
    }
}
