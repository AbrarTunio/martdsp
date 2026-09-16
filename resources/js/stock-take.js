/**
 * The count sheet.
 *
 * A stock take is done walking the aisle with a phone, so the scanner field
 * keeps the focus and every scan counts one more. The item just scanned
 * jumps to the top of the list, because on a small screen the line being
 * counted has to be the one in view.
 *
 * A carton barcode and a sachet barcode can both belong to the same item.
 * When the two are mixed on one line, the line drops to the smallest size so
 * that "2 cartons and 3 sachets" stays exactly right instead of becoming
 * "3 cartons".
 *
 * The difference shown is against the shelf as it stood at the moment of
 * the scan, and is a guide only: the server measures the count against the
 * books again at posting, because the shop carries on selling while the
 * counting happens.
 */

/**
 * @param {object} config
 * @param {string} config.lookupUrl
 * @param {Array<object>} config.rows
 * @param {string|number|null} config.categoryId
 * @param {boolean} config.missingAreZero
 */
export default function stockTake(config) {
    return {
        lookupUrl: config.lookupUrl,
        rows: config.rows ?? [],
        categoryId: config.categoryId ? String(config.categoryId) : '',
        missingAreZero: Boolean(config.missingAreZero),
        code: '',
        busy: false,
        message: null,
        lastProductId: null,

        init() {
            this.$nextTick(() => this.focusScanner());

            this.$watch('categoryId', (value) => {
                if (! value) {
                    this.missingAreZero = false;
                }
            });
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
                    this.add({ ...result.row, counted_at: result.scanned_at ?? null });
                    navigator.vibrate?.(25);
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
         * One more of this item. A new item starts at one; a size different
         * from the line's own turns the line into the smallest size first.
         *
         * An item keeps the moment it was first scanned: that is when its
         * count began.
         */
        add(row) {
            const index = this.rows.findIndex((candidate) => candidate.product_id === row.product_id);

            if (index === -1) {
                this.rows.unshift({ ...row, qty: '1' });
                this.lastProductId = row.product_id;

                return;
            }

            const [existing] = this.rows.splice(index, 1);
            const scannedFactor = this.factorOf(existing, row.product_unit_id);

            if (Number(existing.product_unit_id) === Number(row.product_unit_id)) {
                existing.qty = String((Number(existing.qty) || 0) + 1);
            } else {
                const smallest = this.smallestUnit(existing);
                const alreadyCounted = (Number(existing.qty) || 0) * this.factorFor(existing);

                existing.product_unit_id = smallest?.id ?? existing.product_unit_id;
                existing.qty = String((alreadyCounted + scannedFactor) / this.factorFor(existing));
            }

            this.rows.unshift(existing);
            this.lastProductId = existing.product_id;
        },

        remove(index) {
            this.rows.splice(index, 1);
        },

        smallestUnit(row) {
            return [...(row.units ?? [])].sort((a, b) => Number(a.factor) - Number(b.factor))[0] ?? null;
        },

        factorOf(row, productUnitId) {
            const unit = (row.units ?? []).find((candidate) => Number(candidate.id) === Number(productUnitId));

            return unit ? Math.max(1, Number(unit.factor) || 1) : 1;
        },

        factorFor(row) {
            return this.factorOf(row, row.product_unit_id);
        },

        /** Counted minus what the shelf said at the moment of the scan. */
        difference(row) {
            return (Number(row.qty) || 0) * this.factorFor(row) - Number(row.stock_base ?? 0);
        },

        differenceInWords(row) {
            const difference = this.difference(row);

            if (difference === 0) {
                return 'Matches';
            }

            const sign = difference > 0 ? '+' : '−';

            return `${sign} ${Math.abs(difference).toLocaleString('en-PK')}`;
        },

        differenceClass(row) {
            const difference = this.difference(row);

            if (difference === 0) {
                return 'text-gray-500 dark:text-gray-400';
            }

            return difference > 0 ? 'text-money-in' : 'text-money-out';
        },

        get shortLines() {
            return this.rows.filter((row) => this.difference(row) < 0).length;
        },

        get overLines() {
            return this.rows.filter((row) => this.difference(row) > 0).length;
        },

        get isEmpty() {
            return this.rows.length === 0;
        },
    };
}
