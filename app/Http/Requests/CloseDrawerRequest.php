<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The end-of-shift count: how many of each note and coin, how much stays in
 * the drawer for the next shift, and why the count is out if it is.
 */
class CloseDrawerRequest extends FormRequest
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
            'counts' => ['present', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'left_in_drawer' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'counts.*' => 'count',
            'left_in_drawer' => 'cash left in the drawer',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $denominations = array_map('intval', config('supermart.cash.denominations'));

                foreach (array_keys((array) $this->input('counts', [])) as $denomination) {
                    if (! in_array((int) $denomination, $denominations, true)) {
                        $validator->errors()->add('counts', __('The count has a note that does not exist.'));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<int, int>
     */
    public function counts(): array
    {
        return collect((array) $this->validated('counts'))
            ->mapWithKeys(fn (mixed $count, int|string $denomination): array => [(int) $denomination => (int) $count])
            ->all();
    }

    public function leftInDrawerPaisa(): int
    {
        return Money::parse($this->validated('left_in_drawer'));
    }
}
