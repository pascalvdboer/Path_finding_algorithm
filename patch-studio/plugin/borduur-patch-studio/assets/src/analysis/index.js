/**
 * Patch Studio — analysis module public API (SPEC §7.2). Import only from here.
 *   decodeFile(file, config)                         main thread: File → {kind, ext, bitmap|imageData, svgText, reason}
 *   createAnalyzer({workerUrl, config})              → {analyze(decoded,{job,onProgress}), cancel(job), terminate()}
 *   analyzeImage(input, config, {job, svgText, onProgress})   pure core (Node, worker, main thread) → AnalysisResult
 *   estimateStitches(regions, mmPerPx, colorCount, stitchCfg, {perimeterMm, satinBorder}) → {estimate, min, max}
 * Extra exports: messages (NL/FR catalogue 'analysis.*'), ANALYSIS_DEFAULTS, analysisConfig, rasterizeSvg, svgInfo.
 * Note: importing analyzeImage pulls the whole core into the bundle; the UI normally only needs
 * decodeFile + createAnalyzer + estimateStitches (the core then lives in the worker bundle only).
 */
/*
 * INTEGRATION NOTES (contract clarifications; the external SPEC §7.2 contract is unchanged)
 * 1. Coordinates: every Box / *Px value is in "analysis px" = whole source scaled by pxPerSrcPx
 *    (size = round(src × pxPerSrcPx)). The pass-B label grid is exactly artBox; textLines[].box uses the same frame
 *    (use box.x − artBox.x). For a rectified phone photo the frame is the rectified image.
 * 2. preview + labels: same size, cover artBox (contain, never cropped). Outside the patch shape / background:
 *    alpha 0, label 255. Design edges are halo-decontaminated (no white fringe on dark fabric).
 * 3. Extra field `backdrop` {width,height,data,artBox} only when background.keep: the whole source at preview scale,
 *    so render can continue a DRAWN background up to the patch edge (BRIEF "always a full patch"). Transferred.
 * 4. Extra field regions.bands [[widthPx, areaPx²],…]: estimateStitches() uses it to classify run/satin/fill
 *    correctly for any size; the fixed fields are classified at sizes.defaultCm.
 * 5. onProgress partials are provisional (e.g. background.type may become 'white' for fabric under a patch photo).
 * 6. Worker sends {type:'ready', offscreen} at start-up. Without OffscreenCanvas in the worker the main thread
 *    converts the bitmap to ImageData (≤2048 px) and sends `srcSize` so src / pxPerSrcPx / lowRes stay correct.
 * 7. A new analyze() rejects the previous promise with code 'cancelled' (ignore silently). On 'worker_failed'
 *    (crash mid-job; the bitmap was transferred) call decodeFile() again and analyze(): it then runs on the main
 *    thread by loading config.assets.workerUrl as a classic <script> (keep that script out of WP Rocket delay/combine).
 * 8. Text detection is deliberately conservative (confidence ≥ 0.45, skipped for kind 'photo'): no textLines does
 *    NOT prove there is no small text (e.g. textured mock-ups); fineLinePx still covers thin strokes.
 * 9. UI: add `messages` (analysis.stage.*, analysis.found.*, analysis.decode.*, analysis.error) to createT().
 */
export { decodeFile, rasterizeSvg } from './decode.js';
export { createAnalyzer } from './analyzer.js';
export { analyzeImage, ANALYSIS_DEFAULTS, analysisConfig } from './core.js';
export { estimateStitches } from './stitches.js';
export { svgInfo } from './svg-info.js';
export { default as messages } from './messages.js';
