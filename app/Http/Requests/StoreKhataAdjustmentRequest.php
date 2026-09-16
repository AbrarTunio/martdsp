<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKhataAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('supervise');
    }

    /**
     * A reason is required: a correction nobody can explain later is how a
     * khata quietly stops matching the customer's own reckoning.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'direction' => ['required', Rule::in(['more', 'less'])],
            'note' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'note' => 'reason',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('Write down why the khata is being changed, so it can be explained later.'),
        ];
    }

    /**
     * Positive when the customer owes more.
     */
    public function signedPaisa(): int
    {
        $paisa = Money::parse($this->validated('amount'));

        return $this->validated('direction') === 'more' ? $paisa : -$paisa;
    }
}
