/**
 * AI-ontwerpstudio — in-browser nep-API (SPEC-AI-DIENSTEN §6.4, §10.2 `mockApi`). Zelfde methodes en JSON-vormen
 * als api.js / de echte REST-routes (§4.3), zonder server, netwerk of sleutel. Voor de dev-pagina, de demo en de
 * node-tests. Gebruikt de eigen voorbeeldtekeningen uit config/ai-mock (via mock-data.js). In het paneel staat bij
 * deze bron altijd "Testmodus: dit zijn voorbeeldontwerpen, geen echte AI." — we doen nooit alsof het echte AI is.
 *
 * Testhaken in de beschrijving (zoals de PHP-mock): [[mock:fail]] · [[mock:policy]] · [[mock:slow]] · [[mock:timeout]].
 * E-mailcode in testmodus: 123456 (MOCK_EMAIL_CODE).
 */
import { ApiError } from './api.js';
import DATA from './mock-data.js';

export const MOCK_EMAIL_CODE = '123456';
const LEGAL_VERSION = '2026-11-17.1';

const COLOR_WORDS = [
  [/\b(zwart|zwarte|noir|noire|black)\b/, 'black'],
  [/\b(wit|witte|blanc|blanche|white)\b/, 'white'],
  [/\b(donkerrood|bordeaux|burgundy)\b/, 'burgundy'],
  [/\b(rood|rode|rouge|red)\b/, 'red'],
  [/\b(marineblauw|donkerblauw|marine|navy)\b/, 'navy blue'],
  [/\b(lichtblauw|hemelsblauw|bleu clair|light blue)\b/, 'light blue'],
  [/\b(blauw|blauwe|bleu|bleue|blue)\b/, 'blue'],
  [/\b(olijf|olijfgroen|olive|kaki|khaki)\b/, 'olive green'],
  [/\b(groen|groene|vert|verte|green)\b/, 'green'],
  [/\b(geel|gele|jaune|yellow)\b/, 'yellow'],
  [/\b(goud|gouden|doré|dorée|gold)\b/, 'gold-yellow'],
  [/\b(oranje|orange)\b/, 'orange'],
  [/\b(zilver|zilveren|argent|silver)\b/, 'silver grey'],
  [/\b(grijs|grijze|gris|grise|grey|gray)\b/, 'grey'],
  [/\b(bruin|bruine|brun|brune|brown)\b/, 'brown'],
  [/\b(roze|rose|pink)\b/, 'pink'],
  [/\b(paars|paarse|violet|purple|mauve)\b/, 'purple'],
  [/\b(turkoois|turquoise)\b/, 'turquoise'],
  [/\b(beige|crème|creme|cream)\b/, 'beige'],
];
const COLOR_LABEL = {
  nl: { black: 'zwart', white: 'wit', burgundy: 'bordeaux', red: 'rood', 'navy blue': 'marineblauw', 'light blue': 'lichtblauw', blue: 'blauw', 'olive green': 'olijfgroen', green: 'groen', yellow: 'geel', 'gold-yellow': 'goudgeel', orange: 'oranje', 'silver grey': 'zilvergrijs', grey: 'grijs', brown: 'bruin', pink: 'roze', purple: 'paars', turquoise: 'turkoois', beige: 'beige' },
  fr: { black: 'noir', white: 'blanc', burgundy: 'bordeaux', red: 'rouge', 'navy blue': 'bleu marine', 'light blue': 'bleu clair', blue: 'bleu', 'olive green': 'vert olive', green: 'vert', yellow: 'jaune', 'gold-yellow': 'jaune doré', orange: 'orange', 'silver grey': 'gris argent', grey: 'gris', brown: 'brun', pink: 'rose', purple: 'violet', turquoise: 'turquoise', beige: 'beige' },
};
const SHAPE_WORDS = [
  [/\b(rond|ronde|cirkel|circle|round|cercle)\b/, 'circle'],
  [/\b(ovaal|ovale|oval)\b/, 'oval'],
  [/\b(schild|blason|shield|écu|ecu)\b/, 'shield'],
  [/\b(rechthoek|rechthoekig|rectangle|rectangulaire)\b/, 'rect'],
];
const SHAPE_LABEL = { nl: { circle: 'rond', oval: 'ovaal', shield: 'schild', rect: 'rechthoek', rounded: 'afgeronde rechthoek' }, fr: { circle: 'rond', oval: 'ovale', shield: 'blason', rect: 'rectangle', rounded: 'rectangle arrondi' } };

