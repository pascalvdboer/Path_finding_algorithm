// Shared helpers for the pricing tests (owner: pricing builder). Mirror: functions in run-php.php.
import { readFileSync } from 'node:fs';
import { deepMerge } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';

export const root = new URL('../../', import.meta.url);
export const defaults = JSON.parse(readFileSync(new URL('plugin/borduur-patch-studio/config/defaults.json', root)));
export const vectors = JSON.parse(readFileSync(new URL('tests/pricing/vectors.json', root)));
export const configFor = (overrides) => deepMerge(defaults, overrides || {});

/** Value in a quote for an expect key: aliases from SPEC §7.5, otherwise a dotted path. */
export function pick(quote, key) {
  if (key === 'errors') return quote.errors.map((e) => e.code);
  if (key === 'shippingIncl') return quote.shipping.incl;
  if (key === 'tierDiscount') return quote.tier.discount;
  let v = quote;
  for (const part of key.split('.')) {
    if (v == null) return undefined;
    v = v[part];
  }
  return v;
}

/** Strict deep equality (numbers with ===; null matches undefined). */
export function same(a, b) {
  if (a == null || b == null) return a == null && b == null;
  if (typeof a !== 'object' || typeof b !== 'object') return a === b;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  return ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && same(a[k], b[k]));
}

/** List of mismatches for one vector: [{key, expected, actual}]. */
export function check(vector, quote) {
  const out = [];
  for (const [key, expected] of Object.entries(vector.expect)) {
    const actual = pick(quote, key);
    if (!same(actual, expected)) out.push({ key, expected, actual });
  }
  return out;
}
