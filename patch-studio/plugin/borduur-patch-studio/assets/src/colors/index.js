/**
 * Patch Studio — colors module public API (SPEC §7.3). Other modules import ONLY from this file.
 * Pure (no DOM): main thread, worker and Node.
 *
 * Contract exports: prepareThreads, matchColor, matchPalette, reducePalette, contrastAgainst, pickFabric,
 * pickEdgeColor, threadByCode. Everything else is an extra.
 */
export {
  prepareThreads,
  matchColor,
  matchPalette,
  threadByCode,
  qualityFor,
  QUALITY_LIMITS,
  nearestThreads,
  specialThread,
  normalizeHex,
} from './match.js';
export { reducePalette, reducedHexes, distinctThreadCount } from './reduce.js';
export { contrastAgainst, pickFabric, pickEdgeColor, worstContrast } from './contrast.js';
export {
  threadLabel,
  qualityLabel,
  colorDisclaimer,
  describeMatch,
  describeMerges,
  describeContrast,
  describeFabric,
} from './describe.js';
export { default as colorMessages } from './messages.js';
