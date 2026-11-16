# 01 — Merk & site: borduurservicebelgie.be

Onderzoeker: "site-merk". Opgehaald op **16-11-2026** met curl (browser-UA) en Playwright/Chromium (computed styles + screenshots, 1440px en 390px).
Dit is de enige bron die de bouwers lezen. Alles hieronder staat echt op de site, met bron-URL. Waar ik iets afleid, staat **(afgeleid)**.

Bijlage: `docs/research/01-garenkleuren-polyneon.json` bevat 411 garenkleuren (naam, kleurnummer, schermkleur in hex) van
https://borduurservicebelgie.be/beschikbare-kleuren-garen/ — bruikbaar als garenpalet in de tool (zie §7).

---

## 0. TL;DR voor bouwers

1. **De kleur die je op de site ziet is BLAUW `#21A9E2`, niet paars.** `#5926C5` (primary) en `#FD5C48` (secondary) zijn wel als globale kleur
   gedefinieerd, maar komen visueel bijna niet voor. Knoppen, CTA's, tabelkoppen, links-hover, mobiele balk: allemaal `#21A9E2`.
   Donkere tegenhanger: navy `#0A1F44` (custom snippets, hover) en de topbalk `#092140`. Tekst `#1E1B1B`. Footer `#1E1B1B`/`#323232`.
2. **Lettertypes: Sora (koppen, knoppen) + Assistant (lopende tekst, 18px/1.8, gewicht 500).** Beide zelf-gehost door Elementor; laad ze NIET opnieuw, erf ze.
3. **Het Elementor-kit stijlt álle `button`, `input`, `label`, `h1–h6` globaal** (`.elementor-kit-21 button {…}`, `h2 {text-transform:capitalize}` …).
   Zonder reset krijgen jouw knoppen blauwe achtergrond/Sora 15px/`capitalize` en jouw koppen Elke Woord Met Hoofdletter. Zie §6.3 voor de reset.
4. **WP Rocket draait met "Remove Unused CSS" (`#wpr-usedcss`) en "Delay JS" (`rocketlazyloadscript`).** CSS-klassen die pas via JS verschijnen
   kunnen weggestript worden; JS start pas na interactie. Zie §6.5.
