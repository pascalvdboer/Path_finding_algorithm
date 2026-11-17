// Controle van de eigenaar-feedback (zoom/slepen/centreren/passend, tekstlagen + lettertoets, stofkleur, achtergrond behouden, alleen tekst).
// Gebruik: npm run dev (of PORT=8799 node scripts/serve-dev.mjs) en dan: PORT=8787 OUT=/tmp node scripts/check-eigenaar-feedback.cjs
const { chromium } = require('playwright');
const REF = '/home/user/Path_finding_algorithm/patch-studio/reference/';
async function open(b, vp={width:1280,height:900}) {
  const p = await b.newPage({ viewport: vp });
  p.on('pageerror', e => console.log('PAGEERR', e.message));
  p.on('console', m => { if (m.type()==='error') console.log('CONSOLE', m.text()); });
  await p.goto('http://localhost:' + (process.env.PORT || 8787) + '/dev/');
  await p.evaluate(() => { try { sessionStorage.clear(); localStorage.clear(); } catch(e){} });
  await p.goto('http://localhost:' + (process.env.PORT || 8787) + '/dev/');
  await p.waitForFunction(() => window.BPS_DEV && window.BPS_DEV.app);
  return p;
}
const ready = (p) => p.waitForFunction(() => { const d = BPS_DEV.app.ctx.derived; return d && d.layout && d.layout.artRectMm && BPS_DEV.app.ctx.analysisState.status !== 'running'; }, null, { timeout: 30000 });
const st = (p) => p.evaluate(() => { const c = BPS_DEV.app.ctx, s = c.store.get(), d = c.derived; return { shape: s.shape, art: s.art, texts: s.texts, tech: s.technique, fabric: d.fabric && d.fabric.id, fabricHex: d.fabricHex, keepBg: d.keepBg, layout: d.layout, bg: c.analysis && c.analysis.background, ashape: c.analysis && c.analysis.shape && c.analysis.shape.type }; });
async function synth(p, kind) {
  return Buffer.from(await p.evaluate((kind) => {
    const c = document.createElement('canvas'); c.width = 800; c.height = 500; const x = c.getContext('2d');
    if (kind === 'yellowOnWhite') { x.fillStyle = '#fff'; x.fillRect(0,0,800,500); x.fillStyle = '#f2c500'; x.font = '900 220px Arial'; x.textAlign='center'; x.fillText('ACE', 400, 330); }
    if (kind === 'navyBg') { x.fillStyle = '#1b2a4a'; x.fillRect(0,0,800,500); x.fillStyle = '#f2c500'; x.font='900 150px Arial'; x.textAlign='center'; x.fillText('ACE', 400, 220); x.fillStyle='#c8202f'; x.fillRect(250,290,300,60); x.fillStyle='#ffffff'; x.fillRect(250,380,300,30); }
    return c.toDataURL('image/png').split(',')[1];
  }, kind), 'base64');
}
(async () => {
  const b = await chromium.launch();
  const R = {};
  // 1. Zoom / drag / center / fit on the round patch
  let p = await open(b);
  await p.setInputFiles('input[type=file]', REF + 'eigenaar-ronde-patch.png'); await ready(p);
  const slider = p.locator('.bps-arrange input[type=range]');
  R.sliderRange = await slider.evaluate(e => [e.min, e.max]);
  await slider.fill('150'); await p.waitForTimeout(400);
  R.afterZoom = (await st(p)).art;
  R.artRectZoom = (await st(p)).layout.artRectMm;
  const cv = p.locator('canvas').first(); const bb = await cv.boundingBox();
  await p.mouse.move(bb.x+bb.width/2, bb.y+bb.height/2); await p.mouse.down(); await p.mouse.move(bb.x+bb.width/2+400, bb.y+bb.height/2+30, {steps:8}); await p.mouse.up();
  await p.waitForTimeout(300);
  R.afterDrag = (await st(p)).art;
  await p.screenshot({ path: (process.env.OUT || require('os').tmpdir()) + '/zoom-drag.png' });
  await cv.focus(); await p.keyboard.press('ArrowLeft'); await p.waitForTimeout(100); R.afterKey = (await st(p)).art.offsetMm;
  await p.getByRole('button', { name: /Centreren/i }).click(); await p.waitForTimeout(200); R.afterCenter = (await st(p)).art;
  await p.getByRole('button', { name: /Passend/i }).click(); await p.waitForTimeout(200); R.afterFit = (await st(p)).art;
  // 2. Text layers
  await p.getByRole('button', { name: /tekst toevoegen/i }).click(); await p.waitForTimeout(200);
  const lay = p.locator('.bps-layer').first();
  await lay.locator('input[type=text]').fill('IRON CRUSADERS');
  await lay.locator('select').first().selectOption('Pacifico').catch(async()=>{ const opts = await lay.locator('select').first().locator('option').allTextContents(); console.log('fonts', opts); });
  R.fontOptions = await lay.locator('select').first().locator('option').allTextContents();
  await lay.locator('input[type=number]').fill('3'); await lay.locator('input[type=number]').press('Tab');
  await lay.locator('input[type=radio][value=arc_top]').check({ force: true });
  await p.waitForTimeout(800);
  R.texts = (await st(p)).texts;
  await p.screenshot({ path: (process.env.OUT || require('os').tmpdir()) + '/text-arc.png' });
  // advice per technique for the text
  R.textReasons = await p.evaluate(() => { const v = BPS_DEV.app.ctx.derived.advice.verdicts; const o = {}; for (const [k, x] of Object.entries(v)) o[k] = x.reasons.filter(r => /text|letter|tekst/i.test(r.code + (r.msg && r.msg.text))).map(r => r.severity + ': ' + r.msg.text); return o; });
  // payload
  R.payload = await p.evaluate(() => { const m = BPS_DEV.app.ctx; const fn = m.payload || m.buildPayload; try { const d = m.derived; return d.payload ? { art: d.payload.art, texts: d.payload.texts } : 'no derived.payload'; } catch (e) { return String(e); } });
  await p.close();
  // 3. fabric auto contrast (yellow on white) and keep background (navy bg)
  for (const k of ['yellowOnWhite', 'navyBg']) {
    p = await open(b);
    const buf = await synth(p, k);
    await p.setInputFiles('input[type=file]', { name: k + '.png', mimeType: 'image/png', buffer: buf }); await ready(p); await p.waitForTimeout(600);
    const s = await st(p); R[k] = { bg: s.bg && { type: s.bg.type, hex: s.bg.hex, keep: s.bg.keep }, keepBg: s.keepBg, fabric: s.fabric, fabricHex: s.fabricHex, shape: s.shape, ashape: s.ashape, art: s.layout.artRectMm, patch: [s.layout.patchWmm, s.layout.patchHmm] };
    await p.screenshot({ path: (process.env.OUT || require('os').tmpdir()) + '/' + k + '.png' });
    await p.close();
  }
  // 4. text-only patch
  p = await open(b);
  await p.getByRole('button', { name: /alleen tekst|zonder afbeelding|tekst/i }).first().click(); await p.waitForTimeout(800);
  R.textOnly = await p.evaluate(() => { const c = BPS_DEV.app.ctx, s = c.store.get(); return { kind: s.source.kind, texts: s.texts.map(t => [t.text, t.path, t.font]), step: c.step }; });
  await p.screenshot({ path: (process.env.OUT || require('os').tmpdir()) + '/text-only.png' });
  await p.close();
  console.log(JSON.stringify(R, null, 1));
  await b.close();
})();