/** Lokale veiligheidsfilter (vereenvoudigde spiegel van Prompt_Engine::safety()). */
const SAFETY = [
  ['brand', /\b(nike|adidas|disney|mickey|marvel|batman|superman|pok[eé]mon|harley|ferrari|coca[- ]?cola|apple|gucci|chanel|lacoste|red bull|jack daniels?)\b/],
  ['copy', /\b(kopieer|kopie van|namaak|na ?maken|exact zoals|copie de|copier|copy of)\b/],
  ['official', /\b(politie|police|rijkswacht|gendarmerie|defensie|navo|nato|interpol)\b/],
  ['person', /\b(trump|messi|ronaldo|obama|elon musk|koning filip|roi philippe)\b/],
  ['artist', /\b(in de stijl van|dans le style de|in the style of)\b/],
  ['hate', /\b(nazi|hakenkruis|swastika|croix gamm[ée]e|ss[- ]runen)\b/],
];
const SAFETY_MSG = {
  brand: { nl: 'We maken geen logo’s of merken van anderen na. Beschrijf liever de sfeer die je wil, dan tekenen we iets eigens.', fr: 'Nous ne reproduisons pas les logos ou marques d’autres. Décrivez plutôt l’ambiance souhaitée, nous dessinerons quelque chose d’original.' },
  copy: { nl: 'Wil je een bestaand logo op een patch? Is het van jou of je club, upload het dan gewoon in de studio: dat is gratis en het wordt exact overgenomen.', fr: 'Vous voulez un logo existant sur un écusson ? S’il est à vous ou à votre club, importez-le simplement dans le studio : c’est gratuit et il est repris à l’identique.' },
  official: { nl: 'Officiële emblemen (politie, leger, hulpdiensten) maken we niet met de AI. Heb je een echte toelating? Vraag dan een offerte aan.', fr: 'Nous ne créons pas d’insignes officiels (police, armée, secours) avec l’IA. Vous avez une autorisation ? Demandez alors un devis.' },
  person: { nl: 'We tekenen geen echte personen na. Een eigen, getekend figuur of mascotte kan wel.', fr: 'Nous ne dessinons pas de personnes réelles. Un personnage ou une mascotte original, c’est possible.' },
  artist: { nl: 'We kopiëren de stijl van een bestaande tekenaar niet. Kies een van onze stijlen, bv. vintage of mascotte.', fr: 'Nous ne copions pas le style d’un dessinateur existant. Choisissez un de nos styles, par ex. vintage ou mascotte.' },
  hate: { nl: 'Dit ontwerp kunnen we niet maken.', fr: 'Nous ne pouvons pas réaliser ce design.' },
  provider_policy: { nl: 'De beeld-AI wou dit niet tekenen. Je ronde is teruggezet. Beschrijf het eens anders.', fr: 'L’IA d’images n’a pas voulu dessiner ceci. Votre essai a été recrédité. Décrivez-le autrement.' },
};
const LOCAL = [
  ['reduce_colors', /\b(minder kleuren|moins de couleurs|eenvoudiger kleuren)\b/, { nl: 'Minder kleuren kan gratis: gebruik de schuif ‘Aantal garenkleuren’.', fr: 'Moins de couleurs, c’est gratuit : utilisez le curseur « Nombre de couleurs ».' }],
  ['open_colors', /\b(kleur|kleuren|couleur|couleurs)\b/, { nl: 'Kleuren aanpassen kan gratis in de studio: tik op een kleur en kies een garen.', fr: 'Vous pouvez changer les couleurs gratuitement dans le studio : touchez une couleur et choisissez un fil.' }],
  ['open_text', /\b(tekst|letters|opschrift|naam|texte|lettres|inscription|nom)\b/, { nl: 'Tekst zet je gratis en foutloos in de studio, met echte borduurletters.', fr: 'Le texte s’ajoute gratuitement et sans faute dans le studio, avec de vraies lettres brodées.' }],
  ['open_size', /\b(groter|kleiner|grootte|maat|plus grand|plus petit|taille)\b/, { nl: 'De grootte stel je gratis in de studio in.', fr: 'La taille se règle gratuitement dans le studio.' }],
  ['open_shape', /\b(vorm|ovaal|schild|rechthoek|vierkant|forme|ovale|rectangle|carré)\b/, { nl: 'De vorm van de patch kies je gratis in de studio.', fr: 'La forme de l’écusson se choisit gratuitement dans le studio.' }],
  ['open_arrange', /\b(verschuif|verplaats|links|rechts|hoger|lager|déplace|deplace|gauche|droite)\b/, { nl: 'Verschuiven en schikken doe je gratis in de studio.', fr: 'Déplacer et arranger se fait gratuitement dans le studio.' }],
];
const PRIVACY = [/[\w.+-]+@[\w-]+\.[\w.-]+/g, /(\+|00)?\d[\d ./-]{7,}\d/g];
const ERR = {
  nl: { provider_unavailable: 'Dat lukte niet, je ronde is teruggezet. Probeer het opnieuw.', timeout: 'Dit duurt te lang. Je ronde is teruggezet.', cancelled: 'Geannuleerd. Je ronde is teruggezet.' },
  fr: { provider_unavailable: 'Cela n’a pas fonctionné, votre essai a été recrédité. Réessayez.', timeout: 'Cela prend trop de temps. Votre essai a été recrédité.', cancelled: 'Annulé. Votre essai a été recrédité.' },
};

