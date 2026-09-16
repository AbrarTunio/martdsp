/**
 * The "send goods back" form.
 *
 * Scanned the same way as a delivery, through the same lookup. Each line
 * starts at what the shop last paid for that size, because that is what a
 * supplier normally credits; the owner lowers it when the salesman offers
 * less. Stock and the supplier's account are only touched by the server.
 */
import money from './money';

let nextUid = 1;

/**
 * @param {object} config
 * @param {string} config.lookupUrl
 * @param {Array<object>} config.rows
 * @param {Array<{id: number, label: string}>} config.suppliers
 * @param {string|number|null} config.supplierId
 * @param {string} config.settlement
 */
export default function purchaseReturn(config) {
    return {
        lookupUrl: config.lookupUrl,
        suppliers: config.suppliers ?? [],
        rows: [],
        supplierId: config.supplierId ? String(config.supplierId) : '',
        settlement: config.settlement || 'credit',
        code: '',
        busy: false,
        message: null,
        lastUid: null,

        init() {
            this.rows = (config.rows ?? []).map((row) => this.prepare(row));
            this.$watch('supplierId', (value) => {
                if (value === '') {
                    this.settlement = 'cash';
                }
            });
            this.$nextTick(() => this.$refs.scanner?.focus());
        },

        guardEnter(event) {
            if (event.target.tagName === 'INPUT' && event.target !== this.$refs.scanner) {
                event.preventDefault();
            }
        },

        prepare(row) {
            const unit = (row.units ?? []).find((candidate) => Number(candidate.id) === Number(row.product_unit_id));

            return {
                ...row,
                uid: nextUid++,
                product_unit_id: String(row.product_unit_id ?? ''),
                creditFromHistory: row.unit_cost === '' || row.unit_cost === (unit?.last_cost ?? ''),
            };
        },

        async scan() {
            const code = this.code.trim();

            if (code === '' || this.busy) {
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
                    this.message = `Nothing in the shop matches "${code}". Only items the shop already stocks can be sent back.`;
                }
            } catch (error) {
                this.message = 'Could not reach the shop computer. Check the connection and scan again.';
            } finally {
                this.busy = false;
                this.code = '';
                this.$refs.scanner?.focus();
            }
        },

        add(row) {
            const existing = this.rows.find((candidate) => candidate.product_unit_id === String(row.product_unit_id));

            if (existing) {
                existing.qty = String((Number(existing.qty) || 0) + 1);
                this.lastUid = existing.uid;

                return;
            }

            const prepared = this.prepare({ ...row, qty: '1' });

            this.rows.unshift(prepared);
            this.lastUid = prepared.uid;
        },

        remove(row) {
            this.rows = this.rows.filter((candidate) => candidate.uid !== row.uid);
        },

        unitFor(row) {
            return (row.units ?? []).find((unit) => String(unit.id) === row.product_unit_id);
        },

        factorFor(row) {
            return Number(this.unitFor(row)?.factor ?? 1) || 1;
        },

        unitName(row) {
            return (this.unitFor(row)?.label ?? '').split(' (')[0].toLowerCase();
        },

        unitChanged(row) {
            if (row.creditFromHistory) {
                row.unit_cost = this.unitFor(row)?.last_cost ?? '';
            }
        },

        creditTyped(row) {
            row.creditFromHistory = false;
        },

        linePaisa(row) {
            return (Number(row.qty) || 0) * money.parse(row.unit_cost);
        },

        piecesIn(row) {
            return (Number(row.qty) || 0) * this.factorFor(row);
        },

        /**
         * The server refuses to send back more than the shelf holds, so it is
         * said here first, while the count can still be fixed.
         */
        warnings(row) {
            const list = [];

            if (this.piecesIn(row) > row.stock_base) {
                list.push(`The shelf only shows ${row.stock_words || 'none'}, so this much cannot go back. Recount the item first if that is wrong.`);
            }

            const costPerPiece = row.avg_cost_base * this.factorFor(row);

            if (money.parse(row.unit_cost) > 0 && costPerPiece > 0 && money.parse(row.unit_cost) > costPerPiece * 1.25) {
                list.push(`That is more back than the ${money.withSymbol(costPerPiece)} it cost on average. Check the amount.`);
            }

            return list;
        },

        get hasSupplier() {
            return this.supplierId !== '';
        },

        get totalPaisa() {
            return this.rows.reduce((sum, row) => sum + this.linePaisa(row), 0);
        },

        get pieceCount() {
            return this.rows.reduce((sum, row) => sum + this.piecesIn(row), 0);
        },

        get isEmpty() {
            return this.rows.length === 0;
        },

        get hasQuantities() {
            return this.rows.some((row) => (Number(row.qty) || 0) > 0);
        },

        format(paisa) {
            return money.withSymbol(paisa);
        },

        confirmPost(event) {
            const message = `Send these goods back? ${this.format(this.totalPaisa)} will be ${this.settlement === 'cash' ? 'recorded as cash back' : 'taken off what you owe'}, and stock goes down now.`;

            if (! window.confirm(message)) {
                event.preventDefault();
            }
        },
    };
}
