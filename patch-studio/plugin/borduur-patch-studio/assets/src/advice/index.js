/**
 * Patch Studio — advice module public API (SPEC §7.7). Other modules import ONLY from this file.
 * Pure (no DOM): main thread, worker and Node.
 *
 * Contract exports: evaluate(input) → AdviceResult, messages ({nl, fr} catalogue with 'advice.*' keys).
 * Extras: measure() (fineLineMm/minTextMm for the payload), adviceMessages (alias), helpers for tests/UI.
 */
export { evaluate } from './evaluate.js';
export { default as messages, default as adviceMessages } from './messages.js';
export { measure, layoutFor, optionsForTechnique, minSizeForTechnique } from './measure.js';
