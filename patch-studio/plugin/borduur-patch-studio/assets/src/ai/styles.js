/**
 * AI-ontwerpstudio — stijl, volledig gescoped onder `.bps-ai` en als tekst in de bundel (geen extra request, geen
 * aanpassing aan de build nodig). Wordt één keer in <head> gezet bij mount(). Volgt de studio-tokens (--bps-*) met
 * eigen terugvalwaarden, zodat het paneel ook los (dev-pagina, andere thema's) goed oogt.
 * Gsm eerst: bottom sheet tot 85 % hoogte, grote doelen (≥ 44 px, primaire knop 52 px), duimzone onderaan.
 */
export const CSS = `
.bps-ai{--a-ink:var(--bps-ink,#14192B);--a-ink2:var(--bps-ink-2,#3A4256);--a-muted:var(--bps-muted,#5D6577);--a-line:var(--bps-line,#E2E8F0);--a-line2:var(--bps-line-2,#CBD5E1);--a-bg:var(--bps-bg,#fff);--a-soft:var(--bps-bg-soft,#F6F8FB);--a-cta:var(--bps-cta,#0B74A6);--a-cta-h:var(--bps-cta-hover,#0A1F44);--a-focus:var(--bps-focus,#5926C5);--a-ok:var(--bps-ok,#15803D);--a-okbg:var(--bps-ok-bg,#EAF8F0);--a-warn:var(--bps-warn,#B45309);--a-warnbg:var(--bps-warn-bg,#FFF5E3);--a-bad:var(--bps-bad,#B42318);--a-badbg:var(--bps-bad-bg,#FDECEB);--a-blue50:var(--bps-blue-50,#F1F7FC);--a-blue100:var(--bps-blue-100,#D9ECF7);--a-r:var(--bps-r-card,14px);--a-rb:var(--bps-r-btn,10px);--a-rp:var(--bps-r-panel,20px);--a-ai:#5B3FD1;--a-aibg:#F1EDFF;font-family:var(--bps-font-body,"Assistant",system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--a-ink);font-size:16px;line-height:1.45;-webkit-text-size-adjust:100%}
.bps-ai *,.bps-ai *::before,.bps-ai *::after{box-sizing:border-box}
.bps-ai[hidden],.bps-ai [hidden]{display:none!important}
.bps-ai button,.bps-ai input,.bps-ai textarea{font:inherit;color:inherit}
.bps-ai :focus-visible{outline:3px solid var(--a-focus);outline-offset:2px}
.bps-ai .a-sr{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;margin:-1px;padding:0;border:0}
.bps-ai--sheet{position:fixed;inset:0;z-index:100000;display:flex;align-items:flex-end;justify-content:center}
.bps-ai__backdrop{position:absolute;inset:0;background:rgba(10,31,68,.38);animation:bpsAiFade .18s ease}
.bps-ai__panel{position:relative;display:flex;flex-direction:column;background:var(--a-bg);width:100%;overflow:hidden}
.bps-ai--sheet .bps-ai__panel{max-width:640px;max-height:calc(var(--bps-ai-vh,100vh) * .85);border-radius:var(--a-rp) var(--a-rp) 0 0;box-shadow:0 -12px 40px rgba(10,31,68,.25);animation:bpsAiUp .24s cubic-bezier(.2,.8,.2,1);margin-bottom:var(--bps-ai-kb,0px)}
.bps-ai--sheet .bps-ai__panel::before{content:"";display:block;width:40px;height:4px;border-radius:2px;background:var(--a-line2);margin:8px auto 0;flex:none}
.bps-ai--inline{height:100%}
.bps-ai--inline .bps-ai__panel{position:sticky;top:16px;height:min(880px,calc(100vh - 32px));min-height:480px;border:1px solid var(--a-line);border-radius:var(--a-rp);box-shadow:var(--bps-shadow,0 2px 6px rgba(10,31,68,.05),0 12px 32px rgba(10,31,68,.08))}
.bps-ai__head{display:flex;align-items:center;gap:8px;padding:10px 12px 10px 16px;border-bottom:1px solid var(--a-line);flex:none}
.bps-ai__title{font-family:var(--bps-font-head,"Sora",system-ui,sans-serif);font-size:18px;font-weight:700;margin:0;flex:1;min-width:0;display:flex;align-items:center;gap:8px}
.bps-ai__spark{width:22px;height:22px;flex:none;color:var(--a-ai)}
.bps-ai__counter{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 6px 0 12px;border-radius:999px;border:1px solid var(--a-line2);background:var(--a-soft);font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap}
.bps-ai__counter b{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--a-cta);color:#fff;font-size:18px;line-height:1}
.bps-ai__counter--empty{background:var(--a-warnbg);border-color:#F3C77A}
.bps-ai__x{width:44px;height:44px;border:0;background:none;border-radius:50%;cursor:pointer;display:grid;place-items:center;color:var(--a-ink2);flex:none}
.bps-ai__test{margin:0;padding:8px 16px;background:var(--a-warnbg);color:#7A3E06;font-size:13px;font-weight:600;border-bottom:1px solid #F7DDAE;flex:none}
.bps-ai__body{flex:1;overflow:auto;padding:16px;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
.bps-ai__foot{flex:none;padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--a-line);background:var(--a-bg)}
.bps-ai__sub{margin:8px 0 0;text-align:center;font-size:13px;color:var(--a-muted)}
.bps-ai .a-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 16px;border-radius:var(--a-rb);border:1px solid var(--a-line2);background:var(--a-bg);font-weight:600;cursor:pointer;text-align:center;text-decoration:none;color:var(--a-ink)}
.bps-ai .a-btn--primary{width:100%;min-height:52px;border:0;background:var(--a-cta);color:#fff;font-size:17px}
.bps-ai .a-btn--ghost{border-color:transparent;background:none;color:var(--a-cta);padding:0 8px;text-decoration:underline;text-underline-offset:3px}
.bps-ai .a-btn--wide{width:100%}
.bps-ai .a-btn[disabled],.bps-ai .a-btn[aria-disabled=true]{opacity:.55;cursor:not-allowed}
.bps-ai .a-btn[aria-busy=true]::after{content:"";width:16px;height:16px;border-radius:50%;border:2px solid currentColor;border-right-color:transparent;animation:bpsAiSpin .8s linear infinite}
.bps-ai .a-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bps-ai .a-row--center{justify-content:center}
.bps-ai .a-label{display:block;font-weight:700;font-size:16px;margin:0 0 6px}
.bps-ai .a-field{display:block;width:100%;border:1.5px solid var(--a-line2);border-radius:var(--a-rb);padding:12px;background:var(--a-bg);font-size:16px;min-height:48px}
.bps-ai textarea.a-field{min-height:96px;resize:vertical}
.bps-ai .a-field:focus{border-color:var(--a-cta);outline:none;box-shadow:0 0 0 3px var(--a-blue100)}
.bps-ai .a-count{display:block;text-align:right;font-size:12px;color:var(--a-muted);margin-top:4px}
.bps-ai .a-hint{font-size:13px;color:var(--a-muted);margin:6px 0 0}
.bps-ai .a-block{margin:0 0 18px}
.bps-ai .a-h{font-size:14px;font-weight:700;color:var(--a-ink2);margin:0 0 8px;text-transform:none}
.bps-ai .a-ideas{display:flex;gap:8px;overflow-x:auto;padding:2px 2px 8px;margin:0 -2px;scroll-snap-type:x proximity;list-style:none}
.bps-ai .a-idea{flex:0 0 156px;scroll-snap-align:start;display:flex;flex-direction:column;gap:4px;align-items:flex-start;text-align:left;min-height:92px;padding:10px 12px;border-radius:var(--a-r);border:1.5px solid var(--a-line);background:var(--a-soft);cursor:pointer}
.bps-ai .a-idea[aria-pressed=true]{border-color:var(--a-cta);background:var(--a-blue50);box-shadow:inset 0 0 0 1px var(--a-cta)}
.bps-ai .a-idea strong{font-size:14px}
.bps-ai .a-idea span{font-size:13px;color:var(--a-muted);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.bps-ai details.a-more{border:1px solid var(--a-line);border-radius:var(--a-r);padding:0 12px;margin:0 0 14px}
.bps-ai details.a-more>summary{min-height:48px;display:flex;align-items:center;font-weight:600;cursor:pointer;list-style:none}
.bps-ai details.a-more>summary::-webkit-details-marker{display:none}
.bps-ai details.a-more>summary::after{content:"";margin-left:auto;width:10px;height:10px;border-right:2px solid;border-bottom:2px solid;transform:rotate(45deg);transition:transform .15s}
.bps-ai details.a-more[open]>summary::after{transform:rotate(-135deg)}
.bps-ai fieldset{border:0;padding:0;margin:0 0 14px;min-width:0}
.bps-ai legend{font-weight:600;font-size:14px;margin:0 0 6px;padding:0}
.bps-ai .a-seg{display:flex;flex-wrap:wrap;gap:6px}
.bps-ai .a-seg label{position:relative}
.bps-ai .a-seg input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}
.bps-ai .a-seg span{display:inline-flex;align-items:center;justify-content:center;min-width:48px;min-height:44px;padding:0 14px;border-radius:999px;border:1.5px solid var(--a-line2);font-size:14px;font-weight:600;background:var(--a-bg)}
.bps-ai .a-seg input:checked+span{border-color:var(--a-cta);background:var(--a-blue50);color:var(--a-cta)}
.bps-ai .a-seg input:focus-visible+span{outline:3px solid var(--a-focus);outline-offset:2px}
.bps-ai .a-note{display:flex;gap:10px;align-items:flex-start;padding:12px;border-radius:var(--a-r);background:var(--a-soft);font-size:14px;margin:0 0 14px}
.bps-ai .a-note--warn{background:var(--a-warnbg);color:#6B3A07}
.bps-ai .a-note--ok{background:var(--a-okbg);color:#14532D}
.bps-ai .a-note--bad{background:var(--a-badbg);color:#7A1D14}
.bps-ai .a-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 10px}
.bps-ai .a-grid--4{grid-template-columns:repeat(2,minmax(0,1fr))}
.bps-ai .a-grid--2{grid-template-columns:repeat(2,minmax(0,1fr))}
.bps-ai .a-tile{position:relative;display:flex;flex-direction:column;align-items:stretch;padding:6px 6px 8px;border-radius:var(--a-r);border:2px solid var(--a-line);background:var(--a-bg);cursor:pointer;text-align:left}
.bps-ai .a-tile[aria-checked=true]{border-color:var(--a-cta);box-shadow:0 0 0 3px var(--a-blue100)}
.bps-ai .a-tile img{display:block;width:100%;aspect-ratio:1/1;object-fit:contain;border-radius:10px;background:repeating-conic-gradient(#F1F4F8 0 25%,#fff 0 50%) 0 0/16px 16px}
.bps-ai .a-tile .a-num{position:absolute;top:10px;left:10px;min-width:26px;height:26px;padding:0 8px;border-radius:13px;background:rgba(20,25,43,.82);color:#fff;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:3px}
.bps-ai .a-tile[aria-checked=true] .a-num{background:var(--a-cta)}
.bps-ai .a-tile[aria-checked=true] .a-num::before{content:"✓"}
.bps-ai .a-ailabel{display:inline-flex;align-self:flex-start;margin-top:6px;padding:2px 8px;border-radius:999px;background:var(--a-aibg);color:#3F2A9C;font-size:12px;font-weight:700}
.bps-ai .a-chk{display:block;margin-top:4px;font-size:12px;font-weight:600}
.bps-ai .a-chk--ok{color:var(--a-ok)}
.bps-ai .a-chk--warn{color:var(--a-warn)}
.bps-ai .a-chk--bad{color:var(--a-bad)}
.bps-ai .a-skel{aspect-ratio:1/1;border-radius:var(--a-r);background:var(--a-soft);border:2px dashed var(--a-line2);position:relative;overflow:hidden}
.bps-ai .a-skel::after{content:"";position:absolute;left:16%;right:16%;top:50%;height:0;border-top:3px dashed var(--a-cta);opacity:.55;animation:bpsAiStitch 1.6s linear infinite}
.bps-ai .a-wait{text-align:center;font-weight:600;margin:6px 0 4px}
.bps-ai .a-chips{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 12px;padding:0;list-style:none}
.bps-ai .a-chips li{padding:4px 10px;border-radius:999px;background:var(--a-blue50);border:1px solid var(--a-blue100);font-size:13px;font-weight:600}
.bps-ai .a-small{font-size:13px;color:var(--a-muted);margin:0 0 10px}
.bps-ai details.a-rights{font-size:14px;margin:0 0 14px}
.bps-ai details.a-rights summary{min-height:44px;display:flex;align-items:center;cursor:pointer;color:var(--a-cta);font-weight:600}
.bps-ai .a-chat{border-top:1px solid var(--a-line);padding-top:14px;margin-top:6px}
.bps-ai .a-log{list-style:none;margin:0 0 10px;padding:0;display:flex;flex-direction:column;gap:8px}
.bps-ai .a-msg{max-width:88%;padding:8px 12px;border-radius:14px;font-size:14px;background:var(--a-soft)}
.bps-ai .a-msg--you{align-self:flex-end;background:var(--a-cta);color:#fff;border-bottom-right-radius:4px}
.bps-ai .a-msg--ai{align-self:flex-start;border-bottom-left-radius:4px}
.bps-ai .a-msg--warn{background:var(--a-warnbg)}
.bps-ai .a-msg .a-row{margin-top:8px}
.bps-ai .a-who{display:block;font-size:11px;font-weight:700;opacity:.75}
.bps-ai .a-ask{display:flex;gap:8px}
.bps-ai .a-ask .a-field{flex:1;min-width:0}
.bps-ai .a-send{flex:none;width:52px;min-height:48px;border-radius:var(--a-rb);border:0;background:var(--a-cta);color:#fff;cursor:pointer;display:grid;place-items:center}
.bps-ai .a-send[disabled]{opacity:.5;cursor:not-allowed}
.bps-ai .a-center{text-align:center}
.bps-ai .a-big{font-family:var(--bps-font-head,"Sora",system-ui,sans-serif);font-size:18px;font-weight:700;margin:4px 0 8px}
.bps-ai__sheet{position:absolute;inset:0;z-index:2;background:var(--a-bg);display:flex;flex-direction:column;animation:bpsAiFade .15s ease}
.bps-ai__sheet .bps-ai__head{gap:4px;padding-left:4px}
.bps-ai .a-packs{display:flex;flex-direction:column;gap:10px;margin:0 0 14px}
.bps-ai .a-pack{position:relative;display:flex;align-items:center;gap:12px;padding:14px;border-radius:var(--a-r);border:2px solid var(--a-line);cursor:pointer}
.bps-ai .a-pack input{width:22px;height:22px;margin:0;accent-color:var(--a-cta);flex:none}
.bps-ai .a-pack:has(input:checked){border-color:var(--a-cta);background:var(--a-blue50)}
.bps-ai .a-pack strong{display:block;font-size:16px}
.bps-ai .a-pack small{display:block;color:var(--a-muted);font-size:13px}
.bps-ai .a-check{display:flex;gap:10px;align-items:flex-start;padding:10px 0;font-size:14px;cursor:pointer}
.bps-ai .a-check input{width:22px;height:22px;margin:1px 0 0;flex:none;accent-color:var(--a-cta)}
.bps-ai .a-hist{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
.bps-ai .a-hist li{display:flex;gap:10px;align-items:center;padding:8px;border:1px solid var(--a-line);border-radius:var(--a-r)}
.bps-ai .a-hist img{width:52px;height:52px;object-fit:contain;border-radius:8px;background:var(--a-soft);flex:none}
.bps-ai .a-hist p{margin:0;font-size:13px;flex:1;min-width:0}
.bps-ai .a-icon{width:48px;height:48px;margin:8px auto;display:block}
@media (hover:hover){.bps-ai__counter:hover{border-color:var(--a-cta)}.bps-ai__x:hover{background:var(--a-soft)}.bps-ai .a-btn:hover{border-color:var(--a-cta);color:var(--a-cta)}.bps-ai .a-btn--primary:hover{background:var(--a-cta-h);color:#fff}.bps-ai .a-idea:hover{border-color:var(--a-cta)}.bps-ai .a-tile:hover{border-color:var(--a-line2)}}
@media (max-width:640px){.bps-ai .a-grid--4{max-width:340px;margin-left:auto;margin-right:auto}.bps-ai__counter{font-size:13px;padding-left:10px}}
@media (min-width:641px){.bps-ai--sheet{align-items:center}.bps-ai--sheet .bps-ai__panel{border-radius:var(--a-rp);max-height:min(860px,calc(var(--bps-ai-vh,100vh) * .9))}.bps-ai--sheet .bps-ai__panel::before{display:none}.bps-ai .a-idea{flex-basis:170px}}
@media (max-width:420px){.bps-ai__title{font-size:16px}.bps-ai__spark{display:none}}@media (max-width:380px){.bps-ai .a-grid{gap:6px}.bps-ai .a-tile{padding:4px 4px 6px}.bps-ai .a-ailabel{font-size:11px;padding:2px 6px}}
@media (prefers-reduced-motion:reduce){.bps-ai *,.bps-ai *::before,.bps-ai *::after{animation:none!important;transition:none!important}}
@media (forced-colors:active){.bps-ai .a-tile[aria-checked=true],.bps-ai .a-idea[aria-pressed=true]{outline:3px solid Highlight}}
@keyframes bpsAiUp{from{transform:translateY(24px);opacity:.6}to{transform:none;opacity:1}}
@keyframes bpsAiFade{from{opacity:0}to{opacity:1}}
@keyframes bpsAiSpin{to{transform:rotate(360deg)}}
@keyframes bpsAiStitch{from{transform:translateX(-30%)}to{transform:translateX(30%)}}
html.bps-ai-lock,html.bps-ai-lock body{overflow:hidden!important}
`;

let injected = false;
/** Zet de stijl één keer in het document (idempotent; ook als de bundel twee keer geladen wordt). */
export function injectStyles(doc) {
  const d = doc || (typeof document !== 'undefined' ? document : null);
  if (!d || injected || d.getElementById('bps-ai-css')) {
    injected = true;
    return;
  }
  const el = d.createElement('style');
  el.id = 'bps-ai-css';
  el.textContent = CSS;
  (d.head || d.documentElement).appendChild(el);
  injected = true;
}
