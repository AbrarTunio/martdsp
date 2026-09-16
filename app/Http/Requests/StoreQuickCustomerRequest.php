<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new khata customer added from the till — a name and a phone number is
 * enough to start. The rest can be filled in later from the Khata screen.
 */
class StoreQuickCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:'.PhoneNumber::PATTERN, Rule::unique('customers', 'phone')],
        ];
    }

    /** The one shape a number is stored in, shared with every other form. */
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
            'phone.unique' => __('A customer with that phone number already has a khata. Search for them instead.'),
            'phone.regex' => __('Type the number without the 0 in front, like 3001234567.'),
        ];
    }
}
