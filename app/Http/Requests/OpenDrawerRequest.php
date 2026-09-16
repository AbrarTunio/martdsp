<?php

namespace App\Http\Requests;

use App\Models\Register;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a shift: which counter, and how much cash is in the drawer.
 */
class OpenDrawerRequest extends FormRequest
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
            'float' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'back_to' => ['nullable', Rule::in(['pos', 'drawer'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'register_id' => 'counter',
            'float' => 'opening cash',
        ];
    }

    public function register(): Register
    {
        return Register::query()->findOrFail((int) $this->validated('register_id'));
    }

    public function floatPaisa(): int
    {
        return Money::parse($this->validated('float'));
    }
}
