# Patch Studio voor borduurservicebelgie.be

Met Patch Studio ontwerpen je klanten zelf een patch op je website. Ze uploaden een logo, tekening of gsm-foto van een
bestaande patch, of ze beginnen met alleen tekst. De tool zegt dan meteen en eerlijk wat de beste techniek is
(geborduurd, geweven, bedrukt, gesublimeerd, PVC, chenille of leder) en legt uit waarom. De klant ziet een voorbeeld van
de patch, de prijs met staffelkorting en btw, en kan bestellen of een offerte vragen. Van elke bestelling krijg je een
productiefiche met garencodes, en je stuurt de digitale proef vanuit de bestelling.

De analyse gebeurt volledig in de browser van de klant. Er zijn geen abonnementen of externe diensten nodig. Het bestand
komt pas naar je server als de klant bestelt, een offerte vraagt of zijn ontwerp bewaart.

| Wat | Waar |
|---|---|
| Installeerbare plugin | `dist/borduur-patch-studio.zip` |
| Demo (één bestand, werkt offline, dubbelklikken) | `dist/patch-studio-demo.html` (`?lang=fr` voor Frans) |
| Broncode van de plugin | `plugin/borduur-patch-studio/` |
| Technische details per onderdeel | `docs/SPEC.md`, `docs/handoff/*.md`, `docs/review-*.md` |

---

## Installatie in 5 stappen

