/**
 * The packaging builder behind the product form.
 *
 * The shopkeeper describes sizes the way the delivery arrives — "one box is
 * 24 sachets, one carton is 12 boxes" — and this works out what each size is
 * worth in base units while they type, so the answer is visible before they
 * save rather than discovered at the till.
 *
 * The arithmetic here mirrors App\Support\Packaging on the server, which
 * remains the authority: this copy exists only to show a live preview.
 */

/** Guards against a chain that refers back to itself while being edited. */
const MAX_DEPTH = 10;

/**
 * @param {object} config
 * @param {Array<{id: number, name: string, short_name: string, type: string}>} config.units
 * @param {number|null} config.baseUnitId
 * @param {Array<object>} config.rows
 * @param {number} config.defaultSale
 * @param {number} config.defaultPurchase
 * @param {boolean} config.baseLocked
 */
export default function packagingBuilder(config) {
    return {
        units: config.units ?? [],
        baseUnitId: config.baseUnitId ? Number(config.baseUnitId) : null,
        baseLocked: Boolean(config.baseLocked),
        defaultSale: Number(config.defaultSale ?? 0),
        defaultPurchase: Number(config.defaultPurchase ?? 0),
        rows: [],

        /* Which barcode box just took a scan, to show a tick beside it. */
        justScanned: null,

        /* The phone camera as a scanner, filling one barcode box. */
        sheet: null,
        camera: { supported: false, error: null, stream: null, detector: null, timer: null, target: null },

        init() {
            this.camera.supported = 'BarcodeDetector' in window
                && window.isSecureContext
                && Boolean(navigator.mediaDevices?.getUserMedia);

            this.rows = (config.rows ?? []).map((row) => this.normalise(row));

            if (this.rows.length === 0) {
                this.rows = [this.blankRow(true)];
            }

            this.$watch('baseUnitId', () => this.onBaseUnitChanged());
        },

        /**
         * @returns {object}
         */
        normalise(row) {
            return {
                unit_id: row.unit_id ? Number(row.unit_id) : null,
                parent_unit_id: row.parent_unit_id ? Number(row.parent_unit_id) : null,
                qty_per_parent: row.qty_per_parent ?? 1,
                sale_price: row.sale_price ?? '',
                mrp: row.mrp ?? '',
                barcodes: (row.barcodes ?? []).length ? [...row.barcodes] : [''],
            };
        },

        blankRow(isBase = false) {
            return {
                unit_id: isBase ? this.baseUnitId : null,
                parent_unit_id: isBase ? null : this.smallestUnitId(),
                qty_per_parent: isBase ? 1 : '',
                sale_price: '',
                mrp: '',
                barcodes: [''],
            };
        },

        /** The base row is always first — everything else is measured in it. */
        isBase(row) {
            return this.baseUnitId !== null && Number(row.unit_id) === this.baseUnitId;
        },

        baseUnitName() {
            return this.unitName(this.baseUnitId) || 'base units';
        },

        unitName(id) {
            const unit = this.units.find((candidate) => Number(candidate.id) === Number(id));

            return unit ? unit.name : '';
        },

        smallestUnitId() {
            return this.baseUnitId;
        },

        /** Units not already claimed by another row. */
        availableUnits(row) {
            const taken = this.rows
                .filter((other) => other !== row)
                .map((other) => Number(other.unit_id));

            return this.units.filter((unit) => !taken.includes(Number(unit.id)));
        },

        /** Sizes this row can be described in terms of: any other listed row. */
        parentOptions(row) {
            return this.rows
                .filter((other) => other !== row && other.unit_id)
                .map((other) => ({ id: Number(other.unit_id), name: this.unitName(other.unit_id) }));
        },

        /**
         * Base units held by one of this size, or null while the chain is
         * still incomplete.
         */
        factor(row) {
            let current = row;
            let total = 1;
            let depth = 0;

            while (current && !this.isBase(current)) {
                depth += 1;

                if (depth > MAX_DEPTH) {
                    return null;
                }

                const qty = Number(current.qty_per_parent);

                if (!Number.isFinite(qty) || qty < 1) {
                    return null;
                }

                total *= qty;
                current = this.rows.find((other) => Number(other.unit_id) === Number(current.parent_unit_id));
            }

            return current ? total : null;
        },

        /** "1 carton = 288 sachets" — the sentence that catches a typo. */
        summary(row) {
            const factor = this.factor(row);

            if (!row.unit_id) {
                return '';
            }

            if (factor === null) {
                return 'Say what this is made of first.';
            }

            if (factor === 1) {
                return 'The smallest size you sell.';
            }

            return `1 ${this.unitName(row.unit_id)} = ${factor.toLocaleString('en-PK')} ${this.baseUnitName().toLowerCase()}`;
        },

        /**
         * What one base unit costs at this row's price, so a carton priced
         * worse than the sachets inside it is obvious on the form.
         */
        pricePerBase(row) {
            const factor = this.factor(row);
            const paisa = window.money.parse(row.sale_price);

            if (!factor || paisa <= 0) {
                return null;
            }

            return Math.round(paisa / factor);
        },

        /**
         * True when a bigger pack works out dearer per base unit than the
         * smallest one. Usually a decimal point in the wrong place.
         */
        isPricedAbove(row) {
            const base = this.rows.find((other) => this.isBase(other));
            const here = this.pricePerBase(row);
            const smallest = base ? this.pricePerBase(base) : null;

            return Boolean(here && smallest && this.factor(row) > 1 && here > smallest);
        },

        pricePerBaseLabel(row) {
            const paisa = this.pricePerBase(row);

            return paisa === null ? '' : `${window.money.withSymbol(paisa)} per ${this.baseUnitName().toLowerCase()}`;
        },

        addRow() {
            this.rows.push(this.blankRow());
        },

        removeRow(index) {
            const row = this.rows[index];

            if (this.isBase(row)) {
                return;
            }

            /* Anything described in terms of the removed size is orphaned, so
               it is re-pointed at the base rather than left dangling. */
            this.rows.forEach((other) => {
                if (Number(other.parent_unit_id) === Number(row.unit_id)) {
                    other.parent_unit_id = this.baseUnitId;
                }
            });

            this.rows.splice(index, 1);

            if (this.defaultSale === index) {
                this.defaultSale = 0;
            }

            if (this.defaultPurchase === index) {
                this.defaultPurchase = 0;
            }
        },

        addBarcode(row) {
            row.barcodes.push('');
        },

        removeBarcode(row, index) {
            row.barcodes.splice(index, 1);

            if (row.barcodes.length === 0) {
                row.barcodes.push('');
            }
        },

        /**
         * A USB scanner types the code and then presses Enter. On this form
         * Enter would save the whole product half-filled, so here it only
         * means "that code is in": the box lets go of the cursor, so a second
         * scan cannot run on to the end of the first.
         */
        barcodeEntered(event, rowIndex, position) {
            event.preventDefault();

            if (event.target.value.trim() === '') {
                return;
            }

            event.target.blur();
            this.markScanned(rowIndex, position);
        },

        markScanned(rowIndex, position) {
            const key = `${rowIndex}-${position}`;
            this.justScanned = key;

            setTimeout(() => {
                if (this.justScanned === key) {
                    this.justScanned = null;
                }
            }, 2500);
        },

        async openCamera(rowIndex, position) {
            if (! this.camera.supported) {
                return;
            }

            this.camera.target = { rowIndex, position };
            this.camera.error = null;
            this.sheet = 'camera';

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

            if (! video || video.readyState < 2 || ! this.camera.target) {
                return;
            }

            try {
                const codes = await this.camera.detector.detect(video);

                if (codes.length === 0) {
                    return;
                }

                const { rowIndex, position } = this.camera.target;
                const row = this.rows[rowIndex];

                if (row) {
                    row.barcodes[position] = codes[0].rawValue;
                    this.markScanned(rowIndex, position);
                }

                this.closeSheet();
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

        closeSheet() {
            this.stopCamera();
            this.camera.target = null;
            this.sheet = null;
        },

        /** Keep the first row pointed at whatever the base unit now is. */
        onBaseUnitChanged() {
            if (this.baseUnitId === null || this.rows.length === 0) {
                return;
            }

            const base = this.rows.find((row) => !row.parent_unit_id) ?? this.rows[0];

            base.unit_id = this.baseUnitId;
            base.parent_unit_id = null;
            base.qty_per_parent = 1;

            this.rows.forEach((row) => {
                if (row !== base && !row.parent_unit_id) {
                    row.parent_unit_id = this.baseUnitId;
                }
            });
        },
    };
}
