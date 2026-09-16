/**
 * Rupee helpers.
 *
 * Money is stored in the database as an integer number of paisa so that no
 * total is ever the result of floating-point arithmetic. Everything on the
 * client works in paisa too, and only formats to rupees for display.
 */

const formatter = new Intl.NumberFormat('en-PK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const wholeFormatter = new Intl.NumberFormat('en-PK', {
    maximumFractionDigits: 0,
});

/**
 * Format an integer paisa amount as a rupee string, e.g. 123450 -> "1,234.50".
 */
function format(paisa) {
    return formatter.format(Math.round(Number(paisa) || 0) / 100);
}

/**
 * Format with the currency prefix, e.g. 123450 -> "Rs. 1,234.50".
 */
function withSymbol(paisa) {
    return `Rs. ${format(paisa)}`;
}

/**
 * Format dropping the decimals — used on dashboard tiles and tab badges where
 * the paisa are noise.
 */
function rounded(paisa) {
    return `Rs. ${wholeFormatter.format(Math.round((Number(paisa) || 0) / 100))}`;
}

/**
 * Parse user keyboard input in rupees into integer paisa.
 * Tolerates commas, spaces and a leading "Rs".
 */
function parse(input) {
    const cleaned = String(input ?? '')
        .replace(/[^0-9.-]/g, '')
        .trim();

    if (cleaned === '' || cleaned === '-' || cleaned === '.') {
        return 0;
    }

    return Math.round(parseFloat(cleaned) * 100) || 0;
}

export default { format, withSymbol, rounded, parse };
