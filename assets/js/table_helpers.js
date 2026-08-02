/*
 * Small, framework-free helpers shared by the client-side table controllers
 * (sorting and column filtering). Kept out of the controllers themselves so the
 * German-aware number parsing lives in one place instead of being duplicated.
 */

/**
 * Parse a human-typed decimal that may use German grouping (1.234,56), English
 * grouping (1,234.56) or none at all. Returns a Number with its sign preserved,
 * or null when nothing numeric remains.
 *
 * The heuristic mirrors the bank-import amount parser: a single separator
 * followed by exactly three digits (e.g. "1.000") is read as a thousands group,
 * otherwise the last separator in the string is treated as the decimal point.
 *
 * @param {string|number} raw
 * @returns {number|null}
 */
export function parseDecimal(raw) {
    let value = String(raw ?? '').trim().replace(/[^\d,.\-+]/g, '');
    if (value === '' || value === '-' || value === '+') return null;

    const sign = value.startsWith('-') ? -1 : 1;
    value = value.replace(/[+-]/g, '');

    const lastComma = value.lastIndexOf(',');
    const lastDot = value.lastIndexOf('.');
    if (lastComma >= 0 || lastDot >= 0) {
        // The rightmost of the two separators wins as the potential decimal mark.
        const decimal = lastComma > lastDot ? ',' : '.';
        const decimalPos = value.lastIndexOf(decimal);
        const digitsAfter = value.length - decimalPos - 1;

        // "1.000"/"1,000": a lone separator with three trailing digits and no
        // other separator is a thousands group, not a decimal fraction.
        const isThousandsOnly = digitsAfter === 3 && /^\d{1,3}[.,]\d{3}$/.test(value);
        if (isThousandsOnly) {
            value = value.split(decimal).join('');
        } else {
            const thousands = decimal === ',' ? '.' : ',';
            value = value.split(thousands).join('').replace(decimal, '.');
        }
    }

    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? null : sign * parsed;
}

/**
 * Build an Intl.Collator for locale-aware, case-insensitive text sorting that
 * also orders embedded numbers naturally (so "Row 2" sorts before "Row 10").
 *
 * @param {string} [locale] BCP-47 tag; falls back to the document language.
 * @returns {Intl.Collator}
 */
export function createTableCollator(locale) {
    return new Intl.Collator(
        locale || document.documentElement.lang || undefined,
        { numeric: true, sensitivity: 'base' },
    );
}
