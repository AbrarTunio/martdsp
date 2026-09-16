<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
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
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users')->ignore($this->route('staff')),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::enum(Role::class)],

            /** Left blank means "keep the current password". */
            'password' => ['nullable', 'confirmed', Password::defaults()],
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
     * The edit form can reach 'is_active' and 'role', so it can do everything
     * the destroy route refuses to do. Both paths need the same two guards, or
     * an owner could lock every owner out of the shop with one careless save.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $staff = $this->route('staff');

                if (! $staff instanceof User) {
                    return;
                }

                $deactivating = $this->has('is_active') && ! $this->boolean('is_active');
                $losingOwnership = $staff->isOwner() && $this->input('role') !== Role::Owner->value;

                if ($deactivating && $staff->is($this->user())) {
                    $validator->errors()->add('is_active', __('You cannot stop yourself from signing in. Ask another owner to do it.'));

                    return;
                }

                if (! $deactivating && ! $losingOwnership) {
                    return;
                }

                if ($staff->isOwner() && $this->isLastActiveOwner($staff)) {
                    $validator->errors()->add(
                        $deactivating ? 'is_active' : 'role',
                        __('The shop must always have at least one active owner. Make someone else an owner first.'),
                    );
                }
            },
        ];
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

    private function isLastActiveOwner(User $staff): bool
    {
        return User::active()
            ->where('role', Role::Owner)
            ->whereKeyNot($staff->getKey())
            ->doesntExist();
    }
}
