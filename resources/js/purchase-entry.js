/**
 * The delivery form.
 *
 * A delivery is entered standing next to the van: scan a carton and it goes
 * on the bill, scan the same carton again and the count goes up by one. A
 * barcode the shop has never seen is not an error — it is a new product
 * arriving — so it opens a short sheet to add it without leaving the bill.
 *
 * Totals and warnings are a preview. The server works every figure out
 * again, spreads the discount and tax across the lines, and is the only
 * thing that writes to stock or the supplier's account.
 */
import money from './money';

let nextUid = 1;

/**
 * @param {object} config
 * @param {string} config.lookupUrl
 * @param {string} config.quickProductUrl
 * @param {string} config.quickSupplierUrl
 * @param {string} config.csrf
 * @param {Array<object>} config.rows
 * @param {Array<{id: number, label: string, name: string, company: string, phone: string, terms: number, balance_paisa: number}>} config.suppliers
 * @param {Array<{id: number, name: string, short_name: string}>} config.units
 * @param {string|number|null} config.supplierId
 * @param {string} config.purchaseDate
 * @param {string} config.discount
 * @param {string} config.tax
 * @param {string} config.paid
 * @param {string} config.paymentMethod
 */
export default function purchaseEntry(config) {
    const pieceUnit = (config.units ?? []).find((unit) => unit.short_name === 'pc') ?? (config.units ?? [])[0];
    const cartonUnit = (config.units ?? []).find((unit) => unit.short_name === 'ctn');

    return {
        lookupUrl: config.lookupUrl,
        quickProductUrl: config.quickProductUrl,
        quickSupplierUrl: config.quickSupplierUrl,
        csrf: config.csrf,
        suppliers: config.suppliers ?? [],
        units: config.units ?? [],
        rows: [],
        supplierId: config.supplierId ? String(config.supplierId) : '',
        supplierSearch: '',
        purchaseDate: config.purchaseDate ?? '',
        discount: config.discount ?? '',
        tax: config.tax ?? '',
        paid: config.paid ?? '',
        paymentMethod: config.paymentMethod || 'cash',
        code: '',
        busy: false,
        message: null,
        lastUid: null,

        newSupplier: {
            open: false,
            busy: false,
            errors: {},
            name: '',
            company: '',
            phone: '',
            payment_terms_days: '0',
        },

        quick: {
            open: false,
            busy: false,
            errors: {},
            code: '',
            name: '',
            category_id: '',
            base_unit_id: pieceUnit ? String(pieceUnit.id) : '',
            has_pack: true,
            pack_unit_id: cartonUnit ? String(cartonUnit.id) : '',
            qty_per_pack: '',
            scanned_is: 'pack',
            sale_price: '',
        },

        init() {
            this.rows = (config.rows ?? []).map((row) => this.prepare(row));
            this.$nextTick(() => this.focusScanner());
        },

        focusScanner() {
            this.$refs.scanner?.focus();
        },

        /**
         * A scanner types its code and presses Enter. On any field other than
         * the scanner that Enter would submit the whole bill, so it is eaten.
         */
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
                costFromHistory: row.unit_cost === '' || row.unit_cost === (unit?.last_cost ?? ''),
                showMore: Boolean(row.batch_no || row.expiry_date || Number(row.bonus_qty) > 0),
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
                } else if (result.is_barcode) {
                    this.openQuick(code);
                } else {
                    this.message = `Nothing in the shop is called "${code}". Try part of the name, or scan the barcode.`;
                }
            } catch (error) {
                this.message = 'Could not reach the shop computer. Check the connection and scan again.';
            } finally {
                this.busy = false;
                this.code = '';

                if (! this.quick.open) {
                    this.focusScanner();
                }
            }
        },

        /**
         * A second scan of the same size adds one more of it. A different
         * size of the same product gets its own line, because a bill can
         * carry both cartons and loose boxes of one item.
         */
        add(row) {
            const existing = this.rows.find(
                (candidate) => Number(candidate.product_unit_id) === Number(row.product_unit_id),
            );

            if (existing) {
                existing.qty = String(Math.max(0, Number(existing.qty) || 0) + 1);
                this.lastUid = existing.uid;

                return;
            }

            const prepared = this.prepare({ ...row, qty: row.qty === '' ? '1' : row.qty });

            this.rows.unshift(prepared);
            this.lastUid = prepared.uid;
        },

        remove(index) {
            this.rows.splice(index, 1);
            this.focusScanner();
        },

        unitFor(row) {
            return (row.units ?? []).find((candidate) => Number(candidate.id) === Number(row.product_unit_id));
        },

        factorFor(row) {
            return Math.max(1, Number(this.unitFor(row)?.factor) || 1);
        },

        unitName(row) {
            const label = this.unitFor(row)?.label ?? '';

            return label.split(' (')[0].toLowerCase();
        },

        /** Switching carton to box brings in what a box cost last time. */
        unitChanged(row) {
            if (row.costFromHistory) {
                row.unit_cost = this.unitFor(row)?.last_cost ?? '';
            }
        },

        costTyped(row) {
            row.costFromHistory = false;
        },

        linePaisa(row) {
            return (Number(row.qty) || 0) * money.parse(row.unit_cost);
        },

        piecesIn(row) {
            return ((Number(row.qty) || 0) + (Number(row.bonus_qty) || 0)) * this.factorFor(row);
        },

        /**
         * The things worth a second look before the bill is received: a
         * price far from what the item usually costs, a cost at or above the
         * selling price, and an expiry date the item needs but was not given.
         */
        warnings(row) {
            const warnings = [];
            const cost = money.parse(row.unit_cost);
            const factor = this.factorFor(row);
            const qty = Number(row.qty) || 0;

            if (qty > 0 && cost === 0) {
                warnings.push('No price entered. Leave it only if these came free.');
            }

            if (cost > 0 && row.avg_cost_base > 0) {
                const perPiece = cost / factor;
                const change = (perPiece - row.avg_cost_base) / row.avg_cost_base;

                if (Math.abs(change) > 0.25) {
                    const percent = Math.round(Math.abs(change) * 100);

                    warnings.push(
                        `${percent}% ${change > 0 ? 'dearer' : 'cheaper'} than usual — you normally pay ${money.withSymbol(row.avg_cost_base * factor)} for one ${this.unitName(row)}.`,
                    );
                }
            }

            const salePrice = Number(this.unitFor(row)?.sale_price_paisa) || (Number(row.sale_price_base) || 0) * factor;

            if (cost > 0 && salePrice > 0 && cost >= salePrice) {
                warnings.push(`You sell one ${this.unitName(row)} for ${money.withSymbol(salePrice)}, so this leaves no profit. Check the price, or raise the selling price.`);
            }

            if (row.track_expiry && ! row.expiry_date) {
                warnings.push('This item needs an expiry date before it can be received.');
            }

            return warnings;
        },

        get supplier() {
            return this.suppliers.find((candidate) => String(candidate.id) === this.supplierId) ?? null;
        },

        get isCashPurchase() {
            return this.supplier === null;
        },

        /* ---------------------------------------------------------------- */
        /*  Finding the supplier                                             */
        /* ---------------------------------------------------------------- */

        /**
         * The list is searched here in the browser rather than at the server:
         * it is already on the page, so the names appear as fast as they can
         * be typed, and a delivery can still be entered when the line to the
         * shop computer is down.
         *
         * The number is searched with its punctuation taken off, so 0300 in
         * the search box finds a salesman stored as +92 300 1234567.
         */
        get supplierMatches() {
            const term = this.supplierSearch.trim().toLowerCase();
            const digits = this.nationalDigits(term);

            const matches = term === ''
                ? this.suppliers
                : this.suppliers.filter((supplier) => {
                    const written = `${supplier.name} ${supplier.company}`.toLowerCase();

                    return written.includes(term)
                        || (digits.length >= 3 && this.nationalDigits(supplier.phone).includes(digits));
                });

            return matches.slice(0, 8);
        },

        chooseSupplier(id) {
            this.supplierId = String(id);
            this.supplierSearch = '';
        },

        /** Enter in the search box takes the top name, the way a till does. */
        chooseFirstSupplier() {
            const first = this.supplierMatches[0];

            if (first) {
                this.chooseSupplier(first.id);
            }
        },

        changeSupplier() {
            this.supplierId = '';
            this.$nextTick(() => this.$refs.supplierSearch?.focus());
        },

        /**
         * A number stripped back to the part that is typed beside the +92 —
         * the same job App\Support\PhoneNumber does on the server, so that a
         * number searched for in one spelling finds a supplier stored in
         * another.
         */
        nationalDigits(value) {
            return String(value ?? '')
                .replace(/\D/g, '')
                .replace(/^00/, '')
                .replace(/^0/, '')
                .replace(/^92(?=\d{9,})/, '');
        },

        /** What goes in the number box: digits, and never the 0 in front. */
        tidyNumber(typed) {
            return this.nationalDigits(typed).slice(0, 10);
        },

        get subtotalPaisa() {
            return this.rows.reduce((sum, row) => sum + this.linePaisa(row), 0);
        },

        get discountPaisa() {
            return money.parse(this.discount);
        },

        get taxPaisa() {
            return money.parse(this.tax);
        },

        get totalPaisa() {
            return Math.max(0, this.subtotalPaisa - this.discountPaisa + this.taxPaisa);
        },

        get paidPaisa() {
            return this.isCashPurchase ? this.totalPaisa : money.parse(this.paid);
        },

        get showsPaymentMethod() {
            return this.paidPaisa > 0;
        },

        get balanceAfterPaisa() {
            if (! this.supplier) {
                return 0;
            }

            return this.supplier.balance_paisa + this.totalPaisa - this.paidPaisa;
        },

        get dueOn() {
            if (! this.supplier || this.supplier.terms <= 0 || ! this.purchaseDate) {
                return null;
            }

            const date = new Date(`${this.purchaseDate}T00:00:00`);
            date.setDate(date.getDate() + this.supplier.terms);

            return date.toLocaleDateString('en-PK', { day: 'numeric', month: 'short', year: 'numeric' });
        },

        get pieceCount() {
            return this.rows.reduce((sum, row) => sum + this.piecesIn(row), 0);
        },

        get warningCount() {
            return this.rows.filter((row) => this.warnings(row).length > 0).length;
        },

        get isEmpty() {
            return this.rows.length === 0;
        },

        payInFull() {
            this.paid = (this.totalPaisa / 100).toFixed(2);
        },

        format(paisa) {
            return money.withSymbol(paisa);
        },

        confirmReceive(event) {
            const question = this.isCashPurchase
                ? `Receive ${this.rows.length} items into stock, paid ${money.withSymbol(this.totalPaisa)} in cash?`
                : `Receive ${this.rows.length} items into stock? You will owe ${this.supplier.label} ${money.withSymbol(Math.max(0, this.balanceAfterPaisa))}.`;

            if (! confirm(`${question}\n\nThis cannot be edited afterwards — mistakes are fixed with a return.`)) {
                event.preventDefault();
            }
        },

        /* ---------------------------------------------------------------- */
        /*  Adding a supplier who is not on the list yet                     */
        /* ---------------------------------------------------------------- */

        openNewSupplier() {
            const typed = this.supplierSearch.trim();
            const digits = this.tidyNumber(typed);

            this.newSupplier.errors = {};
            this.newSupplier.company = '';
            this.newSupplier.payment_terms_days = '0';

            /* Whatever was typed into the search box is most of the answer
               already, so it is carried across: a number into the number, a
               name into the name. */
            this.newSupplier.name = digits === '' ? typed : '';
            this.newSupplier.phone = digits;
            this.newSupplier.open = true;

            this.$nextTick(() => this.$refs.newSupplierName?.focus());
        },

        closeNewSupplier() {
            this.newSupplier.open = false;
        },

        newSupplierError(field) {
            return (this.newSupplier.errors[field] ?? [])[0] ?? null;
        },

        async saveNewSupplier() {
            if (this.newSupplier.busy) {
                return;
            }

            this.newSupplier.busy = true;
            this.newSupplier.errors = {};

            const payload = {
                name: this.newSupplier.name,
                company: this.newSupplier.company,
                phone: this.newSupplier.phone,
                payment_terms_days: this.newSupplier.payment_terms_days || 0,
                is_active: 1,
            };

            try {
                const response = await fetch(this.quickSupplierUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify(payload),
                });
                const result = await response.json();

                if (response.status === 422) {
                    this.newSupplier.errors = result.errors ?? {};

                    return;
                }

                if (! response.ok || ! result.supplier) {
                    this.newSupplier.errors = { name: [result.message ?? 'The supplier could not be saved. Try again.'] };

                    return;
                }

                this.suppliers.push(result.supplier);
                this.suppliers.sort((one, other) => one.label.localeCompare(other.label));
                this.chooseSupplier(result.supplier.id);
                this.newSupplier.open = false;
                this.$nextTick(() => this.focusScanner());
            } catch (error) {
                this.newSupplier.errors = { name: ['Could not reach the shop computer. Check the connection and try again.'] };
            } finally {
                this.newSupplier.busy = false;
            }
        },

        /* ---------------------------------------------------------------- */
        /*  Adding a product that has never been stocked                     */
        /* ---------------------------------------------------------------- */

        openQuick(code) {
            this.quick.errors = {};
            this.quick.code = code;
            this.quick.name = '';
            this.quick.qty_per_pack = '';
            this.quick.sale_price = '';
            this.quick.open = true;
            this.$nextTick(() => this.$refs.quickName?.focus());
        },

        closeQuick() {
            this.quick.open = false;
            this.$nextTick(() => this.focusScanner());
        },

        quickError(field) {
            return (this.quick.errors[field] ?? [])[0] ?? null;
        },

        async saveQuick() {
            if (this.quick.busy) {
                return;
            }

            this.quick.busy = true;
            this.quick.errors = {};

            const payload = {
                code: this.quick.code,
                name: this.quick.name,
                category_id: this.quick.category_id || null,
                base_unit_id: this.quick.base_unit_id,
                pack_unit_id: this.quick.has_pack ? this.quick.pack_unit_id || null : null,
                qty_per_pack: this.quick.has_pack ? this.quick.qty_per_pack || null : null,
                scanned_is: this.quick.has_pack ? this.quick.scanned_is : 'base',
                sale_price: this.quick.sale_price,
            };

            try {
                const response = await fetch(this.quickProductUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify(payload),
                });
                const result = await response.json();

                if (response.status === 422) {
                    this.quick.errors = result.errors ?? {};

                    return;
                }

                if (! response.ok || ! result.found) {
                    this.quick.errors = { name: [result.message ?? 'The product could not be saved. Try again.'] };

                    return;
                }

                this.add(result.row);
                this.quick.open = false;
                this.message = null;
                this.$nextTick(() => this.focusScanner());
            } catch (error) {
                this.quick.errors = { name: ['Could not reach the shop computer. Check the connection and try again.'] };
            } finally {
                this.quick.busy = false;
            }
        },
    };
}
