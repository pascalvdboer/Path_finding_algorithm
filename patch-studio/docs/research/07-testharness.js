// Kalibratie-harness beeldanalyse. Draai: NODE_PATH=$(npm root -g) node docs/research/07-testharness.js
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const REF = '/home/user/Path_finding_algorithm/patch-studio/reference/';
const DL = '/home/user/Path_finding_algorithm/patch-studio/reference/testbeelden/';
const lib = fs.readFileSync('/home/user/Path_finding_algorithm/patch-studio/docs/research/07-beeldanalyse-ref.js', 'utf8');
const durl = (f, m) => `data:${m};base64,` + fs.readFileSync(f).toString('base64');
(async () => {
  const b = await chromium.launch(); const pg = await b.newPage();
  await pg.setContent('<html><body></body></html>'); await pg.addScriptTag({ content: lib });
  pg.on('console', m => console.log(m.text()));
  const imgs = {
    ownerPng: [durl(REF + 'eigenaar-ronde-patch.png', 'image/png'), { cx: .5, cy: .5, r: .44 }],
    ownerJpg: [durl(REF + 'eigenaar-foto-patch.jpg', 'image/jpeg'), { cx: .498, cy: .368, r: .46 }],
    tourist: [durl(DL + 'tourist-and-building.webp', 'image/webp')],
    gallery: [durl(DL + 'art-gallery.webp', 'image/webp')],
    stairs: [durl(DL + 'green-staircase.webp', 'image/webp')],
    abstract: [durl(DL + 'abstract-geometric-art.webp', 'image/webp')],
  };
  const res = await pg.evaluate(async (imgs) => {
    const A = PSAnalyse, out = {};
    const load = src => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
    const synth = (kind) => { const c = document.createElement('canvas'); c.width = 800; c.height = 600; const x = c.getContext('2d');
      x.fillStyle = '#fff'; x.fillRect(0, 0, 800, 600);
      if (kind === 'flat') { x.fillStyle = '#5926C5'; x.beginPath(); x.arc(400, 260, 200, 0, 7); x.fill(); x.fillStyle = '#FD5C48'; x.fillRect(250, 200, 300, 120);
        x.fillStyle = '#1E1B1B'; x.font = 'bold 90px sans-serif'; x.textAlign = 'center'; x.fillText('CLUB', 400, 560); }
      if (kind === 'grad') { const g = x.createLinearGradient(0, 0, 800, 600); g.addColorStop(0, '#5926C5'); g.addColorStop(1, '#FD5C48'); x.fillStyle = g; x.fillRect(0, 0, 800, 600);
        x.fillStyle = '#fff'; x.font = 'bold 120px sans-serif'; x.textAlign = 'center'; x.fillText('TEAM', 400, 340); }
      if (kind === 'radial') { const g = x.createRadialGradient(400, 300, 10, 400, 300, 400); g.addColorStop(0, '#52D7B4'); g.addColorStop(1, '#21A9E2'); x.fillStyle = g; x.fillRect(0, 0, 800, 600); }
      if (kind === 'illus') { for (const [cx,cy,r,c1,c2] of [[250,250,170,'#f2c48d','#8a5a2b'],[560,300,200,'#9fd3ff','#1f4e8c'],[400,480,90,'#ff9a8a','#a3271b']]) {
          const g = x.createRadialGradient(cx-r/3, cy-r/3, 5, cx, cy, r); g.addColorStop(0, c1); g.addColorStop(1, c2); x.fillStyle = g; x.beginPath(); x.arc(cx, cy, r, 0, 7); x.fill(); x.lineWidth = 8; x.strokeStyle = '#111'; x.stroke(); } }
      return c; };
    const toJpg = async (c, q) => load(c.toDataURL('image/jpeg', q));
    const srcs = {};
    for (const [k, v] of Object.entries(imgs)) srcs[k] = [await load(v[0]), v[1]];
    srcs.flatPng = [synth('flat')]; srcs.flatJpg60 = [await toJpg(synth('flat'), 0.6)]; srcs.flatJpg30 = [await toJpg(synth('flat'), 0.3)];
    srcs.gradPng = [synth('grad')]; srcs.gradJpg = [await toJpg(synth('grad'), 0.7)]; srcs.radial = [synth('radial')]; srcs.illus = [synth('illus')]; srcs.illusJpg = [await toJpg(synth('illus'),0.7)];
    for (const [k, [im, circ]] of Object.entries(srcs)) {
      const t0 = performance.now();
      const sw = im.naturalWidth || im.width, sh = im.naturalHeight || im.height, s = Math.min(1, A.TH.ANALYSE_PX / Math.max(sw, sh));
      const c = document.createElement('canvas'); c.width = Math.round(sw * s); c.height = Math.round(sh * s);
      const x = c.getContext('2d', { willReadFrequently: true }); x.drawImage(im, 0, 0, c.width, c.height);
      const W = c.width, H = c.height, d = x.getImageData(0, 0, W, H).data;
      let mask = null;
      if (circ) { mask = new Uint8Array(W * H); const cx = circ.cx * W, cy = circ.cy * H, r = circ.r * Math.min(W, H) * (circ.r > .45 ? 1 : 1);
        const R = circ.r * (k === 'ownerJpg' ? W : W); for (let y = 0; y < H; y++) for (let xx = 0; xx < W; xx++) mask[y * W + xx] = ((xx - cx) ** 2 + (y - cy) ** 2) < R * R ? 1 : 0; }
      const pts = A.labHistogram(d, mask), ap = A.autoPalette(pts), es = A.edgeStats(d, W, H, mask), cov = A.cover85(d, mask);
      const le = A.labelEdges(es.lab, W, H, mask, ap.pal);
      const f = { fake: le.fakeEdge, chg: le.changeRatio, residual: ap.residual, flat: es.flat, soft: es.soft, hard: es.hard, texture: es.texture, cover: cov, palSize: ap.pal.length };
      out[k] = { ...Object.fromEntries(Object.entries(f).map(([a, b]) => [a, +b.toFixed(3)])), cls: A.classify(f), ms: Math.round(performance.now() - t0), pal: ap.pal.map(p => '#' + p.rgb.map(v => v.toString(16).padStart(2, '0')).join('') + ':' + (p.share * 100).toFixed(1)).join(' ') };
    }
    return out;
  }, imgs);
  for (const [k, v] of Object.entries(res)) console.log(k.padEnd(10), JSON.stringify(v));
  await b.close();
})();
