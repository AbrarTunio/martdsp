<?php

namespace App\Enums;

/**
 * Why stock moved.
 *
 * Every row in the ledger carries one of these, and nothing else may write a
 * balance. Read together with the sign of `qty_base`, this is the whole
 * explanation of how a product got to the quantity it is at.
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SaleVoid = 'sale_void';
    case SaleReturn = 'sale_return';
    case PurchaseReturn = 'purchase_return';
    case Adjustment = 'adjustment';
    case StockTake = 'stock_take';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::Purchase => 'Purchase',
            self::Sale => 'Sale',
            self::SaleVoid => 'Sale voided',
            self::SaleReturn => 'Customer return',
            self::PurchaseReturn => 'Returned to supplier',
            self::Adjustment => 'Adjustment',
            self::StockTake => 'Stock take',
        };
    }

    /**
     * Whether stock arriving under this type carries a cost that should feed
     * the moving average. A sale return comes back at the cost it left at, so
     * it is deliberately excluded: re-averaging on a return would let a
     * customer's refund quietly move the shop's cost base.
     */
    public function revaluesStock(): bool
    {
        return in_array($this, [self::Opening, self::Purchase, self::Adjustment, self::StockTake], true);
    }

    /**
     * The <x-badge> tone for this type, so the ledger reads at a glance:
     * green arrived, red left, amber corrected.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Purchase, self::SaleReturn, self::SaleVoid, self::Opening => 'success',
            self::Sale, self::PurchaseReturn => 'danger',
            self::Adjustment, self::StockTake => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
