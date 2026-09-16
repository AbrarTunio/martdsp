<?php

namespace App\Http\Requests;

use App\Services\ScanService;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The short form that opens when a delivery contains something the shop has
 * never stocked. It asks only what receiving needs — a name, what the scanned
 * code is, and how many pieces are in a pack — and leaves the rest for the
 * full product form later.
 */
class StoreQuickProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:64'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'pack_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id'), 'different:base_unit_id'],
            'qty_per_pack' => ['nullable', 'required_with:pack_unit_id', 'integer', 'min:2', 'max:100000'],
            'scanned_is' => ['required', Rule::in(['base', 'pack'])],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'barcode',
            'base_unit_id' => 'smallest size',
            'pack_unit_id' => 'pack size',
            'qty_per_pack' => 'pieces in a pack',
            'sale_price' => 'sale price',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pack_unit_id.different' => __('The pack has to be a bigger size than the single piece.'),
            'qty_per_pack.required_with' => __('Say how many pieces come in one pack.'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (app(ScanService::class)->isTaken((string) $this->input('code'))) {
                    $validator->errors()->add('code', __('This barcode already belongs to another item.'));
                }

                if ($this->input('scanned_is') === 'pack' && blank($this->input('pack_unit_id'))) {
                    $validator->errors()->add('pack_unit_id', __('Say what the pack is, since the barcode is on the pack.'));
                }
            },
        ];
    }

    /**
     * Packaging levels in the shape PackagingService::sync() takes. The piece
     * is what the till sells by default; the pack is what the supplier
     * delivers by default. The scanned code goes on whichever it was read
     * from.
     *
     * @return array<int, array<string, mixed>>
     */
    public function levels(): array
    {
        $baseUnitId = (int) $this->validated('base_unit_id');
        $packUnitId = $this->validated('pack_unit_id');
        $code = trim((string) $this->validated('code'));
        $piecePrice = Money::parse($this->validated('sale_price'));
        $scannedPack = $this->validated('scanned_is') === 'pack';

        $levels = [[
            'unit_id' => $baseUnitId,
            'parent_unit_id' => null,
            'qty_per_parent' => 1,
            'sale_price_paisa' => $piecePrice,
            'mrp_paisa' => null,
            'is_default_sale' => true,
            'is_default_purchase' => $packUnitId === null,
            'barcodes' => $scannedPack ? [] : [$code],
        ]];

        if ($packUnitId !== null) {
            $perPack = (int) $this->validated('qty_per_pack');

            $levels[] = [
                'unit_id' => (int) $packUnitId,
                'parent_unit_id' => $baseUnitId,
                'qty_per_parent' => $perPack,
                'sale_price_paisa' => $piecePrice * $perPack,
                'mrp_paisa' => null,
                'is_default_sale' => false,
                'is_default_purchase' => true,
                'barcodes' => $scannedPack ? [$code] : [],
            ];
        }

        return $levels;
    }

    /**
     * Which size the scanned code was read from, so the new line on the
     * purchase starts in that size.
     */
    public function scannedUnitId(): int
    {
        return $this->validated('scanned_is') === 'pack'
            ? (int) $this->validated('pack_unit_id')
            : (int) $this->validated('base_unit_id');
    }
}
