/**
 * Patch Studio — light SVG source inspection (07 §3.2). String based (no DOMParser) so it runs in a Worker and Node.
 */

/**
 * @param {string|null} svgText
 * @returns {{hasGradient:boolean, hasEmbeddedRaster:boolean, hasText:boolean, width:number|null, height:number|null}|null}
 */
export function svgInfo(svgText) {
  if (!svgText || typeof svgText !== 'string') return null;
  const t = svgText.slice(0, 2_000_000);
  const gradIds = [...t.matchAll(/<(?:linear|radial)Gradient\b[^>]*\bid\s*=\s*["']([^"']+)["']/gi)].map((m) => m[1]);
  // A gradient only matters when something actually paints with it.
  const hasGradient = gradIds.some((id) => new RegExp(`url\\(\\s*['"]?#${id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}['"]?\\s*\\)`).test(t))
    || (/<(?:linear|radial)Gradient\b/i.test(t) && !gradIds.length);
  const hasEmbeddedRaster = /<image\b[^>]*(?:xlink:)?href\s*=\s*["']\s*data:image\/(?:png|jpe?g|webp|gif)/i.test(t)
    || /<image\b[^>]*(?:xlink:)?href\s*=\s*["'][^"']+\.(?:png|jpe?g|webp|gif)["']/i.test(t);
  const hasText = /<text\b/i.test(t);
  const root = /<svg\b[^>]*>/i.exec(t);
  let width = null, height = null;
  if (root) {
    const num = (attr) => {
      const m = new RegExp(`\\s${attr}\\s*=\\s*["']\\s*([\\d.]+)\\s*(px)?\\s*["']`, 'i').exec(root[0]);
      return m ? parseFloat(m[1]) : null;
    };
    width = num('width'); height = num('height');
    const vb = /\sviewBox\s*=\s*["']\s*([-\d.]+)[\s,]+([-\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["']/i.exec(root[0]);
    if ((!width || !height) && vb) { width = parseFloat(vb[3]); height = parseFloat(vb[4]); }
  }
  return { hasGradient, hasEmbeddedRaster, hasText, width, height };
}
