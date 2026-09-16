<?php

namespace App\Http\Requests;

use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            'phone' => [
                'nullable', 'string', 'max:20', 'regex:'.PhoneNumber::PATTERN,
                Rule::unique('customers', 'phone')->ignore($this->route('customer')),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'opening_is_advance' => ['boolean'],
        ];
    }

    /**
     * Phone numbers are written a dozen ways on a khata page. They are stored
     * in the one shape — the same shape a supplier's number is stored in — so
     * that searching for one finds it however it was typed, and so the same
     * neighbour cannot end up with two khatas. See App\Support\PhoneNumber.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalise($this->input('phone'))]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name_ur' => 'Urdu name',
            'credit_limit' => 'credit limit',
            'opening_balance' => 'amount already owed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => __('A customer with that phone number already has a khata.'),
            'phone.regex' => __('Type the number without the 0 in front, like 3001234567.'),
        ];
    }

    /**
     * The customer's own columns.
     *
     * @return array<string, mixed>
     */
    public function customerAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'name_ur' => $this->validated('name_ur'),
            'phone' => $this->validated('phone'),
            'address' => $this->validated('address'),
            'credit_limit_paisa' => Money::parse($this->validated('credit_limit')),
            'notes' => $this->validated('notes'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /**
     * What was already on the khata when the shop started using the system.
     * Negative when the customer had money sitting with the shop.
     */
    public function openingBalancePaisa(): int
    {
        $paisa = abs(Money::parse($this->validated('opening_balance')));

        return $this->boolean('opening_is_advance') ? -$paisa : $paisa;
    }
}
