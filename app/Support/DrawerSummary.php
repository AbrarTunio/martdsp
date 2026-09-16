<?php

namespace App\Support;

use App\Enums\DrawerEntryType;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\SalePayment;
use Illuminate\Support\Collection;

/**
 * Everything one shift's report says, worked out once from the drawer ledger
 * and the bills rung up in it. The X-report (a peek while the shift runs) and
 * the Z-report (the shift's end) are the same summary printed at different
 * moments.
 */
final class DrawerSummary
{
    /**
     * @param  array<string, array{count: int, paisa: int}>  $movements  signed totals, keyed by DrawerEntryType value
     * @param  array<string, array{count: int, paisa: int}>  $tenders  what completed bills were paid with, keyed by TenderType value
     * @param  Collection<int, DrawerTransaction>  $handled  pay-ins, pay-outs and safe drops, oldest first
     */
    public function __construct(
        public readonly DrawerSession $session,
        public readonly array $movements,
        public readonly int $expectedPaisa,
        public readonly array $tenders,
        public readonly int $billCount,
        public readonly int $billTotalPaisa,
        public readonly int $discountPaisa,
        public readonly int $taxPaisa,
        public readonly int $voidedCount,
        public readonly Collection $handled,
        public readonly ?DrawerSession $previous,
        public readonly ?DrawerSession $next,
    ) {}

    public static function for(DrawerSession $session): self
    {
        $session->loadMissing(['register', 'opener', 'closer', 'approver', 'countLines']);

        $movements = $session->transactions()
            ->toBase()
            ->selectRaw('type, count(*) as entries, sum(amount_paisa) as paisa')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $row->type => ['count' => (int) $row->entries, 'paisa' => (int) $row->paisa],
            ])
            ->all();

        $bills = $session->sales()
            ->toBase()
            ->where('status', SaleStatus::Completed->value)
            ->selectRaw('count(*) as bills, coalesce(sum(total_paisa), 0) as total, coalesce(sum(discount_paisa), 0) as discount, coalesce(sum(tax_paisa), 0) as tax')
            ->first();

        $tenders = SalePayment::query()
            ->toBase()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.drawer_session_id', $session->id)
            ->where('sales.status', SaleStatus::Completed->value)
            ->selectRaw('sale_payments.method as method, count(*) as entries, sum(sale_payments.amount_paisa) as paisa')
            ->groupBy('sale_payments.method')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $row->method => ['count' => (int) $row->entries, 'paisa' => (int) $row->paisa],
            ])
            ->all();

        $handled = $session->transactions()
            ->with('user')
            ->whereIn('type', array_map(fn (DrawerEntryType $type): string => $type->value, DrawerEntryType::manual()))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return new self(
            session: $session,
            movements: $movements,
            expectedPaisa: $session->isOpen()
                ? array_sum(array_column($movements, 'paisa'))
                : (int) $session->expected_cash_paisa,
            tenders: $tenders,
            billCount: (int) $bills->bills,
            billTotalPaisa: (int) $bills->total,
            discountPaisa: (int) $bills->discount,
            taxPaisa: (int) $bills->tax,
            voidedCount: $session->sales()->where('status', SaleStatus::Void)->count(),
            handled: $handled,
            previous: $session->previous(),
            next: $session->next(),
        );
    }

    /**
     * The signed total of one kind of movement: positive in, negative out.
     */
    public function paisa(DrawerEntryType $type): int
    {
        return $this->movements[$type->value]['paisa'] ?? 0;
    }

    public function count(DrawerEntryType $type): int
    {
        return $this->movements[$type->value]['count'] ?? 0;
    }

    /**
     * The drawer's lines in the order a report reads them, skipping kinds
     * that did not happen this shift. The opening cash always shows.
     *
     * @return list<array{type: DrawerEntryType, count: int, paisa: int}>
     */
    public function lines(): array
    {
        $lines = [];

        foreach (DrawerEntryType::cases() as $type) {
            if ($type !== DrawerEntryType::OpeningFloat && $this->count($type) === 0) {
                continue;
            }

            $lines[] = ['type' => $type, 'count' => $this->count($type), 'paisa' => $this->paisa($type)];
        }

        return $lines;
    }

    /**
     * What completed bills were paid with, in the till's order.
     *
     * @return list<array{method: TenderType, count: int, paisa: int}>
     */
    public function tenderLines(): array
    {
        $lines = [];

        foreach (TenderType::cases() as $method) {
            if (isset($this->tenders[$method->value])) {
                $lines[] = ['method' => $method] + $this->tenders[$method->value];
            }
        }

        return $lines;
    }

    /**
     * Everything that left the drawer to the safe or the owner: the safe
     * drops during the shift, and what was taken at the close.
     */
    public function takenAwayPaisa(): int
    {
        return -$this->paisa(DrawerEntryType::SafeDrop) + $this->session->takenAtClosePaisa();
    }

    /**
     * When this shift opened with something other than what the last one
     * left behind, the difference went somewhere between the two.
     */
    public function gapFromPreviousPaisa(): ?int
    {
        if (! $this->previous || $this->previous->left_in_drawer_paisa === null) {
            return null;
        }

        return $this->session->opening_float_paisa - $this->previous->left_in_drawer_paisa;
    }
}
