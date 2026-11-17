#!/usr/bin/env node
/**
 * Statische dev-server voor dev/index.html (eigenaar: architect). Geen afhankelijkheden.
 *   npm run dev  →  http://localhost:8787/dev/
 * Serveert de map patch-studio/ (zodat ../plugin/... paden werken).
 */
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { resolve, dirname, extname, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const port = Number(process.env.PORT || 8787);
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.map': 'application/json' };

createServer(async (req, res) => {
  try {
    let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (p.endsWith('/')) p += 'index.html';
    const file = normalize(resolve(root, '.' + p));
    if (!file.startsWith(root)) { res.writeHead(403).end(); return; }
    const st = await stat(file);
    if (st.isDirectory()) { res.writeHead(302, { Location: p + '/' }).end(); return; }
    res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    res.end(await readFile(file));
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain' }).end('Niet gevonden');
  }
}).listen(port, () => console.log(`Patch Studio dev: http://localhost:${port}/dev/`));
