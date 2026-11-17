/**
 * Patch Studio — geld: afronding en notatie (eigenaar: architect, BEVROREN contract).
 * De PHP-prijsengine MOET exact hetzelfde afrondingsalgoritme gebruiken (zie SPEC.md §6.3):
 *   PHP: $s = $x < 0 ? -1 : 1; return $s * floor( (float) sprintf( '%.15g', abs( $x ) * 10 ** $d ) + 0.5 ) / 10 ** $d;
 */

/**
 * Half-up afronding op d decimalen, robuust tegen float-ruis (230.175 → 230.18).
 * Werkt via 15 significante cijfers, identiek aan de PHP-variant.
 * @param {number} x @param {number} [d=2]
 */
export function roundHalfUp(x, d = 2) {
  const s = x < 0 ? -1 : 1;
  const f = 10 ** d;
  return (s * Math.floor(Number((Math.abs(x) * f).toPrecision(15)) + 0.5)) / f;
}
export const round2 = (x) => roundHalfUp(x, 2);
export const round4 = (x) => roundHalfUp(x, 4);

const fmtCache = new Map();
/**
 * Bedrag als tekst: nl-BE "€ 5,98", fr-BE "5,98 €".
 * @param {number} amount @param {string} [locale='nl-BE'] @param {string} [currency='EUR']
 */
export function formatMoney(amount, locale = 'nl-BE', currency = 'EUR') {
  const key = locale + currency;
  let f = fmtCache.get(key);
  if (!f) {
    f = new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 });
    fmtCache.set(key, f);
  }
  // Intl gebruikt een harde spatie (U+00A0) tussen € en bedrag: dat is gewenst (geen regelafbreking).
  return f.format(round2(amount));
}

/**
 * Getal met komma (nl/fr): formatNumber(2.5, 'nl-BE', 1) → "2,5".
 */
export function formatNumber(n, locale = 'nl-BE', maxDecimals = 1) {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: maxDecimals }).format(n);
}
