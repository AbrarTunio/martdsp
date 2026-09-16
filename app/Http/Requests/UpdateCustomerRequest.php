<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The same form as adding a customer, without the opening balance: once the
 * khata has lines on it, a changed starting figure would rewrite every
 * balance after it. Corrections go through an adjustment instead.
 */
class UpdateCustomerRequest extends StoreCustomerRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return collect(parent::rules())
            ->except(['opening_balance', 'opening_is_advance'])
            ->all();
    }
}
