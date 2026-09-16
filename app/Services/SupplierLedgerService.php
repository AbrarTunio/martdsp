<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SupplierEntryType;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing allowed to change what the shop owes a supplier.
 *
 * Every bill, payment and return comes through `record()`, which appends one
 * statement line and updates the cached balance inside the same transaction,
 * with the supplier row locked. A credit means the shop owes more; a debit
 * means it owes less.
 */
class SupplierLedgerService
{
    /**
     * Append one line and settle the balance.
     *
     * @throws RuntimeException when both sides are empty or either is negative
     */
    public function record(
        Supplier $supplier,
        SupplierEntryType $type,
        int $creditPaisa = 0,
        int $debitPaisa = 0,
        ?PaymentMethod $method = null,
        ?Model $reference = null,
        ?string $note = null,
        mixed $entryDate = null,
        ?int $userId = null,
    ): SupplierLedgerEntry {
        if ($creditPaisa < 0 || $debitPaisa < 0) {
            throw new RuntimeException('A ledger amount is entered as a plain number; the type decides which way it goes.');
        }

        if ($creditPaisa === 0 && $debitPaisa === 0) {
            throw new RuntimeException('A statement line for nothing cannot be recorded.');
        }

        return DB::transaction(function () use (
            $supplier, $type, $creditPaisa, $debitPaisa, $method, $reference, $note, $entryDate, $userId
        ): SupplierLedgerEntry {
            $locked = Supplier::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();

            $balanceAfter = (int) $locked->balance_paisa + $creditPaisa - $debitPaisa;

            $entry = $locked->ledgerEntries()->create([
                'type' => $type,
                'method' => $method,
                'credit_paisa' => $creditPaisa,
                'debit_paisa' => $debitPaisa,
                'balance_after_paisa' => $balanceAfter,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'entry_date' => $entryDate ?? today(),
                'user_id' => $userId ?? auth()->id(),
                'note' => $note,
            ]);

            $locked->forceFill(['balance_paisa' => $balanceAfter])->save();

            $supplier->forceFill(['balance_paisa' => $balanceAfter])
                ->syncOriginalAttributes(['balance_paisa']);

            return $entry;
        });
    }

    /**
     * What was already owed when the shop started using the system. Negative
     * means an advance is sitting with the supplier.
     *
     * Returns null when there was nothing to bring forward.
     */
    public function opening(Supplier $supplier, int $signedPaisa, ?string $note = null): ?SupplierLedgerEntry
    {
        $supplier->forceFill(['opening_balance_paisa' => $signedPaisa])->save();

        if ($signedPaisa === 0) {
            return null;
        }

        return $this->record(
            supplier: $supplier,
            type: SupplierEntryType::Opening,
            creditPaisa: max(0, $signedPaisa),
            debitPaisa: max(0, -$signedPaisa),
            note: $note ?? __('Owed before this system was used'),
        );
    }

    /**
     * Money handed to the supplier.
     */
    public function payment(
        Supplier $supplier,
        int $paisa,
        PaymentMethod $method,
        mixed $entryDate = null,
        ?string $note = null,
        ?Model $reference = null,
    ): SupplierLedgerEntry {
        return $this->record(
            supplier: $supplier,
            type: SupplierEntryType::Payment,
            debitPaisa: $paisa,
            method: $method,
            reference: $reference,
            note: $note,
            entryDate: $entryDate,
        );
    }

    /**
     * A correction to the balance, with the reason written down. Positive
     * means the shop owes more.
     */
    public function adjust(Supplier $supplier, int $signedPaisa, string $note, mixed $entryDate = null): SupplierLedgerEntry
    {
        return $this->record(
            supplier: $supplier,
            type: SupplierEntryType::Adjustment,
            creditPaisa: max(0, $signedPaisa),
            debitPaisa: max(0, -$signedPaisa),
            note: $note,
            entryDate: $entryDate,
        );
    }

    /**
     * Replay a supplier's statement and report whether the cached balance
     * agrees with it. Writes the ledger's answer back when it does not.
     *
     * @return array{balance_before: int, balance_after: int, drifted: bool}
     */
    public function rebuild(Supplier $supplier): array
    {
        return DB::transaction(function () use ($supplier): array {
            $locked = Supplier::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();

            $before = (int) $locked->balance_paisa;
            $after = (int) $locked->ledgerEntries()->sum(DB::raw('credit_paisa - debit_paisa'));

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
