/**
 * The arithmetic of a bill.
 *
 * A line-for-line copy of App\Support\SaleCalculator, so the till shows the
 * cashier exactly the total the server will charge. Change one and you must
 * change the other; tests/Unit/SaleCalculatorTest pins the figures.
 *
 * Every amount is an integer number of paisa, and quantities, rates and typed
 * discounts are read from their digits rather than through a float.
 */

/**
 * a ÷ b rounded half-up, for a ≥ 0 and b > 0.
 */
function divRound(numerator, denominator) {
    return Math.floor((2 * numerator + denominator) / (2 * denominator));
}

/**
 * Read a non-negative decimal as an integer scaled by 10^decimals. Digits
 * beyond `decimals` are dropped. Null when the value is not a number.
 */
function scaled(value, decimals) {
    const text = String(value ?? '').trim();
    const match = /^(\d*)(?:\.(\d*))?$/.exec(text);

    if (!match || text === '' || text === '.') {
        return null;
    }

    const whole = match[1] === '' ? 0 : parseInt(match[1], 10);
    const fraction = (match[2] ?? '').padEnd(decimals, '0').slice(0, decimals);

    return whole * 10 ** decimals + (decimals > 0 ? parseInt(fraction, 10) : 0);
}

function qtyMilli(qty) {
    return scaled(qty, 3) ?? 0;
}

function isWholeInBase(milli, conversionFactor) {
    return (milli * Math.max(1, conversionFactor)) % 1000 === 0;
}

/**
 * A typed discount in paisa, never more than the amount it comes off.
 */
function discountPaisa(input, basePaisa) {
    const text = String(input ?? '').replace(/[^0-9.%]/g, '');

    if (text === '' || basePaisa <= 0) {
        return 0;
    }

    const isPercent = text.includes('%');
    const number = scaled(text.replace(/%/g, ''), 2) ?? 0;

    const paisa = isPercent
        ? divRound(basePaisa * Math.min(number, 10000), 10000)
        : number;

    return Math.min(basePaisa, paisa);
}

function taxPaisa(netPaisa, rateBp, pricesIncludeTax) {
    if (netPaisa <= 0 || rateBp <= 0) {
        return 0;
    }

    return pricesIncludeTax
        ? divRound(netPaisa * rateBp, 10000 + rateBp)
        : divRound(netPaisa * rateBp, 10000);
}

function roundOff(paisa) {
    return paisa - Math.floor((paisa + 50) / 100) * 100;
}

/**
 * App\Support\Allocation::spread — the largest-remainder method. Ties go to
 * the earlier line, as PHP's stable sort does. The multiply is done in BigInt
 * so a large bill cannot lose a paisa to floating point.
 */
function spread(amount, weights) {
    if (weights.length === 0) {
        return [];
    }

    let clean = weights.map((weight) => Math.max(0, weight));
    let total = clean.reduce((sum, weight) => sum + weight, 0);

    if (total === 0) {
        clean = clean.map(() => 1);
        total = clean.length;
    }

    const sign = amount < 0 ? -1 : 1;
    const absolute = Math.abs(amount);

    const shares = [];
    const remainders = [];

    clean.forEach((weight, index) => {
        if (weight === 0) {
            shares[index] = 0;
            remainders[index] = 0;
            return;
        }

        const product = BigInt(absolute) * BigInt(weight);
        shares[index] = Number(product / BigInt(total));
        remainders[index] = Number(product % BigInt(total));
    });

    const order = clean.map((_, index) => index);
    order.sort((a, b) => remainders[b] - remainders[a]);

    let leftover = absolute - shares.reduce((sum, share) => sum + share, 0);

    while (leftover !== 0) {
        for (const index of order) {
            if (leftover === 0) {
                break;
            }

            if (leftover > 0) {
                shares[index] += 1;
                leftover -= 1;
            } else if (shares[index] > 0) {
                shares[index] -= 1;
                leftover += 1;
            }
        }
    }

    return shares.map((share) => share * sign);
}

/**
 * Work out a whole bill.
 *
 * @param {Array<{qty: string|number, unit_price_paisa: number, tax_rate: string|number, discount?: string|null}>} lines
 * @param {string|null} billDiscount
 * @param {boolean} pricesIncludeTax
 * @param {boolean} roundToRupee
 */
function calculate(lines, billDiscount = null, pricesIncludeTax = true, roundToRupee = false) {
    const worked = lines.map((line) => {
        const milli = qtyMilli(line.qty);
        const gross = divRound(milli * Math.max(0, Number(line.unit_price_paisa) || 0), 1000);
        const discount = discountPaisa(line.discount ?? null, gross);

        return {
            qty_milli: milli,
            gross_paisa: gross,
            discount_paisa: discount,
            rate_bp: scaled(line.tax_rate ?? 0, 2) ?? 0,
            after: gross - discount,
        };
    });

    const afterLineDiscount = worked.map((line) => line.after);
    const billDiscountPaisa = discountPaisa(
        billDiscount,
        afterLineDiscount.reduce((sum, amount) => sum + amount, 0),
    );
    const shares = spread(billDiscountPaisa, afterLineDiscount);

    let subtotal = 0;
    let lineDiscount = 0;
    let tax = 0;
    let exact = 0;

    const result = worked.map((line, index) => {
        const share = shares[index] ?? 0;
        const net = line.after - share;
        const lineTax = taxPaisa(net, line.rate_bp, pricesIncludeTax);
        const lineTotal = pricesIncludeTax ? net : net + lineTax;

        subtotal += line.gross_paisa;
        lineDiscount += line.discount_paisa;
        tax += lineTax;
        exact += lineTotal;

        return {
            qty_milli: line.qty_milli,
            gross_paisa: line.gross_paisa,
            discount_paisa: line.discount_paisa,
            bill_discount_paisa: share,
            net_paisa: net,
            tax_paisa: lineTax,
            line_total_paisa: lineTotal,
        };
    });

    const rounding = roundToRupee ? roundOff(exact) : 0;

    return {
        lines: result,
        subtotal_paisa: subtotal,
        line_discount_paisa: lineDiscount,
        bill_discount_paisa: billDiscountPaisa,
        discount_paisa: lineDiscount + billDiscountPaisa,
        tax_paisa: tax,
        exact_total_paisa: exact,
        round_off_paisa: rounding,
        total_paisa: exact - rounding,
    };
}

export default { calculate, discountPaisa, taxPaisa, roundOff, qtyMilli, isWholeInBase, scaled, spread, divRound };
