<?php

namespace App\Services;

use App\Enums\DrawerEntryType;
use App\Enums\DrawerStatus;
use App\Enums\TenderType;
use App\Models\ActivityLog;
use App\Models\CustomerLedgerEntry;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The only thing allowed to move cash in or out of a drawer's ledger.
 *
 * A shift opens with a float, collects every cash sale and cash refund, takes
 * hand-typed pay-ins, pay-outs and safe drops, and closes with a count note by
 * note. What the drawer should hold is always the sum of its ledger, so the
 * close compares a real count against a figure nobody typed.
 *
 * Lock order, everywhere: the drawer shift, then sales, then products.
 */
class DrawerService
{
    /**
     * Start a shift at a counter.
     *
     * @throws RuntimeException when the counter is off, or already has a shift running
     */
    public function open(Register $register, User $user, int $floatPaisa, ?string $note = null): DrawerSession
    {
        if ($floatPaisa < 0) {
            throw new RuntimeException(__('The opening cash cannot be less than nothing.'));
        }

        return DB::transaction(function () use ($register, $user, $floatPaisa, $note): DrawerSession {
            $locked = Register::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw new RuntimeException(__(':register is switched off.', ['register' => $locked->name]));
            }

            $running = DrawerSession::query()->open()->where('register_id', $locked->id)->with('opener')->first();

            if ($running) {
                throw new RuntimeException(__(':register is already open — since :time, by :name.', [
                    'register' => $locked->name,
                    'time' => $running->opened_at->format('g:i A'),
                    'name' => $running->opener?->name ?? __('someone'),
                ]));
            }

            $session = DrawerSession::query()->create([
                'register_id' => $locked->id,
                'open_register_id' => $locked->id,
                'status' => DrawerStatus::Open,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_float_paisa' => $floatPaisa,
                'opening_note' => $this->typed($note),
            ]);

            if ($floatPaisa > 0) {
                $this->write($session, DrawerEntryType::OpeningFloat, $floatPaisa, $user->id);
            }

            ActivityLog::record('drawer.opened', $session, after: [
                'register' => $locked->name,
                'opening_float_paisa' => $floatPaisa,
            ]);

            return $session;
        });
    }

    /**
     * Cash added, paid out, or taken away to the safe, typed in by hand.
     *
     * @throws RuntimeException when the drawer is closed, the note is missing, or the drawer does not hold that much
     */
    public function move(DrawerSession $session, User $user, DrawerEntryType $type, int $amountPaisa, ?string $note = null): DrawerTransaction
    {
        if (! $type->isManual()) {
            throw new RuntimeException(__(':type is recorded by the till, not by hand.', ['type' => __($type->label())]));
        }

        if ($amountPaisa <= 0) {
            throw new RuntimeException(__('Enter how much.'));
        }

        $note = $this->typed($note);

        if ($type->needsNote() && $note === null) {
            throw new RuntimeException(__('Say what the cash was paid out for.'));
        }

        return DB::transaction(function () use ($session, $user, $type, $amountPaisa, $note): DrawerTransaction {
            $locked = $this->lockOpen($session);

            if ($type->isOut()) {
                $inDrawer = $locked->expectedCashPaisa();

                if ($amountPaisa > $inDrawer) {
                    throw new RuntimeException(Gate::forUser($user)->allows('see-expected-cash')
                        ? __('The drawer should only hold :amount.', ['amount' => Money::withSymbol($inDrawer)])
                        : __('That is more than the drawer should hold. Count it again, or ask a manager.'));
                }
            }

            $entry = $this->write($locked, $type, $type->isOut() ? -$amountPaisa : $amountPaisa, $user->id, note: $note);

            ActivityLog::record('drawer.'.$type->value, $locked, after: [
                'amount_paisa' => $amountPaisa,
                'note' => $note,
            ]);

            return $entry;
        });
    }

    /**
     * The shift running at a counter, locked for the sale that is about to
     * put cash into it. Call it inside the sale's transaction, first.
     *
     * @throws RuntimeException when nobody has opened the drawer
     */
    public function lockOpenAt(Register $register): DrawerSession
    {
        return DrawerSession::query()
            ->open()
            ->where('register_id', $register->id)
            ->lockForUpdate()
            ->first()
            ?? throw new RuntimeException(__('Open the drawer at :register before selling.', ['register' => $register->name]));
    }

    /**
     * Put a completed sale's cash in the drawer — what it came to, not what
     * was handed over, since the change went straight back out.
     */
    public function recordSale(DrawerSession $session, Sale $sale): ?DrawerTransaction
    {
        $cash = $this->cashIn($sale);

        if ($cash === 0) {
            return null;
        }

        return $this->write($session, DrawerEntryType::CashSale, $cash, $sale->user_id, $sale, $sale->invoiceNumber());
    }

    /**
     * Hand a voided sale's cash back out of the drawer it is being voided at.
     */
    public function recordVoid(DrawerSession $session, Sale $sale, User $supervisor): ?DrawerTransaction
    {
        $cash = $this->cashIn($sale);

        if ($cash === 0) {
            return null;
        }

        return $this->write($session, DrawerEntryType::SaleVoid, -$cash, $supervisor->id, $sale, $sale->invoiceNumber());
    }

    /**
     * Hand a customer's refund back out of the drawer at the counter they
     * brought the goods to.
     *
     * Unlike a pay-out, this is not checked against what the drawer should
     * hold. The refund is owed whether or not there is a note in the till to
     * pay it with, and a drawer that goes short says so at the count, which
     * is where a shortage belongs.
     */
    public function recordRefund(DrawerSession $session, SaleReturn $return, User $user): ?DrawerTransaction
    {
        if ($return->total_paisa <= 0) {
            return null;
        }

        return $this->write(
            $session,
            DrawerEntryType::Refund,
            -(int) $return->total_paisa,
            $user->id,
            $return,
            __(':reference against :invoice', [
                'reference' => $return->reference,
                'invoice' => $return->loadMissing('sale')->sale?->invoiceNumber() ?? __('a bill'),
            ]),
        );
    }

    /**
     * Cash handed over against a customer's khata, dropped into the drawer at
     * the counter it was taken at.
     */
    public function recordKhataPayment(DrawerSession $session, CustomerLedgerEntry $entry, ?int $userId = null): DrawerTransaction
    {
        return $this->write(
            $session,
            DrawerEntryType::KhataPayment,
            (int) $entry->credit_paisa,
            $userId ?? $entry->user_id,
            $entry,
            __('Khata payment from :name', [
                'name' => $entry->loadMissing('customer')->customer?->name ?? __('a customer'),
            ]),
        );
    }

    /**
     * What a sale put into the drawer.
     */
    public function cashIn(Sale $sale): int
    {
        return (int) $sale->payments()->where('method', TenderType::Cash)->sum('amount_paisa');
    }

    /**
     * End a shift with a note-by-note count.
     *
     * The gap between the count and the ledger is the variance. Within the
     * tolerance in Settings it is just change-making; beyond it the closer
     * has to say why, and a manager has to sign it off — unless the closer
     * is one.
     *
     * @param  array<int|string, int|string|null>  $counts  notes and coins in rupees => how many
     *
     * @throws RuntimeException when the drawer is already closed, the reason is missing, or more is left than was counted
     */
    public function close(DrawerSession $session, User $user, array $counts, int $leftInDrawerPaisa, ?string $reason = null): DrawerSession
    {
        $lines = $this->countLines($counts);
        $counted = array_sum(array_column($lines, 'subtotal_paisa'));
        $reason = $this->typed($reason);

        if ($leftInDrawerPaisa < 0 || $leftInDrawerPaisa > $counted) {
            throw new RuntimeException(__('You can only leave what you counted — :amount.', ['amount' => Money::withSymbol($counted)]));
        }

        return DB::transaction(function () use ($session, $user, $lines, $counted, $leftInDrawerPaisa, $reason): DrawerSession {
            $locked = $this->lockOpen($session);

            $expected = $locked->expectedCashPaisa();
            $variance = $counted - $expected;
            $beyondTolerance = abs($variance) > $this->tolerancePaisa();

            if ($beyondTolerance && $reason === null) {
                throw new RuntimeException(Gate::forUser($user)->allows('see-expected-cash')
                    ? __('The count is :amount :direction. Say what happened.', [
                        'amount' => Money::withSymbol(abs($variance)),
                        'direction' => $variance < 0 ? __('short') : __('over'),
                    ])
                    : __('The count does not match what the drawer should hold. Count again, or say what happened.'));
            }

            foreach ($lines as $line) {
                $locked->countLines()->create($line);
            }

            $selfApproved = $beyondTolerance && $user->supervises();

            $locked->forceFill([
                'status' => DrawerStatus::Closed,
                'open_register_id' => null,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash_paisa' => $expected,
                'counted_cash_paisa' => $counted,
                'variance_paisa' => $variance,
                'variance_reason' => $reason,
                'needs_approval' => $beyondTolerance,
                'approved_by' => $selfApproved ? $user->id : null,
                'approved_at' => $selfApproved ? now() : null,
                'left_in_drawer_paisa' => $leftInDrawerPaisa,
            ])->save();

            ActivityLog::record('drawer.closed', $locked, after: [
                'expected_cash_paisa' => $expected,
                'counted_cash_paisa' => $counted,
                'variance_paisa' => $variance,
                'left_in_drawer_paisa' => $leftInDrawerPaisa,
                'needs_approval' => $beyondTolerance && ! $selfApproved,
            ]);

            return $locked;
        });
    }

    /**
     * A manager signs off a count that was out by more than the tolerance.
     *
     * @throws RuntimeException when there is nothing waiting to be signed off
     */
    public function approve(DrawerSession $session, User $supervisor, ?string $note = null): DrawerSession
    {
        return DB::transaction(function () use ($session, $supervisor, $note): DrawerSession {
            $locked = DrawerSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isAwaitingApproval()) {
                throw new RuntimeException(__('This count is not waiting to be signed off.'));
            }

            $locked->forceFill([
                'approved_by' => $supervisor->id,
                'approved_at' => now(),
                'approval_note' => $this->typed($note),
            ])->save();

            ActivityLog::record('drawer.approved', $locked, after: [
                'variance_paisa' => $locked->variance_paisa,
                'note' => $locked->approval_note,
            ]);

            return $locked;
        });
    }

    /**
     * What the drawer at this counter was last left with, to suggest as the
     * next shift's opening cash.
     */
    public function suggestedFloatPaisa(Register $register): int
    {
        return (int) $register->lastClosedDrawer()->value('left_in_drawer_paisa');
    }

    /**
     * Turn the count form into one line per note or coin that was there.
     *
     * @param  array<int|string, int|string|null>  $counts
     * @return list<array{denomination: int, count: int, subtotal_paisa: int}>
     *
     * @throws RuntimeException when a note the shop does not use is counted
     */
    private function countLines(array $counts): array
    {
        $denominations = array_map('intval', config('supermart.cash.denominations'));
        $lines = [];

        foreach ($counts as $denomination => $count) {
            $count = (int) $count;

            if ($count === 0) {
                continue;
            }

            if ($count < 0 || ! in_array((int) $denomination, $denominations, true)) {
                throw new RuntimeException(__('The count has a note that does not exist.'));
            }

            $lines[] = [
                'denomination' => (int) $denomination,
                'count' => $count,
                'subtotal_paisa' => (int) $denomination * 100 * $count,
            ];
        }

        usort($lines, fn (array $a, array $b): int => $b['denomination'] <=> $a['denomination']);

        return $lines;
    }

    /**
     * @throws RuntimeException
     */
    private function lockOpen(DrawerSession $session): DrawerSession
    {
        $locked = DrawerSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw new RuntimeException(__('This drawer has already been closed.'));
        }

        return $locked;
    }

    private function write(
        DrawerSession $session,
        DrawerEntryType $type,
        int $signedPaisa,
        ?int $userId,
        ?Model $reference = null,
        ?string $note = null,
    ): DrawerTransaction {
        return $session->transactions()->create([
            'type' => $type,
            'amount_paisa' => $signedPaisa,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'user_id' => $userId,
            'note' => $note,
        ]);
    }

    private function tolerancePaisa(): int
    {
        return max(0, (int) Setting::read('drawer.variance_tolerance')) * 100;
    }

    private function typed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
