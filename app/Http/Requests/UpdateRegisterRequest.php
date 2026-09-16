<?php

namespace App\Http\Requests;

use App\Models\Register;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:60', Rule::unique('registers', 'name')->ignore($this->route('register'))],
            'location' => ['nullable', 'string', 'max:120'],
            'printer_id' => ['nullable', 'exists:printers,id'],
            'printer_profile' => ['nullable', Rule::in(Register::PAPERS)],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
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
