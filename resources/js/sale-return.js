/**
 * Goods a customer has brought back.
 *
 * Every line of the bill is already on the screen, so there is nothing to
 * scan — the cashier only says how many of each are coming back. What this
 * works out is a preview of the refund; the server does the same sums again
 * against the bill at the moment of saving, because another counter may have
 * taken part of the same line back while this form was open.
 *
 * The refund is worked out from what the line actually earned, not from the
 * shelf price, so a discount given at the till is not handed back a second
 * time. Whole lines give back the line to the paisa; part of a line is shared
 * out in proportion, exactly as the server does it.
 */

/**
 * @param {object} config
 * @param {Array<object>} config.lines
 * @param {Array<{value: string, label: string, restocks: boolean}>} config.reasons
 * @param {string} config.reason
 * @param {string} config.settlement
 * @param {boolean} config.hasCustomer
 */
export default function saleReturn(config) {
    return {
        lines: config.lines ?? [],
        reasons: config.reasons ?? [],
        reason: config.reason,
        settlement: config.settlement,
        hasCustomer: Boolean(config.hasCustomer),

        /** The reason currently chosen, with whether its goods are sellable. */
        get chosen() {
            return this.reasons.find((reason) => reason.value === this.reason) ?? this.reasons[0];
        },

        get restocks() {
            return Boolean(this.chosen?.restocks);
        },

        /** Base units on one line, e.g. two boxes of twelve is twenty-four. */
        qtyBase(line) {
            const typed = Number(line.qty) || 0;

            return Math.round(typed * line.factor);
        },

        /** Whether a sachet has been asked for in halves. */
        isPartUnit(line) {
            const typed = Number(line.qty) || 0;

            return typed > 0 && Math.abs(typed * line.factor - Math.round(typed * line.factor)) > 0.0001;
        },

        isOverReturn(line) {
            return this.qtyBase(line) > line.returnable_base;
        },

        /** What this line hands back, in paisa. */
        linePaisa(line) {
            const base = this.qtyBase(line);

            if (base <= 0 || line.sold_base <= 0) {
                return 0;
            }

            if (base >= line.sold_base) {
                return line.line_total_paisa;
            }

            return Math.floor((line.line_total_paisa * base) / line.sold_base);
        },

        format(paisa) {
            return window.money.withSymbol(paisa);
        },

        /** Put the whole of what is left of a line on the return in one tap. */
        fill(line) {
            line.qty = String(line.returnable);
        },

        clear() {
            this.lines.forEach((line) => {
                line.qty = '';
            });
        },

        get totalPaisa() {
            return this.lines.reduce((total, line) => total + this.linePaisa(line), 0);
        },

        get pieceCount() {
            return this.lines.reduce((total, line) => total + Math.max(0, this.qtyBase(line)), 0);
        },

        get problems() {
            return this.lines.some((line) => this.isPartUnit(line) || this.isOverReturn(line));
        },

        get hasQuantities() {
            return this.lines.some((line) => this.qtyBase(line) > 0);
        },

        get canSave() {
            return this.hasQuantities && ! this.problems;
        },

        /**
         * A scanner's trailing Enter must not post the form halfway through
         * a return, so it only moves on.
         */
        guardEnter(event) {
            if (event.target.type !== 'textarea' && event.target.type !== 'submit') {
                event.preventDefault();
            }
        },

        /** Money leaving the drawer is worth one look before it goes. */
        confirmPost(event) {
            const how = this.settlement === 'cash' ? 'handed back in cash' : 'taken off the khata';

            if (! window.confirm(`${this.format(this.totalPaisa)} ${how}. Save this return?`)) {
                event.preventDefault();
            }
        },
    };
}
