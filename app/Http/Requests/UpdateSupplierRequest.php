<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The same form as creating a supplier, without the opening balance: once the
 * account has a history, a changed starting figure would rewrite every
 * balance after it. Corrections go through a balance adjustment instead.
 */
class UpdateSupplierRequest extends StoreSupplierRequest
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
