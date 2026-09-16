<?php

namespace App\Services;

use App\Enums\CustomerEntryType;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Register;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing allowed to change what a customer owes the shop.
 *
 * Every khata sale, payment and return comes through `record()`, which
 * appends one line and updates the cached balance inside the same
 * transaction, with the customer row locked. A debit means the customer owes
 * more; a credit means they owe less.
 */
class KhataService
{
    public function __construct(private readonly DrawerService $drawers) {}

    /**
     * Append one line and settle the balance.
     *
     * @throws RuntimeException when both sides are empty or either is negative
     */
    public function record(
        Customer $customer,
        CustomerEntryType $type,
        int $debitPaisa = 0,
        int $creditPaisa = 0,
        ?TenderType $method = null,
        ?Model $reference = null,
        ?string $note = null,
        mixed $entryDate = null,
        mixed $dueDate = null,
        ?int $userId = null,
    ): CustomerLedgerEntry {
        if ($creditPaisa < 0 || $debitPaisa < 0) {
            throw new RuntimeException('A khata amount is entered as a plain number; the type decides which way it goes.');
        }

        if ($creditPaisa === 0 && $debitPaisa === 0) {
            throw new RuntimeException('A khata line for nothing cannot be recorded.');
        }

        return DB::transaction(function () use (
            $customer, $type, $debitPaisa, $creditPaisa, $method, $reference, $note, $entryDate, $dueDate, $userId
        ): CustomerLedgerEntry {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $balanceAfter = (int) $locked->balance_paisa + $debitPaisa - $creditPaisa;

            $entry = $locked->ledgerEntries()->create([
                'type' => $type,
                'method' => $method,
                'debit_paisa' => $debitPaisa,
                'credit_paisa' => $creditPaisa,
                'balance_after_paisa' => $balanceAfter,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'entry_date' => $entryDate ?? today(),
                'due_date' => $dueDate,
                'user_id' => $userId ?? auth()->id(),
                'note' => $note,
            ]);

            $locked->forceFill(['balance_paisa' => $balanceAfter])->save();

            $customer->forceFill(['balance_paisa' => $balanceAfter])
                ->syncOriginalAttributes(['balance_paisa']);

            return $entry;
        });
    }

    /**
     * What the customer already owed when the shop started using the system.
     * Negative means they had money on account.
     *
     * Returns null when there was nothing to bring forward.
     */
    public function opening(Customer $customer, int $signedPaisa, ?string $note = null): ?CustomerLedgerEntry
    {
        $customer->forceFill(['opening_balance_paisa' => $signedPaisa])->save();

        if ($signedPaisa === 0) {
            return null;
        }

        return $this->record(
            customer: $customer,
            type: CustomerEntryType::Opening,
            debitPaisa: max(0, $signedPaisa),
            creditPaisa: max(0, -$signedPaisa),
            note: $note ?? __('Owed before this system was used'),
        );
    }

    /**
     * Money handed over against a khata.
     *
     * Cash is put into the counter's drawer inside the same transaction, so
     * the count at the end of the shift answers for it just as it does for a
     * cash sale. Anything else — card, wallet, bank — never touches the
     * drawer.
     *
     * @throws RuntimeException when khata is offered as the tender, or cash arrives with no counter named
     */
    public function payment(
        Customer $customer,
        int $paisa,
        TenderType $method,
        ?Register $register = null,
        mixed $entryDate = null,
        ?string $note = null,
        ?User $user = null,
    ): CustomerLedgerEntry {
        if (! $method->isPayment()) {
            throw new RuntimeException(__('A khata cannot be cleared with khata.'));
        }

        if ($method->isCash() && ! $register) {
            throw new RuntimeException(__('Say which counter the cash was handed in at.'));
        }

        return DB::transaction(function () use ($customer, $paisa, $method, $register, $entryDate, $note, $user): CustomerLedgerEntry {
            $session = $method->isCash() ? $this->drawers->lockOpenAt($register) : null;

            $entry = $this->record(
                customer: $customer,
                type: CustomerEntryType::Payment,
                creditPaisa: $paisa,
                method: $method,
                note: $note,
                entryDate: $entryDate,
                userId: $user?->id,
            );

            if ($session) {
                $this->drawers->recordKhataPayment($session, $entry, $user?->id);
            }

            return $entry;
        });
    }

    /**
     * A correction to what a customer owes, with the reason written against
     * it. Positive means they owe more.
     */
    public function adjust(Customer $customer, int $signedPaisa, string $note, mixed $entryDate = null): CustomerLedgerEntry
    {
        return $this->record(
            customer: $customer,
            type: CustomerEntryType::Adjustment,
            debitPaisa: max(0, $signedPaisa),
            creditPaisa: max(0, -$signedPaisa),
            note: $note,
            entryDate: $entryDate,
        );
    }

    /**
     * Giving up on money that will not come back. The khata keeps every line
     * it ever had — the debt is cleared by a line of its own, never by
     * rubbing anything out.
     *
     * @throws RuntimeException when more is written off than is owed
     */
    public function writeOff(Customer $customer, int $paisa, string $note, mixed $entryDate = null): CustomerLedgerEntry
    {
        $owed = max(0, (int) $customer->fresh()->balance_paisa);

        if ($paisa > $owed) {
            throw new RuntimeException(__(':name only owes :amount.', [
                'name' => $customer->name,
                'amount' => Money::withSymbol($owed),
            ]));
        }

        return $this->record(
            customer: $customer,
            type: CustomerEntryType::WriteOff,
            creditPaisa: $paisa,
            note: $note,
            entryDate: $entryDate,
        );
    }

    /**
     * Replay a customer's khata and report whether the cached balance agrees
     * with it. Writes the ledger's answer back when it does not.
     *
     * @return array{balance_before: int, balance_after: int, drifted: bool}
     */
    public function rebuild(Customer $customer): array
    {
        return DB::transaction(function () use ($customer): array {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $before = (int) $locked->balance_paisa;
            $after = (int) $locked->ledgerEntries()->sum(DB::raw('debit_paisa - credit_paisa'));

            if ($before !== $after) {
                $locked->forceFill(['balance_paisa' => $after])->save();
            }

            return [
                'balance_before' => $before,
                'balance_after' => $after,
                'drifted' => $before !== $after,
            ];
        });
    }
}
