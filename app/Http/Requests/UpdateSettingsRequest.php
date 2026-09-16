<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    /**
     * Keys are dotted setting names, so they arrive as `settings[shop.name]`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.shop\.name' => ['required', 'string', 'max:120'],
            'settings.shop\.address' => ['nullable', 'string', 'max:255'],
            'settings.shop\.phone' => ['nullable', 'string', 'max:40'],
            'settings.shop\.ntn' => ['nullable', 'string', 'max:20'],
            'settings.shop\.strn' => ['nullable', 'string', 'max:20'],
            'settings.tax\.gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.tax\.prices_include_tax' => ['boolean'],
            'settings.receipt\.paper_width' => ['required', Rule::in(array_keys(config('supermart.paper_widths')))],
            'settings.receipt\.footer_note' => ['nullable', 'string', 'max:255'],
            'settings.receipt\.auto_print' => ['boolean'],
            'settings.sales\.round_to_rupee' => ['boolean'],
            'settings.sales\.allow_negative_stock' => ['boolean'],
            'settings.sales\.cashier_discount_limit' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.khata\.credit_days' => ['required', 'integer', 'min:0', 'max:365'],
            'settings.drawer\.variance_tolerance' => ['required', 'integer', 'min:0', 'max:100000'],
            'settings.drawer\.blind_count' => ['boolean'],
            'settings.drawer\.pulse_on_cash' => ['boolean'],
        ];
    }

    /**
     * Unchecked checkboxes are simply absent from the payload, so they are
     * folded back in as false before validation.
     */
    protected function prepareForValidation(): void
    {
        $settings = (array) $this->input('settings', []);

        foreach (['tax.prices_include_tax', 'sales.round_to_rupee', 'sales.allow_negative_stock', 'receipt.auto_print', 'drawer.blind_count', 'drawer.pulse_on_cash'] as $key) {
            $settings[$key] = filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge(['settings' => $settings]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'settings.shop.name' => 'shop name',
            'settings.tax.gst_rate' => 'GST rate',
            'settings.receipt.paper_width' => 'receipt paper width',
            'settings.sales.cashier_discount_limit' => 'cashier discount limit',
            'settings.khata.credit_days' => 'khata credit days',
            'settings.drawer.variance_tolerance' => 'drawer tolerance',
        ];
    }
}
