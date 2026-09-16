<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-backups');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'folder' => ['nullable', 'string', 'max:255'],
            'keep_days' => ['required', 'integer', 'min:1', 'max:365'],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
        ];
    }

    /**
     * An unticked box is simply absent from the payload, so it is folded back
     * in as false before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => filter_var($this->input('enabled', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * The four values, under the dotted keys the settings table uses.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'backup.enabled' => $this->boolean('enabled'),
            'backup.folder' => trim((string) $this->input('folder', '')),
            'backup.keep_days' => $this->integer('keep_days'),
            'backup.hour' => $this->integer('hour'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enabled' => 'nightly backup',
            'folder' => 'backup folder',
            'keep_days' => 'days to keep',
            'hour' => 'backup hour',
        ];
    }
}