function crc(s) {
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619) >>> 0;
  return h;
}
const B32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
function jobId(seq, now) {
  let s = '';
  let x = Math.floor(now) + seq * 7919;
  for (let i = 0; i < 10; i++) {
    s = B32[x % 32] + s;
    x = Math.floor(x / 32);
  }
  for (let i = 0; i < 16; i++) s += B32[crc(seq + ':' + i + ':' + now) % 32];
  return s;
}
const lum = (hex) => {
  const n = parseInt(String(hex).slice(1), 16);
  return 0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255);
};

/** Kader (vorm van de patch) rond de tekening; geeft [kader-svg, schaal, ty]. */
function frame(shape, c1, fill, sw) {
  const st = `stroke="${c1}" stroke-width="${sw}" fill="${fill}"`;
  switch (shape) {
    case 'oval':
      return [`<ellipse cx="512" cy="512" rx="486" ry="370" ${st}/>`, 0.62, 0];
    case 'rect':
      return [`<rect x="40" y="170" width="944" height="684" rx="40" ${st}/>`, 0.6, 0];
    case 'rounded':
      return [`<rect x="40" y="120" width="944" height="784" rx="150" ${st}/>`, 0.66, 0];
    case 'shield':
      return [`<path d="M512 40 L944 150 L944 470 C944 700 760 880 512 984 C264 880 80 700 80 470 L80 150 Z" ${st}/>`, 0.66, -30];
    default:
      return [`<circle cx="512" cy="512" r="480" ${st}/>`, 0.7, 0];
  }
}

