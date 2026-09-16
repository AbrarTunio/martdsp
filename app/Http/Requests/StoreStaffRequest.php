<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'pin_code' => ['nullable', 'digits_between:4,6'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Nobody types their email in lowercase. Fold it rather than scolding them.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower((string) $this->input('email'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin_code.digits_between' => 'The till PIN must be 4 to 6 digits.',
        ];
    }
}
