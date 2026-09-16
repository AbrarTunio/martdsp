<?php

namespace App\Models;

use App\Enums\PrinterConnection;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A receipt printer, described well enough that the server can drive it.
 *
 * Everything here is something a shop owner can see or has been told by
 * whoever wired the counter up — the share name, the width of the roll,
 * whether there is a cutter, whether a drawer hangs off the back. None of it
 * is guessed, because a printer that quietly does the wrong thing wastes a
 * roll of paper before anybody notices.
 */
#[Fillable(['name', 'channel', 'target', 'paper', 'cuts', 'has_drawer', 'drawer_pin', 'feed_lines', 'is_active'])]
class Printer extends Model
{
    /** @use HasFactory<PrinterFactory> */
    use HasFactory;

    /**
     * How many characters fit across a line in the printer's normal font,
     * keyed by roll width. Font A is 12 dots wide, which is where these come
     * from; every receipt line is laid out against them.
     *
     * @var array<string, int>
     */
    public const COLUMNS = ['80' => 42, '58' => 32];

    /**
     * The way the server reaches the printer is stored as `channel` rather
     * than the more obvious `connection`, because Eloquent already keeps a
     * property of that name for the database connection, and inside the model
     * that property wins over the cast attribute.
     */
    protected function casts(): array
    {
        return [
            'channel' => PrinterConnection::class,
            'cuts' => 'boolean',
            'has_drawer' => 'boolean',
            'drawer_pin' => 'integer',
            'feed_lines' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('name');
    }

    /**
     * Printers the server can send bytes to itself, which is the only kind a
     * test print or a drawer pulse means anything for.
     */
    #[Scope]
    protected function direct(Builder $query): void
    {
        $query->whereIn('channel', [PrinterConnection::WindowsShare, PrinterConnection::Network]);
    }

    /**
     * How many characters fit across this printer's paper.
     */
    public function columns(): int
    {
        return self::COLUMNS[$this->paper] ?? self::COLUMNS['80'];
    }

    /**
     * Whether the server drives this printer, rather than the browser.
     */
    public function isDirect(): bool
    {
        return $this->channel->isDirect() && $this->is_active;
    }

    /**
     * Whether asking this printer to pop a drawer would do anything.
     */
    public function canPulse(): bool
    {
        return $this->isDirect() && $this->has_drawer && $this->channel->canPulse();
    }

    /**
     * One line describing the printer, for a list or an error message.
     */
    public function describe(): string
    {
        return trim(implode(' · ', array_filter([
            __($this->channel->label()),
            $this->target,
            __(':mm mm roll', ['mm' => $this->paper]),
        ])));
    }

    /**
     * The printer a counter should use: its own if one is set, otherwise the
     * only direct printer in the shop, because a single-till shop should not
     * have to assign anything.
     */
    public static function forRegister(?Register $register): ?self
    {
        if ($register?->printer && $register->printer->isDirect()) {
            return $register->printer;
        }

        if ($register?->printer_id !== null) {
            return null;
        }

        $direct = static::query()->active()->direct()->get();

        return $direct->count() === 1 ? $direct->first() : null;
    }
}
