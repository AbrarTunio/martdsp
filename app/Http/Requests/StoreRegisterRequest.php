<?php

namespace App\Http\Requests;

use App\Models\Register;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('registers', 'name')],
            'location' => ['nullable', 'string', 'max:120'],
            'printer_id' => ['nullable', 'exists:printers,id'],
            'printer_profile' => ['nullable', Rule::in(Register::PAPERS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('There is already a counter with that name.'),
        ];
    }
}
