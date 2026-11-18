# Handoff — ui

> **Let op (v2, 18-11-2026):** de stappen-UI hieronder is vervangen door de ontwerpstudio (`ui/app.js`, `stage.js`, `panels.js`, `assistant.js`, `checkout.js`, `price.js`, `history.js`; `step-*.js` en `preview.js` bestaan niet meer). Panelen: design, text, shape, edge, colors, back, size (links / gsm-tabs) en advice, checkout (rechts). Zichtbare panelen staan in `data-bps-panels` op het root-element. E2E-hulpfuncties: `studio.show(page, id)`, `studio.panels(page)` in `tests/e2e/lib/harness.cjs`. Integratie met render v2: zie `docs/integratie.md`, sectie "Integratie v2".

## Gebouwd
| Bestand | Inhoud |
|---|---|
| `assets/src/main.js` | IIFE `window.BPS`: `mount(el, config?)`, `mountAll()`, `buildPublicConfig`, `version`; automount op DOMContentLoaded (uit te zetten met `window.BPS_NO_AUTOMOUNT`), Elementor-editor-hook. Mount-fout laat het server-skelet staan. |
| `assets/src/ui/app.js` | Orkestratie: store, analyse (worker via `createAnalyzer`, retry bij `worker_failed`), automatische keuzes (§7.4, techniek volgt het advies tot het stabiel is), stappen + History API (`#bps_step=n`), live regions, events `bps:*` (§7.8), `bps:track` enkel met `features.analyticsEvents` + CookieYes-toestemming, herstel via `?bps_design&bps_token` en sessionStorage, Store-API pool-hint. |
| `ui/state.js` | DesignState, `sanitizeState` (wantrouwt opslag/links), `reconcileOptions` (radio-groepen, klittenbandkleur aan/uit), `normalizeQty`, persistentie (`sessionStorage['bps:state:v1']`; `localStorage` enkel na "Bewaar ook op dit toestel"). |
| `ui/derive.js` | Garen-match/reductie, stofkeuze, rand-/tekstkleur, contrast, `quote` + `quoteAll`, `evaluate`, `layoutPatch`, RenderOptions, **DesignPayload** (incl. steekbereik). |
| `ui/preview.js` | Canvas via `render/index.js` (+ eenvoudige fallback als de renderer faalt), slepen (muis/touch) van ontwerp én tekstlagen (boog: hoek + afstand tot rand), pijltjes (Shift = 5 mm), dynamische `aria-label`, `toBlob` voor upload. |
| `ui/step-design.js` | Lege staat (dropzone = `<label>` + echte input, drag & drop, 3 eigen voorbeeld-SVG's, "Start met tekst", beloftes, privacyzin V1) en stap 1 (analyse-log, herkenningsmeldingen, Bijstellen, vorm, schikken, tekstlagen). |
| `ui/step-technique.js` | Adviesbox, top-3 techniekkaarten (radiogroep, status icoon+woord, badge "Ons advies", reden, fix-knop, prijs, "Waarom?"), alle technieken, upsells, vergelijk naast elkaar, garenkleuren (aantal 6/8/10/12, alternatieven kiezen, "Toon wat er verandert"). |
| `ui/step-size.js` · `step-finish.js` · `step-summary.js` | Maat (chips + andere maat, maatadvies-knop, kledingstuk), aantal (stepper, presets, PVC-correctie met uitleg), staffelladder + eerlijke `savingHint`, verzendhint; opties per `optionGroups` met effect (`optionEffect`), stof (auto + uitleg), randkleur, gebruik (voedt advice `useCase`); overzicht met Wijzig, "Wat er nu gebeurt", levertijd, opmerking, rechten-checkbox (fout + focus), maatwerkmelding A/A+/B, bestel-/offerteknop, vertrouwensbundel, bewaren, vakman. |
| `ui/price.js` | Prijskaart (desktop sticky), btw-schakelaar, ⓘ-uitleg fees, opbouwtabel; sticky mobiele balk + bottom sheet. |
| `ui/flows.js` | `<dialog>`s (focus in/uit, Esc), offerteformulier (inline fouten, honeypot, elapsed), bewaar & mail, Bijstellen (vorm, randgevoeligheid → herhaalde analyse met `floodNeighborDE`, achtergrond meenemen), bestellen: `POST /designs` (hergebruik bij ongewijzigde inhoud) → `POST /cart` → redirect/melding + `wc_fragment_refresh`; 403 `bps_bad_token` → nieuw token. Offline (`rest:null`) → dev-dialoog met payload. |
| `ui/messages.js`, `ui/dom.js`, `ui/api.js`, `ui/samples.js` | Catalogus `ui.*` NL ("je") + FR ("vous"); DOM-helpers; REST-client; voorbeelden. |
| `assets/css/patch-studio.css` | Tokens SPEC D3 (blauw/navy, CTA `#0B74A6`, paarse focusring, koraal enkel decor), kit-reset 01 §6.3, mobiel eerst (768/1025), sticky bar + `scroll-padding-bottom`, `.bsb-mob-balk` verborgen, reduced-motion, forced-colors, ook stijl voor het PHP-skelet. |
| `dev/index.html` | Standalone (`npm run dev` → `/dev/`), `?lang=fr`, `?techniek=&maat=&aantal=&vorm=&compact=1`. |

## Getest
Playwright-scripts in scratchpad (`scratchpad/uitest/smoke.cjs`, `flow.cjs`, niet in de repo wegens schrijfrechten): eigenaar-ronde-patch (rond + fillsPatch, illustratie), gsm-foto (uitgeknipt + Bijstellen + heranalyse), PDF (vriendelijke melding), voorbeeld + tekst slepen, tekst-only op 390 px tot payload-dialoog, rechtenfout + focus, prijs-sheet, browser-terug, herladen, FR. **axe-core (wcag2a/aa/21aa/22aa) 0 violations** in elke stap, dialogen en sheet; geen horizontale scroll op 390 px; geen JS-fouten. `npm test` blijft groen.

## Afwijkingen / aanvullingen
- Taak noemde "paars/koraal"; SPEC D3 (bindend) kiest blauw zoals de site echt toont → gevolgd. Paars = focus, koraal = decor (testimonial).
- Extra statevelden (niet in DesignState, server negeert ze): `sizePicked`, `useCase`, `poolQty`, `feesAlreadyCharged`, `otherItemsInclVat`.
- `bps:design-saved` stuurt `email:''` (geen persoonsgegevens in events).

## Voor de integrator (nodig, buiten mijn bestanden)
1. **Budget**: `patch-studio.js` = **94 kB gzip** (budget 60). De andere modules samen zijn al ± 57 kB (advice ± 20, render ± 15); ui ± 37 kB (waarvan catalogus NL+FR ± 10). Opties: budget naar ± 100 kB, of FR-catalogi uit de bundel en via `config.strings` (PHP) laden — de UI leest `config.strings[locale]`, `[nl|fr]` of plat.
2. **Lettertypes** `assets/fonts/*.woff2` (config.fonts[].file) ontbreken → 404 + fallbackfont. Ophalen via `@fontsource/*` (OFL).
3. `woo.redirectToCart` is privé en bereikt de browser niet: UI redirect standaard, tenzij `/cart`-antwoord `redirect:false` geeft of `features.redirectToCart:false`.
4. Pool-hint verwacht in de Store API `items[].extensions.bps.technique` (woo); zonder dat geen pool-hint (geen schade).
5. Ingelogde gebruikers: `config.rest.nonce` wordt als `X-WP-Nonce` meegestuurd als PHP het toevoegt.
6. `retention.savedDays` is privé → bewaartekst toont 90 dagen standaard.

## Open punten
- Advice wisselt soms zijn aanbeveling naargelang de gekozen techniek (bedrukt ↔ gesublimeerd); de UI vangt dat op door te volgen tot het stabiel is, maar advice zou deterministisch onafhankelijk van `design.technique` mogen zijn.
- FR door moedertaalspreker laten nalezen. Het voorbeeld van een tekst in een boog draait nog niet; `art.rotation` heeft geen knop (layoutPatch ondersteunt het niet).
