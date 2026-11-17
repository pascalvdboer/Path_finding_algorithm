/**
 * Accuracy cases for the whole analysis → colours → advice chain (reviewer "accuracy", docs/review-accuracy.md).
 *
 * Every case is a realistic customer upload, generated deterministically with synth.js, plus what a craftsman
 * would say about it according to research 04 (embroidery: caps ≥ 4 mm, satin ≥ 1 mm, run stitch ≥ 0,4 mm, gap ≥ 1 mm,
 * ≤ 12 colours, no photo; woven caps ≥ 2 mm; print caps ≥ 1,6 mm, everything goes; PVC no gradients/photo, ≤ 8 colours).
 *
 * Expectations are deliberately written as RULES, not as snapshots: e.g. "embroidered is not bad", "recommended is a
 * print technique", "measured text within ± 20 % of the drawn cap height". A future change that breaks a rule is a
 * real accuracy regression, a change that only shuffles scores is not.
 *
 * Fields of `expect` (all optional):
 *   kind: string | string[]          analysis.kind
 *   bg: string | string[]            analysis.background.type
 *   keepBg: boolean                  analysis.background.keep
 *   shape: string | null | string[]  analysis.shape.type when fillsPatch, null = does not fill the patch
 *   recommended: string[]            advice.recommended ∈
 *   status: { [tech]: string|string[] }   verdict status per technique (ok|warn|bad)
 *   textMm: [lo, hi]                 measured smallest text (embroidered layout) in mm
 *   lineMm: [lo, hi]                 measured finest line (embroidered layout) in mm
 *   threads: [lo, hi]                advice.colors.maxThreadColors (thread colour suggestion)
 *   warnings: string[]               analysis.warnings ⊇
 *   noWarnings: string[]             analysis.warnings ∩ = ∅
 *   reasons: { [tech]: string[] }    verdict reason codes ⊇
 *   noReasons: { [tech]: string[] }  verdict reason codes ∩ = ∅
 *   resizeTo: [lo, hi]               embroidered resize fix target (cm) when present
 */
import * as S from './synth.js';

/* ------------------------------------------------------------------ helpers */

const W = '#ffffff';
const NAVY = '#1b2a4a', RED = '#c8102e', GOLD = '#d4a72c', GREEN = '#2e7d32', BLACK = '#111111', ORANGE = '#e86a1f';

function star(cx, cy, r0, r1, n = 5) {
  const pts = Array.from({ length: n * 2 }, (_, i) => {
    const a = (i * Math.PI) / n - Math.PI / 2, rr = i % 2 ? r0 : r1;
    return [cx + rr * Math.cos(a), cy + rr * Math.sin(a)];
  });
  return (x, y) => {
    let c = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
      const [xi, yi] = pts[i], [xj, yj] = pts[j];
      if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) c = !c;
    }
    return c;
  };
}

/** Pseudo photo: smooth field with many tones, mild noise (no hard edges). */
function photoField(im, x0 = 0, y0 = 0, w = im.width, h = im.height, inside = null) {
  for (let y = y0; y < y0 + h; y++) for (let x = x0; x < x0 + w; x++) {
    if (inside && !inside(x + 0.5, y + 0.5)) continue;
    const i = (y * im.width + x) * 4;
    im.data[i] = 128 + 90 * Math.sin(x / 37) * Math.cos(y / 23) + 20 * Math.sin(x / 7.3 + y / 11.1);
    im.data[i + 1] = 128 + 90 * Math.sin((x + y) / 53) + 18 * Math.cos(x / 9.7);
    im.data[i + 2] = 128 + 90 * Math.cos(x / 19 - y / 41) + 15 * Math.sin(y / 6.1);
    im.data[i + 3] = 255;
  }
  return im;
}

