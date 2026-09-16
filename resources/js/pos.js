/**
 * The till.
 *
 * The basket lives here, in the browser, so a scan only waits on the network
 * for the lookup itself. It is worked out with sale-maths.js, the same sums the
 * server does, so the total on screen is the total charged; the server checks
 * that when the sale is completed and says so if a price changed meanwhile.
 *
 * The basket is also kept in localStorage, so a phone that locks or a tab that
 * reloads mid-sale does not lose it.
 */
import money from './money';
import maths from './sale-maths';

let nextUid = 1;

/**
 * A name for a bill that no other till will ever produce. crypto.randomUUID
 * needs https, which a shop computer on its own network rarely has, so the
 * bytes are drawn by hand when it is missing.
 */
function uuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);

    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const QUICK_NOTES = [50000, 100000, 500000];

/**
 * @param {object} config see PosController::index
 */
export default function pos(config) {
    return {
        config,
        tenders: config.tenders ?? [],

        lines: [],
        customer: null,
        billDiscount: '',
        note: '',

        code: '',
        busy: false,
        flash: null,
        flashTimer: null,
        lastUid: null,
        openDiscountUid: null,

        sheet: null,
        heldCount: config.heldCount ?? 0,

        searchTerm: '',
        searchResults: [],
        searching: false,

        customerTerm: '',
        customerResults: [],
        customerSearching: false,
        newCustomerPhone: '',
        addingCustomer: false,
        payAfterCustomer: false,

        held: [],
        heldLoading: false,

        payments: [],
        payError: null,
        paying: false,

        done: null,
        audio: null,

        /* Bills rung up while the line was down, waiting to be sent. */
        queue: [],
        flushing: false,
        online: true,

        camera: { supported: false, open: false, error: null, stream: null, detector: null, timer: null },

        init() {
            this.camera.supported = 'BarcodeDetector' in window && window.isSecureContext;
            this.online = navigator.onLine !== false;
            this.restore();
            this.restoreQueue();

            window.addEventListener('online', () => {
                this.online = true;
                this.flush();
            });

            window.addEventListener('offline', () => {
                this.online = false;
            });

            this.flush();
            this.$watch('lines', () => this.save(), { deep: true });
            this.$watch('customer', () => this.save());
            this.$watch('billDiscount', () => this.save());
            this.$watch('note', () => this.save());
            this.focusScanner();
        },

        /* ------------------------------------------------------------------
         | Keyboard
         * ---------------------------------------------------------------- */

        /**
         * Enter in any field but the scan box would submit nothing useful and,
         * with a USB scanner, usually means a barcode landed in the wrong box.
         */
        guardEnter(event) {
            if (event.target.tagName === 'INPUT' && event.target !== this.$refs.scanner) {
                event.preventDefault();
            }
        },

        /**
         * The counter keyboard.
         *
         * A busy till is worked with one hand on the scanner and one on the
         * keyboard, so everything the thumb can reach on a phone has a key
         * here too. Function keys only, because anything else would be eaten
         * by the scan box — the one exception is plus and minus, which change
         * the last line but only while the scan box is empty, so a barcode
         * being typed in by the scanner is never interrupted.
         */
        shortcut(event) {
            if (event.ctrlKey || event.altKey || event.metaKey) {
                return;
            }

            const scanning = this.code !== '' || document.activeElement !== this.$refs.scanner;

            switch (event.key) {
                case 'F1':
                    event.preventDefault();
                    this.sheet = this.sheet === 'keys' ? null : 'keys';

                    return;

                case 'F2':
                    event.preventDefault();
                    this.openSearch(this.code.trim());
                    this.code = '';

                    return;

                case 'F3':
                    event.preventDefault();
                    this.openCustomers();

                    return;

                case 'F4':
                    event.preventDefault();
                    this.openHeld();

                    return;

                case 'F6':
                    event.preventDefault();
                    this.hold();

                    return;

                case 'F7':
                    event.preventDefault();
                    this.removeLast();

                    return;

                case 'F8':
                    event.preventDefault();
                    this.discountLast();

                    return;

                case 'F9':
                    event.preventDefault();
                    this.openPay();

                    return;

                case 'Escape':
                    event.preventDefault();

                    if (this.sheet) {
                        this.closeSheet();
                    } else {
                        this.code = '';
                        this.focusScanner();
                    }

                    return;

                case '+':
                case '=':
                    if (! scanning && this.lastLine) {
                        event.preventDefault();
                        this.increase(this.lastLine);
                    }

                    return;

                case '-':
                    if (! scanning && this.lastLine) {
                        event.preventDefault();
                        this.decrease(this.lastLine);
                    }
            }
        },

        /** The line a key without a line of its own acts on. */
        get lastLine() {
            return this.lines.find((line) => line.uid === this.lastUid) ?? this.lines[this.lines.length - 1] ?? null;
        },

        removeLast() {
            if (this.lastLine) {
                this.remove(this.lastLine);
            }
        },

        discountLast() {
            if (this.lastLine) {
                this.toggleDiscount(this.lastLine);
                this.$nextTick(() => document.getElementById(`discount-${this.lastLine?.uid}`)?.focus());
            }
        },

        focusScanner() {
            this.$nextTick(() => {
                if (! this.sheet) {
                    this.$refs.scanner?.focus({ preventScroll: true });
                }
            });
        },

        say(text, tone = 'warning') {
            this.flash = { text, tone };
            clearTimeout(this.flashTimer);
            this.flashTimer = setTimeout(() => {
                this.flash = null;
            }, tone === 'success' ? 3500 : 7000);
        },

        /* ------------------------------------------------------------------
         | Scanning and searching
         * ---------------------------------------------------------------- */

        async scan() {
            const code = this.code.trim();

            if (code === '' || this.busy) {
                return;
            }

            this.busy = true;
            this.code = '';

            try {
                const result = await this.request('GET', `${this.config.urls.lookup}?code=${encodeURIComponent(code)}`);

                if (result.ok && result.data.found) {
                    this.add(result.data.item);
                    this.beep(true);
                } else if (result.data.search) {
                    this.openSearch(code);
                } else {
                    this.beep(false);
                    this.say(result.data.message ?? `Nothing matches "${code}".`);
                }
            } finally {
                this.busy = false;
                this.focusScanner();
            }
        },

        /**
         * A scan of something already in the basket counts one more of it,
         * which is how cashiers ring up six of the same biscuit.
         */
        add(item, qty = '1') {
            const unitId = String(item.product_unit_id ?? item.units?.[0]?.id ?? '');
            const existing = this.lines.find((line) => line.product_unit_id === unitId);

            if (existing) {
                existing.qty = this.plus(existing.qty, 1);
                this.lastUid = existing.uid;
                this.moveToTop(existing);

                return;
            }

            const line = {
                uid: nextUid++,
                item,
                product_unit_id: unitId,
                qty: String(qty),
                discount: '',
            };

            this.lines.unshift(line);
            this.lastUid = line.uid;
        },

        moveToTop(line) {
            const index = this.lines.indexOf(line);

            if (index > 0) {
                this.lines.splice(index, 1);
                this.lines.unshift(line);
            }
        },

        openSearch(term = '') {
            this.searchTerm = term;
            this.searchResults = [];
            this.sheet = 'search';
            this.$nextTick(() => {
                this.$refs.searchInput?.focus();

                if (term.length >= 2) {
                    this.runSearch();
                }
            });
        },

        async runSearch() {
            const term = this.searchTerm.trim();

            if (term.length < 2) {
                this.searchResults = [];

                return;
            }

            this.searching = true;

            const result = await this.request('GET', `${this.config.urls.search}?q=${encodeURIComponent(term)}`);

            if (term === this.searchTerm.trim()) {
                this.searchResults = result.ok ? result.data.items : [];
                this.searching = false;
            }
        },

        pick(item, unitId = null) {
            this.add(unitId ? { ...item, product_unit_id: unitId } : item);
            this.closeSheet();
        },

        /* ------------------------------------------------------------------
         | Camera — for a phone with no scanner attached
         * ---------------------------------------------------------------- */

        async openCamera() {
            if (! this.camera.supported) {
                return;
            }

            this.sheet = 'camera';
            this.camera.error = null;

            try {
                this.camera.detector ??= new window.BarcodeDetector();
                this.camera.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' },
                    audio: false,
                });

                const video = this.$refs.cameraVideo;
                video.srcObject = this.camera.stream;
                await video.play();

                this.camera.timer = setInterval(() => this.detect(), 250);
            } catch (error) {
                this.camera.error = 'The camera could not be opened. Allow camera access for this site, or type the code instead.';
                this.stopCamera();
            }
        },

        async detect() {
            const video = this.$refs.cameraVideo;

            if (! video || video.readyState < 2 || this.busy) {
                return;
            }

            try {
                const codes = await this.camera.detector.detect(video);

                if (codes.length > 0) {
                    this.code = codes[0].rawValue;
                    this.closeSheet();
                    await this.scan();
                }
            } catch (error) {
                // A frame the detector could not read; try the next one.
            }
        },

        stopCamera() {
            clearInterval(this.camera.timer);
            this.camera.timer = null;
            this.camera.stream?.getTracks().forEach((track) => track.stop());
            this.camera.stream = null;
        },

        /* ------------------------------------------------------------------
         | Lines
         * ---------------------------------------------------------------- */

        unitFor(line) {
            return line.item.units.find((unit) => String(unit.id) === line.product_unit_id) ?? line.item.units[0];
        },

        factorFor(line) {
            return Number(this.unitFor(line)?.factor ?? 1) || 1;
        },

        unitName(line) {
            return this.unitFor(line)?.name ?? '';
        },

        priceFor(line) {
            return Number(this.unitFor(line)?.price_paisa ?? 0);
        },

        plus(qty, by) {
            const milli = maths.qtyMilli(qty) + by * 1000;

            return this.qtyText(Math.max(0, milli));
        },

        qtyText(milli) {
            const whole = Math.floor(milli / 1000);
            const fraction = String(milli % 1000).padStart(3, '0').replace(/0+$/, '');

            return fraction === '' ? String(whole) : `${whole}.${fraction}`;
        },

        increase(line) {
            line.qty = this.plus(line.qty, 1);
        },

        decrease(line) {
            if (maths.qtyMilli(line.qty) <= 1000) {
                this.remove(line);

                return;
            }

            line.qty = this.plus(line.qty, -1);
        },

        remove(line) {
            this.lines = this.lines.filter((candidate) => candidate.uid !== line.uid);
            this.focusScanner();
        },

        toggleDiscount(line) {
            this.openDiscountUid = this.openDiscountUid === line.uid ? null : line.uid;
        },

        piecesIn(line) {
            return (maths.qtyMilli(line.qty) * this.factorFor(line)) / 1000;
        },

        /** Base units the whole basket takes of this product, across its sizes. */
        piecesOfProduct(productId) {
            return this.lines
                .filter((line) => line.item.product_id === productId)
                .reduce((sum, line) => sum + this.piecesIn(line), 0);
        },

        /**
         * A part of one is only possible when it comes to whole base units:
         * 0.5 of a carton of 24 is 12 packets, but 0.5 of a packet is nothing.
         */
        lineProblem(line) {
            const milli = maths.qtyMilli(line.qty);

            if (milli <= 0) {
                return `Enter how many ${this.unitName(line).toLowerCase() || 'of these'}.`;
            }

            if (! maths.isWholeInBase(milli, this.factorFor(line))) {
                return `${line.qty} ${this.unitName(line).toLowerCase()} is not a whole number of ${line.item.base_unit.toLowerCase() || 'pieces'}.`;
            }

            return null;
        },

        stockWarning(line) {
            const wanted = this.piecesOfProduct(line.item.product_id);
            const onShelf = line.item.stock_qty_base;

            if (wanted <= onShelf) {
                return null;
            }

            const shelf = onShelf > 0 ? `Only ${line.item.stock_label} in stock` : 'Shows out of stock';

            return this.config.allowNegativeStock
                ? `${shelf} — selling anyway. Recount it later.`
                : `${shelf}. The sale will be refused until it is recounted.`;
        },

        /* ------------------------------------------------------------------
         | The bill
         * ---------------------------------------------------------------- */

        get bill() {
            return maths.calculate(
                this.lines.map((line) => ({
                    qty: line.qty,
                    unit_price_paisa: this.priceFor(line),
                    tax_rate: line.item.tax_rate,
                    discount: line.discount || null,
                })),
                this.billDiscount || null,
                this.config.pricesIncludeTax,
                this.config.roundToRupee,
            );
        },

        lineTotal(index) {
            return this.bill.lines[index]?.line_total_paisa ?? 0;
        },

        lineDiscount(index) {
            return this.bill.lines[index]?.discount_paisa ?? 0;
        },

        get total() {
            return this.bill.total_paisa;
        },

        get itemCount() {
            return this.lines.reduce((sum, line) => sum + maths.qtyMilli(line.qty), 0) / 1000;
        },

        get isEmpty() {
            return this.lines.length === 0;
        },

        get hasProblems() {
            return this.lines.some((line) => this.lineProblem(line) !== null);
        },

        /**
         * The server refuses a cashier's discount above the shop's limit, so it
         * is said here while it can still be changed.
         */
        get discountWarning() {
            if (this.config.supervises || this.bill.discount_paisa === 0) {
                return null;
            }

            const limitBp = Math.round(this.config.discountLimit * 100);
            const allowed = maths.divRound(this.bill.subtotal_paisa * limitBp, 10000);

            if (this.bill.discount_paisa <= allowed) {
                return null;
            }

            return `A cashier can give up to ${this.config.discountLimit}% (${this.format(allowed)}). Lower the discount or ask a manager.`;
        },

        /* ------------------------------------------------------------------
         | Paying
         * ---------------------------------------------------------------- */

        openPay() {
            if (this.isEmpty || this.sheet === 'done') {
                return;
            }

            if (this.hasProblems) {
                this.say('Fix the lines marked in red first.');

                return;
            }

            this.payError = null;

            if (this.payments.length === 0) {
                this.payments = [this.paymentRow('cash', '')];
            }

            this.sheet = 'pay';
            this.$nextTick(() => this.$refs.cashInput?.focus());
        },

        paymentRow(method, amount) {
            return { uid: nextUid++, method, amount, reference: '' };
        },

        tender(method) {
            return this.tenders.find((candidate) => candidate.value === method);
        },

        get cashRow() {
            return this.payments.find((row) => row.method === 'cash');
        },

        get nonCashPaisa() {
            return this.payments
                .filter((row) => row.method !== 'cash')
                .reduce((sum, row) => sum + money.parse(row.amount), 0);
        },

        get cashPaisa() {
            return money.parse(this.cashRow?.amount);
        },

        /** What cash still has to cover once the card, wallet and khata rows are counted. */
        get leftForCash() {
            return Math.max(0, this.total - this.nonCashPaisa);
        },

        get shortPaisa() {
            return Math.max(0, this.leftForCash - this.cashPaisa);
        },

        /**
         * Is there anything left for cash to pay? Once a card or a wallet has
         * taken the whole bill the cash box is switched off, so the same forty
         * rupees cannot be typed in twice and handed straight back as change.
         */
        get cashIsOpen() {
            return this.leftForCash > 0;
        },

        /** The method that has the bill, named so the cashier can see why cash is off. */
        get billIsOnLabel() {
            const rows = this.payments.filter((row) => row.method !== 'cash' && money.parse(row.amount) > 0);

            return rows.map((row) => this.tender(row.method)?.label).filter(Boolean).join(' + ');
        },

        get changePaisa() {
            return Math.max(0, this.cashPaisa - this.leftForCash);
        },

        get khataPaisa() {
            return this.payments
                .filter((row) => this.tender(row.method)?.needs_customer)
                .reduce((sum, row) => sum + money.parse(row.amount), 0);
        },

        get canComplete() {
            return ! this.paying
                && ! this.isEmpty
                && this.shortPaisa === 0
                && this.nonCashPaisa <= this.total
                && (this.khataPaisa === 0 || this.customer !== null);
        },

        /**
         * Round amounts the customer is likely to hand over: the exact bill,
         * the next hundred, and the common notes above it.
         */
        get quickCash() {
            const due = this.leftForCash;

            if (due <= 0) {
                return [];
            }

            const options = [due, Math.ceil(due / 10000) * 10000, ...QUICK_NOTES.filter((note) => note > due)];

            return [...new Set(options)].slice(0, 4);
        },

        setCash(paisa) {
            if (! this.cashRow) {
                this.payments.unshift(this.paymentRow('cash', ''));
            }

            this.cashRow.amount = this.rupees(paisa);
        },

        rupees(paisa) {
            return (paisa / 100).toFixed(2).replace(/\.00$/, '');
        },

        /**
         * Put the bill on a card, a wallet or the khata — or take it back off.
         *
         * Tapping a method that is already lit removes it and hands the bill
         * back to cash, because the way a payment gets entered twice is a
         * cashier tapping Card, changing their mind, and typing into the cash
         * box as well.
         */
        addTender(method) {
            const tender = this.tender(method);

            if (! tender || tender.is_cash) {
                return;
            }

            const chosen = this.payments.find((candidate) => candidate.method === method);

            if (chosen) {
                this.removeTender(chosen);

                return;
            }

            if (tender.needs_customer && ! this.customer) {
                this.payAfterCustomer = true;
                this.openCustomers();

                return;
            }

            const row = this.paymentRow(method, '');
            this.payments.push(row);

            const open = Math.max(0, this.total - (this.nonCashPaisa - money.parse(row.amount)));
            let amount = open - Math.min(this.cashPaisa, open);

            if (amount === 0 && this.cashRow) {
                amount = open;
                this.cashRow.amount = '';
            }

            row.amount = this.rupees(amount);
        },

        removeTender(row) {
            this.payments = this.payments.filter((candidate) => candidate.uid !== row.uid);

            /* Whatever that method was holding is cash's problem again, so the
               box it goes in is switched back on and waiting. */
            if (this.sheet === 'pay' && this.cashIsOpen) {
                this.$nextTick(() => this.$refs.cashInput?.focus());
            }
        },

        async complete() {
            if (! this.canComplete) {
                return;
            }

            this.paying = true;
            this.payError = null;

            /* The bill is named here, before it is sent. If the reply is lost
               on the way back the same name goes again and the server hands
               back the bill it already has, so nobody is charged twice. */
            const payload = {
                ...this.basketPayload(),
                expected_total_paisa: this.total,
                offline_uid: uuid(),
                offline_rung_at: new Date().toISOString(),
                payments: this.payments
                    .filter((row) => money.parse(row.amount) > 0)
                    .map((row) => ({
                        method: row.method,
                        amount_paisa: money.parse(row.amount),
                        reference: row.reference || null,
                    })),
            };

            const result = await this.request('POST', this.config.urls.store, payload);

            this.paying = false;

            if (result.ok) {
                this.done = {
                    ...result.data.sale,
                    receipt_url: result.data.receipt_url,
                    printed: result.data.printed === true,
                };
                this.clearBasket();
                this.sheet = 'done';
                this.beep(true);

                return;
            }

            /* Nothing answered at all: the line is down, not the sale. Keep
               the bill here and carry on serving, because the shop cannot
               stop for the internet. */
            if (result.status === 0) {
                this.keep(payload);

                return;
            }

            if (result.data.repriced) {
                this.reprice(result.data.repriced.prices);
            }

            this.payError = result.data.message ?? 'The sale could not be completed.';
        },

        /* ------------------------------------------------------------------
         | Selling with the line down
         * ---------------------------------------------------------------- */

        /**
         * Put a bill in the queue and show the cashier the change to give, as
         * though it had gone through. As far as the customer standing there is
         * concerned, it has.
         */
        keep(payload) {
            const change = this.changePaisa;
            const due = this.khataPaisa;

            this.queue.push({
                uid: payload.offline_uid,
                rungAt: payload.offline_rung_at,
                total_paisa: this.total,
                change_paisa: change,
                due_paisa: due,
                customer: this.customer?.name ?? null,
                problem: null,
                payload,
            });

            this.saveQueue();
            this.online = false;

            this.done = {
                queued: true,
                invoice: null,
                total_paisa: this.total,
                change_paisa: change,
                due_paisa: due,
                customer: this.customer?.name ?? null,
                customer_balance: null,
                receipt_url: null,
                printed: false,
            };

            this.clearBasket();
            this.sheet = 'done';
            this.beep(true);
        },

        /** Bills still waiting, and the ones the server has refused. */
        get waitingCount() {
            return this.queue.filter((entry) => ! entry.problem).length;
        },

        get stuckCount() {
            return this.queue.filter((entry) => entry.problem).length;
        },

        /**
         * Send what is waiting, oldest first and one at a time, so the invoice
         * numbers come out in the order the customers were served. A bill that
         * cannot go stops nothing else; it is set aside, marked with whatever
         * the server said about it.
         */
        async flush() {
            if (this.flushing || this.queue.length === 0) {
                return;
            }

            this.flushing = true;

            for (const entry of [...this.queue]) {
                if (entry.problem) {
                    continue;
                }

                const result = await this.request('POST', this.config.urls.store, entry.payload);

                if (result.ok) {
                    this.queue = this.queue.filter((candidate) => candidate.uid !== entry.uid);
                    this.saveQueue();

                    continue;
                }

                /* Still nothing on the other end. Leave the rest for later. */
                if (result.status === 0) {
                    this.online = false;
                    this.flushing = false;

                    return;
                }

                entry.problem = result.data.message ?? 'The shop computer would not take this bill.';
                this.saveQueue();
            }

            this.flushing = false;
            this.online = true;

            if (this.queue.length === 0) {
                this.say('Everything that was waiting has gone through.', 'success');
            }
        },

        /**
         * Throwing a bill away means money was taken and never recorded, so it
         * is a supervisor's decision and nobody else's.
         */
        discard(entry) {
            if (! this.config.supervises) {
                this.say('Only a manager can throw a waiting bill away.');

                return;
            }

            if (! window.confirm('Throw this bill away? The money was taken but nothing will be recorded.')) {
                return;
            }

            this.queue = this.queue.filter((candidate) => candidate.uid !== entry.uid);
            this.saveQueue();
        },

        /** A bill that was set aside can be offered to the server once more. */
        retry(entry) {
            entry.problem = null;
            this.saveQueue();
            this.flush();
        },

        get queueKey() {
            return `supermart.pos.queue.${this.config.userId}.${this.config.register.id}`;
        },

        saveQueue() {
            try {
                if (this.queue.length === 0) {
                    localStorage.removeItem(this.queueKey);

                    return;
                }

                localStorage.setItem(this.queueKey, JSON.stringify(this.queue));
            } catch (error) {
                // Nothing can be written. The queue still works until the tab closes.
            }
        },

        restoreQueue() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.queueKey) ?? 'null');

                if (Array.isArray(saved)) {
                    this.queue = saved.filter((entry) => entry && entry.uid && entry.payload);
                }
            } catch (error) {
                this.queue = [];
            }
        },

        reprice(prices) {
            this.lines.forEach((line) => {
                const price = prices[line.product_unit_id];

                if (price === undefined) {
                    return;
                }

                line.item.units = line.item.units.map((unit) => (
                    String(unit.id) === line.product_unit_id ? { ...unit, price_paisa: price } : unit
                ));
            });
        },

        printReceipt(paper = null) {
            if (! this.done) {
                return;
            }

            const url = new URL(this.done.receipt_url, window.location.href);
            url.searchParams.set('print', '1');

            if (paper) {
                url.searchParams.set('paper', paper);
            }

            const frame = this.$refs.printFrame;

            if (frame) {
                frame.src = url.toString();
            } else {
                window.open(url.toString(), '_blank');
            }
        },

        nextCustomer() {
            this.done = null;
            this.closeSheet();
        },

        /* ------------------------------------------------------------------
         | Customer
         * ---------------------------------------------------------------- */

        openCustomers() {
            this.customerTerm = '';
            this.newCustomerPhone = '';
            this.sheet = 'customer';
            this.searchCustomers();
            this.$nextTick(() => this.$refs.customerInput?.focus());
        },

        async searchCustomers() {
            const term = this.customerTerm.trim();

            this.customerSearching = true;

            const result = await this.request('GET', `${this.config.urls.customers}?q=${encodeURIComponent(term)}`);

            if (term === this.customerTerm.trim()) {
                this.customerResults = result.ok ? result.data.customers : [];
                this.customerSearching = false;
            }

            /* A number searched for and not found is the number the new
               customer is about to be given, so it drops into the box below
               rather than being typed a second time. */
            if (this.typedNumber !== '') {
                this.newCustomerPhone = this.typedNumber;
            }
        },

        chooseCustomer(customer) {
            this.customer = customer;

            if (this.payAfterCustomer) {
                this.payAfterCustomer = false;
                this.sheet = 'pay';
                this.addTender('khata');

                return;
            }

            this.closeSheet();
        },

        clearCustomer() {
            this.customer = null;
            this.payments = this.payments.filter((row) => ! this.tender(row.method)?.needs_customer);
        },

        /**
         * A number stripped back to the part that is typed beside the +92 —
         * the same job App\Support\PhoneNumber does on the server, so a
         * customer added at the till is stored in the one shape every other
         * number in the shop is stored in.
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

        /**
         * The name for somebody new, taken from the search box — that is where
         * the cashier has already typed it, and a second name field beside it
         * only ever gets the same thing typed into it twice. Anything with a
         * letter in it is a name.
         */
        get typedName() {
            const typed = this.customerTerm.trim();

            return /\p{L}/u.test(typed) ? typed : '';
        },

        /** The same box when a number was typed into it instead of a name. */
        get typedNumber() {
            return this.typedName === '' ? this.tidyNumber(this.customerTerm) : '';
        },

        async addCustomer() {
            if (this.addingCustomer || this.typedName === '') {
                return;
            }

            this.addingCustomer = true;

            const result = await this.request('POST', this.config.urls.addCustomer, {
                name: this.typedName,
                phone: this.newCustomerPhone,
            });

            this.addingCustomer = false;

            if (result.ok) {
                this.say(result.data.message, 'success');
                this.chooseCustomer(result.data.customer);
            } else {
                this.say(this.firstError(result.data));
            }
        },

        creditWarning() {
            const customer = this.customer;

            if (! customer || this.khataPaisa === 0 || customer.credit_limit_paisa <= 0) {
                return null;
            }

            const owed = customer.balance_paisa + this.khataPaisa;

            if (owed <= customer.credit_limit_paisa) {
                return null;
            }

            return `${customer.name} would owe ${this.format(owed)}, over their limit of ${this.format(customer.credit_limit_paisa)}.`
                + (this.config.supervises ? '' : ' Take more now, or ask a manager.');
        },

        /* ------------------------------------------------------------------
         | Hold and resume
         * ---------------------------------------------------------------- */

        async hold() {
            if (this.isEmpty || this.busy) {
                return;
            }

            this.busy = true;

            const result = await this.request('POST', this.config.urls.hold, this.basketPayload());

            this.busy = false;

            if (result.ok) {
                this.heldCount = result.data.held_count;
                this.clearBasket();
                this.closeSheet();
                this.say(result.data.message, 'success');
            } else {
                this.say(this.firstError(result.data));
            }
        },

        async openHeld() {
            this.sheet = 'held';
            this.heldLoading = true;

            const result = await this.request('GET', this.config.urls.held);

            this.heldLoading = false;
            this.held = result.ok ? result.data.held : [];
            this.heldCount = this.held.length;
        },

        async resume(entry) {
            if (! this.isEmpty && ! window.confirm('Put the basket on screen back on hold and bring this one back?')) {
                return;
            }

            if (! this.isEmpty) {
                await this.hold();
            }

            const result = await this.request('POST', this.config.urls.resume.replace('__ID__', entry.id));

            if (! result.ok) {
                this.say(result.data.message ?? 'That basket could not be brought back.');
                await this.openHeld();

                return;
            }

            const cart = result.data.cart;

            this.lines = cart.lines.map((line) => ({
                uid: nextUid++,
                item: line.item,
                product_unit_id: String(line.item.product_unit_id),
                qty: line.qty,
                discount: line.discount ?? '',
            })).reverse();
            this.customer = cart.customer;
            this.billDiscount = cart.bill_discount ?? '';
            this.note = cart.note ?? '';
            this.heldCount = result.data.held_count;
            this.closeSheet();
            this.say(result.data.message, 'success');
        },

        async discard(entry) {
            if (! window.confirm(`Throw away the held basket for ${entry.customer}? Nothing was sold, so no stock changes.`)) {
                return;
            }

            const result = await this.request('DELETE', this.config.urls.discard.replace('__ID__', entry.id));

            if (! result.ok) {
                this.say(result.data.message ?? 'That basket could not be thrown away.');
            }

            await this.openHeld();
        },

        /* ------------------------------------------------------------------
         | Basket housekeeping
         * ---------------------------------------------------------------- */

        basketPayload() {
            return {
                register_id: this.config.register.id,
                customer_id: this.customer?.id ?? null,
                bill_discount: this.billDiscount || null,
                note: this.note || null,
                lines: [...this.lines].reverse().map((line) => ({
                    product_unit_id: Number(line.product_unit_id),
                    qty: line.qty,
                    discount: line.discount || null,
                })),
            };
        },

        clearBasket() {
            this.lines = [];
            this.customer = null;
            this.billDiscount = '';
            this.note = '';
            this.payments = [];
            this.payError = null;
            this.lastUid = null;
            this.openDiscountUid = null;
        },

        confirmClear() {
            if (this.isEmpty || window.confirm('Empty the basket? Nothing has been sold yet.')) {
                this.clearBasket();
                this.focusScanner();
            }
        },

        closeSheet() {
            if (this.sheet === 'camera') {
                this.stopCamera();
            }

            if (this.sheet === 'done') {
                this.done = null;
            }

            this.payAfterCustomer = false;
            this.sheet = null;
            this.focusScanner();
        },

        get storageKey() {
            return `supermart.pos.cart.${this.config.userId}.${this.config.register.id}`;
        },

        save() {
            try {
                if (this.isEmpty && ! this.customer) {
                    localStorage.removeItem(this.storageKey);

                    return;
                }

                localStorage.setItem(this.storageKey, JSON.stringify({
                    lines: this.lines,
                    customer: this.customer,
                    billDiscount: this.billDiscount,
                    note: this.note,
                }));
            } catch (error) {
                // Private browsing or a full disk: the basket still works, it just will not survive a reload.
            }
        },

        restore() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey) ?? 'null');

                if (! saved || ! Array.isArray(saved.lines)) {
                    return;
                }

                this.lines = saved.lines.map((line) => ({ ...line, uid: nextUid++ }));
                this.customer = saved.customer ?? null;
                this.billDiscount = saved.billDiscount ?? '';
                this.note = saved.note ?? '';
            } catch (error) {
                // Nothing usable was saved.
            }
        },

        /* ------------------------------------------------------------------
         | Plumbing
         * ---------------------------------------------------------------- */

        format(paisa) {
            return money.withSymbol(paisa);
        },

        async request(method, url, body = null) {
            try {
                const response = await fetch(url, {
                    method,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: body === null ? null : JSON.stringify(body),
                });

                let data = {};

                try {
                    data = await response.json();
                } catch (error) {
                    data = {};
                }

                if (response.status === 419) {
                    data.message = 'This page has been open too long. Reload it — the basket will still be here.';
                }

                return { ok: response.ok, status: response.status, data };
            } catch (error) {
                return { ok: false, status: 0, data: { message: 'Could not reach the shop computer. Check the connection and try again.' } };
            }
        },

        firstError(data) {
            const errors = Object.values(data.errors ?? {}).flat();

            return errors[0] ?? data.message ?? 'Something went wrong.';
        },

        /**
         * A short tone, so the cashier knows a scan landed without looking up.
         */
        beep(success) {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;

                this.audio ??= new AudioContext();

                const oscillator = this.audio.createOscillator();
                const gain = this.audio.createGain();

                oscillator.frequency.value = success ? 1500 : 300;
                gain.gain.value = 0.06;
                oscillator.connect(gain);
                gain.connect(this.audio.destination);
                oscillator.start();
                oscillator.stop(this.audio.currentTime + (success ? 0.07 : 0.25));
            } catch (error) {
                // No sound available; the screen still shows it.
            }
        },
    };
}
