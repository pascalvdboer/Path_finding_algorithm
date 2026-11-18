/**
 * Naamstroken — lijst plakken / CSV inlezen (SPEC-EXTRA §1.3). Pure functie, enkel client.
 * De server krijgt gestructureerde rijen en valideert opnieuw (includes/nametape/class-validator.php).
 */

const BLOOD_RE = /^(?:(?:BT|BG)\s*:?\s*)?(AB|A|B|O|0)\s*(POS|NEG|POSITIEF|NEGATIEF|POSITIVE|NEGATIVE|POSITIF|NEGATIF|\+|-|−)$/i;
const QTY_RE = /^\d{1,2}$/;

const KEYWORDS = {
  name: ['naam', 'name', 'nom', 'achternaam', 'naam op strook', 'nom et prenom', 'nom prenom'],
  line2: ['functie', 'fonction', 'function', 'rang', 'grade', 'rank', 'afdeling', 'service', 'regel 2', 'regel2', 'ligne 2', 'ligne2'],
  blood: ['bloedgroep', 'blood', 'blood type', 'bloodtype', 'groupe sanguin', 'bt', 'bg'],
  qty: ['aantal', 'qty', 'quantite', 'quantity', 'stuks', 'pieces', 'pcs', 'nombre'],
};

/** Kopcel opschonen: kleine letters, zonder accenten, enkel letters/cijfers/spaties. */
function foldHeader(s) {
  return String(s).normalize('NFD').replace(/\p{M}+/gu, '').toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
}

/** Bloedgroep herkennen → {group, rh} of null. */
export function parseBlood(s) {
  if (s && typeof s === 'object') {
    let g = String(s.group || '').toUpperCase();
    if (g === '0') g = 'O';
    return ['O', 'A', 'B', 'AB'].includes(g) && (s.rh === '+' || s.rh === '-') ? { group: g, rh: s.rh } : null;
  }
  const t = String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
  const m = t.match(BLOOD_RE);
  if (!m) return null;
  let g = m[1].toUpperCase();
  if (g === '0') g = 'O';
  const r = m[2].toUpperCase();
  return { group: g, rh: r === '+' || r.startsWith('POS') ? '+' : '-' };
}

/** Eén regel splitsen met aanhalingstekens ("" = aanhalingsteken). */
export function splitLine(line, delim) {
  if (!delim) return [line];
  const out = [];
  let cur = '', q = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (q) {
      if (c === '"') {
        if (line[i + 1] === '"') { cur += '"'; i++; } else q = false;
      } else cur += c;
    } else if (c === '"' && cur.trim() === '') {
      q = true; cur = '';
    } else if (c === delim) {
      out.push(cur); cur = '';
    } else cur += c;
  }
  out.push(cur);
  return out.map((c) => c.replace(/ {2,}/g, ' ').trim());
}

function normalizeText(text) {
  return String(text == null ? '' : text)
    .replace(/^\uFEFF/, '')
    .replace(/\r\n?/g, '\n')
    .normalize('NFC')
    .replace(/[\u0000-\u0008\u000B-\u001F\u007F-\u009F\u200B-\u200F\u2028-\u202E\u2060-\u206F\uFEFF]/g, '')
    .replace(/\u00A0/g, ' ');
}

function detectDelimiter(lines, source) {
  if (lines.some((l) => l.indexOf('\t') !== -1)) return '\t';
  if (lines.some((l) => l.indexOf(';') !== -1)) return ';';
  if (source === 'csv') {
    const counts = new Map();
    for (const l of lines) {
      const n = splitLine(l, ',').length;
      counts.set(n, (counts.get(n) || 0) + 1);
    }
    let best = 1, bestN = 0;
    for (const [n, c] of counts) if (c > bestN) { best = n; bestN = c; }
    if (best > 1 && bestN / lines.length >= 0.8) return ',';
  }
  return null;
}

function headerMap(cells) {
  const map = {};
  let hit = false;
  cells.forEach((c, i) => {
    const f = foldHeader(c);
    for (const [key, words] of Object.entries(KEYWORDS)) {
      if (map[key] == null && words.includes(f)) { map[key] = i; hit = true; break; }
    }
  });
  return hit ? map : null;
}

function guessColumns(table) {
  const width = Math.max(1, ...table.map((r) => r.length));
  const map = { name: 0 };
  const warnings = [];
  const rest = [];
  for (let c = 1; c < width; c++) {
    const vals = table.map((r) => (r[c] || '').trim()).filter((v) => v !== '');
    if (!vals.length) continue;
    const blood = vals.filter((v) => parseBlood(v)).length;
    if (map.blood == null && blood / vals.length >= 0.6) { map.blood = c; continue; }
    if (map.qty == null && vals.every((v) => QTY_RE.test(v) && +v >= 1 && +v <= 99)) { map.qty = c; continue; }
    rest.push(c);
  }
  if (rest.length) map.line2 = rest[0];
  if (rest.length > 1) warnings.push({ key: 'nt.parse.ignoredCols', params: { col: rest[1] + 1 } });
  return { map, warnings };
}

/** Sleutel voor dubbels: naam + regel 2, hoofdletterongevoelig. */
export function dupKey(row) {
  return (row.name + '\u0001' + (row.line2 || '')).toLocaleUpperCase('nl-BE').replace(/\s+/g, ' ').trim();
}

