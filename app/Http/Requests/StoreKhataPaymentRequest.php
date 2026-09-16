<?php

namespace App\Http\Requests;

use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\Register;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Money handed in against a khata. Anyone on the till may take it — that is
 * how it arrives, over the counter, in the middle of a shift.
 */
class StoreKhataPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', Rule::in(array_keys(TenderType::paymentOptions()))],
            'register_id' => ['nullable', 'integer', Rule::exists('registers', 'id')],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'paid_on' => 'date',
            'method' => 'how it was paid',
            'register_id' => 'counter',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_on.before_or_equal' => __('A payment cannot be dated in the future.'),
        ];
    }

    /**
     * Cash has to land in a drawer somebody has opened, and paying many times
     * over what is owed is a slipped zero rather than generosity.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->tender()?->isCash() && ! $this->register()?->openDrawer) {
                    $validator->errors()->add('register_id', __('No drawer is open at that counter. Open it first, then take the cash.'));
                }
            },
            function (Validator $validator): void {
                $customer = $this->route('customer');
                $owed = $customer instanceof Customer ? max(0, (int) $customer->balance_paisa) : 0;
                $paying = Money::parse($this->input('amount'));

                if ($owed > 0 && $paying > $owed * 10) {
                    $validator->errors()->add('amount', __('That is more than ten times what they owe. Check the figure — a zero may have slipped in.'));
                }
            },
        ];
    }

    public function amountPaisa(): int
    {
        return Money::parse($this->validated('amount'));
    }

    public function tender(): ?TenderType
    {
        return TenderType::tryFrom((string) $this->input('method'));
    }

    /**
     * The counter the cash went into. Falls back to the only counter there
     * is, which is the usual shop.
     */
    public function register(): ?Register
    {
        $id = $this->input('register_id');

        if ($id) {
            return Register::query()->with('openDrawer')->find($id);
        }

        return Register::query()->with('openDrawer')->active()->count() === 1
            ? Register::query()->with('openDrawer')->active()->first()
            : null;
    }
}
