<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Giving up on money owed. Owners and managers only, and never without a
 * reason — a write-off is the one line on a khata that costs the shop.
 */
class StoreKhataWriteOffRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
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
            'note.required' => __('Write down why this money is being given up on.'),
        ];
    }

    public function amountPaisa(): int
    {
        return Money::parse($this->validated('amount'));
    }
}