/** JPEG-like 8×8 block artefacts: each block gets a small constant offset (quantised DC). */
function blocky(im, amp = 4, seed = 11) {
  let s = seed;
  const rnd = () => ((s = (Math.imul(s, 1103515245) + 12345) >>> 0) / 4294967296) * 2 - 1;
  for (let by = 0; by < im.height; by += 8) for (let bx = 0; bx < im.width; bx += 8) {
    const o = Math.round(rnd() * amp);
    for (let y = by; y < Math.min(im.height, by + 8); y++) for (let x = bx; x < Math.min(im.width, bx + 8); x++) {
      const i = (y * im.width + x) * 4;
      for (let k = 0; k < 3; k++) im.data[i + k] = im.data[i + k] + o;
    }
  }
  return im;
}

/** Classic club logo: navy disc, white star inside, red ring. */
function clubLogo(size = 800) {
  const im = S.image(size, size, W);
  const c = size / 2;
  S.paint(im, S.circle(c, c, size * 0.42), NAVY);
  S.paint(im, S.ring(c, c, size * 0.36, size * 0.39), RED);
  S.paint(im, star(c, c, size * 0.12, size * 0.28), W);
  return im;
}


/**
 * Seam-free block text: synth.text() paints every glyph cell as its own anti-aliased square, which leaves
 * light hairline seams inside the letters (1 px "lines" and "gaps" no real font has). Here every glyph is ONE
 * coverage function, like a real rasterised font.
 */
function txt(im, str, x, y, capHeight, color, { spacing = 1.4 } = {}) {
  const cell = capHeight / 7;
  let cx = x;
  for (const ch of str) {
    const g = S.GLYPHS[ch];
    if (g) {
      const gx = cx;
      const inside = (px, py) => {
        const c = Math.floor((px - gx) / cell), r = Math.floor((py - y) / cell);
        return r >= 0 && r < 7 && c >= 0 && c < 5 && g[r][c] === '1';
      };
      S.paint(im, inside, color, { bbox: [gx - 1, y - 1, gx + 5 * cell + 1, y + 7 * cell + 1] });
    }
    cx += 5 * cell + spacing * cell;
  }
  return cx - x;
}

const PRINT = ['printed', 'sublimated'];
const THREADISH = ['embroidered', 'woven'];

/* ------------------------------------------------------------------ cases */

