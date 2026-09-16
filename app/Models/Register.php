<?php

namespace App\Models;

use App\Enums\DrawerStatus;
use Database\Factories\RegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A counter with a till on it.
 */
#[Fillable(['name', 'location', 'printer_id', 'printer_profile', 'is_active'])]
class Register extends Model
{
    /** @use HasFactory<RegisterFactory> */
    use HasFactory;

    /**
     * What a counter's receipts print on: the two thermal roll widths, or a
     * full A4 page for customers who need a proper invoice.
     *
     * @var list<string>
     */
    public const PAPERS = ['80', '58', 'a4'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The receipt printer standing at this counter. Null means the shop has
     * not wired one up, and receipts go through the browser.
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function drawerSessions(): HasMany
    {
        return $this->hasMany(DrawerSession::class);
    }

    /**
     * The shift running on this counter's drawer right now, if any.
     */
    public function openDrawer(): HasOne
    {
        return $this->hasOne(DrawerSession::class)->where('status', DrawerStatus::Open);
    }

    /**
     * The shift that closed most recently here, for what it left in the drawer.
     */
    public function lastClosedDrawer(): HasOne
    {
        return $this->hasOne(DrawerSession::class)->ofMany(
            ['closed_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('status', DrawerStatus::Closed),
        );
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('name');
    }

    /**
     * The paper this counter prints on, falling back to the shop's setting.
     */
    public function paper(): string
    {
        if (in_array($this->printer_profile, self::PAPERS, true)) {
            return $this->printer_profile;
        }

        /* A wired-up printer knows what roll is in it better than a setting does. */
        return $this->printer?->paper ?? (string) Setting::read('receipt.paper_width');
    }

    /**
     * @return array<string, string>
     */
    public static function paperOptions(): array
    {
        return [
            '80' => __('80 mm thermal (standard)'),
            '58' => __('58 mm thermal (small)'),
            'a4' => __('A4 page'),
        ];
    }

    /**
     * A shop with one till should never have to set a counter up before it
     * can sell, so the first visit to the till makes one.
     */
    public static function ensureOne(): void
    {
        if (static::query()->doesntExist()) {
            static::query()->create(['name' => __('Counter 1')]);
        }
    }
}
