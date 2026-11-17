/**
 * Patch Studio admin bundle (window.BPSAdmin): production cards on the order screen and the printable sheet.
 * Built by scripts/build.mjs → assets/dist/patch-studio-admin.js (+ .css). Budget 15 kB gzip (SPEC §5.3).
 */
import './admin.css';
import { encodeQr, qrSvg } from './qr.js';
import { initProduction } from './production.js';

export const version = '1.0.0';
export { encodeQr, qrSvg, initProduction };

/** Render QR codes into [data-bps-qr] elements (printable sheet). */
export function renderQrCodes(scope) {
  (scope || document).querySelectorAll('[data-bps-qr]').forEach((el) => {
    if (el.firstChild) return;
    const svg = qrSvg(el.getAttribute('data-bps-qr') || '');
    if (svg) el.innerHTML = svg;
  });
}

function initPrintPage() {
  const body = document.body;
  if (!body || !body.classList.contains('bps-fiche-print')) return;
  renderQrCodes(document);
  document.querySelectorAll('[data-bps-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  if (body.getAttribute('data-bps-autoprint') === '1') {
    // Wait for the preview images so they appear on paper.
    const imgs = Array.from(document.images).filter((i) => !i.complete);
    let left = imgs.length;
    const go = () => window.setTimeout(() => window.print(), 150);
    if (!left) go();
    else imgs.forEach((i) => {
      const done = () => {
        left -= 1;
        if (left === 0) go();
      };
      i.addEventListener('load', done, { once: true });
      i.addEventListener('error', done, { once: true });
    });
  }
}

export function boot() {
  initPrintPage();
  document.querySelectorAll('[data-bps-production]').forEach((el) => initProduction(el));
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
