<?php

namespace App\Http\Requests;

use App\Enums\TenderType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * A basket being paid for: the basket itself, how it is being paid, and the
 * total the cashier's screen showed, so the server can tell if a price moved
 * in between.
 */
class StoreSaleRequest extends HoldSaleRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'expected_total_paisa' => ['nullable', 'integer', 'min:0'],

            // The name a till gave a bill it rang up while the line was down.
            'offline_uid' => ['nullable', 'uuid'],
            'offline_rung_at' => ['nullable', 'date'],

            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(TenderType::class)],
            'payments.*.amount_paisa' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + [
            'payments.*.method' => 'payment method',
            'payments.*.amount_paisa' => 'amount',
            'payments.*.reference' => 'reference',
        ];
    }

    /**
     * @return list<array{method: TenderType, amount_paisa: int, reference: string|null}>
     */
    public function payments(): array
    {
        return array_values(array_map(fn (array $payment): array => [
            'method' => TenderType::from($payment['method']),
            'amount_paisa' => (int) $payment['amount_paisa'],
            'reference' => $payment['reference'] ?? null,
        ], (array) $this->validated('payments', [])));
    }

    /**
     * Whether a note actually changed hands, which is the only reason to make
     * the drawer jump. Card and khata bills leave it shut.
     */
    public function tookCash(): bool
    {
        foreach ($this->payments() as $payment) {
            if ($payment['method'] === TenderType::Cash && $payment['amount_paisa'] > 0) {
                return true;
            }
        }

        return false;
    }

    public function expectedTotalPaisa(): ?int
    {
        $total = $this->validated('expected_total_paisa');

        return $total === null ? null : (int) $total;
    }

    /**
     * The name the till gave this bill before the server ever saw it. Sending
     * the same one twice gets the same bill back, not a second one.
     */
    public function offlineUid(): ?string
    {
        $uid = $this->validated('offline_uid');

        return $uid === null ? null : (string) $uid;
    }

    /**
     * When the cashier actually took the money, if that was earlier.
     */
    public function rungUpAt(): ?string
    {
        $moment = $this->validated('offline_rung_at');

        return $moment === null ? null : (string) $moment;
    }
}
