
## Van auditor "security" (17-11-2026), zie docs/premium/audit-security.md
- **security-4 (kloon bij opnieuw bestellen):** `POST /cart` kan straks een **nieuw** `designId`, `token` en `ref` teruggeven als het ontwerp al besteld was. De UI neemt die over in haar state en sessionStorage, en gebruikt ze voor latere calls (`/email`, `/quotes`, herstel).
- **security-5 (tokens niet in URL's):**
  - (a) `GET /designs/{id}`: stuur het token als header `X-BPS-Design-Token` in plaats van `?token=`. De query-variant blijft voorlopig werken.
  - (b) Lees de herstellink liefst uit het fragment `#bps=ID.TOKEN`, of uit een cookie of redirect die de server zet. Haal het ontwerp-token zo vroeg mogelijk weg, vóór analytics laadt.
- **security-1 (optionele captcha):** komt er een Turnstile/hCaptcha-optie (standaard uit), dan heeft de UI een plek nodig voor de widget bij "Offerte aanvragen" en "Bewaar & mail". Het antwoordtoken gaat mee als veld `captcha`. Bij 400 `bps_captcha` toon je in het Vlaams: "Bevestig even dat je geen robot bent." / FR: "Confirme que tu n'es pas un robot."
- **security-3 (opslagquotum):** het nieuwe foutantwoord 507 `bps_storage` krijgt een vriendelijke melding met e-mail/WeTransfer-alternatief, net zoals bij `bps_too_big`.
- **security-8 (Origin-check):** het nieuwe foutantwoord 403 `bps_bad_origin` verschijnt niet bij normaal gebruik. Toon dan de algemene foutmelding en probeer niet opnieuw zoals bij `bps_bad_token`.

## Van auditor "prestatie" (17-11-2026), zie docs/premium/audit-prestatie.md
- **prestatie-6 (Store API-call bij elke lading):** `api.storeCart()` (ui/app.js) draait bij elke studiolading, ook voor nieuwe bezoekers. Vraag het alleen op als de cookie `woocommerce_items_in_cart` bestaat, en dan pas na de eerste interactie of via `requestIdleCallback`. Doel: 0 requests naar `/wp-json/` voor de eerste klik van een nieuwe bezoeker.
- **prestatie-7 (INP / long tasks):** met 4x CPU-throttle waren er 18 long tasks, max 626 ms, tijdens upload en render. Graag renderwerk in stukken van ≤ 50 ms of in de worker (OffscreenCanvas), en hertekenen bij slepen/schuifbalk via `requestAnimationFrame` op lage resolutie, met de volledige render bij loslaten. Doel: geen long task > 200 ms na de upload, INP ≤ 200 ms.
- **prestatie-4 (bundel 115 kB gzip > 100 kB):** een build per taal (nl/fr) kan zonder UI-wijziging, als `ui/messages.js` één export per taal houdt. Wil je verder gaan: maak `main.js` geschikt voor ESM + dynamische `import()` van render/advice na de eerste bestandskeuze. De server-side skeleton staat er al.
- **prestatie-1 (previews):** de server maakt straks `-300.webp` (winkelmand) en `-600.jpg` (mail) bij de preview-PNG. De UI hoeft niets te wijzigen. De 1200 px-PNG blijft het upload-formaat.
- **prestatie-13 (demo):** Lighthouse mobiel op `dist/patch-studio-demo.html` = 80 (FCP 3,6 s) omdat alles (830 kB) inline staat. Voorstel voor build-demo: JS apart/uitgesteld, alleen het koptekstfont preloaden.

## Van auditor "admin-ux" (17-11-2026), zie docs/premium/audit-admin-ux.md
- **admin-ux-6 (live voorbeeld in de beheerpagina):** de beheerpagina wil de studio tonen in een iframe met **niet-bewaarde** instellingen. Nodig aan de UI-kant: kan `main.js`/de UI een volledige config ontvangen via `window.postMessage({type:'bps:preview-config', config})` van dezelfde origin? Dan opnieuw renderen zonder te herladen. Alternatief: de server geeft bij `?bps_preview=<nonce>` een tijdelijke `public_config` mee. In voorbeeldmodus: geen winkelmand- of offerte-calls (knoppen zichtbaar maar uitgeschakeld, met het label "Voorbeeld").
- **admin-ux-8 (merk):** komt er een instelling `brand.accentColor` / `brand.logoUrl` / `brand.studioTitle {nl,fr}` in `public_config`, lees die dan in als CSS-variabele (bv. `--bps-accent`) en als titel of logo in de kop van de studio.
- **admin-ux-13 (vormen/lettertypes aan/uit):** de UI moet uitgeschakelde `shapes` en `fonts` (veld `enabled: false`) verbergen, en `shapeOrder` volgen.

## Van auditor "atelier-flow" (17-11-2026), zie docs/premium/audit-atelier-flow.md
- **atelier-flow-6 (schoon artwork voor de digitaliseerder):** stuur bij `POST /cart` (en `/quotes`) naast de preview-PNG ook een **schone compositie** mee als veld `artwork`. Dat is een PNG, transparant, zonder rand, stof, schaduw of textuur, met alleen beeld + tekstlagen op hun positie, in 300 dpi van de echte maat (b × h mm / 25,4 × 300 px, max 4000 px). Liefst ook `artworkSvg`: tekst als `<text>` met font-family, en boogtekst via `<textPath>`. Dan kan de server een zip maken voor het digitaliseren, ook voor patches met alleen tekst (die hebben nu geen origineel).
- **atelier-flow-1 (proef per orderregel):** geen UI-wijziging nodig. Wel: als `/cart` straks een nieuw `designId` teruggeeft (security-4), gebruik dat ook voor latere calls.

## Van auditor "woo-compat" (17-11-2026), zie docs/premium/audit-woo-compat.md
- **woo-compat-5 (btw zoals de kassa):** `/quote` en het `/cart`-antwoord krijgen straks `vatRate` uit WooCommerce en een veld `vatMode`: `'incl'` (standaard), `'excl'` of `'exempt'` (btw-vrijgestelde B2B-klant). Toon bij `'exempt'` het bedrag zonder btw met het label "excl. btw – btw verlegd" / "HTVA – autoliquidation", en reken nooit zelf met een vaste 21 %: gebruik altijd `vatRate` uit het antwoord.
- **woo-compat-6 (verzending):** `shipping.remainingForFreeInclVat` en `shipping.incl` komen dan uit de Woo-verzendzone van de klant. Toon de hint alleen als het veld niet `null` is.
- **woo-compat-3 (herbestellen):** een cartregel kan straks `reorderOf: "PS-2026-0007"` hebben, zonder eenmalige kosten. Als de studio die toont: "Herbestelling – geen nieuwe borduurkaart nodig" / "Recommande – pas de nouvelle carte de broderie".
- **woo-compat-13 (blok-mini-cart):** de blok-mini-cart toont alle itemregels (8 per patch). Overweeg CSS die in `.wc-block-mini-cart__drawer` enkel de eerste 2 `.wc-block-components-product-details__item` toont.

## Van auditor "klant-account" (17-11-2026), zie docs/premium/audit-klant-account.md
- **klant-account-1 (herinnering):** het vinkje "Stuur me over 3 dagen één herinnering" doet nu niets op de server. Toon het alleen als `config.features.reminder === true`. Het antwoord van `/email` krijgt `reminderScheduled: bool`. Is dat `false` terwijl het vinkje aan stond, toon dan geen bevestiging van een herinnering.
- **klant-account-3 (bijwerken):** heeft de state al een `designId` + `token`, roep dan `POST /designs/{id}/revise` aan (multipart; `original` alleen als er een nieuw bestand is) in plaats van `createDesign`, en neem `{designId, ref, token}` uit het antwoord over (`cloned:true` = nieuw ontwerp). Gebruik nooit een oud `designId` voor `/cart` als de payload veranderd is: dat gebeurt nu in `flows.js:76` wanneer er geen `ctx.file` is.
- **klant-account-2/-10 (Mijn ontwerpen, bewaren):** voor een ingelogde bezoeker (`config.account.loggedIn`, nonce in `config.account.nonce`) wordt de knop "Bewaar in mijn account" / "Enregistrer dans mon compte" (`POST /designs/{id}/save {title?}`), zonder verplicht e-mailveld. Daarna komt een link "Bekijk in Mijn ontwerpen" naar `config.account.url`.
- **klant-account-4 (delen):** een knop "Deel dit ontwerp" / "Partager ce design" → `POST /designs/{id}/share` → `shareUrl` kopiëren (navigator.clipboard + fallback). Een studio die geladen wordt met `?bps_share=` of `#bps-share=` toont een alleen-lezen weergave met "Maak er een kopie van" / "En faire une copie" → `POST /shared/{s}/copy`.
- **klant-account-5 (herbestellen):** een cartregel of antwoord met `reorderOf` toont "Herbestelling van {ref} – geen nieuwe borduurkaart nodig" / "Recommande de {ref} – pas de nouvelle carte de broderie" (sluit aan bij woo-compat-3).
- **klant-account-7 (gast → account):** na inloggen of registreren stuurt de studio de ontwerpen uit sessionStorage/localStorage (`designId` + `token`, max 20) één keer naar `POST /account/claim`.

## Van auditor "i18n-a11y" (17-11-2026), zie docs/premium/audit-i18n-a11y.md
- **i18n-a11y-3 (derde taal / en_US):** de PHP-kant gaat naar een taalregister `nl | fr | en` (filter `bps_supported_languages`). `config.locale` kan dan ook `en-GB`/`en-US` worden. De JS-catalogi (`assets/src/**/messages.js`) hebben een `en`-kolom nodig, met als fallbackketen: gevraagde taal → en → nl. Zet nergens meer `locale.startsWith('fr') ? 'fr' : 'nl'` vast.
- **i18n-a11y-7 (labels uit config):** `{nl,fr}`-labels in `public_config` kunnen straks extra taalsleutels hebben (bv. `en`). Lees ze met dezelfde fallbackketen en niet met een vaste `obj.nl` / `obj.fr`.
- **i18n-a11y-9 (bedragen):** de server formatteert bedragen voortaan volgens de WooCommerce-instellingen. Komen ze in `public_config` (bv. `currency: {symbol, position, decimals, decimalSep, thousandSep}`), formatteer dan in de studio daarmee (of met `Intl.NumberFormat(locale, {style:'currency', currency})`) zodat studio, winkelmand en mail hetzelfde tonen. fr-BE: "1 234,56 €" met een smalle vaste spatie.
- **i18n-a11y-13 (terminologie FR):** woordenlijst NL→FR voor studio én mails: ontwerp = **design**, patch = **écusson**, proef = **épreuve (numérique)**, klittenband = **velcro**, borduurkaart = **carte de broderie**, garen = **fil**. De studio gebruikt al "design". Hou dat zo, de .po wordt erop afgestemd.
- **i18n-a11y-11 (RTL):** gebruik in nieuwe CSS logische eigenschappen (`margin-inline-start`, `text-align: start`, `inset-inline-*`). Zet `dir` op de studio-root naast de `lang` die de shortcode al meegeeft.

## Van auditor "productisering" (18-11-2026), zie docs/premium/audit-productisering.md
- **productisering-4 (geen vaste merkgegevens):** haal de fallback `'Kalmthout'` weg uit `assets/src/ui/assistant.js:42` en `assets/src/ui/panels.js:65`. Is `config.contact.city` leeg, gebruik dan een zin zonder plaatsnaam (bv. "Vanaf 1 stuk, gemaakt op eigen machines." / "Dès 1 pièce, fabriqué sur nos propres machines."). Toon "WhatsApp" of "bel ons" alleen als `contact.whatsapp` of `contact.phone` ingevuld is. Zijn beide leeg, toon dan een link naar `contact.quoteUrl` ("Stel je vraag" / "Posez votre question"). Hetzelfde geldt voor `nt.error.generic` in `assets/src/nametape/messages.js`.
- **productisering-5 (huisstijl instelbaar):** de server zet straks CSS-variabelen inline op de studio-root (`--bps-accent`, `--bps-cta`, `--bps-cta-hover`, `--bps-ink`, `--bps-radius`). Laat `assets/css/patch-studio.css` (en de naamstrook- en cross-sell-CSS) daarop steunen: `--bps-blue: var(--bps-accent, #21A9E2)` in plaats van de sitespecifieke `var(--e-global-color-8bf8b31, …)`. Gebruik geen vaste merkkleuren buiten `:root`.
- **productisering-6 (public config):** de server stuurt geen `_note`/`_openPoint`/`_doc`, geen `priceSource` en geen inhoud van uitgeschakelde blokken meer (`promises.testimonial` wordt `{enabled:false}`). Lees die velden in de UI niet.

## Van auditor "concurrentie-features" (18-11-2026), zie docs/premium/audit-concurrentie-features.md
- **concurrentie-features-1 (sjablonen/galerij):**
  - De server krijgt `GET /templates?technique=&use=&shape=` met per item `{id, slug, label{nl,fr}, technique, use[], shape, previewUrl, photoUrl?, payload}`.
  - De startgalerij leest deze lijst in plaats van de vaste `ui/samples.js`. Hou die als fallback als de route leeg is of faalt.
  - Toon `photoUrl` met het label "Echte patch uit ons atelier" / "Vrai écusson de notre atelier".
  - `config.page.template` (shortcode `sjabloon=`) laadt meteen dat sjabloon.
- **concurrentie-features-6 (elementen):** `GET /elements` geeft borduurbare SVG-bouwstenen (lauwerkrans, sterren, lint, vlaggen, jaartalbadge) met `{id, label{nl,fr}, category, techniques[], svgUrl}`. De UI voegt ze toe als beeldlaag en toont alleen elementen waarvan de huidige techniek in `techniques` staat.
- **concurrentie-features-7 (leverdatum):** `/quote` en `/cart` kunnen `estimate: {from, to}` (ISO-datums) of `null` teruggeven.
  - Toon bij een waarde "Rond {from}–{to} in huis, vanaf je akkoord op de proef" / "Vers le {from}–{to}, à compter de votre accord sur l'épreuve".
  - Bij `null`: de bestaande `leadTimeFallback`. Verzin nooit zelf een datum.
- **concurrentie-features-9 (namen op het embleem):** een tekstlaag kan `variable: true` + `values: string[]` (max 500) krijgen.
  - De invoer werkt zoals bij naamstroken (plakken, één naam per regel). Het voorbeeld toont de eerste naam, met een bladerknop.
  - Het aantal volgt het aantal namen.
  - Fout `bps_variant_invalid` heeft `data.lines[]` met de regelnummers.
- **concurrentie-features-10 (presets):** `config.page` kan `techniques[]` (alleen deze tonen), `options[]` (voorgekozen), `fabric` en `action: 'quote'|'order'` krijgen. Bij `'quote'` verberg je "Bestellen" en blijft "Offerte aanvragen" zichtbaar.
- **concurrentie-features-3 (atelier-modus):** met `config.atelier = {nonce, customerEmail?}` toont de studio een smalle balk "Atelier-modus".
  - Velden: klant-e-mail, interne notitie en een prijsafwijking met reden.
  - De knop "Bewaar en maak bestellink" roept `POST /atelier/designs` aan en toont de link om te kopiëren.
  - Er gaan geen klant-analytics-events uit.

## Van auditor "gdpr-juridisch" (18-11-2026), zie docs/premium/audit-gdpr-juridisch.md
- **gdpr-juridisch-8 (rechtenverklaring uit één bron):** de server stuurt de tekst van de rechtenverklaring straks mee in `config.legal.rights.patch = {v, text}` (al in de taal van de bezoeker). Toon die tekst bij het vinkje in plaats van `ui.s5.rights` uit `messages.js`, en stuur `rightsConsent: {v: config.legal.rights.patch.v, accepted: true}`. Ontbreekt het veld, val dan terug op de huidige tekst. Zo is de sha1 die de server bewaart gegarandeerd die van de tekst die de klant zag.
- **gdpr-juridisch-10 (offerte zonder verplicht privacyvinkje):** haal het verplichte vinkje "Bevestig dat we je gegevens mogen gebruiken…" weg uit het offerteformulier. Zet onder de knop een informatiezin met een link naar `config.contact.privacyUrl`: "We gebruiken je gegevens alleen om je aanvraag te beantwoorden. Meer in ons privacybeleid." / "Nous utilisons vos données uniquement pour répondre à votre demande. Plus d'infos dans notre politique de confidentialité." De server eist `consent` dan niet meer.
- **gdpr-juridisch-11 (cookieloos tot de eerste AI-actie):** vraag `GET /ai/status` pas op als de klant de AI-sectie echt opent, niet bij het laden van de studio. Bewaar het wegklikken van een dienstaanbod ("Nee, bedankt") in `sessionStorage` (`bps:svc:dismissed`, try/catch) en niet via een serveroproep die een cookie zet.
- **gdpr-juridisch-12 (uitleg bij upload concreet):** vervang in `ui.empty.privacy` de zin "We bewaren het niet langer dan nodig." door de echte termijn uit `config.retention.orphanDays` (komt mee in de public config): "Bestel je niet, dan wissen we het na {days} dagen." / "Sans commande, nous le supprimons après {days} jours." De link naar het privacybeleid blijft.
- **gdpr-juridisch-14 (elke cookietool):** `ctx.track` controleert eerst `window.wp_has_consent?.('statistics')` (WP Consent API) of `config.consent.statistics === true`, en pas daarna `getCkyConsent`. Zonder een van beide wordt er niets gepusht.
