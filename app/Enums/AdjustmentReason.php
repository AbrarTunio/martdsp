<?php

namespace App\Enums;

/**
 * Why stock was corrected by hand.
 *
 * This is a required list rather than a free-text box on purpose: "50 sachets
 * gone" tells the owner nothing, while "50 sachets, expired" against "50
 * sachets, theft" is the difference between a purchasing problem and a
 * staffing one. Phase 10's insights read exactly this column.
 */
enum AdjustmentReason: string
{
    case Opening = 'opening';
    case Damage = 'damage';
    case Expiry = 'expiry';
    case Theft = 'theft';
    case InternalUse = 'internal_use';
    case Recount = 'recount';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::Damage => 'Damaged',
            self::Expiry => 'Expired',
            self::Theft => 'Theft or loss',
            self::InternalUse => 'Used in the shop',
            self::Recount => 'Recount',
        };
    }

    /**
     * The sentence shown under the reason on the form, in the words a
     * shopkeeper would use.
     */
    public function description(): string
    {
        return match ($this) {
            self::Opening => 'Stock you already had when you started using this system.',
            self::Damage => 'Broken, crushed, leaked or spoiled before it could be sold.',
            self::Expiry => 'Past its date and taken off the shelf.',
            self::Theft => 'Missing, and you believe it was taken.',
            self::InternalUse => 'Taken for the shop itself — tea for staff, a bag used at the counter.',
            self::Recount => 'You counted the shelf and the system was wrong. Enter what you counted.',
        };
    }

    /**
     * Whether this reason brings stock in. Everything else takes it away,
     * except a recount, which sets the balance to whatever was counted.
     */
    public function addsStock(): bool
    {
        return $this === self::Opening;
    }

    /**
     * A recount does not add or remove a quantity — it states the truth, and
     * the difference is worked out from the balance on hand.
     */
    public function isRecount(): bool
    {
        return $this === self::Recount;
    }

    /**
     * Reasons that represent stock lost rather than sold. Shrinkage reports
     * and the AI insight for "what is leaking" read this set.
     */
    public function isShrinkage(): bool
    {
        return in_array($this, [self::Damage, self::Expiry, self::Theft], true);
    }

    /**
     * The <x-badge> tone for this reason.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Opening => 'success',
            self::Recount => 'info',
            self::InternalUse => 'warning',
            self::Damage, self::Expiry, self::Theft => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }
}