function render(tpl, colors, shape, sw, ring) {
  const [c1, c2, c3, c4] = colors;
  const body = tpl.svg.replace(/\{\{c1\}\}/g, c1).replace(/\{\{c2\}\}/g, c2).replace(/\{\{c3\}\}/g, c3).replace(/\{\{c4\}\}/g, c4).replace(/\{\{sw\}\}/g, String(sw));
  const [fr, scale, ty] = frame(shape, c1, c4 === '#ffffff' && lum(c2) > 200 ? '#f3ead2' : c4, 26);
  const off = (1024 - 1024 * scale) / 2;
  const inner = ring ? frame(shape, c3, 'none', 10)[0].replace(/(r|rx|ry)="(\d+)"/g, (m, k, v) => `${k}="${Math.max(0, Number(v) - 40)}"`) : '';
  return (
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="1024" height="1024">' +
    '<metadata>AI-generated (Patch Studio, mock/mock-1) · Testmodus: voorbeeldontwerp</metadata>' +
    fr + (shape === 'circle' ? inner : '') +
    `<g transform="translate(${off} ${off + ty}) scale(${scale})">${body}</g></svg>`
  );
}

/**
 * @param {object} [o]
 * @param {'nl'|'fr'} [o.lang] @param {number} [o.n=3] @param {number} [o.refineN=2] @param {number} [o.latencyMs=1200]
 * @param {object} [o.balance] begin-saldo, bv. {free:1} @param {boolean} [o.emailDone] @param {boolean} [o.packsBuyable=true]
 * @param {number} [o.paymentDelayMs=2500] na "Betaal" komen de rondes binnen na deze tijd (gesimuleerde kassa)
 * @param {boolean} [o.lockPaid] gekochte rondes eerst vergrendeld (toestemmingsvakjes niet aangevinkt)
 * @param {number} [o.netDelayMs=0] kunstmatige netwerkvertraging per call
 * @param {() => number} [o.now]
 */
