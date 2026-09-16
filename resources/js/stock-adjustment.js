/**
 * The stock correction form.
 *
 * A shelf is counted with a scanner in one hand, so the code field stays
 * focused and every scan either adds the item or adds one to the count it
 * already has. Scanning the same sachet forty times is how a shopkeeper
 * counts forty sachets, and the form has to make that the easy path.
 *
 * The effect shown against each line is a preview only. The server settles
 * every quantity against the shelf again at the moment of posting, because a
 * sale rung up while the counting was happening would otherwise be lost.
 */

/**
 * @param {object} config
 * @param {string} config.lookupUrl
 * @param {Array<object>} config.rows
 * @param {Array<{value: string, label: string, description: string, adds: boolean, recount: boolean}>} config.reasons
 * @param {string} config.reason
 */
export default function stockAdjustment(config) {
    return {
        lookupUrl: config.lookupUrl,
        reasons: config.reasons ?? [],
        reason: config.reason,
        rows: config.rows ?? [],
        code: '',
        busy: false,
        message: null,

        init() {
            this.$nextTick(() => this.focusScanner());
        },

        /** The reason currently chosen, with its help text and behaviour. */
        get chosen() {
            return this.reasons.find((reason) => reason.value === this.reason) ?? this.reasons[0];
        },

        get isRecount() {
            return Boolean(this.chosen?.recount);
        },

        get addsStock() {
            return Boolean(this.chosen?.adds);
        },

        /** "Counted" reads very differently from "How many", and it matters. */
        get quantityLabel() {
            return this.isRecount ? 'Counted' : 'How many';
        },

        focusScanner() {
            this.$refs.scanner?.focus();
        },

        async scan() {
            const code = this.code.trim();

            if (code === '') {
                return;
            }

            this.busy = true;
            this.message = null;

            try {
                const response = await fetch(`${this.lookupUrl}?code=${encodeURIComponent(code)}`, {
                    headers: { Accept: 'application/json' },
                });
                const result = await response.json();

                if (result.found) {
                    this.add(result.row);
                } else {
                    this.message = `Nothing in the shop uses "${code}".`;
                }
            } catch (error) {
                this.message = 'Could not reach the shop computer. Check the connection and scan again.';
            } finally {
                this.busy = false;
                this.code = '';
                this.focusScanner();
            }
        },

        /**
         * A second scan of an item already on the list counts one more of it,
         * rather than adding a line the server would reject.
         */
        add(row) {
            const existing = this.rows.find((candidate) => candidate.product_id === row.product_id);

            if (existing) {
                existing.qty = String(Math.max(0, Number(existing.qty) || 0) + 1);
                this.message = null;

                return;
            }

            this.rows.push({ ...row, qty: row.qty === '' ? '1' : row.qty });
        },

        remove(index) {
            this.rows.splice(index, 1);
        },

        factorFor(row) {
            const unit = (row.units ?? []).find((candidate) => Number(candidate.id) === Number(row.product_unit_id));

            return unit ? Math.max(1, Number(unit.factor) || 1) : 1;
        },

        /** What this line would do to the balance, in base units. */
        effect(row) {
            const entered = (Number(row.qty) || 0) * this.factorFor(row);

            if (this.isRecount) {
                return entered - Number(row.stock_base ?? 0);
            }

            return this.addsStock ? entered : -entered;
        },

        effectInWords(row) {
            const effect = this.effect(row);

            if (effect === 0) {
                return 'No change';
            }

            const sign = effect > 0 ? '+' : '−';

            return `${sign} ${Math.abs(effect).toLocaleString('en-PK')}`;
        },

        effectClass(row) {
            const effect = this.effect(row);

            if (effect === 0) {
                return 'text-gray-500 dark:text-gray-400';
            }

            return effect > 0 ? 'text-money-in' : 'text-money-out';
        },

        /** The count of lines that would actually move something. */
        get movingLines() {
            return this.rows.filter((row) => this.effect(row) !== 0).length;
        },

        get isEmpty() {
            return this.rows.length === 0;
        },
    };
}
