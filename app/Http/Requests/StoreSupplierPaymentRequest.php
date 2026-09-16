<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplierPaymentRequest extends FormRequest
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
            'method' => ['required', Rule::enum(PaymentMethod::class)],
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
     * Paying more than is owed leaves an advance with the supplier, which is
     * normal — but paying ten times more is a typing mistake, so that much is
     * refused and has to be entered deliberately as two payments.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $supplier = $this->route('supplier');
                $owed = max(0, (int) $supplier?->balance_paisa);
                $paying = Money::parse($this->input('amount'));

                if ($owed > 0 && $paying > $owed * 10) {
                    $validator->errors()->add('amount', __('That is more than ten times what you owe. Check the figure — a zero may have slipped in.'));
                }
            },
        ];
    }

    public function amountPaisa(): int
    {
        return Money::parse($this->validated('amount'));
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('method'));
    }
}