export function createMockApi(o = {}) {
  const lang = o.lang === 'fr' ? 'fr' : 'nl';
  const now = o.now || (() => Date.now());
  const n = Math.max(2, Math.min(4, o.n || 3));
  const refineN = Math.max(1, Math.min(2, o.refineN || 2));
  const latency = o.latencyMs != null ? o.latencyMs : 1200;
  const bal = { free: 1, paid: 0, paidLocked: 0, ...(o.balance || {}) };
  const st = { emailDone: !!o.emailDone, emailPending: null, tries: 5, payments: [], jobs: new Map(), seq: 0 };
  const packs = [
    { id: 'ai_pack_10', rounds: 10, priceIncl: 4.95, priceHtml: lang === 'fr' ? '4,95 €' : '€ 4,95' },
    { id: 'ai_pack_25', rounds: 25, priceIncl: 9.95, priceHtml: lang === 'fr' ? '9,95 €' : '€ 9,95' },
  ];

  const wait = (ms) => (ms > 0 ? new Promise((r) => setTimeout(r, ms)) : Promise.resolve());
  const balance = () => {
    settle();
    return { free: bal.free, paid: bal.paid, paidLocked: bal.paidLocked, total: bal.free + bal.paid, nextExpiry: null, lockedUntil: bal.paidLocked ? new Date(now() + 14 * 864e5).toISOString() : null };
  };
  const fail = (status, code, message, data) => {
    throw new ApiError(status, code, message || code, Object.assign({ status }, data || {}));
  };
  function settle() {
    const t = now();
    st.payments = st.payments.filter((p) => {
      if (p.at > t) return true;
      if (o.lockPaid) bal.paidLocked += p.rounds;
      else bal.paid += p.rounds;
      return false;
    });
  }
  function spend(k) {
    if (bal.free >= k) bal.free -= k;
    else if (bal.paid >= k) bal.paid -= k;
    else return false;
    return true;
  }
  function refund(job) {
    if (job.charged > 0) {
      if (job.paidFrom === 'paid') bal.paid += job.charged;
      else bal.free += job.charged;
      job.charged = 0;
    }
  }

  function understand(desc, text, shapeIn, styleIn, maxColors) {
    const low = desc.toLowerCase();
    const names = [];
    COLOR_WORDS.forEach(([re, name]) => {
      const m = low.match(re);
      if (m && !names.some((x) => x.name === name)) names.push({ name, at: m.index });
    });
    names.sort((a, b) => a.at - b.at);
    let shape = shapeIn && shapeIn !== 'auto' ? shapeIn : null;
    if (!shape) SHAPE_WORDS.some(([re, s]) => (re.test(low) ? (shape = s) : false));
    const matches = DATA.templates.filter((t) => t.kw.some((k) => new RegExp('\\b' + k + '\\b').test(low)));
    const tpl = matches[0] || DATA.templates[crc(low) % DATA.templates.length];
    const style = styleIn && styleIn !== 'auto' ? styleIn : matches.length ? 'badge' : 'club';
    const hex = names.slice(0, 4).map((x) => DATA.colorHex[x.name]).filter(Boolean);
    const summary = [
      tpl.alt[lang],
      shape ? SHAPE_LABEL[lang][shape] : null,
      names.length ? names.slice(0, 3).map((x) => COLOR_LABEL[lang][x.name]).join(lang === 'fr' ? ' et ' : ' en ') : null,
      (lang === 'fr' ? 'style ' : 'stijl ') + style,
    ].filter(Boolean).join(' · ');
    return {
      tpl,
      matches,
      und: {
        summary,
        style,
        shape: shape || 'circle',
        colors: hex,
        maxColors: maxColors || Math.max(3, Math.min(6, hex.length + 2)),
        textMode: text ? 'studio' : 'none',
        text: text || null,
        placement: text ? 'arc_bottom' : null,
        warnings: [],
      },
    };
  }

  function palette(hex, seed) {
    const base = DATA.palettes[seed % DATA.palettes.length].slice();
    if (!hex.length) return base;
    const sorted = hex.slice().sort((a, b) => lum(a) - lum(b));
    const dark = lum(sorted[0]) < 90 ? sorted[0] : '#1b1b1b';
    const rest = hex.filter((h) => h !== dark && lum(h) < 235);
    const light = hex.find((h) => lum(h) >= 235) || base[3];
    return [dark, rest[0] || base[1], rest[1] || base[2], light];
  }

  function makeProposals(job) {
    const out = [];
    const { tpl, matches, und, shapeFixed } = job.plan;
    const shapes = shapeFixed ? [und.shape] : [und.shape, 'shield', und.shape === 'circle' ? 'rounded' : 'circle', 'oval'];
    for (let i = 0; i < job.n; i++) {
      let t = tpl;
      let cols = palette(und.colors, crc(tpl.id) + i);
      let sw = 18;
      if (job.kind === 'refine') {
        // Zichtbare, voorspelbare wijziging: dikkere lijnen, en in versie 2 omgewisselde accentkleuren.
        cols = job.parentColors.slice();
        sw = job.parentSw + 10;
        if (i === 1) cols = [cols[0], cols[2], cols[1], cols[3]];
        out.push(mk(t, cols, job.parentShape, sw, false, i, job));
        continue;
      }
      if (i === 1) cols = [cols[0], cols[2], cols[1], cols[3]];
      if (i === 2) sw = 26;
      if (i === 3) t = matches[1] || DATA.templates[(DATA.templates.indexOf(tpl) + 3) % DATA.templates.length];
      out.push(mk(t, cols, shapes[i % shapes.length], sw, i === 2, i, job));
    }
    return out;
  }
  function mk(t, cols, shape, sw, ring, i, job) {
    const svg = render(t, cols, shape, sw, ring);
    return {
      index: i,
      url: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg),
      mime: 'image/svg+xml',
      w: 1024,
      h: 1024,
      alt: (lang === 'fr' ? `Proposition ${i + 1} : ` : `Voorstel ${i + 1}: `) + job.und.summary,
      _meta: { tpl: t.id, colors: cols, shape, sw },
    };
  }

  function view(job, withBal) {
    const t = now();
    if (job.status === 'queued' || job.status === 'running') {
      const due = job.createdAt + job.latency;
      if (job.hook === 'timeout' && t - job.createdAt > 100000) finish(job, 'failed', 'timeout');
      else if (job.hook !== 'timeout' && t >= due) {
        if (job.hook === 'fail') finish(job, 'failed', 'provider_unavailable');
        else if (job.hook === 'policy') finish(job, 'blocked', 'provider_policy');
        else {
          job.result = makeProposals(job);
          job.status = 'done';
        }
      } else if (t - job.createdAt > 400) job.status = 'running';
    }
    const out = {
      jobId: job.id,
      kind: job.kind,
      status: job.status,
      blocked: job.status === 'blocked',
      pollAfterMs: job.status === 'queued' || job.status === 'running' ? 1500 : 0,
      proposals: job.status === 'done' ? job.result.map(({ _meta, ...p }) => p) : [],
      understood: job.und,
      charged: job.charged,
      error: job.error,
    };
    if (job.status === 'blocked') {
      out.code = job.error.code;
      out.message = job.error.message;
    }
    if (withBal) {
      const b = balance();
      out.balance = { free: b.free, paid: b.paid, paidLocked: b.paidLocked, total: b.total };
    }
    return out;
  }
  function finish(job, status, code) {
    job.status = status;
    refund(job);
    const msg = status === 'blocked' ? SAFETY_MSG[code] ? SAFETY_MSG[code][lang] : SAFETY_MSG.hate[lang] : ERR[lang][code] || ERR[lang].provider_unavailable;
    job.error = { code, message: msg };
  }
  function getJob(id) {
    const j = st.jobs.get(String(id));
    if (!j) fail(404, 'bps_ai_not_found', lang === 'fr' ? 'Introuvable.' : 'Niet gevonden.');
    return j;
  }
  function openJob() {
    for (const j of st.jobs.values()) {
      view(j, false);
      if (j.status === 'queued' || j.status === 'running') return j;
    }
    return null;
  }
  function hookOf(s) {
    const m = String(s).match(/\[\[mock:(fail|policy|slow|timeout)\]\]/i);
    return { hook: m ? m[1].toLowerCase() : '', clean: String(s).replace(/\[\[mock:\w+\]\]/gi, '').trim() };
  }
  function safety(s) {
    const low = s.toLowerCase();
    for (const [code, re] of SAFETY) if (re.test(low)) return code;
    return null;
  }
  function blockedJob(kind, code, und) {
    const id = jobId(++st.seq, now());
    const job = { id, kind, status: 'blocked', charged: 0, und, createdAt: now(), error: { code, message: SAFETY_MSG[code][lang] } };
    st.jobs.set(id, job);
    return view(job, true);
  }

  const api = {
    lang,
    mock: true,
    async status() {
      await wait(o.netDelayMs);
      const oj = openJob();
      return {
        mode: 'test',
        providerLabel: 'Voorbeeldontwerpen (test)',
        testMode: true,
        testNotice: lang === 'fr' ? 'Mode test : ce sont des exemples, pas une vraie IA.' : 'Testmodus: dit zijn voorbeeldontwerpen, geen echte AI.',
        n,
        refineN,
        costs: { generate: 1, refine: 1, choose: 0 },
        balance: balance(),
        freePaused: false,
        emailGate: { offer: 2, done: st.emailDone },
        packs,
        orderCredit: { percent: 100, minArticleIncl: 50, validMonths: 12 },
        packsBuyable: o.packsBuyable !== false,
        limits: { descriptionMaxChars: 400, refineMaxChars: 200, textMaxChars: 60 },
        pausedUntil: null,
        legalVersion: LEGAL_VERSION,
        openJob: oj ? oj.id : null,
      };
    },
    async generate(body) {
      await wait(o.netDelayMs);
      const b = body || {};
      const { hook, clean } = hookOf(b.description || '');
      let desc = clean;
      const warnings = [];
      PRIVACY.forEach((re) => {
        if (re.test(desc)) {
          warnings.push('personal_data_removed');
          desc = desc.replace(re, ' ').replace(/\s+/g, ' ').trim();
        }
        re.lastIndex = 0;
      });
      if (desc.length < 3 || desc.length > 400) fail(400, 'bps_ai_invalid', lang === 'fr' ? 'Description trop courte ou trop longue.' : 'Beschrijving te kort of te lang.');
      for (const j of st.jobs.values()) if (j.clientReq && j.clientReq === b.clientRequestId) return view(j, true);
      const text = String(b.text || '').slice(0, 60);
      const plan = understand(desc, text, b.shape, b.style, b.maxColors);
      plan.und.warnings = warnings;
      plan.shapeFixed = !!(b.shape && b.shape !== 'auto') || SHAPE_WORDS.some(([re]) => re.test(desc.toLowerCase()));
      const unsafe = safety(desc + ' ' + text);
      if (unsafe) return blockedJob('generate', unsafe, plan.und);
      const oj = openJob();
      if (oj) fail(409, 'bps_ai_busy', '', { jobId: oj.id });
      const paidFrom = bal.free >= 1 ? 'free' : 'paid';
      if (!spend(1)) {
        if (bal.paidLocked > 0) fail(402, 'bps_ai_consent_needed', '');
        fail(402, 'bps_ai_no_rounds', '', { emailGate: { offer: 2, done: st.emailDone }, packs });
      }
      const id = jobId(++st.seq, now());
      const job = { id, kind: 'generate', status: 'queued', n, charged: 1, paidFrom, clientReq: b.clientRequestId, hook, latency: hook === 'slow' ? latency * 4 : latency, createdAt: now(), plan, und: plan.und, error: null };
      st.jobs.set(id, job);
      return view(job, true);
    },
    async job(id) {
      await wait(o.netDelayMs);
      return view(getJob(id), true);
    },
    async cancel(id) {
      await wait(o.netDelayMs);
      const j = getJob(id);
      view(j, false);
      if (j.status === 'queued' || j.status === 'running') finish(j, 'cancelled', 'cancelled');
      return view(j, true);
    },
    async refine(id, body) {
      await wait(o.netDelayMs);
      const parent = getJob(id);
      const b = body || {};
      const idx = Number(b.proposalIndex) || 0;
      const src = parent.result && parent.result[idx];
      if (!src) fail(400, 'bps_ai_invalid', '');
      const { hook, clean } = hookOf(b.instruction || '');
      const instr = clean.slice(0, 200);
      if (instr.length < 2) fail(400, 'bps_ai_invalid', '');
      const low = instr.toLowerCase();
      const unsafe = safety(low);
      if (unsafe) return blockedJob('refine', unsafe, parent.und);
      if (!b.forceAi) {
        for (const [action, re, msg] of LOCAL) {
          if (re.test(low)) return { route: 'local', action, message: msg[lang], colors: null, text: null, shape: null, aiOffer: { cost: 1 } };
        }
      }
      for (const j of st.jobs.values()) if (j.clientReq && j.clientReq === b.clientRequestId) return view(j, true);
      const oj = openJob();
      if (oj) fail(409, 'bps_ai_busy', '', { jobId: oj.id });
      const paidFrom = bal.free >= 1 ? 'free' : 'paid';
      if (!spend(1)) {
        if (bal.paidLocked > 0) fail(402, 'bps_ai_consent_needed', '');
        fail(402, 'bps_ai_no_rounds', '', { emailGate: { offer: 2, done: st.emailDone }, packs });
      }
      const nid = jobId(++st.seq, now());
      const tpl = DATA.templates.find((t) => t.id === src._meta.tpl);
      const job = {
        id: nid, kind: 'refine', status: 'queued', n: refineN, charged: 1, paidFrom, clientReq: b.clientRequestId, hook,
        latency: hook === 'slow' ? latency * 4 : latency, createdAt: now(),
        plan: { tpl, matches: [tpl], und: parent.und, shapeFixed: true },
        und: Object.assign({}, parent.und), parentColors: src._meta.colors, parentShape: src._meta.shape, parentSw: src._meta.sw, error: null,
      };
      st.jobs.set(nid, job);
      return view(job, true);
    },
    async choose(id, body) {
      await wait(o.netDelayMs);
      const j = getJob(id);
      const idx = Number((body || {}).proposalIndex) || 0;
      const p = j.result && j.result[idx];
      if (!p) fail(400, 'bps_ai_invalid', '');
      j.chosen = idx;
      return {
        imageUrl: p.url,
        mime: p.mime,
        fileName: `ai-ontwerp-${j.id.slice(0, 6)}-${idx + 1}.svg`,
        svgUrl: p.url,
        ai: { jobId: j.id, index: idx, provider: 'mock', model: 'mock-1', label: lang === 'fr' ? 'Créé avec l’IA' : 'Gemaakt met AI', humanFinish: false, legalVersion: LEGAL_VERSION },
        hints: { shape: j.und.shape, maxColors: j.und.maxColors, colors: j.und.colors, textMode: j.und.textMode, text: j.und.text, placement: j.und.placement, background: 'transparent' },
      };
    },
    async history() {
      await wait(o.netDelayMs);
      const jobs = [];
      for (const j of st.jobs.values()) {
        const v = view(j, false);
        if (v.status === 'done') jobs.push({ jobId: v.jobId, kind: v.kind, status: v.status, createdAt: new Date(j.createdAt).toISOString(), chosenIndex: j.chosen == null ? null : j.chosen, proposals: v.proposals, understood: v.understood });
      }
      return { jobs: jobs.reverse().slice(0, 20) };
    },
    async credits() {
      await wait(o.netDelayMs);
      return { balance: balance(), lots: [] };
    },
    async consent(body) {
      await wait(o.netDelayMs);
      if (!body || !body.start || !body.ack) fail(400, 'bps_ai_invalid', '');
      bal.paid += bal.paidLocked;
      bal.paidLocked = 0;
      return { balance: balance() };
    },
    async email(body) {
      await wait(o.netDelayMs);
      const e = String((body || {}).email || '').trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(e)) fail(400, 'bps_ai_invalid', '');
      st.emailPending = e.toLowerCase();
      st.tries = 5;
      return { sent: true };
    },
    async emailVerify(body) {
      await wait(o.netDelayMs);
      const b = body || {};
      if (!st.emailPending || String(b.email || '').trim().toLowerCase() !== st.emailPending) fail(400, 'bps_ai_code_expired', '');
      if (String(b.code || '').trim() !== MOCK_EMAIL_CODE) {
        st.tries -= 1;
        if (st.tries <= 0) {
          st.emailPending = null;
          fail(400, 'bps_ai_code_expired', '');
        }
        fail(400, 'bps_ai_code_wrong', '', { triesLeft: st.tries });
      }
      let granted = 0;
      if (!st.emailDone) {
        granted = 2;
        bal.free += 2;
      }
      st.emailDone = true;
      st.emailPending = null;
      return { balance: balance(), granted };
    },
    async checkout(body) {
      await wait(o.netDelayMs);
      const pack = packs.find((p) => p.id === (body || {}).packId);
      if (!pack || o.packsBuyable === false) fail(400, 'bps_ai_invalid', '');
      st.payments.push({ at: now() + (o.paymentDelayMs != null ? o.paymentDelayMs : 2500), rounds: pack.rounds });
      return { checkoutUrl: '#bps-mock-checkout-' + pack.id, packId: pack.id };
    },
    async file(url, name, mime) {
      const m = String(url).match(/^data:([^;,]+)(;charset=[^;,]+)?(;base64)?,(.*)$/);
      if (!m) fail(400, 'storage', '');
      const raw = m[3] ? atob(m[4]) : decodeURIComponent(m[4]);
      const blob = new Blob([raw], { type: mime || m[1] });
      if (typeof File === 'function') return new File([blob], name, { type: mime || m[1] });
      return blob;
    },
    /** Alleen voor tests/dev: interne toestand. */
    _debug: () => ({ bal: { ...bal }, jobs: st.jobs.size, payments: st.payments.length }),
  };
  return api;
}
