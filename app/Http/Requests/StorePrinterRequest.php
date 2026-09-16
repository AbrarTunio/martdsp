<?php

namespace App\Http\Requests;

use App\Enums\PrinterConnection;
use App\Models\Printer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('printers', 'name')],
            'channel' => ['required', Rule::enum(PrinterConnection::class)],
            'target' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->needsTarget())],
            'paper' => ['required', Rule::in(array_keys(Printer::COLUMNS))],
            'cuts' => ['boolean'],
            'has_drawer' => ['boolean'],
            'drawer_pin' => ['integer', 'in:0,1'],
            'feed_lines' => ['integer', 'min:0', 'max:10'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cuts' => $this->boolean('cuts'),
            'has_drawer' => $this->boolean('has_drawer'),
            'drawer_pin' => (int) $this->input('drawer_pin', 0),
            'feed_lines' => (int) $this->input('feed_lines', 4),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('There is already a printer with that name.'),
            'target.required' => __('This kind of printer needs an address before the shop can reach it.'),
        ];
    }

    /**
     * A browser printer has nowhere to point at; every other kind does.
     */
    private function needsTarget(): bool
    {
        return PrinterConnection::tryFrom((string) $this->input('channel'))?->isDirect() === true;
    }
}
