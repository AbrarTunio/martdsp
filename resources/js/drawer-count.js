/**
 * The end-of-shift count.
 *
 * The cashier types how many of each note and coin they are holding and the
 * total adds up as they go. What the drawer should hold is only sent to this
 * page when the person counting is allowed to see it; under a blind count it
 * is null, and no gap is shown until a manager opens the closed shift.
 */
import money from './money';

/**
 * A paisa amount as a plain rupee figure for an input: 500000 -> "5000".
 */
function rupees(paisa) {
    const value = (Math.max(0, Number(paisa) || 0) / 100).toFixed(2);

    return value.replace(/\.?0+$/, '') || '0';
}

/**
 * @param {object} config
 * @param {Array<number>} config.denominations  rupee values, largest first
 * @param {number|null} config.expectedPaisa
 * @param {number} config.tolerancePaisa
 * @param {number} config.floatPaisa  what the shift started with, the usual amount to leave
 * @param {{counts: object, left: string|null, reason: string|null}} config.old
 */
export default function drawerCount(config) {
    return {
        denominations: config.denominations ?? [],
        counts: {},
        expected: config.expectedPaisa ?? null,
        tolerance: config.tolerancePaisa ?? 0,
        floatPaisa: config.floatPaisa ?? 0,
        left: '',
        leftTouched: false,

        init() {
            this.denominations.forEach((value) => {
                const old = config.old?.counts?.[value];
                this.counts[value] = old === undefined || old === null ? '' : String(old);
            });

            if (config.old?.left !== null && config.old?.left !== undefined) {
                this.left = String(config.old.left);
                this.leftTouched = true;
            } else {
                this.suggestLeft();
            }

            this.$watch('counts', () => this.suggestLeft());
        },

        /**
         * Until the cashier types their own figure, offer to leave what the
         * shift started with, or everything if there is less than that.
         */
        suggestLeft() {
            if (!this.leftTouched) {
                this.left = rupees(Math.min(this.floatPaisa, this.counted));
            }
        },

        pieces(value) {
            const count = parseInt(this.counts[value], 10);

            return Number.isFinite(count) && count > 0 ? count : 0;
        },

        step(value, by) {
            this.counts[value] = String(Math.max(0, this.pieces(value) + by));
        },

        subtotal(value) {
            return this.pieces(value) * value * 100;
        },

        get counted() {
            return this.denominations.reduce((sum, value) => sum + this.subtotal(value), 0);
        },

        get variance() {
            return this.expected === null ? null : this.counted - this.expected;
        },

        get beyondTolerance() {
            return this.variance !== null && Math.abs(this.variance) > this.tolerance;
        },

        get leftPaisa() {
            return money.parse(this.left);
        },

        get leftTooMuch() {
            return this.leftPaisa > this.counted;
        },

        get takenPaisa() {
            return Math.max(0, this.counted - this.leftPaisa);
        },

        format(paisa) {
            return money.withSymbol(paisa);
        },

        signed(paisa) {
            if (paisa === 0) {
                return money.withSymbol(0);
            }

            return `${paisa > 0 ? '+' : '−'} ${money.withSymbol(Math.abs(paisa))}`;
        },
    };
}
