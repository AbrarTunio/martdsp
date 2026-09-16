<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    /**
     * The key is its own field, because it is stored encrypted and is never
     * sent back to the browser: left blank, whatever is saved stays.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['nullable', Rule::enum(AiProvider::class)],
            'api_key' => ['nullable', 'string', 'max:300'],
            'model' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255', Rule::requiredIf($this->input('provider') === AiProvider::Custom->value)],
            'monthly_cap' => ['required', 'integer', 'min:0', 'max:1000000'],
            'language' => ['required', Rule::in(['en', 'ur'])],
            'usd_rate' => ['required', 'integer', 'min:1', 'max:100000'],
            'insights' => ['required', 'array'],
            'insights.dead_stock_days' => ['required', 'integer', 'min:7', 'max:365'],
            'insights.dead_stock_value' => ['required', 'integer', 'min:0', 'max:10000000'],
            'insights.overstock_days' => ['required', 'integer', 'min:7', 'max:365'],
            'insights.payment_gap_days' => ['required', 'integer', 'min:1', 'max:365'],
            'insights.debt_growth_ratio' => ['required', 'numeric', 'min:1', 'max:10'],
            'insights.receivables_share' => ['required', 'numeric', 'min:1', 'max:100'],
            'insights.variance_alert' => ['required', 'integer', 'min:0', 'max:1000000'],
            'insights.void_rate_multiple' => ['required', 'numeric', 'min:1', 'max:20'],
            'insights.price_rise_percent' => ['required', 'numeric', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'api_key' => __('API key'),
            'base_url' => __('address'),
            'monthly_cap' => __('monthly limit'),
            'usd_rate' => __('dollar rate'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_url.required' => __('A custom provider needs the address its API lives at.'),
        ];
    }
}