1. **Back-up en staging.** Maak een back-up van de site. Test bij voorkeur eerst op een stagingkopie (zie "Eerst op
   staging nakijken" hieronder).
2. **Uploaden.** Ga in WordPress naar *Plugins › Nieuwe plugin › Plugin uploaden*, kies `borduur-patch-studio.zip`,
   klik op *Nu installeren* en daarna op *Plugin activeren*. De plugin maakt zelf een verborgen product "Patch op maat"
   aan. Je bestaande producten blijven onaangeroerd.
3. **Instellingen nakijken.** Ga naar *WooCommerce › Patch Studio*. Bovenaan staat een statusblok: alles moet op
   "In orde" staan. Kijk in het tabblad **Prijzen** of de prijzen kloppen. Ze zijn overgenomen uit je huidige kassa
   (gemeten op 16-11-2026).
4. **Ontwerppagina maken.** Klik op *Maak de ontwerppagina aan* (tabblad Algemeen › Snel aan de slag). Of zet de
   shortcode `[patch_studio]` op een bestaande pagina, of sleep de Elementor-widget **Patch Studio** erop. Laat de
   knop "Ontwerp het zelf" op de homepage en het menu-item *Patches laten maken* naar die pagina wijzen.
5. **Proefbestelling.** Leeg de cache van WP Rocket, open de pagina in een privévenster en doe een volledige
   bestelling met overschrijving: upload een logo, bekijk het advies, leg in de winkelmand en reken af. Open daarna de
   bestelling in WooCommerce. Je ziet de productiefiche en de knop *Digitale proef versturen*.

Vereisten: WordPress 6.4 of hoger, WooCommerce 8.8 of hoger, PHP 8.1 of hoger, met de GD-extensie (standaard
aanwezig). Op een Franstalige site schakelt de tool zelf over naar het Frans.

---

## Instellingen (*WooCommerce › Patch Studio*)

Alles staat al ingesteld met de prijzen, beloftes en contactgegevens van je webshop. Pas enkel aan wat voor jou anders
moet. Elk tabblad heeft een knop "Herstel standaard voor dit tabblad".

| Tabblad | Wat stel je er in |
|---|---|
| **Technieken** | Welke technieken klanten zien, en of ze meteen online bestellen of eerst een offerte vragen. Standaard: geborduurd, bedrukt, gesublimeerd (vanaf 1 stuk) en PVC (vanaf 50 stuks) zijn online te bestellen. Geweven (vanaf 100), chenille en leder lopen via een offerte. Onder "Technische grenzen (gevorderd)" staan de vakregels die het advies gebruikt, bv. minstens 4 mm letterhoogte voor borduren. Laat die staan als je twijfelt. |
| **Prijzen** | Prijs per stuk per maat, staffelkorting, eenmalige kosten (borduurkaart, PVC-mal …), verzending en btw, en de meerprijs van elke afwerking. Bovenaan zie je meteen wat een klant betaalt. |
| **Kleuren** | Vink de garenkleuren uit die je niet op voorraad hebt. De tool kiest dan de dichtstbijzijnde kleur die je wel hebt. Hier staan ook de stofkleuren (twill) en ledertinten. |
| **Teksten & beloftes** | Levertijd, hoe snel de digitale proef komt, "vanaf 1 stuk", contactgegevens (telefoon, WhatsApp, e-mail), een echte klantgetuigenis (optioneel) en een meldingsbalk voor bv. een sluitingsperiode. Laat je een veld leeg, dan belooft de tool niets en gebruikt ze een neutrale zin. |
| **Algemeen** | Status, de ontwerppagina, onderdelen aan/uit (tekst toevoegen, "bekijk op een kledingstuk", bewaren en mailen, offerte, prijzen zonder btw), de kassaknop "Bestellen en betalen", de melding over het herroepingsrecht, de maximale uploadgrootte, bewaartermijnen (privacy) en wat er gebeurt bij het verwijderen van de plugin. |

**Shortcode-opties:** `[patch_studio techniek="embroidered" maat="8" aantal="25" vorm="circle" compact="yes"]`.
Zo kan je bv. op de pagina "Geborduurde patches" de tool openen met borduren al gekozen.

**Eerlijkheid.** De tool toont nooit sterren, nep-reviews, aftelklokken of valse schaarste. Een getuigenis verschijnt
alleen als je ze zelf invult. Gebruik er enkel een echte, met toestemming van de klant.

---

## Prijzen aanpassen

1. Ga naar *WooCommerce › Patch Studio › Prijzen*.
2. Alle bedragen vul je in **zonder btw**, net zoals in WooCommerce. De klant ziet altijd de prijs met btw.
3. **Prijs per stuk:** één rij per techniek, één kolom per maat (langste zijde). Een tussenmaat krijgt de prijs van de
   volgende maat, net zoals nu in je webshop.
4. **Staffelkorting:** standaard 5/10/15/20 % vanaf 10/25/50/100 stuks, zoals Woo Discount Rules nu doet. Met
   "Alle patches in de winkelmand tellen samen" geven 10 stuks van ontwerp A en 15 van ontwerp B samen de korting van 25.
5. **Eenmalige kosten:** bv. borduurkaart € 45 excl. btw. Kies of je ze één keer per bestelling (zoals je kassa nu)
   of één keer per ontwerp aanrekent.
6. Klik op **Wijzigingen bewaren**. De tool, de winkelmand en de kassa rekenen meteen met de nieuwe prijzen. De prijs wordt
   altijd op de server berekend: een klant kan de prijs in zijn browser niet vervalsen.
7. Onderaan staat **Controle met je webshop**. Daar vergelijkt de plugin je prijzen met je bestaande patchproducten. Zo zie
   je verschillen meteen.

Wil je een techniek nog niet online verkopen? Zet ze in het tabblad Technieken op "Offerte op aanvraag". De klant ziet
dan geen prijs, maar vraagt een offerte aan met zijn ontwerp erbij.

Let op: de standaard verzendkost (€ 10, gratis vanaf € 150 incl. btw) dient enkel voor de schatting in de tool.
WooCommerce rekent de echte verzending in de kassa. Hou beide gelijk, en zet in WooCommerce "Gratis verzending"
bovenaan in de verzendzone.

---

## Samenwerking met WP Rocket (en andere caches)

Je hoeft in WP Rocket niets in te stellen. De plugin regelt dit zelf:

- **Delay JavaScript:** het script van de tool wordt automatisch uitgesloten (`rocket_delay_js_exclusions`). Anders zou
  de tool pas laden als de klant scrolt of klikt.
- **Remove Unused CSS:** de CSS van de tool staat op de safelist. De tool bouwt zijn scherm in de browser, dus RUCSS kan
  die klassen niet zien.
- **Minify/combine JS:** de analyse-worker wordt uitgesloten. Hij wordt nooit via een scripttag geladen.
- **Paginacache:** de pagina met de tool mag gewoon gecachet worden. Er staat geen nonce of persoonlijke data in de HTML:
  de tool vraagt een kort token op vlak voor hij iets bewaart. Alle antwoorden van de tool-API krijgen `no-store`.
- **Cache legen:** als je instellingen bewaart, leegt de plugin de cache van WP Rocket (en ook die van WP Super Cache,
  W3 Total Cache en LiteSpeed) zodat klanten meteen de nieuwe prijzen zien.
- **Snelheid:** het script laadt alleen op de pagina met de tool (± 97 kB gzip), niet op de rest van de site. Ook
  Cloudflare Rocket Loader en Jetpack Boost laten het script met rust.

Gebruik je Cloudflare, dan herkent de rate limit het echte bezoekers-IP via de Cloudflare-adressen.

---

## Wat er getest is (17-11-2026)

Alle tests draaien met `bash tests/run-all.sh`. Laatste run: **alles groen**.

| Test | Resultaat |
|---|---|
| Build + groottebudget (`npm run build`) | geslaagd: `patch-studio.js` 97,1 kB gzip (budget 100), worker 18,0 kB (25), admin 5,1 kB (15) |
| Unit- en browsertests (`npm test`: analyse, kleuren, advies, prijzen, render, demo, 42 nauwkeurigheidscases) | 216 tests: 213 geslaagd, 0 gefaald, 3 bekende beperkingen gemarkeerd als TODO (zie onder) |
| Prijzen PHP = JS (`npm run test:php` + 6000 willekeurige vergelijkingen) | 47 vectoren, 652 checks, 0 fouten |
| `php -l` op alle PHP-bestanden | 56 bestanden, 0 fouten |
| End-to-end in een echte WordPress + WooCommerce (Hello Elementor, HPOS, Playwright/Chromium) | 8 scenario's, 202 controles, allemaal geslaagd; geen PHP-notices van de plugin. Getest: laden, upload + advies (ook je ronde patch en gsm-foto), winkelmand en kassa met overschrijving en cheque, gratis verzending, productiefiche + digitale proef + goedkeuring door de klant, offerte-aanvraag met mails, gsm (390 px), aantal wijzigen in de mand, twee ontwerpen in één mand. Zowel met de klassieke kassa als met de blok-kassa. |
| Installatie van de zip | geïnstalleerd, geactiveerd, gedeactiveerd en verwijderd via WP-CLI op een kopie van de testsite, zonder fouten |
| Frans | `.mo` laadt in WordPress (bv. "Bestellen en betalen" → "Commander et payer"); de tool zelf is volledig in het Frans met `?lang=fr` of op een fr_BE-site |
| Toegankelijkheid | axe-core in elke stap: 0 ernstige overtredingen; toetsenbord, focus en touch-doelen ≥ 44 px |

Wat **niet** getest kon worden: zie "Eerst op staging nakijken".

---

## Bekende beperkingen

- **Kleine voettekst in een groot bestand.** Een tekstje van ± 0,5 mm in een bestand van 1400 px ziet de analyse niet.
  De tool waarschuwt dan niet. De digitale proef vangt dit op.
- **Meer dan 16 vlakke kleuren** worden "illustratie met tinten" genoemd. Dat komt zelden voor.
- **Zeer slechte JPG (veel ruis)** krijgt soms de melding "we hebben je patch uit de foto geknipt". Dat is onschuldig.
- **Sterk perspectief** (scheef gefotografeerde rechthoekige patch) wordt niet rechtgezet. De klant kan het bijstellen
  met de knop *Bijstellen*. Licht scheve ronde patches worden wel rechtgezet.
- **Merkpaars #5926C5 bestaat niet als garen** in het Polyneon-palet. Bij een paars logo zegt de tool eerlijk "geen
  exacte garenkleur" en raadt ze vaak bedrukt aan. Heb je een violet garen, voeg het toe in tabblad Kleuren.
- **Advies bij illustraties met schaduw:** de tool raadt dan meestal bedrukt of gesublimeerd aan, en noemt geweven en
  geborduurd (na vereenvoudiging) als eerlijk alternatief. Geweven staat standaard op offerte (vanaf 100 stuks) en
  komt daardoor minder vaak bovenaan. Wil je borduren of geweven vaker als eerste advies, dan past een ontwikkelaar
  één scoregewicht aan (`KIND_FIT.illustration` in `assets/src/advice/evaluate.js`) en bouwt opnieuw. Dat is geen
  instelling in het beheerscherm.
- **Satijnsteek ≥ 1 mm** maakt kleine blokletters streng: een letter van 4 mm met dunne lijnen vraagt soms een
  grotere patch. Laat je digitaliseerder de grenzen in Technieken › Technische grenzen bevestigen.
- **Bestellen via je bestaande patchproducten** komt in een volgende versie. Nu gaat alles via het verborgen product
  "Patch op maat".
- **Eenmalige kosten bij herbestelling** worden in de kassa opnieuw aangerekend. Zet de belofte "vervallen bij
  herbestelling" dus pas aan als dat geregeld is.
- **nginx:** de map met privé-uploads is afgeschermd via `.htaccess` (Apache/LiteSpeed). Draait je server op nginx, dan
  toont het statusblok de regel die je hostingpartij moet toevoegen.
- **Opruimen van oude bestanden** gebeurt via WP-Cron. Op een site met weinig bezoekers of met `DISABLE_WP_CRON` laat
  je best een echte servercron `wp-cron.php` aanroepen.
- **Vertalingen:** de Franse teksten van de ontwerptool en de beheerschermen zijn door ons vertaald, niet door een
  moedertaalspreker. Laat ze nalezen. De privacytekst gebruikt bewust "u", zoals je bestaande privacybeleid.

## Eerst op staging nakijken (niet testbaar zonder je licenties/live plugins)

1. **Elementor Pro-winkelwagenwidget:** staan de ontwerpreferentie, de miniatuur en de melding "maatwerk" in de mand?
   De plugin gebruikt de standaard WooCommerce-filters die die widget ook gebruikt.
2. **Woo Discount Rules:** de plugin sluit zijn eigen regels uit van die kortingen, zodat de staffel niet dubbel wordt
   toegepast. Controleer dat de prijs in de mand gelijk is aan de prijs in de tool.
3. **Conditional Extra Fees, Product Add-ons:** mogen geen extra kosten op "Patch op maat" zetten.
4. **WP Rocket:** open de ontwerppagina in een privévenster nadat de cache is opgebouwd en controleer dat de tool meteen laadt.
5. **Kortingsbonnen** werken ook op Patch Studio-patches. Dat is een zakelijke keuze.

---

## Voor de ontwikkelaar

```bash
npm install                 # eenmalig (esbuild)
npm run build               # JS bouwen → plugin/borduur-patch-studio/assets/dist/
npm run demo                # dist/patch-studio-demo.html opnieuw bouwen
npm run dev                 # testpagina zonder WordPress: http://localhost:8787/dev/
bash tests/run-all.sh       # alles: build, unit, PHP-prijzen, php -l, e2e (lokale WP via tests/e2e/start-wp.sh)
bash tests/run-all.sh --no-e2e
bash scripts/build-zip.sh   # dist/borduur-patch-studio.zip (zonder bronbestanden, tests en node_modules)
bash scripts/make-i18n.sh   # .pot opnieuw maken en fr_BE .po/.mo/.l10n.php bijwerken (WP-CLI nodig)
```

**Vertalingen.** De PHP-teksten (winkelmand, kassa, mails, productiefiche, beheer) staan in
`languages/borduur-patch-studio.pot` met de Franse vertaling in `borduur-patch-studio-fr_BE.po/.mo/.l10n.php` (727 teksten).
Voor fr_FR kopieer je de fr_BE-bestanden met de nieuwe locale in de naam. De teksten van de ontwerptool zelf staan in
NL en FR in `assets/src/**/messages.js`. Ze gebruiken geen `wp.i18n`, dus er zijn geen JSON-vertaalbestanden nodig
(`wp i18n make-json` maakt er 0). Een tekst in de tool overschrijven kan via de filter `bps_public_config`
(`config.strings`).

**De zip** bevat alleen de gebouwde JS (`assets/dist`). De leesbare broncode staat in
`plugin/borduur-patch-studio/assets/src` in deze projectmap (GPL).

Nuttige filters: `bps_config`, `bps_public_config`, `bps_price_quote`, `bps_rate_limit`, `bps_upload_max_bytes`,
`bps_max_emails_per_design`, `bps_fiche_sections`, `bps_cart_item_display`.

**Laatste afronding (17-11-2026).** Verwerkt uit de reviews: "kun je" → "kan je" in mails en beheer; sublimatie
dwingt nu ook op de server een witte drager af (`Payload::fabric`); op gsm is het voorbeeld vanaf stap 2 kleiner zodat
het advies boven de vouw staat (`docs/screenshots/gsm-stap2-advies-boven-vouw.png`); bij een nieuw bestand start het
automatische advies weer van de standaardtechniek (een techniek van het vorige ontwerp kleurde het advies, bv. een vlak
logo na een foto werd "gesublimeerd"); `.pot` + fr_BE-vertaling; zip- en i18n-scripts.
