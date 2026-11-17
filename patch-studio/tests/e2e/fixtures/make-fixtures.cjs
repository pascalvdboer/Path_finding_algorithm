'use strict';
/**
 * Generates deterministic test images for the e2e scenarios (no image libraries needed).
 *   logo-flat.png  : 640×640 flat 3-colour logo on white (navy disc, white ring, coral star, blue bar)
 * Run: node tests/e2e/fixtures/make-fixtures.cjs   (output is committed next to this file)
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

function crc32(buf) {
  let c, crc = 0xffffffff;
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function png(w, h, pixel) {
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) {
      const [r, g, b] = pixel(x, y);
      const o = y * (w * 3 + 1) + 1 + x * 3;
      raw[o] = r; raw[o + 1] = g; raw[o + 2] = b;
    }
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}
const hex = (s) => [parseInt(s.slice(1, 3), 16), parseInt(s.slice(3, 5), 16), parseInt(s.slice(5, 7), 16)];
const WHITE = hex('#FFFFFF'), NAVY = hex('#0A1F44'), CORAL = hex('#FD5C48'), BLUE = hex('#21A9E2');

// 5-point star test (point-in-polygon, even-odd).
const star = [];
for (let i = 0; i < 10; i++) {
  const a = -Math.PI / 2 + (i * Math.PI) / 5;
  const r = i % 2 ? 62 : 150;
  star.push([320 + r * Math.cos(a), 290 + r * Math.sin(a)]);
}
function inPoly(x, y, poly) {
  let inside = false;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
    const [xi, yi] = poly[i], [xj, yj] = poly[j];
    if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
  }
  return inside;
}
const logo = png(640, 640, (x, y) => {
  const d = Math.hypot(x - 320, y - 320);
  if (d > 270) return WHITE;
  if (d > 250) return NAVY;
  if (d > 236) return WHITE;
  if (inPoly(x + 0.5, y + 0.5, star)) return CORAL;
  if (y >= 450 && y < 486 && x >= 190 && x < 450) return BLUE;
  return NAVY;
});
fs.writeFileSync(path.join(__dirname, 'logo-flat.png'), logo);
console.log('wrote logo-flat.png', logo.length, 'bytes');
