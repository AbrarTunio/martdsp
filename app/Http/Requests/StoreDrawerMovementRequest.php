<?php

namespace App\Http\Requests;

use App\Enums\DrawerEntryType;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cash added to, paid out of, or taken away from a running drawer. Anyone
 * at the counter may record one; it is kept with their name on it.
 */
class StoreDrawerMovementRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_map(fn (DrawerEntryType $type): string => $type->value, DrawerEntryType::manual()))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'note' => ['nullable', 'required_if:type,'.DrawerEntryType::PayOut->value, 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required_if' => __('Say what the cash was paid out for.'),
        ];
    }

    public function entryType(): DrawerEntryType
    {
        return DrawerEntryType::from($this->validated('type'));
    }

    public function amountPaisa(): int
    {
        return Money::parse($this->validated('amount'));
    }
}
