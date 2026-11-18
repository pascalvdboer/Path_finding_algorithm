# AI-ontwerpstudio + diensten — front-end (Patch Studio)

Bouwer `frontend` · 18-11-2026 · contract: `docs/ai/SPEC-AI-DIENSTEN.md` §1, §10, §14. Integratie: `docs/handoff/ai-services-needs.md` §frontend.
Testpagina: `npm run dev` → `http://localhost:8787/dev/ai.html` (mock, offline; `?lang=fr`, `?n=3|4`, `?bal=empty|emptyMail|paid7|locked`, `?view=sheet|inline`).

## Flow (één primaire knop per scherm)

1. **Beschrijven**: één tekstvak ("Wat wil je op je patch?") of een **ideeënkaart** per stijl (vult een voorbeeldzin in die
   je kan aanpassen), optioneel tekst op de patch, "Meer opties" (vorm, aantal kleuren) dicht. Knop: **Maak 4 voorstellen** ·
   *1 ronde · geen merken of logo's van anderen*. Wat de studio al weet (vorm, techniek, maat, stof, rand) gaat automatisch mee.
2. **Wachten**: skeletten met rustige steek-animatie (statisch bij `prefers-reduced-motion`), "± 15 s", Annuleren (ronde terug).
3. **Voorstellen**: "Zo begrepen we je" als chips, raster (3 op een rij, 2×2 bij 4), elke tegel met DOM-label **Gemaakt met AI**
   en, als de studio `quickCheck` levert, ✓/! borduurbaarheid in woorden. Tik = op de patch tonen. Verfijnen via chat
   ("maak de wolf bozer"): wat gratis in de studio kan, zegt dat ("Open in de studio" / "Toch met AI (1 ronde)").
4. **Gebruik in mijn patch** (gratis) → `bps:ai:design:chosen` met een `File` + `ai` + `hints`, paneel sluit.

Zijpaden: geweigerd (geen ronde af, "Mijn eigen logo opladen (gratis)"), storing/time-out (ronde terug), pauze, budget.

## Rondes zonder de flow te verlaten

- Teller altijd zichtbaar in de kop ("1 gratis ronde" · "Nog 7 rondes" · "Geen rondes meer"), tik = rondes kopen.
- Op → **e-mailsheet** (+2 met 6-cijferige code) als die nog niet gebruikt is, anders **pakketsheet**: 2 pakketten (bovenste
  als keuze, geen vinkje), prijs per ronde, terug als korting, "Betaal € 4,95". De Woo-kassa opent in een nieuw tabblad
  (venster synchroon geopend tegen pop-upblokkers, anders een link); het saldo komt vanzelf binnen (focus, zichtbaarheid,
  elke 4 s) en de klant gaat verder waar hij was. Gekocht maar vergrendeld → **toestemmingssheet** met 2 lege vakjes.

## Bestanden

| Bestand | Rol |
|---|---|
| `assets/src/ai/index.js` | Bundel-entry, `mount(el, ctx)` (idempotent), enige global `window.BPSAI` |
| `ai/panel.js` | Paneel: sheet (gsm, dialog + focus-trap + Esc/terug + `visualViewport`) of kolom (desktop), acties, events |
| `ai/sheets.js` | E-mail-, pakket-, toestemmings- en geschiedenissheet |
| `ai/store.js` | Pure toestandsmachine (`reduce`, `primaryAction`, `counterText`, `pickSheet`) |
| `ai/api.js` | REST-client (§4), token-vernieuwing bij 401, `mapError()` voor elke foutcode van §4.4 |
| `ai/poll.js` | Polling 1,5 s → 3 s na 10 s, `pollAfterMs` wint, pauze bij verborgen tabblad, time-out, back-off |
| `ai/mock-api.js` + `ai/mock-data.js` | In-browser nep-API met dezelfde JSON-vormen; eigen tekeningen uit `config/ai-mock` (gegenereerd) |
| `ai/messages.js` | NL ("je") + FR ("vous"), ideeënkaarten; juridische teksten komen uit `config/ai-legal.json` (`config.ai.legal`) |
| `ai/styles.js`, `ai/dom.js` | Gescopete stijl (in de bundel), veilige DOM-helper (geen HTML uit data), geldnotatie zoals PHP |
| `assets/src/services/index.js` | `evaluate()` (= PHP `Services::evaluate()`), `card()`, `catalogFromConfig()`, `dismissedFor()` |
| `services/api.js`, `services/messages.js` | `/services/cart|dismiss|catalog`, teksten van de dienstkaart |
| `dev/ai.html` | Standalone testpagina met nep-studio, eventlog en alle dienstvectoren |

## Tests

- `tests/ai/frontend-logic.test.js` — toestandsmachine, REST-headers/paden/foutmapping, polling met nepklok.
- `tests/ai/frontend-mock.test.js` — nep-API: vormen = backend, rondes eerlijk (weigering/storing/annuleren gratis), e-mail, pakket, sync met `config/ai-mock`.
- `tests/ai/frontend-bundle.test.js` — NL/FR compleet, SPEC-basisset, eerlijke teksten, elke sleutel bestaat, ontkoppeling, geen `eval`/HTML-injectie, bundel ≤ 25 kB gzip zonder mock, en de Playwright-brug.
- `tests/services/services-js.test.js` — alle gedeelde vectoren + spoed, **byte-gelijk met PHP** (`tests/services/js-parity.php`), afwijzing onthouden, REST-client.
- `tests/ai/frontend-e2e.mjs` — 58 browsercontroles op gsm 390×844 en desktop 1280×860 (flow < 2 min, axe zonder ernstige fouten, doelen ≥ 44 px, geen horizontale scroll, focus terug). Schermafdrukken: `docs/ai/screenshots/ai-*.png`.

## Bewuste keuzes

- Nep-API niet in de productiebundel (budget 25 kB): `mockApi` is een object (`createMockApi()`) of `true` met `window.BPSAIMock`.
- Geen apart `ai.css`: stijl als tekst in de bundel (0 extra requests, geen build-wijziging voor CSS).
- "Gebruik in mijn patch" (opdracht eigenaar) i.p.v. "Gebruik dit ontwerp"; overschrijfbaar via `config.strings`.
- Aantal voorstellen volgt de server (`status.n`, 2–4); de testpagina staat op 4.
