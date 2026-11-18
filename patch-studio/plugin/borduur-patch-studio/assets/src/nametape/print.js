/**
 * Naamstroken — productielijst / digitale proef (SPEC-EXTRA §1.8). De beheerpagina `admin-post.php?action=bps_nt_print`
 * zet een root `[data-bps-nt-print]` met een JSON-blok `script.bps-nt-print-data`:
 *   { cfg:{fabrics, letterColors, borders, fonts, fit, metrics}, style:{fabric, letter, border, borderColor, font},
 *     format:{w,h,lines}, bloodFormat:{w,h,lines}|null, rows:[{lines, capMm, bloodTape:{text,capMm}|null}], fontsUrl }
 * Elke `<canvas data-bps-nt-row="i">` (en `data-bps-nt-blood="i"`) krijgt dezelfde tekening als in de klantpagina.
 */
import { renderInto } from './render.js';

const byId = (list, id) => (list || []).find((x) => x && x.id === id) || null;

/** Zelfde stijlobjecten als ui.js (styleObjects), maar zonder store. */
export function printSpecBase(cfg, style) {
  const fabric = byId(cfg.fabrics, style.fabric) || { hex: '#1c1c1c' };
  const letter = byId(cfg.letterColors, style.letter) || { hex: '#ffffff' };
  const border = byId(cfg.borders, style.border) || { borderMm: 0 };
  const bc = style.border === 'satin_color' ? byId(cfg.letterColors, style.borderColor || style.letter) || letter : letter;
  const font = byId(cfg.fonts, style.font) || (cfg.fonts || [])[0] || { id: 'block', family: 'sans-serif', weight: 700 };
  const fm = (cfg.metrics || {})[font.id] || {};
  return {
    fabric,
    letterHex: letter.hex,
    borderHex: border.borderMm > 0 ? bc.hex : null,
    borderMm: border.borderMm || 0,
    font: { ...font, capHeightEm: fm.capHeightEm || 0.72, loadedFamily: null },
    lineGapRatio: (cfg.fit && cfg.fit.lineGapRatio) || 0.35,
  };
}

/** @param {HTMLElement} root */
export function renderPrint(root) {
  const node = root && root.querySelector('script.bps-nt-print-data');
  if (!node) return false;
  let data;
  try { data = JSON.parse(node.textContent || '{}'); } catch (e) { return false; }
  const base = printSpecBase(data.cfg || {}, data.style || {});
  const rows = data.rows || [];

  const draw = (loadedFamily) => {
    const font = { ...base.font, loadedFamily };
    root.querySelectorAll('canvas[data-bps-nt-row]').forEach((c) => {
      const r = rows[Number(c.getAttribute('data-bps-nt-row'))];
      if (!r || !data.format) return;
      renderInto(c, { ...base, font, format: data.format, lines: r.lines, capMm: r.capMm }, { maxCssW: Number(c.getAttribute('data-w')) || 280 });
    });
    root.querySelectorAll('canvas[data-bps-nt-blood]').forEach((c) => {
      const r = rows[Number(c.getAttribute('data-bps-nt-blood'))];
      if (!r || !r.bloodTape || !data.bloodFormat) return;
      renderInto(c, { ...base, font, format: data.bloodFormat, lines: [r.bloodTape.text], capMm: r.bloodTape.capMm }, { maxCssW: 140 });
    });
  };
  draw(null);

  // Echt lettertype laden (zelfde woff2 als de klantpagina); faalt het, dan blijft de systeemletter staan.
  const f = byId((data.cfg || {}).fonts, (data.style || {}).font);
  if (f && data.fontsUrl && typeof FontFace !== 'undefined') {
    const fam = 'BPSNT-' + f.id;
    new FontFace(fam, `url(${data.fontsUrl}${f.file}) format("woff2")`, { weight: String(f.weight || 400) })
      .load()
      .then((ff) => { document.fonts.add(ff); draw(fam); })
      .catch(() => {});
  }
  const btn = root.querySelector('[data-bps-nt-print-btn]');
  if (btn) btn.addEventListener('click', () => window.print());
  root.setAttribute('data-bps-nt-ready', '1');
  return true;
}
