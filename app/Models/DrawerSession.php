<?php

namespace App\Models;

use App\Enums\DrawerStatus;
use Database\Factories\DrawerSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shift on one counter's cash drawer.
 *
 * Only App\Services\DrawerService writes one, because opening, moving cash
 * and closing all have to be checked against the drawer's state under a lock.
 */
#[Fillable([
    'register_id', 'open_register_id', 'status', 'opened_by', 'opened_at', 'opening_float_paisa', 'opening_note',
    'closed_by', 'closed_at', 'expected_cash_paisa', 'counted_cash_paisa', 'variance_paisa', 'variance_reason',
    'needs_approval', 'approved_by', 'approved_at', 'approval_note', 'left_in_drawer_paisa',
])]
class DrawerSession extends Model
{
    /** @use HasFactory<DrawerSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DrawerStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'opening_float_paisa' => 'integer',
            'expected_cash_paisa' => 'integer',
            'counted_cash_paisa' => 'integer',
            'variance_paisa' => 'integer',
            'left_in_drawer_paisa' => 'integer',
            'needs_approval' => 'boolean',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DrawerTransaction::class);
    }

    public function countLines(): HasMany
    {
        return $this->hasMany(CashCountLine::class)->orderByDesc('denomination');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', DrawerStatus::Open);
    }

    /**
     * Closed with a gap beyond the tolerance, and nobody has signed it off.
     */
    #[Scope]
    protected function awaitingApproval(Builder $query): void
    {
        $query->where('status', DrawerStatus::Closed)
            ->where('needs_approval', true)
            ->whereNull('approved_at');
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('opened_at')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return $this->status === DrawerStatus::Open;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === DrawerStatus::Closed && $this->needs_approval && $this->approved_at === null;
    }

    /**
     * What should be in the drawer right now, from the ledger. A closed
     * shift answers with the figure frozen when it closed.
     */
    public function expectedCashPaisa(): int
    {
        if (! $this->isOpen() && $this->expected_cash_paisa !== null) {
            return $this->expected_cash_paisa;
        }

        return (int) $this->transactions()->sum('amount_paisa');
    }

    /**
     * What went to the owner or the safe when the drawer was closed.
     */
    public function takenAtClosePaisa(): int
    {
        if ($this->counted_cash_paisa === null) {
            return 0;
        }

        return max(0, $this->counted_cash_paisa - (int) $this->left_in_drawer_paisa);
    }

    /**
     * The shift before this one at the same counter.
     */
    public function previous(): ?self
    {
        return self::query()
            ->where('register_id', $this->register_id)
            ->where(fn (Builder $query) => $query
                ->where('opened_at', '<', $this->opened_at)
                ->orWhere(fn (Builder $query) => $query->where('opened_at', $this->opened_at)->where('id', '<', $this->id)))
            ->latestFirst()
            ->first();
    }

    /**
     * The shift after this one at the same counter.
     */
    public function next(): ?self
    {
        return self::query()
            ->where('register_id', $this->register_id)
            ->where(fn (Builder $query) => $query
                ->where('opened_at', '>', $this->opened_at)
                ->orWhere(fn (Builder $query) => $query->where('opened_at', $this->opened_at)->where('id', '>', $this->id)))
            ->orderBy('opened_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * "Short", "Over" or "Exact", for the badge beside a variance.
     */
    public function varianceLabel(): ?string
    {
        return match (true) {
            $this->variance_paisa === null => null,
            $this->variance_paisa < 0 => __('Short'),
            $this->variance_paisa > 0 => __('Over'),
            default => __('Exact'),
        };
    }

    public function varianceTone(): string
    {
        return match (true) {
            $this->variance_paisa === null, $this->variance_paisa === 0 => 'success',
            $this->needs_approval && $this->approved_at === null => 'danger',
            default => 'warning',
        };
    }
}