/** Dubbels zoeken → [{key, name, count, ids}]. */
export function findDuplicates(rows) {
  const groups = new Map();
  for (const r of rows) {
    if (!r.name) continue;
    const k = dupKey(r);
    if (!groups.has(k)) groups.set(k, { key: k, name: r.name, ids: [] });
    groups.get(k).ids.push(r.id);
  }
  return [...groups.values()].filter((g) => g.ids.length > 1).map((g) => ({ ...g, count: g.ids.length }));
}

/**
 * Lijst inlezen.
 * @param {string} text
 * @param {{source?:'paste'|'csv', config?:object, startId?:number, fileName?:string}} [opt]
 * @returns {{rows:object[], header:string[]|null, delimiter:string|null, warnings:{key:string,params:object}[], truncated:boolean, bloodOnly:boolean, duplicates:object[]}}
 */
export function parseList(text, opt = {}) {
  const cfg = opt.config || {};
  const maxRows = Number(cfg.maxRows) || 250;
  const warnings = [];
  const raw = String(text == null ? '' : text);

  if ((opt.fileName && /\.xlsx?$/i.test(opt.fileName)) || raw.slice(0, 4) === 'PK\u0003\u0004') {
    return { rows: [], header: null, delimiter: null, warnings: [{ key: 'nt.parse.xlsx', params: {} }], truncated: false, bloodOnly: false, duplicates: [] };
  }

  const lines = normalizeText(raw)
    .split('\n')
    .map((l) => l.replace(/ {2,}/g, ' ').trim())
    .filter((l) => l !== '' && l[0] !== '#');
  if (!lines.length) {
    return { rows: [], header: null, delimiter: null, warnings: [{ key: 'nt.parse.empty', params: {} }], truncated: false, bloodOnly: false, duplicates: [] };
  }

  const delimiter = detectDelimiter(lines, opt.source || 'paste');
  let table = lines.map((l) => splitLine(l, delimiter));
  let header = null;
  let map = headerMap(table[0]);
  if (map) {
    header = table[0];
    table = table.slice(1);
    if (map.name == null) map.name = [0, 1, 2, 3].find((i) => !Object.values(map).includes(i)) ?? 0;
    const used = new Set(Object.values(map));
    const extra = header.map((_, i) => i).filter((i) => !used.has(i) && table.some((r) => (r[i] || '').trim()));
    if (extra.length) warnings.push({ key: 'nt.parse.ignoredCols', params: { col: extra[0] + 1 } });
  } else {
    const g = guessColumns(table);
    map = g.map;
    warnings.push(...g.warnings);
  }

  // Een lijst met enkel bloedgroepen (één kolom) → de bloedgroep is de tekst van de strook.
  const nameVals = table.map((r) => (r[map.name] || '').trim()).filter(Boolean);
  const bloodOnly = !header && map.line2 == null && map.blood == null && nameVals.length > 0 &&
    nameVals.filter((v) => parseBlood(v)).length / nameVals.length >= 0.6;
  const notation = (cfg.blood && cfg.blood.notation) || 'O POS';

  let id = Number(opt.startId) || 1;
  let rows = [];
  table.forEach((r, i) => {
    const name = (r[map.name] || '').trim();
    const line2 = map.line2 != null ? (r[map.line2] || '').trim() : '';
    const bloodRaw = map.blood != null ? (r[map.blood] || '').trim() : '';
    let qty = 1;
    if (map.qty != null) {
      const qv = (r[map.qty] || '').trim();
      if (qv !== '') {
        const n = Number(qv.replace(',', '.'));
        if (Number.isInteger(n) && n >= 1) qty = Math.min(99, n);
        else warnings.push({ key: 'nt.parse.badQty', params: { row: i + 1 + (header ? 1 : 0) } });
      }
    }
    let blood = bloodRaw ? parseBlood(bloodRaw) : null;
    let text = name;
    if (bloodOnly) {
      const b = parseBlood(name);
      if (b) text = notation.indexOf(' ') === -1 ? b.group + b.rh : b.group + (b.rh === '+' ? ' POS' : ' NEG');
      blood = null;
    }
    if (!text && !line2) return;
    rows.push({ id: 'r' + id++, name: text, line2, blood, qty, raw: lines[i + (header ? 1 : 0)] || '' });
  });

  let truncated = false;
  if (rows.length > maxRows) {
    rows = rows.slice(0, maxRows);
    truncated = true;
    warnings.push({ key: 'nt.parse.tooMany', params: { max: maxRows } });
  }
  if (!rows.length) warnings.push({ key: 'nt.parse.empty', params: {} });

  return { rows, header, delimiter, warnings, truncated, bloodOnly, duplicates: findDuplicates(rows) };
}

/** Dubbels samenvoegen: eerste rij krijgt de som van de aantallen (max 99), de rest verdwijnt. */
export function mergeDuplicates(rows, ids) {
  const set = new Set(ids);
  const first = rows.find((r) => set.has(r.id));
  if (!first) return rows;
  let sum = 0;
  for (const r of rows) if (set.has(r.id)) sum += r.qty || 1;
  return rows.filter((r) => !set.has(r.id) || r === first).map((r) => (r === first ? { ...r, qty: Math.min(99, sum) } : r));
}