5. **Er staat al een eigen configurator op /patches/** ("Stel je patch samen & zie meteen je prijs", klassen `.bsb-pc`, `.pc-*`) — in een
   **groen** palet (`#1F7A4D`) dat niet bij het merk past. Patch Studio moet die kunnen vervangen en wél in blauw/navy.
6. **Toon: Vlaams, "je", kort, concreet, eerlijk.** Kernzinnen: "Vanaf 1 stuk", "Digitale proef vóór productie", "Pas na jouw akkoord starten we",
   "Je hoeft geen perfect bestand te hebben". Zie §3.
7. **Echte prijzen bestaan** (WooCommerce-producten met maten 4–12 cm, staffel 5/10/15/20 %, borduurkaart € 29,95, PVC-mal € 150, PVC min. 50 st.). Zie §5.

---

## 1. Technische context van de site

| Item | Waarde | Bron |
|---|---|---|
| Thema | Hello Elementor + Elementor Pro (kit-id **21**: `.elementor-kit-21`) | inline CSS homepage |
| Taal | `<html lang="nl-BE">`; **geen** hreflang / geen FR-versie gevonden | https://borduurservicebelgie.be/ |
| Containerbreedte | `--container-max-width: 1280px` (desktop); custom secties `max-width:1140px` | inline CSS |
| Breakpoints | Elementor standaard: tablet ≤ 1024px, mobiel ≤ 767px | inline CSS media queries |
| Widget-spacing | `--widgets-spacing: 20px`; containers vaak `gap:10px`, secties padding-top 50/60/80/100px | inline CSS |
| Caching | WP Rocket: RUCSS (`<style id="wpr-usedcss">`), Delay JS (`type="rocketlazyloadscript"`), lazyload | bron homepage |
| Fonts | `/wp-content/uploads/elementor/google-fonts/fonts/sora-*.woff2`, `assistant-*.woff2` (lokaal) | `<link rel=preload>` homepage |
| Woo-prijs kleur | `.price{color:#958e09}` (olijf/goud, Woo-default restant) | inline CSS winkel |
| Eigen snippets | WPCode-snippets met prefix `bsb-` (`.bsb-pk`, `.bsb-pc`, `.bsb-mob-balk`, `.bsb-link` …) | /patches/laten-maken/ |
| Mobiele sticky balk | `.bsb-mob-balk`: `position:fixed; bottom:0; z-index:9990; min-height:52px`, links "Offerte aanvragen" (#21A9E2) + "WhatsApp" (#1E1B1B) | /patches/laten-maken/ (mobiel) |
| Zijtab | Verticale knop "Vraag Nu Je Offerte Aan" rechts vast in beeld (desktop) | alle pagina's |
| Topbalk | "Patches op maat – vanaf 1 stuk, met digitale proef vóór productie" — wit op `#092140` | alle pagina's |

**Gevolg voor de plugin:** houd onderaan op mobiel ≥ 60px vrij (of zet je eigen sticky CTA boven `.bsb-mob-balk`, z-index > 9990 alleen als je die balk verbergt op de studio-pagina).

---

## 2. Merkgids — design tokens

### 2.1 Elementor globale kleuren (letterlijk uit `.elementor-kit-21`)

```
--e-global-color-primary:   #5926C5   (paars — gedefinieerd, visueel bijna ongebruikt)
--e-global-color-secondary: #FD5C48   (koraal — gedefinieerd, visueel bijna ongebruikt)
--e-global-color-text:      #959595   (labels; lopende tekst is in de praktijk #1E1B1B)
--e-global-color-accent:    #1E1B1B   (knop-hover, donker)
--e-global-color-84c4bf7:   #52D7B4   (mint)
--e-global-color-8bf8b31:   #21A9E2   (DE KNOPKLEUR)
--e-global-color-285bc0e:   #21A9E2
--e-global-color-76cd240:   #1E1B1B   (koppen, links, body-tekst)
--e-global-color-873c396:   #323232
--e-global-color-9a89829:   #F5F5F5   (knoptekst, lichte vlakken)
--e-global-color-86e39d7:   #BDBDBD   (inputrand)
--e-global-color-3625d8a:   #FCFCFC   (input-achtergrond, witte koppen op hero)
```

### 2.2 Kleuren zoals ze echt op het scherm staan (computed styles + pixelmeting)

| Rol | Hex | Waar gezien |
|---|---|---|
| Merkblauw / CTA | `#21A9E2` | alle knoppen, tabelkop "Waarom kiezen voor ons", blauwe infoblokken, logo IMTA |
| Navy (tekst-accent, hover) | `#0A1F44` | `.bsb-pk-stap b`, `.bsb-pk-knop:hover`, tegeltitels |
| Topbalk navy | `#092140` | aankondigingsbalk boven header |
| Hero | blauwe foto-/rookachtergrond van `#0B2444` (links) naar `#61B4E1`/`#9CC7E1` (rechts), witte tekst, onderaan schuine golf-rand | hero elke pagina |
| Tekst | `#1E1B1B` | body |
| Secundaire tekst | `#69727D` / opacity .85 | Elementor-icoontjes, tegelsubtitels |
| Licht blauw vlak | `#F3F8FC` | stapkaarten "Zo laat je je patch maken" |
| Tabelrij licht blauw | `#D2EBF0` | vergelijkingstabel (afwisselend met `#F5F5F5`) |
| Kaartrand | `#E3EBF2` (hover: `#21A9E2`) | patch-tegels |
| Rand neutraal | `#DFDFDF` | garenkleur-kaarten (radius 15px) |
| Succes-vinkje | `#22C55E` (groen bolletje) | vergelijkingstabel ✓ |
| Fout-kruisje | rood bolletje (`#E5484D`-achtig) | vergelijkingstabel ✗ |
| Footer | `#1E1B1B` (hoofd), `#323232` (CTA-band erboven `#4C4C4C`), koppen in `#21A9E2` | footer |
| Woo-prijs | `#958E09` | productarchief (bij voorkeur NIET overnemen in de tool, leest slecht) |

### 2.3 Typografie (uit kit, desktop → tablet → mobiel)

| Token | Font | Grootte (D / T / M) | Gewicht | Line-height | Transform |
|---|---|---|---|---|---|
| H1 (`bfd2d76`) | Sora | 40 / 34 / 26px | 700 | 1.2 | capitalize |
| H2 (`01b20cf`) | Sora | 30 / 28 / 26px | 700 | 1.3 | capitalize |
| H3 (`3bc7cf0`) | Sora | 24 / 20 / 22px | 700 | 1.4 | capitalize |
| H4 (`6523a6b`) | Sora | 22 / 20 / 18px | 700 | 1.5 | capitalize |
| H5 (`64cdf67`) | Sora | 18 / 19.2 / 18px | 700 | 1.6 | capitalize |
| H6 (`8606af0`) | Sora | 16px | 700 | 1.7 | capitalize |
| Display (`d4e1b50`) | Sora | 90 / 55px | 800 | 1.2 | uppercase |
| Body (`text`) | Assistant | 18px | 500 | 1.8 | none |
| Formulierveld (`fc8217a`) | Assistant | 16px | 500 | 1.8 | none |
| Knop (`accent`) | Sora | 15px | 600 | — | capitalize |
| Secondary (`secondary`) | Sora | 14px | 400 | 1.7 | uppercase |

Opmerking: de site zet koppen op `capitalize` ("Waarom Kiezen Voor Ons?"). Dat is een Elementor-instelling, geen bewuste huisstijl in de
teksten zelf (de broncode schrijft "Waarom kiezen voor ons?"). **(afgeleid)** Voor de tool: gebruik normale zinshoofdletters in UI-tekst
en zet `text-transform:none` expliciet, anders ziet advies er schreeuwerig uit ("Te Fijn Om Te Borduren").

### 2.4 Vorm, schaduw, spacing

| Element | Waarde | Bron |
|---|---|---|
| Kit-knop | bg `#21A9E2`, tekst `#F5F5F5`, Sora 15/600, `border-radius:5px`, padding `15px 30px` (md) / `12px 24px` (sm) / `20px 40px` (lg), geen rand/schaduw; hover bg `#1E1B1B` tekst `#FCFCFC` | kit + computed |
| Custom CTA `.bsb-pk-knop` | bg `#21A9E2`, wit, Assistant 600, padding `12px 26px`, `radius:6px`; hover `#0A1F44` | /patches/laten-maken/ |
| Woo "Opties kiezen" | bg `#21A9E2`, `#FCFCFC`, Assistant 18/700, radius 3px | /product-categorie/patch/ |
| Kaarten / tegels | wit, `1px solid #E3EBF2`, `radius:8px`, geen schaduw; hover rand `#21A9E2` | `.bsb-pk-tegels` |
| Stapkaarten | `#F3F8FC`, radius 8px, padding `14px 16px` | `.bsb-pk-stap li` |
| Elementor-containers | meest gebruikte radius: **15px** (456×), daarna 5px, 10px, 100px (pill) | telling over alle pagina-CSS |
| Icon-boxes (winkel) | wit op lichte textuur, zachte schaduw ±`0 0 15px rgba(0,0,0,.22)`/`0 0 30px -10px rgba(0,0,0,.5)` | /product-categorie/patch/ |
| Inputs | bg `#FCFCFC`, rand `1px solid #BDBDBD`, **radius 0**, Assistant 16px | kit |
| Schaduwen | Site is vlak: bijna overal `box-shadow:none`. Hoogstens zacht `0 6px 24px rgba(10,31,68,.07)` **(afgeleid)** | telling |

### 2.5 Kant-en-klare CSS-variabelen voor Patch Studio

Scope alles onder één wrapper (`.bps-studio`), zodat niets lekt naar het thema en het thema zo min mogelijk naar binnen lekt.
Lees eerst de Elementor-variabelen (dan volgt de tool automatisch als de eigenaar zijn kit aanpast), met vaste fallback.

```css
.bps-studio{
  /* merk */
  --bps-blue:        var(--e-global-color-8bf8b31, #21A9E2); /* CTA, actief, links-hover */
  --bps-blue-600:    #1A8FC0;   /* (afgeleid) iets donkerder voor focus/pressed */
  --bps-blue-50:     #F3F8FC;   /* lichte vlakken */
  --bps-blue-100:    #D2EBF0;   /* tabelrij / highlight */
  --bps-navy:        #0A1F44;   /* koppen-accent, hover CTA */
  --bps-navy-900:    #092140;   /* topbalk */
  --bps-ink:         var(--e-global-color-76cd240, #1E1B1B);
  --bps-ink-2:       var(--e-global-color-873c396, #323232);
  --bps-muted:       #69727D;
  --bps-line:        #E3EBF2;
  --bps-line-2:      #DFDFDF;
  --bps-bg:          #FFFFFF;
  --bps-bg-soft:     var(--e-global-color-9a89829, #F5F5F5);
  --bps-ok:          #22C55E;   /* "Kan perfect" */
  --bps-warn:        #F5A524;   /* (afgeleid) "Kan, met aanpassing" */
  --bps-bad:         #E5484D;   /* (afgeleid) "Niet aan te raden" */
  /* reserve-accenten uit de kit, spaarzaam (bv. badge/illustratie) */
  --bps-purple:      var(--e-global-color-primary, #5926C5);
  --bps-coral:       var(--e-global-color-secondary, #FD5C48);
  --bps-mint:        var(--e-global-color-84c4bf7, #52D7B4);

  /* type */
  --bps-font-head:   var(--e-global-typography-primary-font-family, "Sora"), system-ui, sans-serif;
  --bps-font-body:   var(--e-global-typography-text-font-family, "Assistant"), system-ui, sans-serif;
  --bps-fs-body:     18px;   --bps-lh-body: 1.6;  /* site gebruikt 1.8; in een tool is 1.5–1.6 compacter */
  --bps-fs-small:    15px;
  --bps-fs-h2:       clamp(24px, 2.2vw + 12px, 30px);
  --bps-fs-h3:       clamp(19px, 1vw + 14px, 22px);

  /* vorm */
  --bps-r-btn:   5px;    /* = kit-knop */
  --bps-r-card:  8px;    /* = bsb-tegels */
  --bps-r-panel: 15px;   /* = meest gebruikte container-radius */
  --bps-r-pill:  100px;
  --bps-shadow:  0 6px 24px rgba(10,31,68,.07);
  --bps-shadow-lg: 0 16px 40px rgba(10,31,68,.12);

  /* ruimte */
  --bps-gap: 20px;  --bps-gap-sm: 10px;  --bps-pad: 24px;
  --bps-maxw: 1140px;
}
```

---

## 3. Toon van stem

### 3.1 Kenmerken
- **Vlaams Nederlands, "je/jouw"** in alle recente teksten (patches, offerte, homepage-intro, FAQ). Oudere blokken gebruiken nog "u" — niet overnemen.
- **Kort en concreet**, vaak met dubbelpunt-opbouw: "**Vertel wat je wil:** je logo of idee, het product, de aantallen en maten."
- **Geruststellend, zonder druk**: "Je hoeft nog niet alles te weten." "Je aanvraag is vrijblijvend." Geen uitroeptekens-spam (behalve "Bestel nu!").
- **Eerlijk-deskundig**: legt uit wanneer borduren NIET de beste keuze is en wat dan wel. Dit is precies de toon voor het advies in de tool.
- **Vakwoorden, maar uitgelegd**: borduurkaart, digitaliseren, merrow-boord, onderlaag, staffel, steken.
- Lokaal: "in eigen productie in Kalmthout", "Antwerpse Kempen", "in heel België".

### 3.2 Letterlijke zinnen die je mag hergebruiken (met bron)

| Zin | Bron |
|---|---|
| "Patches op maat – vanaf 1 stuk, met digitale proef vóór productie" | topbalk, alle pagina's |
| "Je hoeft geen perfect bestand te hebben. Een logo, schets of idee is genoeg om te beginnen, ook voor één stuk." | https://borduurservicebelgie.be/ |
| "Bekijk je digitale proef: klopt er iets niet, dan laat je het weten." / "Keur goed: pas na jouw akkoord starten we." | https://borduurservicebelgie.be/ |
| "Stuur je ontwerp — Een logo, tekening of foto. Een schets volstaat ook." | https://borduurservicebelgie.be/patches/laten-maken/ |
| "Je ziet eerst hoe je patch eruitziet, met maat en kleuren." | idem |
| "Pas na jouw akkoord starten we de productie." | idem |
| "Bevat je ontwerp veel kleine letters, dunne lijnen of kleurverlopen, dan komt dat detail beter tot zijn recht in een geweven of geprinte patch dan in borduurwerk: een borduurnaald heeft ruimte nodig, waardoor fijne details dichtlopen." | idem |
| "Twijfel je? Stuur je ontwerp mee, dan geven wij aan welke techniek het beeld het beste weergeeft." | idem |
| "Een groot ontwerp, zoals het rugembleem van een motorclub of vereniging, telt heel veel steken en wordt als borduurwerk stijf en zwaar. Voor zulke formaten raden we een geprinte patch met geborduurde rand aan: die blijft soepel en het detail blijft scherp." | idem |
| "Twijfel je over het formaat, de kleuren of de achterkant? Laat dan eerst één patch maken. Veel clubs en bedrijven doen dat voor ze een grotere oplage bestellen." | idem |
| "Borduurgaren komt in vaste kleuren; een geleidelijke overgang van de ene tint naar de andere bestaat in garen niet." | https://borduurservicebelgie.be/digitaliseren/ |
| "Blijkt een onderdeel te fijn, dan melden we dat vóór er iets geproduceerd wordt, samen met het alternatief dat wél werkt." | idem |
| "Zo komt u niet voor verrassingen te staan wanneer het werk al op de machine ligt." (→ in de tool in je-vorm) | idem |
| "Wij adviseren eerlijk of borduren dan wel bedrukken de beste keuze is voor jouw toepassing, aantallen en budget." | https://borduurservicebelgie.be/borduren/borduurproces/ |
| "Je hoeft nog niet alles te weten. Vertel ons in het formulier wat je in gedachten hebt" | https://borduurservicebelgie.be/offerte-aanvragen/ |
| "Toch een beetje buiten uw budget? Bekijk dan zeker onze geprinte patch!" / "Werk je met een foto of een kleurverloop? Dan is onze gesublimeerde patch de betere keuze." | https://borduurservicebelgie.be/product/geborduurde-patch/ |
| "Garenkleuren kunnen licht afwijken van kleuren op een beeldscherm." | https://borduurservicebelgie.be/faq/ |

### 3.3 Voorbeelden van toolteksten in die toon (voorstel, (afgeleid))
- Oordeel OK: "Dit ontwerp borduren we strak. Effen vlakken, 4 kleuren en geen lijnen dunner dan 1 mm."
- Oordeel twijfel: "Borduren kan, maar de tekst onderaan is 2,5 mm hoog. Dat loopt dicht. Maak de patch 8 cm i.p.v. 6 cm, of kies een geprinte patch met geborduurde rand."
- Oordeel nee: "Er zit een kleurverloop in je logo. In garen bestaat zo'n overgang niet. Een gesublimeerde patch toont het wél zoals op je scherm."
- Geruststelling bij de knop: "Pas na jouw akkoord op de digitale proef starten we de productie." (waar volgens de site). Beloof NIET "je betaalt pas na de proef": in de webshop reken je af bij bestelling — alleen tonen als de eigenaar dat zo instelt.
- Microcopy upload: "Een logo, foto of schets is genoeg. Een perfect bestand hoeft niet."

### 3.4 Frans
De site heeft **geen Franse versie** (lang `nl-BE`, geen hreflang, geen WPML/Polylang gezien). Franse teksten in de plugin zijn dus nieuw;
gebruik "vous" (Belgisch-Franse zakelijke norm) en dezelfde kernbeloftes: « dès 1 pièce », « épreuve numérique avant production »,
« production propre à Kalmthout ». **(afgeleid — laat vertalen/nalezen door eigenaar)**

---

## 4. USP's en vertrouwenssignalen die ECHT op de site staan

### 4.1 USP's (gebruik letterlijk; bron erbij)

| USP | Exacte formulering | Bron |
|---|---|---|
| Vanaf 1 stuk | "Vanaf 1 stuk mogelijk" / "al mogelijk vanaf één patch" / "Geen minimumaantal verplicht" | / ; /patches/laten-maken/ |
| Uitzondering | "PVC-patch: … Vanaf 50 stuks." / "Geweven patch: … Vanaf 100 stuks." | /patches/laten-maken/ |
| Digitale proef | "Van elk ontwerp krijg je een digitale proef ter goedkeuring vóór de productie start." / "Digitale proef vooraf, jij keurt goed" | / ; /patches/laten-maken/ |
| Eigen productie | "Borduurservice België maakt en borduurt je patches in eigen productie in Kalmthout" / "Eigen productie in Kalmthout – geen importkosten of douane" | /patches/laten-maken/ |
| Ervaring | "met meer dan 15 jaar ervaring" / "Meer dan 15 jaar ervaring in patch productie" | /patches/laten-maken/ ; /patches/geborduurd/ |
| Persoonlijk | "Ouderwetse service" / "Persoonlijke service op de traditionele manier" / "Persoonlijke support bij ontwerp en bestelling" | / ; /patches/laten-maken/ |
| Eén aanspreekpunt | "Borduren, bedrukken en graveren regel je bij één aanspreekpunt." | / |
| Herbestellen | "Bestel je later hetzelfde ontwerp opnieuw, dan gebruiken we die borduurkaart opnieuw." / "Herhaalbestellingen snel geregeld" | /patches/laten-maken/ |
| Gratis advies | "Borduurservice België biedt gratis advies voor het optimaliseren van je logo of ontwerp." | /faq/ |
| Belgisch bedrijf | "Belgisch bedrijf, BTW BE1012263492 — factuur mogelijk" | /patches/ |
| Werkgebied | "Levering in heel België" / "Standaard verzenden we binnen België en Nederland." / "Actief in geheel de Benelux" | / ; /faq/ ; /offerte-aanvragen/ |
| Ophalen | "Wil je je bestelling liever ophalen in Kalmthout? Vermeld het in je bericht" | /contact/ |
| Betalen | "Bancontact, kredietkaart en directe bankoverschrijving. Voor grotere zakelijke opdrachten zijn facturen mogelijk na goedkeuring." | /faq/ |

### 4.2 Technische specs die de site zelf publiceert (geborduurde patch) — bruikbaar voor de analyse-drempels
Bron: https://borduurservicebelgie.be/patches/geborduurd/
- Minimale teksthoogte: **4 mm**
- Stikdichtheid: tot 75 % dekking
- Standaard onderstof: twill 180 g/m² (let op: productpagina zegt "100% borduurgaren, zonder achtergrondstof" — zie §8)
- Garen: kleurvast polyester borduurgaren (UV-bestendig); "fijn borduurwerk met tot 12 kleuren"
- Formaat: van 5 cm tot 30 cm ("tot 30 x 30 cm op aanvraag"); webshop-maten 4/6/8/10/12 cm
- Wasbaarheid patches: tot 60 °C
- Vormen: rond, rechthoekig, ovaal, custom; rand: merrow, laser cut, gestikt; achterzijde: opnaaibaar, strijkbaar, velcro, zelfklevend
- Op /patches/ (bestaande keuzehulp): vraag "fijne details of kleine tekst (kleiner dan ± 3 mm)?" → de site hanteert dus 3–4 mm als grens.
- Op /faq/: "Zorg voor een helder ontwerp zonder kleurverlopen of schaduwen, omdat het borduurproces gebruik maakt van garen."

### 4.3 Bevestigingsadvies van de site (letterlijk inzetbaar in de tool)
Bron: /patches/laten-maken/
- "Opstrijkbaar werkt het best op stevige, gladde stoffen zoals katoen en katoen-polyester. Op waterafstotende of gecoate stof, sterk elastisch materiaal en fleece hecht een opstrijk patch minder goed; kies daar voor opnaaien of klittenband."
- "Opnaaien … is aan te raden voor leer en voor kleding die vaak gewassen wordt."
- "Klittenband (haakzijde, luszijde of beide) als je de patch wilt kunnen wisselen"
- "Zelfklevend — tijdelijk of voor gladde oppervlakken (niet wasbaar)"

### 4.4 Reviews / sociale bewijskracht — wat er WEL en NIET is
- **Getuigenissen (5 stuks)** staan op https://borduurservicebelgie.be/ en https://borduurservicebelgie.be/product-categorie/patch/ onder
  "Wat klanten zeggen over Borduurservice België". Letterlijk:
  - Sven, zaakvoerder cateringbedrijf: "We bestelden sweaters voor ons hele team. Perfect geborduurd!"
  - Kim, jonge mama: "De gepersonaliseerde romper met naam was hét cadeau van de babyborrel. Zo zacht en mooi afgewerkt!"
  - Jasper, student grafisch ontwerp: "Super service. Zelfs voor mijn kleine bestelling van 3 stuks kreeg ik professioneel advies."
  - Robin, zaakvoerder Flanders Classics: "T-shirts voor deelnemers en crew met perfecte kwaliteit, een stipte levering en uiterst vlot contact: de samenwerking met IMTA Technics voor Heathland Gravel 2025 was een succes van formaat!"
  - Liam, zaakvoerder Berg Selected: "Zeer tevreden over onze samenwerking! Alles verliep vlot, snel en uiterst correct. Eerlijke en transparante communicatie van begin tot eind. …"
  → Geen enkele gaat specifiek over **patches**. Als je ze toont: letterlijk, met naam/rol zoals op de site, en configureerbaar (eigenaar bevestigt). De Jasper-quote ("kleine bestelling van 3 stuks … professioneel advies") past het best bij de tool.
- **Geen sterrenscore, geen aggregateRating-schema, geen Google/Trustpilot-widget, geen aantal klanten/bestellingen** gevonden. → Toon dus GEEN sterren of "x tevreden klanten".
- **"Onze samenwerkingen"**: logo-slider op de homepage (o.a. Finaspan zichtbaar). Alleen tonen als eigenaar logo's aanlevert.
- **Echte voorbeeldfoto's**: patchfoto's op /product-categorie/patch/ en /patches/laten-maken/ (o.a. "Tutti Fratelli" patches in de IMTA-machine, militaire PVC-patches, "Mamma Mia!" gesublimeerd). Eigen machinefoto met "IMTA Technics"-machine = sterk authentiek beeld.
- **Zaakvoerder**: citaat op /over-ons/ van "Pascal van den Boer – Zaakvoerder" (let op: tekst zegt daar "Borduurservice Vlaanderen" — tikfout op de site, niet overnemen).
- **Reactietijd**: "we antwoorden binnen 24u" (homepage-formulier) en "we antwoorden meestal binnen één werkdag" (/faq/). Gebruik de voorzichtigste: "meestal binnen één werkdag".

### 4.5 Contact (letterlijk)
- Boomgaardstraat 22, 2920 Kalmthout, België
- WhatsApp 0491 06 32 01 (link op site: `https://wa.me/32491063201`)
- info@borduurservicebelgie.be
- Onderneming: IMTA technics bv, BTW BE1012263492 (/over-ons/)

---

## 5. Bestaande prijzen en producten (voor prijsdefaults van de tool)

Bron: WooCommerce Store API `https://borduurservicebelgie.be/wp-json/wc/store/v1/products?category=patch` + productpagina's. Alle prijzen **incl. 21 % btw, per stuk**.

| Product (ID) | 4 cm | 6 cm | 8 cm | 10 cm | 12 cm | Min. | Eenmalige kost | URL |
|---|---|---|---|---|---|---|---|---|
| Geborduurde Patch (29902) | 3,03 | 4,89 | 6,29 | 7,54 | 8,68 | 1 | borduurkaart (€ 29,95 volgens /patches/ en /digitaliseren/) | /product/geborduurde-patch/ |
| Geprinte patch (30484) | 4,24 | 4,84 | 5,45 | 6,66 | 7,87 | 1 | "eenmalige instelkosten" (bedrag niet vermeld) | /product/geprinte-patch/ |
| PVC Patch (30541) | 3,63 | 4,84 | 6,05 | 7,26 | 8,47 | **50** | mal **€ 150**; "Levertijd: rond de 4 weken" | /product/pvc-patch/ |
| Gesublimeerde patch (36158) | 3,03 | 4,24 | 4,84 | 5,45 | 6,05 | 1 | "eenmalige instelkosten per ontwerp" | /product/gesublimeerde-patch/ |

- Maat = "maximale maat van uw patch" (langste zijde). Attribuut `pa_sizes`.
- **Staffelkorting** (alle 4 producten, plugin woo-discount-rules): 10–24 st. **5 %**, 25–49 **10 %**, 50–99 **15 %**, 100+ **20 %**.
- **Add-ons** (woocommerce-product-addons), per stuk:
  - Achterkant: klittenband haak/lus zwart/wit +€ 2,42 · gesneden +€ 1,21 · opstrijkbare lijmlaag +€ 1,82 · dikke patch (dubbele onderlaag) +€ 1,82 · extra dik +€ 3,63 (PVC: klittenband +€ 1,21)
  - Rand (geborduurd): geknipt uit doek (lichte rafels mogelijk) · op folie (verfijnde rand) +€ 3,03 · zelf uitknippen −10 %
  - Gesublimeerd: drager standaard of Flock +€ 0,91
  - Extra's: rol garen +€ 6,05 · gebogen stofschaar +€ 30,25 · rechte stofschaar +€ 24,14
  - Upload: "Optioneel — je kunt je ontwerp ook later doorsturen; we nemen contact op." (max. 3 GB)
- Prijsindicatie borduren op kleding (/borduren/prijzen-borduren/): borstlogo ±8 cm vanaf € 4,95; ±25 cm vanaf € 7,50; voorbeelden met steken: 6.339 steken 69×78 mm = € 6,83; 19.929 steken 96×77 mm = € 13,01 (nuttig om steken→prijs te kalibreren).
- Digitaliseren kleding: "€ 29,95 per borst- of ruglogo (voor € 45,- voor borst- en ruglogo samen). In sommige gevallen vervallen deze kosten." (/digitaliseren/)

→ De tool kan deze prijzen als **standaardwaarden** inladen (configureerbaar), en bij voorkeur rechtstreeks aan de bestaande Woo-variaties koppelen
(product-ID + `attribute_pa_sizes`) zodat "Bestellen" in de bestaande kassa landt.

---

## 6. Hoe de plugin naadloos in Hello Elementor past

### 6.1 Visuele regels
1. **Wit canvas, blauwe accenten, navy voor nadruk.** Geen gradiënten in UI-elementen, behalve eventueel één navy→blauw-paneel voor het prijs/oordeel-blok
   (echo van de hero `#0B2444 → #21A9E2`). Paars/koraal hooguit als klein accent; nooit groen zoals de oude `.bsb-pc`.
2. **Kaarten**: wit, rand `#E3EBF2`, radius 8px (klein) of 15px (paneel), vrijwel geen schaduw. Actief/geselecteerd: rand `#21A9E2` 2px + lichtblauwe vulling `#F3F8FC`.
3. **Knoppen**: primair = kit-stijl (`#21A9E2`, radius 5px, Sora 15/600, padding 15px 30px, hover `#0A1F44` of `#1E1B1B`). Secundair = wit met blauwe rand.
   Hoofd-CTA "Bestellen" mag groter (Sora 16–17px, padding 16px 32px) maar in dezelfde kleur — consistent met "Vraag Nu Je Offerte Aan".
4. **Koppen** in Sora 700, kleur `#1E1B1B` of `#0A1F44`; tekst in Assistant 500. Stapnummers als navy/blauwe bolletjes (de oude configurator gebruikt genummerde bolletjes — gebruikers kennen dat patroon).
5. **Vinkjes-patroon** van de site: groen bolletje ✓ / rood bolletje ✗ (vergelijkingstabel). Hergebruik voor het techniek-oordeel (✓ / ! / ✗) — met tekstlabel erbij, niet alleen kleur.
6. **Breedte**: max 1140–1280px, gecentreerd, 16px zijpadding op mobiel. Breakpoints 1024/767.
7. **Mobiel**: rekening houden met `.bsb-mob-balk` (52px, fixed bottom, z 9990) en de rechter zijtab. Eigen sticky prijsbalk erboven: `bottom: 52px` of de balk op de studiopagina verbergen.

### 6.2 Inpassen in de pagina
- Lever een **shortcode** én een **Elementor-widget**, zodat de eigenaar de tool in een bestaande Elementor-pagina plaatst (onder de standaard hero met breadcrumb).
  De hero (blauwe rookfoto, witte H1, golfrand) blijft dus van Elementor; de plugin begint op wit.
- Gebruik geen eigen header/footer/fonts. Geen jQuery-UI-thema's of Bootstrap.

### 6.3 Kit-lekken neutraliseren (VERPLICHT)
Het kit stijlt elementen globaal met specificiteit `.elementor-kit-21 button` (0,1,1). Scoped resets met hogere specificiteit:

```css
.bps-studio :where(h1,h2,h3,h4,h5,h6){ text-transform:none; font-family:var(--bps-font-head); color:var(--bps-ink); }
.bps-studio label{ font-size:var(--bps-fs-small); color:var(--bps-ink); line-height:1.4; }
.elementor-kit-21 .bps-studio button,
.bps-studio button{ /* reset kit-knop */
  background:none; color:inherit; font:inherit; text-transform:none; border-radius:0; padding:0; border:0;
}
.bps-studio .bps-btn{ background:var(--bps-blue); color:#fff; font-family:var(--bps-font-head); font-weight:600; font-size:15px;
  border-radius:var(--bps-r-btn); padding:15px 30px; text-transform:none; transition:background .15s; }
.bps-studio .bps-btn:hover,.bps-studio .bps-btn:focus-visible{ background:var(--bps-navy); color:#fff; }
.bps-studio input:not([type=button]):not([type=submit]),.bps-studio textarea,.bps-studio select{
  border:1px solid #BDBDBD; border-radius:var(--bps-r-btn); background:#fff; font-family:var(--bps-font-body); font-size:16px; }
.bps-studio a{ color:var(--bps-blue); } .bps-studio a:hover{ color:var(--bps-navy); } /* kit zet a:hover op #959595 */
```
Let op: `.elementor-kit-21 a:hover{color:#959595}` en `.home-box .elementor-kit-21 a:hover` bestaan; zet link-kleuren expliciet.
Gebruik bij voorkeur `<button class="bps-btn">` met de reset-regel `.elementor-kit-21 .bps-studio button` (specificiteit 0,2,1 > 0,1,1).

### 6.4 Fonts
Sora en Assistant zijn al geladen (Elementor lokaal, met preload). Verwijs met `font-family` en fallback `system-ui, sans-serif`. Laad niets extra.
Canvas-tekst (preview) moet wachten op `document.fonts.load('700 20px Sora')` voor je tekent.

### 6.5 WP Rocket
- **RUCSS**: WP Rocket bewaart enkel CSS-selectors die het in de HTML ziet. Klassen die pas via JS ontstaan (stappen, oordeel-badges, modals) worden mogelijk gestript.
  Oplossing: laad de plugin-CSS als eigen bestand en voeg het toe aan de RUCSS-safelist via filter `rocket_rucss_external_exclusions` / `rocket_rucss_inline_content_exclusions`
  of zet in de plugin-instructies: WP Rocket → Bestandsoptimalisatie → "CSS-safelist" → `/wp-content/plugins/borduur-patch-studio/(.*).css`.
- **Delay JS**: scripts starten pas bij eerste interactie. Voor een tool bovenaan de pagina: sluit het plugin-script uit via filter `rocket_delay_js_exclusions`
  (pad `borduur-patch-studio`), en render een bruikbare server-side HTML-skelet (geen lege div) zodat er niets "verspringt".
- Geen jQuery-afhankelijkheid nodig (jQuery wordt ook vertraagd).

---

## 7. Garenkleuren (bijlage JSON)
- 411 kleuren van "POLYNEON 100% polyester" met kleurnummer en de hex die de site als swatch toont. Bron: /beschikbare-kleuren-garen/.
- Site-tekst: "Vermeld bij het bestellen elke kleur op kleurnummer." en "garenkleuren kunnen licht afwijken van kleuren op een beeldscherm".
- **Datakwaliteit**: de laatste blokken "Fluorescent Pink" (5×), "Fluorescent Green" (5×) enz. hebben dubbele namen en sommige bruine hexwaarden →
  waarschijnlijk kopieerfouten op de site. Gebruik voor kleurmatching **kleurnummer + hex**, toon de naam secundair, en filter of markeer de fluo-reeks.
- Toepassing in de tool **(afgeleid)**: elke ontwerpkleur → dichtstbijzijnde Polyneon-nummer (ΔE in Lab) tonen ("Je rood ≈ Polyneon 1747"), met de site-disclaimer.

---

## 8. Tegenstrijdigheden op de site — NIET overnemen zonder keuze van eigenaar

| Onderwerp | Varianten op de site | Aanbeveling voor de tool |
|---|---|---|
| Levertijd | "meestal binnen 2–3 weken" (/product-categorie/patch/) · "Gemiddeld 3-4 weken. Spoed mogelijk binnen 1-2 weken." (idem) · "rond de 4 weken" (PVC) · "Levertijd volgens je offerte" (/patches/) | Instelbaar veld; standaardtekst: "Je krijgt de levertijd bij je digitale proef. Heb je een datum? Vermeld die, dan kijken we wat haalbaar is." |
| Wastemperatuur | patches "tot 60 °C" (/patches/geborduurd/) · borduren op kleding "tot 40 graden" (/, /over-ons/) | Patch: 60 °C mag (site-claim), maar "volg het wasetiket van het kledingstuk". |
| Onderstof geborduurde patch | "twill 180 g/m²" (/patches/geborduurd/) vs "100% borduurgaren, zonder achtergrondstof" (productpagina) | Product-pagina volgen voor het webshopproduct; laten bevestigen. |
| Geweven patch | aangeboden in teksten ("Vanaf 100 stuks"), maar **geen webshopproduct** | In de tool: advies + "offerte" i.p.v. directe bestelknop. |
| Chenille / leder | chenille genoemd ("Speciale types: PVC, Rubber, Chenille"), leder/gelaserd niet als patch-product | Alleen als offerte-route tonen. |
| Borduurkaart bij geprint | /patches/ zegt borduurkaart voor "geborduurde & geweven"; productpagina geprint zegt "eenmalige instelkosten" (bedrag onbekend) | Bedragen instelbaar maken, bron vragen aan eigenaar. |
| u / je | gemengd | Altijd "je". |

---

## 9. Bronnen (allemaal opgehaald 16-11-2026)
- https://borduurservicebelgie.be/ (homepage, kit-CSS, getuigenissen, USP-iconen)
- https://borduurservicebelgie.be/patches/laten-maken/ (toon, techniekadvies, bevestiging, stappen)
- https://borduurservicebelgie.be/patches/geborduurd/ (technische specs)
- https://borduurservicebelgie.be/patches/ (bestaande configurator `.bsb-pc`, borduurkaart € 29,95)
- https://borduurservicebelgie.be/product-categorie/patch/ (winkel, prijsranges, levertijden)
- https://borduurservicebelgie.be/product/geborduurde-patch/ · /product/geprinte-patch/ · /product/pvc-patch/ · /product/gesublimeerde-patch/
- https://borduurservicebelgie.be/wp-json/wc/store/v1/products?category=patch
- https://borduurservicebelgie.be/borduren/prijzen-borduren/ · /borduren/borduurproces/ · /digitaliseren/
- https://borduurservicebelgie.be/faq/ · /offerte-aanvragen/ · /over-ons/ · /contact/
- https://borduurservicebelgie.be/beschikbare-kleuren-garen/ (→ 01-garenkleuren-polyneon.json)

Ruwe werkbestanden (niet in project): screenshots en gedownloade HTML in
`/tmp/claude-0/-home-user-Path-finding-algorithm/119da7ba-62fd-5edf-a213-c52888883035/scratchpad/pw/` en `…/scratchpad/site/`.
