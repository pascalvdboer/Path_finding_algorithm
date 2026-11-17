/**
 * Patch Studio — eventnamen (eigenaar: architect, BEVROREN contract). Zie SPEC.md §7.8.
 * Alle events zijn CustomEvents op het root-element (.bps-studio), bubbles:true, detail = payload hieronder.
 * Externe code (analytics van de eigenaar) kan luisteren op document.
 */
export const EV = Object.freeze({
  /** detail: { file?: {name,type,size}, mode: 'upload'|'text' } */
  DESIGN_START: 'bps:design-start',
  /** detail: { stage: 'decode'|'background'|'shape'|'colors'|'details'|'preview'|'done', partial?: object } */
  ANALYSIS_PROGRESS: 'bps:analysis-progress',
  /** detail: { result: AnalysisResult } */
  ANALYSIS_DONE: 'bps:analysis-done',
  /** detail: { code: string, message: string } */
  ANALYSIS_ERROR: 'bps:analysis-error',
  /** detail: { state: DesignState, changed: string[] } — na elke state-wijziging (gedebounced 0 ms) */
  STATE: 'bps:state',
  /** detail: { advice: AdviceResult } */
  ADVICE: 'bps:advice',
  /** detail: { quote: PriceQuote, source: 'client'|'server' } */
  QUOTE: 'bps:quote',
  /** detail: { step: 1|2|3|4|5 } */
  STEP: 'bps:step',
  /** detail: { designId, ref, cartUrl, cartCount } */
  ADDED_TO_CART: 'bps:added-to-cart',
  /** detail: { ref } */
  QUOTE_REQUESTED: 'bps:quote-requested',
  /** detail: { designId, email } */
  DESIGN_SAVED: 'bps:design-saved',
  /** detail: { code, message, where } */
  ERROR: 'bps:error',
  /** detail: { event: string, props: object } — enkel verstuurd als analytics-toestemming bestaat */
  TRACK: 'bps:track',
});

/** Hulpje: dispatch op element */
export function emit(el, name, detail) {
  el.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
}