export const CASES = [
  /* ---------- flat logos ---------- */
  {
    id: 'flat-club-logo',
    note: 'Navy disc + red ring + white star on white paper, 800 px. Textbook embroidery.',
    build: () => clubLogo(800),
    expect: { kind: 'flat', bg: 'white', keepBg: false, shape: 'circle', recommended: ['embroidered'], status: { embroidered: 'ok' }, threads: [3, 3] },
  },
  {
    id: 'flat-3col-text',
    note: 'Purple disc, coral bar, bold "CLUB" (cap 70 px of 800) below: text ≈ 6,6 mm at 8 cm.',
    build: () => {
      const im = S.image(800, 600);
      S.paint(im, S.circle(400, 260, 200), '#5926C5');
      S.paint(im, S.rect(250, 200, 300, 120), '#FD5C48');
      txt(im, 'CLUB', 290, 500, 70, '#1E1B1B');
      return im;
    },
    expect: { kind: 'flat', bg: 'white', shape: null, status: { embroidered: ['ok', 'warn'], printed: 'ok' }, textMm: [5, 8.5] },
  },
  {
    id: 'one-colour-silhouette',
    note: 'Black single-colour silhouette (star) on white. 1 thread colour.',
    build: () => { const im = S.image(800, 800); S.paint(im, star(400, 400, 150, 340), BLACK); return im; },
    expect: { kind: 'flat', bg: 'white', recommended: ['embroidered'], status: { embroidered: 'ok' }, threads: [1, 1] },
  },
  {
    id: 'one-colour-text-big',
    note: 'Only "TEAM" in black, cap 160 px over 900 px width: ± 13 mm caps at 8 cm.',
    build: () => { const im = S.image(900, 400); txt(im, 'TEAM', 110, 120, 160, BLACK); return im; },
    expect: { kind: 'flat', recommended: ['embroidered'], status: { embroidered: 'ok', woven: 'ok', printed: 'ok' }, textMm: [9, 16] },
  },
  {
    id: 'two-colour-badge-rect',
    note: 'Red rectangle badge with white "MECKS": shape rect fills the patch.',
    build: () => { const im = S.image(900, 500); S.paint(im, S.rect(100, 100, 700, 300), RED); txt(im, 'MECKS', 200, 190, 120, W); return im; },
    expect: { kind: 'flat', shape: 'rect', recommended: ['embroidered'], status: { embroidered: 'ok' }, threads: [2, 2] },
  },
  {
    id: 'shield-two-colour',
    note: 'Blue shield with a gold bar.',
    build: () => { const im = S.image(600, 700); S.paint(im, S.shield(100, 80, 400, 520), '#1f5aa6'); S.paint(im, S.rect(250, 200, 100, 200), GOLD); return im; },
    expect: { kind: 'flat', shape: 'shield', recommended: ['embroidered'], status: { embroidered: 'ok' } },
  },
  {
    id: 'round-patch-arc-text',
    note: 'Complete round patch on white: navy disc, thin red ring, grey centre, white arc text cap 40/660 px and "CLUB".',
    build: () => {
      const im = S.image(800, 800, W);
      S.paint(im, S.circle(400, 400, 330), NAVY);
      S.paint(im, S.ring(400, 400, 300, 306), '#b3262d');
      S.paint(im, S.circle(400, 400, 250), '#c0c4cc');
      S.arcText(im, 'IRON CRUSADERS', 400, 400, 270, 40, W, -170, 12);
      txt(im, 'CLUB', 300, 370, 60, NAVY);
      return im;
    },
    // 40 px caps on a 660 px circle at 8 cm (≈ 74 mm inside the edge) ≈ 4,5 mm: legal for embroidery, under comfort.
    expect: { kind: 'flat', shape: 'circle', status: { embroidered: ['ok', 'warn'], woven: 'ok', printed: 'ok' }, textMm: [3.6, 5.4] },
  },
  {
    id: 'star-contour',
    note: 'Orange star silhouette: contour shape, merrow edge impossible (holle hoeken).',
    build: () => { const im = S.image(700, 700); S.paint(im, star(350, 350, 120, 300), ORANGE); return im; },
    expect: { kind: 'flat', status: { embroidered: ['ok', 'warn'] } },
  },

  /* ---------- small text ---------- */
  {
    id: 'small-text-fixable',
    note: 'Logo bar + "SMALL TEXT CLUB" cap 30 px on 1200 px width: ± 2 mm caps at 8 cm → embroidery needs a bigger patch.',
    build: () => {
      const im = S.image(1200, 600);
      S.paint(im, S.rect(80, 100, 1040, 40), '#b3262d');
      txt(im, 'SMALL TEXT CLUB', 100, 250, 30, NAVY);
      return im;
    },
    expect: { status: { embroidered: ['warn', 'bad'], printed: 'ok' }, textMm: [1.5, 2.6], reasons: { embroidered: ['text_small'] } },
  },
  {
    id: 'text-just-legal',
    note: 'Bold caps 52 px across 1000 px: ± 4,2 mm at 8 cm → legal, under comfort (6 mm). Never "bad".',
    build: () => {
      const im = S.image(1000, 500);
      S.paint(im, S.rect(60, 60, 880, 120), GREEN);
      txt(im, 'BOSKLUB', 250, 300, 52, BLACK);
      return im;
    },
    expect: { status: { embroidered: ['ok', 'warn'], printed: 'ok' }, textMm: [3.5, 5], noReasons: { embroidered: ['text_small'] } },
  },
  {
    id: 'tiny-text-unfixable',
    note: 'Big emblem + footer text cap 9 px across 1400 px: ± 0,5 mm at 8 cm. Not even 12 cm makes it 4 mm.',
    build: () => {
      const im = S.image(1400, 900);
      S.paint(im, S.circle(700, 380, 330), NAVY);
      S.paint(im, star(700, 380, 110, 250), GOLD);
      txt(im, 'MADE IN BELGIE DOOR ONS TEAM', 380, 800, 9, BLACK, { spacing: 1.6 });
      return im;
    },
    expect: { status: { embroidered: ['warn', 'bad'] } },
  },
  {
    id: 'reverse-small-text',
    note: 'White caps 28 px inside a dark bar (1000 px wide): ± 2,2 mm, white in dark (loopt dicht).',
    build: () => {
      const im = S.image(1000, 400);
      S.paint(im, S.rect(50, 100, 900, 200), NAVY);
      txt(im, 'SECURITY TEAM', 260, 186, 28, W);
      return im;
    },
    expect: { shape: ['rect', null], status: { embroidered: ['warn', 'bad'], printed: 'ok' }, textMm: [1.6, 2.8] },
  },

  /* ---------- lines ---------- */
  {
    id: 'hairline',
    note: 'Blue square + diagonal hairline 3 px over 800 px: ± 0,3 mm at 8 cm (below run-stitch 0,4 mm).',
    build: () => { const im = S.image(800, 800); S.paint(im, S.rect(200, 200, 400, 400), '#1f5aa6'); S.paint(im, S.line(100, 700, 700, 100, 3), BLACK); return im; },
    expect: { status: { embroidered: 'warn', printed: ['ok', 'warn'] }, lineMm: [0.15, 0.6], noReasons: { embroidered: [] } },
  },
  {
    id: 'thin-line-run-stitch',
    note: 'Outline ring 7 px on 800 px (± 0,7 mm): too thin for satin, fine as run stitch → warn, not bad.',
    build: () => { const im = S.image(800, 800); S.paint(im, S.circle(400, 400, 300), GOLD); S.paint(im, S.ring(400, 400, 240, 247), BLACK); return im; },
    expect: { status: { embroidered: 'warn' }, lineMm: [0.45, 1.0] },
  },
  {
    id: 'line-ok',
    note: 'Ring 16 px on 800 px (± 1,5 mm) → satin column ok.',
    build: () => { const im = S.image(800, 800); S.paint(im, S.circle(400, 400, 300), GOLD); S.paint(im, S.ring(400, 400, 230, 246), BLACK); return im; },
    expect: { status: { embroidered: 'ok' }, lineMm: [1.1, 2.0] },
  },
  {
    id: 'small-gap',
    note: 'Two red blocks with a 5 px gap on 800 px (± 0,5 mm gap): embroidery warns (loopt dicht), never bad.',
    build: () => { const im = S.image(800, 800); S.paint(im, S.rect(150, 200, 247, 400), RED); S.paint(im, S.rect(402, 200, 247, 400), RED); return im; },
    expect: { status: { embroidered: ['warn', 'ok'] }, noReasons: { embroidered: [] } },
  },

  /* ---------- gradients ---------- */
  {
    id: 'gradient-full-bleed',
    note: 'Purple→coral gradient full bleed with white "TEAM".',
    build: () => {
      const im = S.image(800, 600);
      S.paint(im, () => true, S.linearGradient(0, 0, 800, 600, '#5926C5', '#FD5C48'));
      txt(im, 'TEAM', 220, 240, 120, W);
      return im;
    },
    expect: { kind: 'gradient', bg: 'none', recommended: PRINT, status: { embroidered: 'warn', printed: 'ok', sublimated: 'ok' }, reasons: { embroidered: ['gradient'] } },
  },
  {
    id: 'gradient-radial-badge',
    note: 'Round badge on white with radial gradient (mint→blue).',
    build: () => { const im = S.image(800, 800); S.paint(im, S.circle(400, 400, 330), S.radialGradient(330, 330, 420, '#52D7B4', '#21A9E2')); return im; },
    expect: { kind: 'gradient', shape: 'circle', recommended: PRINT, status: { printed: 'ok' } },
  },
  {
    id: 'gradient-text-on-white',
    note: 'Logo with gradient letters (black→red) on white: soft shading must be seen.',
    build: () => {
      const im = S.image(900, 500);
      S.paint(im, S.rect(80, 80, 740, 340), S.linearGradient(80, 0, 820, 0, '#ffd54f', '#e65100'));
      txt(im, 'SUN', 260, 170, 160, NAVY);
      return im;
    },
    expect: { kind: ['gradient', 'illustration'], recommended: [...PRINT, 'woven', 'embroidered'], status: { printed: 'ok' } },
  },

  /* ---------- photos ---------- */
  {
    id: 'photo-full-bleed',
    note: 'Pseudo photo, many tones, full bleed.',
    build: () => S.noise(photoField(S.image(900, 600)), 3),
    expect: { kind: 'photo', recommended: PRINT, status: { embroidered: 'bad', printed: 'ok', sublimated: 'ok', pvc: 'bad' } },
  },
  {
    id: 'photo-in-circle',
    note: 'Pseudo photo cropped to a circle on white (portrait badge).',
    build: () => { const im = S.image(800, 800); photoField(im, 0, 0, 800, 800, S.circle(400, 400, 340)); return S.noise(im, 2); },
    expect: { kind: 'photo', shape: 'circle', recommended: PRINT, status: { embroidered: 'bad', printed: 'ok' } },
  },
  {
    id: 'photo-jpg-noisy',
    note: 'Pseudo photo with JPEG blocks and strong noise.',
    build: () => blocky(S.noise(photoField(S.image(1000, 700)), 8), 5),
    expect: { kind: 'photo', recommended: PRINT, status: { embroidered: 'bad' } },
  },

  /* ---------- JPEG noise on flat art ---------- */
  {
    id: 'jpg-flat-logo',
    note: 'The club logo saved as a medium JPEG (noise ± 6, 8×8 blocks): still flat, same thread count.',
    build: () => blocky(S.noise(clubLogo(800), 6), 3),
    expect: { kind: 'flat', bg: 'white', shape: 'circle', recommended: ['embroidered'], status: { embroidered: 'ok' }, threads: [3, 4] },
  },
  {
    id: 'jpg-flat-logo-heavy',
    note: 'Low-quality JPEG (noise ± 12, blocks ± 6): must not become "photo" or "gradient".',
    build: () => blocky(S.noise(clubLogo(800), 12), 6),
    expect: { kind: 'flat', recommended: ['embroidered'], status: { embroidered: ['ok', 'warn'] } },
  },

  /* ---------- white / transparent ---------- */
  {
    id: 'white-on-white-enclosed',
    note: 'Navy disc with white centre disc on a white page: inner white is a thread colour, not paper.',
    build: () => { const im = S.image(800, 600); S.paint(im, S.circle(400, 300, 220), NAVY); S.paint(im, S.circle(400, 300, 150), W); return im; },
    expect: { kind: 'flat', bg: 'white', shape: 'circle', status: { embroidered: 'ok' }, threads: [2, 2] },
  },
  {
    id: 'white-element-touching-paper',
    note: 'White star breaks through the navy disc border into the white page (common in amateur logos).',
    build: () => { const im = S.image(800, 800); S.paint(im, S.circle(400, 400, 300), NAVY); S.paint(im, star(400, 400, 120, 330), W); S.paint(im, star(400, 400, 60, 140), RED); return im; },
    expect: { kind: 'flat', bg: 'white', status: { embroidered: ['ok', 'warn'] } },
  },
  {
    id: 'transparent-flat',
    note: 'Transparent PNG: two-colour emblem without background.',
    build: () => { const im = S.image(700, 700, '#000000', 0); S.paint(im, S.circle(350, 350, 300), GREEN); S.paint(im, star(350, 350, 90, 220), GOLD); return im; },
    expect: { kind: 'flat', bg: 'transparent', keepBg: false, shape: 'circle', recommended: ['embroidered'], status: { embroidered: 'ok' } },
  },
  {
    id: 'transparent-white-shadow',
    note: 'Transparent PNG, white disc with drop shadow (typical export from Canva).',
    build: () => { const im = S.image(600, 600, '#000000', 0); S.paint(im, S.circle(315, 315, 205), '#000000', { alpha: 80 }); S.paint(im, S.circle(300, 300, 200), W); txt(im, 'BOS', 200, 260, 80, NAVY); return im; },
    expect: { bg: 'transparent', warnings: ['transparent_shadow', 'white_on_transparent'], status: { embroidered: ['ok', 'warn'] } },
  },
  {
    id: 'coloured-background-kept',
    note: 'Gold "TEAM" on navy page: navy is the patch background.',
    build: () => { const im = S.image(800, 600, NAVY); txt(im, 'TEAM', 220, 240, 120, GOLD); return im; },
    expect: { kind: 'flat', bg: 'solid', keepBg: true, recommended: ['embroidered'], status: { embroidered: 'ok' } },
  },

  /* ---------- SVG ---------- */
  {
    id: 'svg-flat',
    note: 'Pure vector SVG (rasterised at 2048 px by decodeFile; here 800 px + svgText).',
    build: () => clubLogo(800),
    svgText: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="#1b2a4a"/></svg>',
    expect: { kind: 'flat', recommended: ['embroidered'], status: { embroidered: 'ok' }, noWarnings: ['low_res'] },
  },
  {
    id: 'svg-small-vector',
    note: 'SVG with a tiny pixel size (rasterised small): vector is never low-res.',
    build: () => { const im = S.image(240, 240); S.paint(im, S.circle(120, 120, 100), NAVY); S.paint(im, star(120, 120, 30, 70), W); return im; },
    svgText: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>',
    expect: { kind: 'flat', noWarnings: ['low_res'], status: { printed: 'ok' }, noReasons: { printed: ['low_res'] } },
  },
  {
    id: 'svg-gradient',
    note: 'SVG that uses a linearGradient fill.',
    build: () => { const im = S.image(800, 800); S.paint(im, S.circle(400, 400, 330), S.linearGradient(0, 0, 800, 800, '#5926C5', '#21A9E2')); return im; },
    svgText: '<svg viewBox="0 0 10 10"><defs><linearGradient id="g"><stop/></linearGradient></defs><circle fill="url(#g)" cx="5" cy="5" r="4"/></svg>',
    expect: { kind: 'gradient', warnings: ['svg_gradient'], recommended: PRINT },
  },

  /* ---------- colour counts ---------- */
  {
    id: 'many-colours-flat',
    note: '20 clearly different flat colour tiles (4×5 grid) on white.',
    build: () => {
      const im = S.image(1000, 800);
      const cols = ['#e53935', '#d81b60', '#8e24aa', '#5e35b1', '#3949ab', '#1e88e5', '#039be5', '#00acc1', '#00897b', '#43a047',
        '#7cb342', '#c0ca33', '#fdd835', '#ffb300', '#fb8c00', '#f4511e', '#6d4c41', '#757575', '#546e7a', '#000000'];
      cols.forEach((c, i) => S.paint(im, S.rect(60 + (i % 5) * 180, 60 + Math.floor(i / 5) * 175, 160, 155), c));
      return im;
    },
    expect: { kind: ['flat', 'illustration'], status: { embroidered: 'warn', printed: 'ok', pvc: ['warn', 'bad'] }, threads: [10, 12] },
  },
  {
    id: 'ten-colours-flat',
    note: '10 different flat colours in stripes: ≤ 12 threads, > comfort 8.',
    build: () => {
      const im = S.image(1000, 600);
      const cols = ['#e53935', '#8e24aa', '#1e88e5', '#00897b', '#7cb342', '#fdd835', '#fb8c00', '#6d4c41', '#000000', '#90caf9'];
      cols.forEach((c, i) => S.paint(im, S.rect(50 + i * 90, 80, 80, 440), c));
      return im;
    },
    expect: { kind: 'flat', status: { embroidered: ['ok', 'warn'], printed: 'ok' }, threads: [10, 12] },
  },

  /* ---------- illustration ---------- */
  {
    id: 'illustration-shaded',
    note: 'Outlined shapes with soft radial shading: illustration, not photo.',
    build: () => {
      const im = S.image(800, 600);
      for (const [cx, cy, rr, c1, c2] of [[250, 250, 170, '#f2c48d', '#8a5a2b'], [560, 300, 200, '#9fd3ff', '#1f4e8c'], [400, 480, 90, '#ff9a8a', '#a3271b']]) {
        S.paint(im, S.circle(cx, cy, rr), BLACK);
        S.paint(im, S.circle(cx, cy, rr - 6), S.radialGradient(cx - rr / 3, cy - rr / 3, rr, c1, c2));
      }
      return im;
    },
    expect: { kind: 'illustration', recommended: ['embroidered', 'woven', 'printed', 'sublimated'], status: { embroidered: ['ok', 'warn'], printed: 'ok' }, noReasons: { embroidered: ['photo'] } },
  },
  {
    id: 'illustration-round-patch',
    note: 'Round military-style patch on white with shaded centre emblem and arc text.',
    build: () => {
      const im = S.image(900, 900, W);
      S.paint(im, S.circle(450, 450, 400), '#2f3b1f');
      S.paint(im, S.ring(450, 450, 380, 388), GOLD);
      S.paint(im, S.circle(450, 450, 260), S.radialGradient(380, 380, 300, '#b0bec5', '#263238'));
      S.paint(im, star(450, 450, 70, 170), S.radialGradient(400, 380, 200, '#ffe082', '#b8860b'));
      S.arcText(im, 'RECON UNIT', 450, 450, 325, 50, GOLD, -160, 15);
      return im;
    },
    expect: { kind: ['illustration', 'gradient'], shape: 'circle', status: { printed: 'ok', embroidered: ['ok', 'warn'] }, noReasons: { embroidered: ['photo'] } },
  },

  /* ---------- special ---------- */
  {
    id: 'low-res-logo',
    note: '220 px club logo (screenshot from a website): low resolution warning, print asks for a sharper file.',
    build: () => clubLogo(220),
    expect: { warnings: ['low_res'], status: { embroidered: ['ok', 'warn'] }, reasons: { printed: ['low_res'] } },
  },
  {
    id: 'brand-purple',
    note: 'Brand purple #5926C5 disc with white text: purple must still match a thread (Polyneon has violets).',
    build: () => { const im = S.image(800, 800); S.paint(im, S.circle(400, 400, 330), '#5926C5'); txt(im, 'BSB', 250, 330, 140, W); return im; },
    expect: { kind: 'flat', recommended: ['embroidered'], status: { embroidered: ['ok', 'warn'] } },
  },
  {
    id: 'phone-photo-patch',
    note: 'Tilted phone photo of a round patch on cream fabric.',
    build: () => {
      const im = S.noise(S.image(700, 1000, '#f2efe6'), 5);
      S.paint(im, S.ellipse(350, 450, 330, 300, 0.3), '#202020');
      S.paint(im, S.ellipse(350, 450, 250, 227, 0.3), GOLD);
      return S.noise(im, 4, 3);
    },
    expect: { shape: 'circle', warnings: ['patch_photo_cut'], status: { embroidered: ['ok', 'warn'] } },
  },
  {
    id: 'big-text-puff',
    note: 'Big bold single-colour initials (cap 300 of 800 px): candidate for 3D-puff upsell, embroidery ok.',
    build: () => { const im = S.image(800, 800, W); S.paint(im, S.circle(400, 400, 360), RED); txt(im, 'BK', 220, 250, 300, W); return im; },
    expect: { kind: 'flat', recommended: ['embroidered'], status: { embroidered: 'ok' } },
  },
];
