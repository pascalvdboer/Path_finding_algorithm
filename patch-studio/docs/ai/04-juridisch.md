# 04 — Juridisch: AI-ontwerpstudio, ontwerpkredieten en diensten (BE/EU)

Onderzoeker: "juridisch" · 17-11-2026 · voor de bouwers van `includes/ai/**`, `includes/services/**`, `assets/src/ai/**`.
Bouwt verder op `docs/research/08-juridisch-i18n-a11y.md` (maatwerk, rechten-checkbox, GDPR-basis, btw-weergave),
`docs/ai/01-apis.md` (providers, retentie) en `docs/ai/03-verdienmodel-ux.md` (pakketten, diensten D1–D7).
De teksten staan ook machineleesbaar in `plugin/borduur-patch-studio/config/ai-legal.json`, met versienummer
(dat versienummer bewaren we bij elke toestemming).

> **Status:** praktisch onderzoek, geen juridisch advies. De wettelijke kern (herroeping, AI-label, GDPR, btw-plaats)
> is stabiel. Wat een jurist, boekhouder of de eigenaar moet bevestigen staat als **[EIGENAAR]**, **[BOEKHOUDER]** of
> **[NAKIJKEN]**. Data in de AI-wereld bewegen snel: de AI Act-data hieronder komen uit secundaire bronnen (advocatenkantoren,
> nov. 2026). Controleer ze in het Publicatieblad vóór livegang. Franse teksten in Belgisch-zakelijk Frans met "vous"
> (zoals 08), laten nalezen door een moedertaalspreker **[EIGENAAR]**.

Verkoper (uit het privacybeleid, zie 08): **IMTA Technics BV (Borduurservice België), Boomgaardstraat 22, 2920 Kalmthout,
BE 1012.263.492**. In de plugin altijd uit de instellingen halen, nooit hardcoden.

---

## 0. TL;DR — 16 regels voor de bouwers

1. **Label elk AI-voorstel zichtbaar** met "Gemaakt met AI" / "Créé avec l'IA", en noem op het AI-scherm welke dienst
   het maakt ("beeldmodel van OpenAI"). Ook in het winkelmandje, de ordermeta, de proefmail en de beheerpagina. §1.3
2. **Machineleesbare markering**: bewaar de C2PA-gegevens van de provider waar het kan, en schrijf in elke PNG/SVG die
   wij afleveren de IPTC-waarde `trainedAlgorithmicMedia` (XMP) of een `tEXt`-chunk. Vectoriseren of hersamplen mag de
   markering niet stilletjes wissen. §1.2
3. **De verfijn-chat zegt dat het een AI is** (AI Act art. 50(1)): "Je praat met een AI-assistent, niet met een mens." §1.1
4. **Geen merken, clubs, personages, officiële emblemen, echte personen**: drie lagen (eigen woordfilter vóór de API →
   providerfilter → menselijke check bij de digitale proef). Een weigering kost **nooit** een ronde. §3
5. **Eigen logo? Geen AI nodig**: verwijs naar de gewone upload met rechten-checkbox. Dat is gratis en exact. §3.4
6. **Rechten op AI-output**: wij dragen alle rechten die we hebben over aan de klant, niet-exclusief qua gelijkenis,
   zonder garantie dat er auteursrecht op rust. De klant mag het ontwerp vrij (ook commercieel) gebruiken. §2
7. **Kopen van rondes = twee aparte, niet vooraf aangevinkte vinkjes** op de betaalpagina van het pakket:
   (a) "start meteen" en (b) "ik weet dat ik mijn herroepingsrecht verlies voor wat al gebruikt is". Zonder beide:
   geen generatie met betaalde rondes binnen 14 dagen (gratis rondes blijven werken). §4.2
8. **Bevestigingsmail van het pakket** herhaalt die toestemming (tekstversie, datum, uur). §4.3
9. **Ongebruikte rondes**: binnen 14 dagen na aankoop terugbetaald op vraag, via een **herroepknop in "Mijn account"**
   (herroepingsfunctie, verplicht voor online overeenkomsten met herroepingsrecht sinds 19-06-2026). §4.4
10. **Rondes zijn 24 maanden geldig** (instelbaar, nooit korter dan 12). Stopt de AI-dienst, dan betalen we de
    ongebruikte rondes terug. §4.5
11. **Mislukte generatie of storing = ronde automatisch terug** (staat al in 03 als `ai.error`). §4.5
12. **Diensten** (hertekenen, digitaliseren, testfoto): één informatie- + toestemmingsvinkje in de kassa als er een dienst
    in de mand zit. Spoed en fysiek staal horen bij het maatwerk-goed: geen herroepingsrecht, wel informeren. §5
13. **GDPR**: naar de AI-provider gaat **alleen de ontwerpomschrijving** (gezuiverd door `privacy.patterns` in
    `config/ai-prompts.json`) en eventueel het gekozen beeld. Geen naam, e-mail, IP, ordernummer, klant-ID. Geen
    `user`/`safety_identifier` met een echte ID: hoogstens een HMAC per klant, standaard uit. §6.2
14. **Bewaartermijnen AI** (instelbaar): prompts + voorstellen zonder bestelling 30 dagen; opgeslagen AI-ontwerpen in een
    account 12 maanden na laatste gebruik; geweigerde prompts 30 dagen (misbruik), daarna enkel tellers; kostenlog zonder
    persoonsgegevens onbeperkt; rondeboekhouding en facturen 10 jaar **[BOEKHOUDER]**. §6.4
15. **Btw**: Belgische consument en Belgisch bedrijf 21 %; EU-bedrijf met geldig btw-nummer: verlegd; EU-consument: btw
    van zijn land via OSS (de drempel van € 10.000 is waarschijnlijk al overschreden door de patchverkoop naar NL)
    **[BOEKHOUDER]**. Belgische B2B-facturen sinds 1-1-2026 als e-factuur (Peppol). §7
16. **Eerlijk**: geen "nog maar 2 rondes aan deze prijs", geen aftelklokken, geen verzonnen aantallen ontwerpen of klanten,
    geen "gratis" voor iets wat later aangerekend wordt. De terugbetaling als korting heeft voorwaarden: noem ze altijd
    naast het bedrag (vanaf € 50, 12 maanden). §4.6

---

## 1. EU AI-verordening (AI Act, Verordening 2024/1689)

### 1.1 Welke artikels raken ons
| Artikel | Wat | Geldt voor ons? | Wat bouwen |
|---|---|---|---|
| **Art. 50(1)** | Wie een AI-systeem aanbiedt dat met mensen praat, moet zeggen dat het een AI is (tenzij het overduidelijk is) | **Ja**: de verfijn-chat ("maak de wolf bozer") | Vaste regel boven het chatveld, tekst `legal.ai.chat_notice` |
| **Art. 50(2)** | Aanbieders van AI-systemen die beelden genereren, markeren de output **machineleesbaar** en detecteerbaar als kunstmatig | **Waarschijnlijk ja**: wij bouwen een eigen AI-systeem (de studio) op een model van OpenAI en zetten het onder onze naam in gebruik. OpenAI markeert zelf (C2PA), maar onze vectorstap of herschaling kan die markering wissen | Markering behouden of zelf toevoegen (§1.2) |
| **Art. 50(4)** | Gebruikers van deepfakes melden dat het om AI gaat | Nee, zolang we geen echte personen/plaatsen nabootsen (dat weigeren we, §3) | — |
| **Art. 4** | AI-geletterdheid: wie AI inzet zorgt dat zijn mensen weten wat ze doen | Ja (geldt sinds 2-2-2025) | Korte interne instructie voor medewerkers die AI-ontwerpen nakijken (§1.4) **[EIGENAAR]** |
| Hoog-risico (bijlage III) | — | Nee, een ontwerptool voor patches is geen hoog-risico-toepassing | — |

**Data (secundaire bronnen, nov. 2026) [NAKIJKEN]:** art. 50 geldt sinds **2-8-2026**. Het AI-Omnibus-akkoord (mei 2026)
geeft systemen die al vóór die datum op de markt waren een overgangsperiode tot **2-12-2026** voor de machineleesbare
markering (50(2)). De Patch Studio-AI gaat pas na 2-8-2026 live, dus **geen overgangsperiode: vanaf de lancering
markeren**. De Commissie publiceerde richtsnoeren bij art. 50 (juli 2026) en een vrijwillige Praktijkcode voor
transparantie van AI-gegenereerde inhoud (juni 2026). Het volgen van die code is de eenvoudigste manier om te tonen
dat je in orde bent.

Boetes voor art. 50 kunnen hoog oplopen (tot € 15 mio of 3 % omzet, lager voor kmo's). Het label kost niets. Gewoon doen.

### 1.2 Machineleesbaar markeren (art. 50(2)): concreet
1. **Bewaar wat de provider meestuurt.** OpenAI zet C2PA-metadata in zijn beelden. Sla de ruwe provider-PNG op zoals
   ontvangen (privémap, zie bewaartermijnen) en gooi de C2PA-manifest niet weg bij het opslaan.
2. **Elke afgeleide afbeelding die wij afleveren** (voorbeeld in de studio, download, beeld in de proefmail) krijgt:
   - PNG: `iTXt`-chunk `XML:com.adobe.xmp` met
     `Iptc4xmpExt:DigitalSourceType = http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
     (na menselijk afwerken door onze ontwerper: `.../compositeWithTrainedAlgorithmicMedia`), plus een eenvoudige
     `tEXt`-chunk `Comment: AI-generated (Patch Studio, <provider>/<model>)`.
   - SVG (na vectoriseren): `<metadata>` met dezelfde XMP-waarde en een `<desc>`-regel.
   - JPEG/WebP: XMP-blok met dezelfde waarde.
3. **Ontwerprecord** (server, ook in ordermeta `_bps_ai`): `{ ai: true, provider, model, generated_at, refined: n,
   human_finish: bool, label_v }`. Zo blijft het ook na export in DST/EMB (borduurbestanden kennen geen metadata)
   traceerbaar.
4. **De fysieke patch** hoeft geen label te dragen: art. 50 gaat over digitale output, en tussen voorstel en patch zit
   menselijk digitaliseerwerk **[NAKIJKEN]**. Wel in de proefmail en factuurregel vermelden: "Ontwerp op basis van een
   AI-voorstel".
5. Geen "verwijder de AI-markering"-optie aanbieden, ook niet betaald.

### 1.3 Zichtbaar label (UX, ook voor eerlijkheid)
- Klein label linksboven op elke voorbeeldtegel: **icoon ✦ + "Gemaakt met AI"**. Tekst in de DOM (niet in het
  canvas), contrast ≥ 4,5:1, `aria-label` gelijk aan de tekst.
- Onder de voorstellen één zin: `legal.ai.provider_notice`.
- Na "Gebruik dit ontwerp" blijft in de studio een badge "AI-voorstel" staan zolang het ontwerp niet door een mens
  is afgewerkt; in de winkelmandregel staat `legal.ai.cart_line`.
- Geen label dat iets anders doet geloven ("Ontworpen door onze ontwerpers") zolang dat niet waar is.

### 1.4 Interne instructie (art. 4), één A4 **[EIGENAAR]**
Wat het model kan/niet kan; dat voorstellen op bestaande logo's kunnen lijken; checklist bij de proef: (1) lijkt het op
een merk/club/personage? (2) staan er echte personen of officiële emblemen in? (3) is de tekst correct gespeld (AI
spelt slecht)? Bij twijfel: niet produceren, klant contacteren.

---

## 2. Auteursrecht op AI-output: wat de klant mag

### 2.1 Stand van het recht (nov. 2026)
- In de EU is een werk beschermd als het **origineel** is: een eigen intellectuele schepping die de persoonlijkheid van
  een **mens** weerspiegelt (HvJ Infopaq C-5/08, Painer C-145/10, Cofemel C-683/17). Een prompt alleen is meestal te
  weinig. Het HvJ heeft nog niet beslist over AI-beelden; eerste nationale uitspraken (o.a. Amtsgericht München
  26-02-2026 over AI-logo's, een Tsjechische rechtbank in 2024) weigeren bescherming bij louter prompten. Zaken over
  AI en auteursrecht bij het HvJ (bv. Like Company C-250/25, advies AG Szpunar 3-11-2026) gaan over training en
  output-reproductie, niet over het auteurschap van de gebruiker **[NAKIJKEN bij arrest]**.
- Gevolg: op een **puur AI-voorstel** heeft waarschijnlijk niemand auteursrecht. Iedereen die het beeld ziet, kan het in
  principe ook gebruiken. Wordt het door onze ontwerper hertekend (dienst D1/D2), dan is er menselijke inbreng en kan
  er wel bescherming ontstaan op die bewerking.
- **Providervoorwaarden:** OpenAI kent de rechten op output toe aan de **API-klant**, dat zijn wij, niet de eindklant.
  Daarom moeten wij die rechten in onze voorwaarden doorgeven. Andere providers (Recraft, BFL, Ideogram): voorwaarden
  per provider nakijken vóór je ze inschakelt; enkel providers die commercieel gebruik en overdracht toelaten
  **[NAKIJKEN]**.
- **Merk ≠ auteursrecht.** Ook zonder auteursrecht kan een klant zijn AI-logo als merk deponeren (Benelux-merk via BOIP,
  EU-merk via EUIPO). Dat is de echte bescherming voor een clublogo. Wij geven geen merkadvies.

### 2.2 Wat we beloven (en wat niet)
| Beloven | Niet beloven |
|---|---|
| Je mag de voorstellen en je gekozen ontwerp vrij gebruiken, ook commercieel en buiten onze winkel | Dat het ontwerp uniek of exclusief is (een ander kan iets gelijkaardigs krijgen) |
| Wij claimen geen rechten op je AI-ontwerp en dragen over wat we eventueel hebben | Dat er auteursrecht op rust of dat je anderen kunt verbieden het te gebruiken |
| Wij gebruiken je ontwerp niet in reclame zonder je toestemming (opt-in, zoals 08 §3.3) | Dat het geen bestaand merk of werk raakt (we filteren en kijken na, maar garanderen niets) |
| Wij gebruiken je prompts en ontwerpen niet om AI-modellen te trainen, en kiezen providers die dat ook niet doen | — |

**Borduurbestand** (DST/EMB dat onze digitaliseerder maakt): menselijk werk van ons atelier. Voorstel: de klant krijgt
een gebruiksrecht voor patches die hij bij ons bestelt; het bestand zelf wordt op vraag geleverd tegen de digitaliseerprijs
(dienst "Digitaliseren"). **[EIGENAAR]** bevestigen; zo staat het nu niet in de AV.

### 2.3 Microcopy rechten (kort, bij de voorstellen; lang in de AV §4.7)
- `legal.ai.rights_short`: zie §8.

---

## 3. Merkinbreuk en ander verboden namaak: risico en filter

### 3.1 Waarom dit voor óns een risico is (niet alleen voor de klant)
- Een patch met andermans merk produceren en verkopen is zelf "gebruik in het economisch verkeer" door de producent
  (art. 9 Uniemerkenverordening 2017/1001; art. 2.20 Benelux-verdrag inzake de intellectuele eigendom). De klant laten
  aanvinken dat hij de rechten heeft, verkleint het risico maar haalt het niet weg. **Wie het borduurt, is mee
  aansprakelijk** (inbeslagname, schadevergoeding, vernietiging).
- AI maakt het risico groter: een prompt als "een springend paard in een gele schild" levert snel iets op dat op een
  bekend automerk lijkt, ook zonder merknaam in de prompt.
- Bijzonder beschermd (los van merkenrecht):
  - **Rode Kruis-embleem** en -naam (Conventies van Genève; Belgische wet van 4-7-1956): nooit, ook niet als "ehbo-kruis"
    in rood op wit. Groen kruis / wit kruis op groen kan wel.
  - **Politie-, leger-, douane-, brandweerinsignes**: misbruik kan strafbaar zijn (o.a. art. 228 Strafwetboek, onrechtmatig
    dragen van kentekens) en is misleidend. Alleen via offerte met bewijs van toelating (het `official`-bericht in
    `config/ai-prompts.json` doet dat al goed).
  - **Olympische symbolen**, wapenschilden van steden/gemeenten, de **Belgische/EU-overheidslogo's**.
  - **Echte personen** (portretrecht, art. XI.174 WER / art. 8 EVRM): niet natekenen. **Bekende figuren en personages**
    (Disney, Marvel, anime, Pokémon, voetbalclubs) zijn merk én auteursrecht.
  - **Haatsymbolen**: weigeren (anti-racismewet 1981, negationismewet; los daarvan: huisregel).

### 3.2 Filter in drie lagen (stand van de code)
De prompt-engine (`includes/ai/class-ai-prompt-engine.php`, `config/ai-prompts.json → safety`) heeft al:
`brandsHard`, `brandsSoft`, `copyIntent`, `officialInsignia`, `person`, `styleOfArtist`, `hate`, `adult`, `violence`,
`drugs`, met een systeemregel "completely original" in elke prompt. Aanvullingen vanuit juridisch oogpunt:

| Laag | Wat | Aanvulling |
|---|---|---|
| 1. Eigen woordfilter **vóór** de API | Normaliseren (hoofdletters, accenten, leetspeak `n1ke`), NL/FR/EN | Voeg toe aan `officialInsignia`: `rode kruis`, `croix-rouge`, `red cross`, `olympisch`, `olympique`, `olympic rings`, `wapenschild`, `blason de la ville`. Voeg toe aan `copyIntent`: `zoals het logo van`, `comme le logo de`, `in de stijl van het logo`, `namaak`, `contrefaçon`, `replica`. **[studio/ai-team]** |
| 2. Provider-moderatie | OpenAI `moderation: "auto"` (niet `low`) | Een beleidsweigering van de provider **niet** doorzetten naar een andere provider (staat in 01 §5) |
| 3. Mens bij de proef | Digitaliseerder kijkt elk AI-ontwerp na met de checklist §1.4 | In de beheer-orderweergave een duidelijk blok "AI-ontwerp: nakijken op merken/emblemen" + vinkje "nagekeken" met naam en datum (in `_bps_ai.review`) **[handoff premium-team]** |

Output-check met een tweede AI (beeldherkenning van logo's) is optioneel en duur; niet nodig voor de lancering zolang
laag 3 er is.

### 3.3 Beleid bij een treffer
- Geen ronde aftrekken (weigering vóór de API: er is ook geen kost).
- Vriendelijke, niet-beschuldigende tekst met een alternatief (de bestaande `safety.messages` zijn goed).
- Geen lijst tonen van wat er precies blokkeerde (anders leert men omzeilen), wel de categorie.
- Log enkel `{code, match_category, ts}` + gehashte sessie-ID, 30 dagen.
- **Herhaald omzeilen** (bv. ≥ 5 weigeringen per uur): rondes tijdelijk pauzeren met `legal.ai.abuse_pause`.

### 3.4 De eerlijke uitweg: eigen logo
Wie zijn **eigen** merk of clublogo wil, heeft geen AI nodig. Toon bij `brand`/`copy`-weigeringen de knop
"Mijn eigen logo opladen" (gratis, exact, met de gewone rechten-checkbox uit 08 §3.2). Zo verliezen we geen
klant door een vals positief (bv. "Apple" als fruitkweker-club).

**Let op [ai-team]:** de Franse teksten in `config/ai-prompts.json → safety.messages` gebruiken "tu/ton". De rest van
de plugin gebruikt "vous" (08, 03 §8). Gelijktrekken naar "vous".

---

## 4. Voorwaarden voor AI-ontwerprondes (kredieten)

### 4.1 Wat verkopen we juridisch?
- Een **ontwerpronde** = het op vraag genereren van 4 beeldvoorstellen op onze servers. Dat is een **digitale dienst**
  (Richtlijn 2019/770 art. 2(2): dienst waarmee de consument digitale gegevens kan creëren) en de geleverde beelden zijn
  **digitale inhoud**. Juridisch is de grens tussen "dienst" en "inhoud" hier niet scherp.
- **Conservatieve keuze**: we behandelen het pakket zo dat het onder beide regimes klopt: uitdrukkelijk verzoek +
  erkenning vooraf (geldt voor beide), en terugbetaling van ongebruikte rondes bij herroeping binnen 14 dagen (dat is
  bij een gedeeltelijk uitgevoerde dienst sowieso de wettelijke uitkomst: de consument betaalt enkel het deel dat al
  geleverd is, art. VI.51 WER).
- De **gratis rondes** zijn geen overeenkomst onder bezwarende titel: geen herroepingsvragen, wel het AI-label en de
  privacyzin.

### 4.2 Toestemming bij aankoop van een pakket (art. VI.53, 1° en 13° WER)
Herroepingsrecht vervalt alleen als de consument **vooraf uitdrukkelijk** vraagt om te starten binnen de 14 dagen **én**
**erkent** dat hij zijn herroepingsrecht daardoor verliest. Een zin in de AV of een vooraf aangevinkt vakje volstaat niet.

Bouw (in de Woo-kassa, alleen als er een AI-pakket in de mand zit, hook `woocommerce_review_order_before_submit` +
validatie in `woocommerce_checkout_process`; zie handoff):
- Twee **afzonderlijke**, **niet vooraf aangevinkte** vakjes, `legal.ai.pack_consent_start` en
  `legal.ai.pack_consent_ack`.
- Klant vinkt niets aan? De bestelling mag **gewoon doorgaan** (geen drempel), maar de rondes worden pas bruikbaar na
  14 dagen, of eerder zodra de klant de twee vakjes alsnog aanvinkt in het AI-scherm (`legal.ai.pack_consent_later`).
  Zo blijft het een echte keuze.
- Opslaan in de ordermeta `_bps_ai_withdrawal_consent`: `{ v, start:true, ack:true, at, locale, text_sha1 }`.
- Rondes uit dit pakket die al gebruikt zijn, blijven betaald; ongebruikte zijn herroepbaar binnen 14 dagen (§4.4).

> **Correctie op 03 (`ai.pack.withdraw`):** daar staat één vakje dat starten en erkennen combineert, en de zin
> "Ongebruikte rondes betalen we op vraag terug binnen 14 dagen" kan gelezen worden als "terugbetaling duurt 14 dagen".
> Gebruik de twee vakjes uit §8 en `legal.ai.pack_unused_refund`.

### 4.3 Bevestiging op een duurzame drager (art. VI.46 §7 WER)
De orderbevestigingsmail van een AI-pakket (hook `woocommerce_email_after_order_table`, enkel bij AI-pakket) bevat
`legal.ai.pack_email` met: aantal rondes, geldigheid, de bevestigde toestemming (tekstversie + datum/uur), hoe herroepen,
de korting-terug-regel, en een link naar de AV (art. AI). PDF van de AV als bijlage is niet nodig, een link naar een
**vaste, gedateerde versie** wel (`/algemene-voorwaarden/#ai` + versiedatum in de mail).

### 4.4 Herroepingsfunctie ("herroepknop") in Mijn account
Richtlijn 2023/2673 voegt art. 11bis toe aan Richtlijn 2011/83: wie online overeenkomsten sluit waarvoor een
herroepingsrecht bestaat, moet sinds **19-06-2026** een duidelijk zichtbare herroepingsfunctie aanbieden, met een
bevestigingsstap. Belgische omzetting in het WER: **[NAKIJKEN]** (exacte wettekst en artikelnummer).
- Geldt voor AI-pakketten en voor diensten (§5). **Niet** voor op maat gemaakte patches (geen herroepingsrecht).
- Bouw in "Mijn account → Ontwerprondes": bij elk pakket jonger dan 14 dagen een knop `legal.ai.withdraw_button`
  → scherm met ordernummer, ongebruikte rondes, terug te betalen bedrag → knop `legal.ai.withdraw_confirm`
  → bevestigingsmail `legal.ai.withdraw_email`. Geen inlog-truc of verborgen menu; voor gasten: link in de
  bestelmail naar dezelfde functie (getekende URL).
- Terugbetaling: `ongebruikt / totaal × prijs`, op de cent afgerond met `roundHalfUp` (nooit systematisch naar beneden),
  binnen 14 dagen, via hetzelfde betaalmiddel (Woo-refund).
  De korting-terug-coupon van dat pakket vervalt.
- Wettelijke knoptekst volgens de richtlijn: "withdraw from contract here" / "confirm withdrawal" of gelijkwaardig.
  Onze NL/FR-voorstellen liggen dicht bij de officiële taalversies; vergelijk met de Belgische wettekst **[NAKIJKEN]**.

### 4.5 Overige voorwaarden (eerlijk en eenvoudig)
| Onderwerp | Regel | Waarom |
|---|---|---|
| Geldigheid | **24 maanden** na aankoop (instelbaar, min. 12). Op de productpagina, in de mand en in de mail | Te korte vervaltermijn kan een onrechtmatig beding zijn (art. VI.83 WER); patchklanten bestellen 1–2× per jaar |
| Mislukt / storing | Ronde automatisch terug; melding `ai.error` | Je betaalt voor een resultaat, niet voor onze fout |
| Weigering door filter | Kost geen ronde | Zie §3.3 |
| Kwaliteit | Geen garantie dat een voorstel je smaak is; wel dat het technisch borduurbaar is na onze proef | Eerlijke verwachting |
| Dienst stopgezet | Ongebruikte rondes worden terugbetaald | Anders zou je betaalde rondes eenzijdig waardeloos maken |
| Overdraagbaarheid | Rondes horen bij je account/e-mailadres, niet overdraagbaar, niet inwisselbaar voor geld (behalve herroeping en stopzetting) | Eenvoud; misbruik |
| Korting terug | Pakketprijs terug als korting op je eerste patchbestelling ≥ € 50 artikelwaarde binnen 12 maanden (instelbaar) | Altijd mét voorwaarden naast het bedrag tonen (UCPD: geen misleidende weglating) |
| Misbruik | Rondes pauzeren bij herhaald omzeilen van het filter; geen schrapping zonder terugbetaling | Proportioneel |
| Prijs | Altijd incl. btw voor consumenten, prijs per ronde erbij ("€ 0,50 per ronde") | Art. VI.4 WER, prijsaanduiding per eenheid helpt vergelijken |

### 4.6 Wat NIET mag (UCPD / art. VI.93–VI.103 WER)
- "Nog maar 3 pakketten aan deze prijs", aftelklokken, "47 mensen ontwerpen nu" — tenzij echt en gemeten: niet doen.
- "Gratis" voor de pakketten (ze kosten geld; enkel "terug als korting" met voorwaarden).
- Doorstreepte "was"-prijs bij een pakket zonder echte vorige prijs (art. VI.18 WER, laagste prijs van 30 dagen).
- Beweren dat een ontwerp "uniek" of "auteursrechtelijk beschermd" is.
- Een kostprijs pas tonen nadat iemand zijn prompt heeft ingetypt en op "Maak" heeft gedrukt: de kost per ronde staat op
  de knop (`ai.cost`, 03 §8).

### 4.7 Tekst voor de algemene voorwaarden: nieuw artikel "AI-ontwerpstudio" **[EIGENAAR / jurist]**

**NL**

> **Artikel X – AI-ontwerpstudio en ontwerprondes**
> 1. *Wat het is.* In de AI-ontwerpstudio maak je met een beschrijving ontwerpvoorstellen voor patches. Die voorstellen
>    worden gemaakt door een beeldmodel van een externe aanbieder (momenteel OpenAI). Elk voorstel is gemarkeerd als
>    "Gemaakt met AI".
> 2. *Rondes.* Eén ronde geeft je {n} voorstellen of één aanpassing van een voorstel. Je krijgt {gratis} gratis rondes.
>    Extra rondes koop je in pakketten. Gekochte rondes zijn {geldigheid} maanden geldig vanaf de aankoop, horen bij je
>    account of e-mailadres en zijn niet overdraagbaar of inwisselbaar voor geld, behalve in de gevallen hieronder.
>    Lukt een ronde niet door een technische fout, of weigert ons filter je beschrijving, dan kost dat geen ronde.
> 3. *Herroepingsrecht.* Je hebt 14 dagen herroepingsrecht vanaf de aankoop van een pakket. Vraag je uitdrukkelijk om
>    meteen te beginnen en erken je dat je daardoor je herroepingsrecht verliest voor de rondes die je gebruikt, dan
>    kun je binnen die 14 dagen nog herroepen voor de rondes die je niet gebruikte. Die betalen we terug binnen 14 dagen
>    na je herroeping, via hetzelfde betaalmiddel. Herroepen doe je met de knop "Overeenkomst hier herroepen" in Mijn
>    account of via de link in je bestelmail, of met een eenduidige verklaring aan {e-mail}.
> 4. *Korting terug.* Bestel je binnen {k} maanden na de aankoop van een pakket patches voor minstens € {min}
>    (artikelwaarde, incl. btw), dan krijg je {pct} % van de pakketprijs terug als korting op die bestelling. De korting
>    geldt één keer per pakket en vervalt bij herroeping van het pakket.
> 5. *Gebruik van de ontwerpen.* Je mag de voorstellen en het ontwerp dat je kiest vrij gebruiken, ook commercieel en
>    buiten onze winkel. Wij claimen er geen rechten op en dragen alle rechten over die wij er eventueel op hebben. Een
>    AI-voorstel is niet uniek: anderen kunnen gelijkaardige voorstellen krijgen. Voor AI-beelden zonder menselijke
>    creatieve inbreng is auteursrechtelijke bescherming niet gegarandeerd. Wil je je logo beschermen, denk dan aan een
>    merkdepot.
> 6. *Wat je niet mag vragen.* Je vraagt geen ontwerpen die merken, logo's, clubwapens, personages of werken van anderen
>    namaken, echte personen afbeelden, officiële emblemen (zoals politie, leger of het Rode Kruis) bevatten, of
>    haatdragend, seksueel of gewelddadig zijn. We filteren beschrijvingen automatisch en kijken elk AI-ontwerp na vóór
>    productie. We mogen een bestelling weigeren of je contacteren als een ontwerp toch rechten van anderen lijkt te
>    raken. Jij blijft verantwoordelijk voor het gebruik van je ontwerp; deze controle is geen juridische merkentoets.
> 7. *Je gegevens.* Naar de aanbieder van het beeldmodel sturen we alleen je ontwerpbeschrijving (zonder namen,
>    contactgegevens of adressen) en, bij een aanpassing, het gekozen voorstel. Je beschrijvingen en ontwerpen worden
>    niet gebruikt om AI-modellen te trainen. Meer in ons privacybeleid.
> 8. *Stopzetting.* Stoppen we met de AI-ontwerpstudio, dan betalen we je ongebruikte, nog geldige rondes terug.
> 9. Voor de patches zelf blijven de gewone voorwaarden gelden (maatwerk, digitale proef, geen herroepingsrecht na
>    productie, wettelijke garantie).

**FR**

> **Article X – Studio de création IA et essais de création**
> 1. *Description.* Dans le studio de création IA, vous créez des propositions de design pour écussons à partir d'une
>    description. Ces propositions sont générées par un modèle d'image d'un prestataire externe (actuellement OpenAI).
>    Chaque proposition porte la mention « Créé avec l'IA ».
> 2. *Essais.* Un essai vous donne {n} propositions ou une modification d'une proposition. Vous recevez {gratis} essais
>    gratuits. Les essais supplémentaires s'achètent par packs. Les essais achetés sont valables {geldigheid} mois à
>    compter de l'achat, sont liés à votre compte ou adresse e-mail et ne sont ni cessibles ni échangeables contre de
>    l'argent, sauf dans les cas ci-dessous. Si un essai échoue à la suite d'une erreur technique, ou si notre filtre
>    refuse votre description, aucun essai n'est décompté.
> 3. *Droit de rétractation.* Vous disposez d'un droit de rétractation de 14 jours à compter de l'achat d'un pack. Si
>    vous demandez expressément de commencer immédiatement et reconnaissez perdre ainsi votre droit de rétractation pour
>    les essais utilisés, vous pouvez encore vous rétracter dans ce délai pour les essais non utilisés. Nous vous les
>    remboursons dans les 14 jours suivant votre rétractation, par le même moyen de paiement. Vous vous rétractez via le
>    bouton « Se rétracter du contrat ici » dans Mon compte ou via le lien de votre e-mail de commande, ou par une
>    déclaration dénuée d'ambiguïté adressée à {e-mail}.
> 4. *Montant remboursé en réduction.* Si, dans les {k} mois suivant l'achat d'un pack, vous commandez des écussons pour
>    au moins {min} € (valeur des articles, TVA comprise), vous récupérez {pct} % du prix du pack sous forme de réduction
>    sur cette commande. La réduction s'applique une seule fois par pack et expire en cas de rétractation du pack.
> 5. *Utilisation des designs.* Vous pouvez utiliser librement les propositions et le design choisi, y compris à des fins
>    commerciales et en dehors de notre boutique. Nous ne revendiquons aucun droit sur ceux-ci et vous cédons les droits
>    que nous pourrions éventuellement détenir. Une proposition IA n'est pas unique : d'autres personnes peuvent obtenir
>    des propositions similaires. La protection par le droit d'auteur n'est pas garantie pour des images IA sans apport
>    créatif humain. Pour protéger votre logo, pensez à un dépôt de marque.
> 6. *Demandes interdites.* Vous ne demandez pas de designs qui imitent des marques, logos, blasons de clubs, personnages
>    ou œuvres de tiers, représentent des personnes réelles, contiennent des emblèmes officiels (police, armée,
>    Croix-Rouge, etc.), ou ont un caractère haineux, sexuel ou violent. Nous filtrons automatiquement les descriptions
>    et vérifions chaque design IA avant production. Nous pouvons refuser une commande ou vous contacter si un design
>    semble porter atteinte aux droits de tiers. Vous restez responsable de l'utilisation de votre design ; ce contrôle
>    ne constitue pas un examen juridique de marques.
> 7. *Vos données.* Nous n'envoyons au prestataire du modèle d'image que votre description (sans noms, coordonnées ou
>    adresses) et, en cas de modification, la proposition choisie. Vos descriptions et designs ne sont pas utilisés pour
>    entraîner des modèles d'IA. Plus d'informations dans notre politique de confidentialité.
> 8. *Arrêt du service.* Si nous arrêtons le studio de création IA, nous remboursons vos essais non utilisés et encore
>    valables.
> 9. Pour les écussons eux-mêmes, les conditions habituelles restent d'application (fabrication sur mesure, épreuve
>    numérique, pas de droit de rétractation après production, garantie légale).

---

## 5. Diensten op de site (D1–D7 uit 03 §5)

| Dienst | Aard | Herroepingsrecht consument | Wat tonen |
|---|---|---|---|
| D1 Ontwerper werkt AI-ontwerp af | Dienst (menselijk), levert een op maat gemaakt bestand | 14 dagen, tenzij volledig uitgevoerd na uitdrukkelijk verzoek + erkenning (VI.53, 1°); bij herroeping tijdens uitvoering: evenredig deel betalen (VI.51) | Vinkje `legal.svc.consent` in de kassa |
| D2 Logo hertekenen/vectoriseren | idem | idem | idem |
| D3 Digitaliseren eigen bestand (los) | idem | idem | idem |
| D4 Spoedlevering | Toeslag op de levering van een maatwerk-goed | Geen (deel van het maatwerk, VI.53, 3°) | Maatwerk-regel uit 08 §1.4 (A/D) volstaat |
| D5 Extra proefronde | Bijkomende dienst bij het maatwerk-goed | Formeel dienst: valt onder `legal.svc.consent` **[NAKIJKEN]** | idem D1 |
| D6 Fysiek staal | Goed op maat | Geen (VI.53, 3°) | Maatwerk-regel |
| D7 Foto van een testborduring | Dienst | Zoals D1 | idem D1 |

Praktisch: zit er minstens één dienst D1/D2/D3/D5/D7 in de mand, toon dan in de kassa **één** vinkje
`legal.svc.consent` (niet vooraf aangevinkt). Zonder vinkje mag de bestelling door, maar dan start het atelier pas na
14 dagen (en zeggen we dat: `legal.svc.no_consent`). Herroepknop (§4.4) ook voor diensten die nog niet volledig
uitgevoerd zijn. Een dienst wordt pas getoond als hij relevant is en is nooit vooraf aangevinkt (03 §5; UCPD).

---

## 6. GDPR: prompts, uploads, kredieten

### 6.1 Rollen
- **Verwerkingsverantwoordelijke**: IMTA Technics BV.
- **Verwerker**: de AI-provider (OpenAI Ireland Ltd. voor EU-klanten **[NAKIJKEN]**), onder zijn verwerkersovereenkomst
  (DPA). De eigenaar moet die DPA **actief aanvaarden** in het provideraccount en een kopie bewaren **[EIGENAAR]**.
  Zelfde voor een fallback-provider (Recraft enz.): pas inschakelen als er een DPA is (01 §6).
- **Doorgifte buiten de EER**: OpenAI verwerkt (ook) in de VS. Grondslag: EU-VS Data Privacy Framework als de entiteit
  gecertificeerd is, anders de modelcontractbepalingen (SCC's) in de DPA **[NAKIJKEN op dataprivacyframework.gov]**.
  Vermelden in het privacybeleid. Waar beschikbaar: een API-project met **dataresidentie Europa** (01 §2; nog niet
  bevestigd voor de beeld-endpoints).
- **Training**: API-data worden volgens OpenAI niet gebruikt voor training; standaardretentie bij OpenAI tot 30 dagen
  voor misbruikcontrole (01). Zero Data Retention aanvragen als dat kan **[EIGENAAR]**.

### 6.2 Wat naar de provider gaat (en wat nooit)
| Gaat mee | Gaat nooit mee |
|---|---|
| Ontwerpbeschrijving, gezuiverd door `privacy.patterns` + `scrubNamePairs` (al gebouwd in de prompt-engine) | Naam, e-mail, telefoon, adres, IP-adres, ordernummer, WP-gebruikers-ID |
| Stijl-, vorm- en kleurkeuzes | Uploads van klanten met gezichten of persoonsgegevens (geen foto-naar-patch via AI zonder aparte beslissing en DPIA-check) |
| Bij verfijnen: het gekozen voorstel (dat zelf AI-output is) | Tekst voor naamstroken (namen gaan nooit naar AI: tekst zet de studio er zelf op) |
| Optioneel `safety_identifier`: **HMAC-SHA256(site-geheim, klant-ID)**, standaard **uit**; enkel aanzetten als de provider erom vraagt bij misbruik | Ruwe klant-ID of e-mailadres als identifier |

Tip die ook UX helpt: AI-beeldmodellen spellen tekst slecht. Laat de klant **tekst (clubnaam, namen) apart invullen**
en zet die in de studio er als echte tekst op (`textModes` in de prompt-engine). Dan gaat er nog minder persoonlijke
info naar buiten en is de spelling juist. Microcopy `legal.ai.privacy_hint`.

### 6.3 Rechtsgronden
| Verwerking | Grondslag (art. 6 AVG) |
|---|---|
| Voorstellen genereren, rondes bijhouden, pakket leveren | Uitvoering overeenkomst (6.1.b); bij gratis rondes: precontractueel/overeenkomst |
| Misbruikfilter, geweigerde prompts loggen, limieten | Gerechtvaardigd belang (6.1.f): misbruik en inbreuk voorkomen |
| Facturen, rondeboekhouding | Wettelijke verplichting (6.1.c) |
| E-mail voor "2 extra gratis rondes" (03 `ai.email_gate`) | Overeenkomst (we bewaren je ontwerpen en sturen je de link). **Nieuwsbrief = apart, optioneel vinkje** (toestemming, 6.1.a); niet koppelen aan de extra rondes (AVG art. 7(4)) |
| Ontwerp als voorbeeld tonen | Toestemming (opt-in, 08 §3.3) |

Geen geautomatiseerde besluitvorming met rechtsgevolg (art. 22): het filter weigert enkel een generatie, niet een
klant; bij twijfel kan de klant altijd een offerte vragen. Een DPIA is voor deze verwerking waarschijnlijk niet
verplicht (geen hoog risico, geen bijzondere gegevens) — kort intern noteren waarom **[EIGENAAR]**.

### 6.4 Bewaartermijnen (instelbaar, WP-Cron ruimt op)
| Gegeven | Standaard | Opmerking |
|---|---|---|
| Prompts + voorstellen van gasten/zonder bestelling | **30 dagen** na aanmaak | Gelijk aan uploads zonder bestelling (08 §2.3) |
| AI-ontwerpen in een account ("Mijn ontwerpen") | **12 maanden** na laatste opening | Klant kan zelf eerder wissen |
| Gekozen AI-ontwerp bij een bestelling | Zoals orderbestanden: **3 jaar** na laatste bestelling (herbestellen) | 08 §2.3 |
| Ruwe provider-PNG met C2PA | Zolang het afgeleide ontwerp bestaat | Nodig voor §1.2 |
| Geweigerde prompts (moderatielog) | **30 dagen**, daarna enkel tellers per categorie | Geen persoonsgegevens in tellers |
| Kostenlogboek per generatie | Onbeperkt, **zonder** persoonsgegevens (enkel tijd, model, kost, gehashte sessie) | Kostenbewaking |
| Rondesaldo/boekingen, facturen, toestemmingen herroeping | **10 jaar** (btw-bewaartermijn België sinds 2023) | Het huidige privacybeleid zegt "facturen 7 jaar" → laten actualiseren **[BOEKHOUDER]** |

### 6.5 WordPress-privacytools
Uitbreiden van de exporter/eraser uit 08 §2.6 met AI-gegevens: prompts (gezuiverd), ontwerpen, rondesaldo,
toestemmingen. Bij wissen: prompts/ontwerpen weg, rondesaldo en facturen anonimiseren maar bewaren (wettelijke plicht).

### 6.6 Privacyzin in de AI-studio (gelaagd, art. 13)
Onder het tekstvak, klein: `legal.ai.privacy_line` met link "Meer info" naar het privacybeleid (anker `#ai`).

### 6.7 Tekstblok voor het privacybeleid (via `wp_add_privacy_policy_content`) — u-vorm zoals het bestaande beleid

**NL**
> **AI-ontwerpstudio.** Als u de AI-ontwerpstudio gebruikt, verwerken wij uw ontwerpbeschrijving, uw keuzes en de
> gegenereerde ontwerpen om u ontwerpvoorstellen te tonen en uw ontwerprondes bij te houden (uitvoering van de
> overeenkomst). Om de voorstellen te maken sturen wij uw beschrijving, ontdaan van namen, contactgegevens en adressen,
> en bij een aanpassing het gekozen voorstel, naar onze verwerker {provider} ({vestiging}). Die gegevens kunnen in de
> Verenigde Staten verwerkt worden op basis van {grondslag: EU-VS Data Privacy Framework / modelcontractbepalingen}.
> {Provider} gebruikt ze niet om modellen te trainen en bewaart ze maximaal {x} dagen voor misbruikcontrole. Wij
> filteren beschrijvingen automatisch om inbreuken op rechten van anderen en misbruik te voorkomen (gerechtvaardigd
> belang); geweigerde beschrijvingen bewaren wij 30 dagen. Ontwerpen zonder bestelling wissen wij na 30 dagen,
> ontwerpen in uw account 12 maanden na uw laatste bezoek, ontwerpen bij een bestelling 3 jaar na uw laatste
> bestelling. Gegenereerde beelden bevatten een technische markering dat ze met AI gemaakt zijn.

**FR**
> **Studio de création IA.** Lorsque vous utilisez le studio de création IA, nous traitons votre description, vos choix
> et les designs générés afin de vous montrer des propositions et de gérer vos essais (exécution du contrat). Pour
> créer les propositions, nous envoyons votre description, expurgée des noms, coordonnées et adresses, et, en cas de
> modification, la proposition choisie, à notre sous-traitant {provider} ({vestiging}). Ces données peuvent être
> traitées aux États-Unis sur la base {du cadre de protection des données UE-États-Unis / des clauses contractuelles
> types}. {Provider} ne les utilise pas pour entraîner des modèles et les conserve au maximum {x} jours à des fins de
> contrôle des abus. Nous filtrons automatiquement les descriptions afin de prévenir les atteintes aux droits de tiers
> et les abus (intérêt légitime) ; les descriptions refusées sont conservées 30 jours. Les designs sans commande sont
> supprimés après 30 jours, ceux de votre compte 12 mois après votre dernière visite, ceux liés à une commande 3 ans
> après votre dernière commande. Les images générées contiennent un marquage technique indiquant qu'elles ont été créées
> par IA.

---

## 7. Btw op ontwerprondes en diensten **[BOEKHOUDER]**

| Klant | Ontwerprondes (elektronische dienst, art. 7 Uitv.Vo. 282/2011) | Menselijke diensten D1–D3/D7 (ontwerp/digitaliseren) |
|---|---|---|
| Belgische consument | 21 % BE | 21 % BE |
| Belgisch bedrijf | 21 % BE, factuur verplicht, **e-factuur via Peppol** (verplicht B2B sinds 1-1-2026) | idem |
| Bedrijf in andere EU-lidstaat met geldig btw-nummer (VIES-check bij de kassa) | Verlegd (art. 44/196 Btw-richtlijn), vermelding "Btw verlegd – art. 196 Richtlijn 2006/112/EG" / "Autoliquidation – art. 196 directive 2006/112/CE" | idem |
| Consument in andere EU-lidstaat (bv. NL, FR) | Btw van het land van de klant via **OSS** zodra de EU-drempel van € 10.000 (grensoverschrijdende B2C-diensten + afstandsverkopen van goederen samen) overschreden is. Met de patchverzending naar NL is dat wellicht al zo → NL 21 %, FR 20 %. Bewijs van plaats: factuuradres (bij < € 100.000 per jaar volstaat één bewijsstuk, art. 24ter Uitv.Vo.) | Ontwerpdienst aan particulier: in principe plaats van de dienstverrichter (BE 21 %) **[NAKIJKEN]**; wordt het eindresultaat automatisch/elektronisch geleverd, dan kan het als elektronische dienst gelden |
| Buiten de EU | Doorgaans geen Belgische btw; lokale regels | idem |

- **Wanneer btw?** Bij verkoop van het pakket (enkelvoudig-doelwaardebon of vooruitbetaling van een gekende dienst: in
  beide gevallen btw bij betaling).
- **Korting terug** op de patchbestelling: handelskorting die de maatstaf van die patchlevering verlaagt (03 §4.2).
  Alternatief: creditnota op het pakket. Eén manier kiezen en consequent toepassen.
- **Herroeping**: creditnota voor het terugbetaalde deel.
- Prijzen in Woo instellen excl. btw en Woo de btw per land laten berekenen (Woo "btw op basis van factuuradres"), zodat
  OSS automatisch klopt. Consumentenprijs altijd incl. btw tonen (08 §4).

---

## 8. Exacte microcopy (NL "je" / FR "vous")

Sleutels staan ook in `config/ai-legal.json` (met `version`). Vakjes nooit vooraf aangevinkt.

| Sleutel | Plaats | NL | FR |
|---|---|---|---|
| legal.ai.label | Op elke voorbeeldtegel | Gemaakt met AI | Créé avec l'IA |
| legal.ai.provider_notice | Onder de voorstellen | Deze voorstellen zijn gemaakt door een beeld-AI (beeldmodel van {provider}). Onze digitaliseerder kijkt je ontwerp na voor je proef. | Ces propositions ont été créées par une IA d'images (modèle d'image de {provider}). Notre digitaliseur vérifie votre design avant votre épreuve. |
| legal.ai.chat_notice | Boven het verfijn-chatveld | Je praat met een AI-assistent, niet met een mens. Een echte ontwerper nodig? Kies "Laat onze ontwerper dit afwerken". | Vous échangez avec un assistant IA, pas avec une personne. Besoin d'un vrai graphiste ? Choisissez « Faites finaliser par notre graphiste ». |
| legal.ai.cart_line | Winkelmandregel (label → waarde) | Ontwerp → Op basis van een AI-voorstel · nagekeken bij je proef | Design → Basé sur une proposition IA · vérifié lors de votre épreuve |
| legal.ai.rights_short | Uitklapper "Mag ik dit ontwerp gebruiken?" bij de voorstellen | Ja, je mag je ontwerp vrij gebruiken, ook voor je zaak of club. Wij claimen er geen rechten op. Een AI-voorstel is wel niet uniek: iemand anders kan iets gelijkaardigs krijgen, en auteursrecht is niet gegarandeerd. Wil je je logo beschermen? Denk aan een merkdepot. | Oui, vous pouvez utiliser librement votre design, aussi pour votre entreprise ou votre club. Nous ne revendiquons aucun droit. Une proposition IA n'est toutefois pas unique : quelqu'un d'autre peut obtenir quelque chose de similaire, et le droit d'auteur n'est pas garanti. Vous voulez protéger votre logo ? Pensez à un dépôt de marque. |
| legal.ai.privacy_line | Onder het tekstvak | Zet geen namen, adressen of contactgegevens in je beschrijving. We sturen enkel je beschrijving naar de beeld-AI, nooit wie je bent. Meer info | N'indiquez pas de noms, adresses ou coordonnées dans votre description. Nous envoyons uniquement votre description à l'IA d'images, jamais votre identité. En savoir plus |
| legal.ai.privacy_hint | Bij het tekstveld "Tekst op je patch" | Tekst zoals je clubnaam zetten we er straks zelf op, netjes gespeld. Die gaat niet naar de AI. | Le texte, comme le nom de votre club, nous l'ajoutons ensuite nous-mêmes, sans faute. Il n'est pas envoyé à l'IA. |
| legal.ai.own_logo_cta | Knop bij merk-/kopie-weigering | Mijn eigen logo opladen (gratis) | Importer mon propre logo (gratuit) |
| legal.ai.abuse_pause | Na herhaalde weigeringen | We pauzeren de AI-studio even voor je. Probeer het over een uur opnieuw, of vraag een gratis offerte aan: dan helpt onze ontwerper je verder. | Nous mettons le studio IA en pause pour vous. Réessayez dans une heure, ou demandez un devis gratuit : notre graphiste vous aidera. |
| legal.ai.pack_info | Productpagina/aankoopscherm pakket | {n} rondes · € {prijs} incl. btw (€ {per} per ronde) · {m} maanden geldig · mislukte rondes krijg je terug. Je krijgt het bedrag terug als korting op je patches (vanaf € {min}, {k} maanden geldig). | {n} essais · {prijs} € TVA comprise ({per} € par essai) · valables {m} mois · les essais échoués vous sont recrédités. Le montant vous est rendu en réduction sur vos écussons (dès {min} €, valable {k} mois). |
| legal.ai.pack_consent_start | Kassa, vakje 1 | Ik vraag uitdrukkelijk om meteen met mijn ontwerprondes te kunnen beginnen, binnen de herroepingstermijn van 14 dagen. | Je demande expressément de pouvoir commencer immédiatement à utiliser mes essais, pendant le délai de rétractation de 14 jours. |
| legal.ai.pack_consent_ack | Kassa, vakje 2 | Ik weet dat ik mijn herroepingsrecht verlies voor de rondes die ik gebruik. | Je reconnais perdre mon droit de rétractation pour les essais que j'utilise. |
| legal.ai.pack_unused_refund | Kassa, onder de vakjes | Bedenk je je binnen 14 dagen na je aankoop? Dan betalen we de rondes die je nog niet gebruikte terug. | Vous changez d'avis dans les 14 jours suivant votre achat ? Nous vous remboursons les essais non utilisés. |
| legal.ai.pack_no_consent | Kassa, als vakjes leeg blijven (info, geen fout) | Geen probleem: je kunt gewoon bestellen. Je rondes worden dan bruikbaar na 14 dagen, of meteen zodra je de twee vakjes alsnog aanvinkt in de AI-studio. | Pas de problème : vous pouvez commander. Vos essais seront alors disponibles après 14 jours, ou immédiatement dès que vous cochez les deux cases dans le studio IA. |
| legal.ai.pack_consent_later | AI-studio, als rondes nog geblokkeerd zijn | Je rondes staan klaar. Wil je meteen beginnen? Vink dan beide vakjes aan. | Vos essais sont prêts. Vous voulez commencer tout de suite ? Cochez alors les deux cases. |
| legal.ai.pack_email | Bevestigingsmail pakket | **Je ontwerprondes.** Je kocht {n} rondes, geldig tot {datum}. Je vroeg op {datum_toestemming} om {uur} om meteen te beginnen en erkende dat je je herroepingsrecht verliest voor de rondes die je gebruikt (tekstversie {v}). Bedenk je je vóór {datum_14}? Herroep dan via deze link: {link}. Ongebruikte rondes betalen we terug. Bestel je binnen {k} maanden patches voor minstens € {min}, dan krijg je € {bedrag} terug als korting. Voorwaarden: {av_link} | **Vos essais de création.** Vous avez acheté {n} essais, valables jusqu'au {datum}. Le {datum_toestemming} à {uur}, vous avez demandé à commencer immédiatement et reconnu perdre votre droit de rétractation pour les essais utilisés (version du texte {v}). Vous changez d'avis avant le {datum_14} ? Rétractez-vous via ce lien : {link}. Les essais non utilisés vous sont remboursés. Si vous commandez dans les {k} mois des écussons pour au moins {min} €, vous récupérez {bedrag} € en réduction. Conditions : {av_link} |
| legal.ai.withdraw_button | Mijn account / mail-link | Overeenkomst hier herroepen | Se rétracter du contrat ici |
| legal.ai.withdraw_summary | Bevestigingsscherm | Je herroept bestelling {nr}. Ongebruikte rondes: {u} van {n}. We betalen € {bedrag} terug binnen 14 dagen, via je oorspronkelijke betaalmethode. De korting op patches van dit pakket vervalt. | Vous vous rétractez de la commande {nr}. Essais non utilisés : {u} sur {n}. Nous remboursons {bedrag} € dans les 14 jours, via votre moyen de paiement initial. La réduction sur écussons liée à ce pack expire. |
| legal.ai.withdraw_confirm | Knop bevestigingsscherm | Herroeping bevestigen | Confirmer la rétractation |
| legal.ai.withdraw_email | Mail na herroeping | We ontvingen je herroeping voor bestelling {nr} op {datum} om {uur}. Je krijgt € {bedrag} terug binnen 14 dagen. | Nous avons bien reçu votre rétractation pour la commande {nr} le {datum} à {uur}. Vous serez remboursé de {bedrag} € dans les 14 jours. |
| legal.ai.expiry_notice | Saldo in AI-studio, 30 dagen voor vervaldatum | Je hebt nog {u} rondes, geldig tot {datum}. | Il vous reste {u} essais, valables jusqu'au {datum}. |
| legal.ai.proof_line | Proefmail / factuurregel | Ontwerp op basis van een AI-voorstel, nagekeken en gedigitaliseerd door ons atelier. | Design basé sur une proposition IA, vérifié et digitalisé par notre atelier. |
| legal.svc.consent | Kassa, als er een dienst in de mand zit | Start de dienst(en) meteen. Ik weet dat ik mijn herroepingsrecht verlies zodra de dienst volledig uitgevoerd is, en dat ik bij herroeping tijdens de uitvoering het al gedane werk betaal. | Démarrez le(s) service(s) immédiatement. Je reconnais perdre mon droit de rétractation dès que le service est entièrement exécuté, et payer le travail déjà effectué en cas de rétractation en cours d'exécution. |
| legal.svc.no_consent | Kassa, als het vakje leeg blijft (info) | Je kunt gewoon bestellen. Zonder dit vakje starten we de dienst pas na de herroepingstermijn van 14 dagen, wat je levering vertraagt. | Vous pouvez commander. Sans cette case, nous ne démarrons le service qu'après le délai de rétractation de 14 jours, ce qui retarde votre livraison. |
| legal.svc.info | Bij elke dienst (uitklapper "Goed om te weten") | Dit is werk van een echte ontwerper in ons atelier. Je krijgt het resultaat eerst te zien bij je digitale proef. | Ce travail est réalisé par un vrai graphiste de notre atelier. Vous voyez le résultat d'abord dans votre épreuve numérique. |
| legal.svc.withdraw_summary | Bevestigingsscherm herroeping dienst (nog niet gestart) | Je herroept de dienst {dienst} van bestelling {nr}. We zijn er nog niet aan begonnen: we betalen € {bedrag} terug binnen 14 dagen, via je oorspronkelijke betaalmethode. | Vous vous rétractez du service {dienst} de la commande {nr}. Nous ne l'avons pas encore commencé : nous remboursons {bedrag} € dans les 14 jours, via votre moyen de paiement initial. |
| legal.svc.withdraw_summary_started | Bevestigingsscherm herroeping dienst (al gestart) | Je herroept de dienst {dienst} van bestelling {nr}. Ons atelier is er al aan begonnen: je betaalt enkel het werk dat al gedaan is, de rest betalen we terug binnen 14 dagen. We mailen je het bedrag. | Vous vous rétractez du service {dienst} de la commande {nr}. Notre atelier l'a déjà commencé : vous payez uniquement le travail déjà effectué, nous remboursons le reste dans les 14 jours. Nous vous communiquons le montant par e-mail. |
| legal.svc.withdraw_email | Mail na herroeping dienst (nog niet gestart) | We ontvingen je herroeping voor de dienst {dienst} van bestelling {nr} op {datum} om {uur}. Je krijgt € {bedrag} terug binnen 14 dagen. | Nous avons bien reçu votre rétractation pour le service {dienst} de la commande {nr} le {datum} à {uur}. Vous serez remboursé de {bedrag} € dans les 14 jours. |
| legal.svc.withdraw_email_started | Mail na herroeping dienst (al gestart) | We ontvingen je herroeping voor de dienst {dienst} van bestelling {nr} op {datum} om {uur}. We stoppen het werk. Je betaalt enkel het deel dat al gedaan is; de rest betalen we terug binnen 14 dagen. Je krijgt nog een mail met het bedrag. | Nous avons bien reçu votre rétractation pour le service {dienst} de la commande {nr} le {datum} à {uur}. Nous arrêtons le travail. Vous payez uniquement la partie déjà effectuée ; nous remboursons le reste dans les 14 jours. Vous recevrez encore un e-mail avec le montant. |

> **Aanvulling 18-11 (review juridisch-check):** de vier `legal.svc.withdraw_*`-sleutels horen bij de herroepknop voor diensten (§4.4, §5): nog niet gestart = volle terugbetaling van de regel; al gestart = evenredig deel betalen (art. VI.51 WER), rest manueel terug. Gebouwd in `includes/services/class-service-withdrawal.php`.

Bestaande teksten in 03 §8 blijven geldig, behalve `ai.pack.withdraw` (vervangen door de drie `pack_*`-sleutels hierboven).

---

## 9. Checklist voor bouwers en handoff

**Zelf te bouwen in nieuwe AI/services-bestanden**
- [ ] Label-component + `provider_notice` + `chat_notice` (assets/src/ai).
- [ ] XMP/tEXt-markering bij elke afgeleide PNG/SVG; ruwe provider-PNG behouden (includes/ai).
- [ ] `_bps_ai`-record in ontwerp en ordermeta, inclusief `review` (wie/wanneer nagekeken).
- [ ] Weigeringen kosten geen ronde; log 30 dagen; pauze bij misbruik.
- [ ] Rondesaldo met vervaldatum, terugzetten bij fout, terugbetaling bij stopzetting (admin-actie).
- [ ] Twee toestemmingsvakjes + opslag `_bps_ai_withdrawal_consent` + blokkering betaalde rondes tot toestemming of dag 15.
- [ ] Herroepfunctie (account + getekende gastlink), proportionele Woo-refund, coupon laten vervallen.
- [ ] Dienst-toestemmingsvakje + "start na 14 dagen"-vlag op het orderitem.
- [ ] Privacy-exporter/eraser voor AI-gegevens; cron voor de bewaartermijnen §6.4.
- [ ] Geen klant-ID/e-mail/IP naar de provider (test: request-body van de echte provider bevat geen `@`, geen klant-ID).

**Handoff (in `docs/handoff/ai-services-needs.md` opnemen):**
- Kassa-hooks `woocommerce_review_order_before_submit`, `woocommerce_checkout_process`,
  `woocommerce_checkout_create_order` (meta), `woocommerce_email_after_order_table` (pakketmail) — door AI-bestanden
  zelf te registreren, maar ze delen de kassa met de bestaande maatwerk-tekst D uit 08: volgorde afspreken.
- Beheer-orderweergave: blok "AI-ontwerp nakijken" met vinkje (premium-team).
- AV-pagina en privacybeleid: teksten §4.7 en §6.7 door de eigenaar laten plaatsen (geen code).
- `config/ai-prompts.json`: FR "tu" → "vous"; extra termen §3.2 (ai/studio-team).

**Open vragen [EIGENAAR / BOEKHOUDER / jurist]**
1. Jurist: kwalificatie ontwerpronde (dienst vs. digitale inhoud) en Belgische omzetting van de herroepingsfunctie.
2. Eigenaar: OpenAI-DPA aanvaard? Zero Data Retention / EU-dataresidentie aangevraagd?
3. Eigenaar: rechten op het borduurbestand (§2.2) en geldigheid rondes (24 maanden?).
4. Boekhouder: OSS-registratie, btw-behandeling "korting terug", bewaartermijn 10 jaar, Peppol voor B2B-pakketten.
5. Eigenaar: interne AI-instructie (art. 4) opstellen en bij het atelier verspreiden.
6. Moedertaalspreker: Franse teksten nalezen.

---

## Bronnen (geraadpleegd 17-11-2026; secundaire bronnen nakijken in de primaire tekst)
- AI Act art. 50 en Omnibus-timing: Houthoff, "AI Act: transparency obligations and the AI Omnibus Regulation"
  (https://www.houthoff.com/insights/news/ai-act-transparency-obligations-and-the-ai-omnibus-regulation/);
  Ropes & Gray (aug. 2026) (https://www.ropesgray.com/en/insights/viewpoints/2026/08/102nfqm/you-talkin-to-me-operationalising-the-eu-ai-acts-transparency-obligations);
  Clifford Chance, "Making AI transparency work" (https://www.cliffordchance.com/content/dam/cliffordchance/Thought_Leadership/making-ai-transparency-work-four-implementation-questions.pdf);
  Lumenova (sept. 2026) (https://www.lumenova.ai/blog/eu-ai-act-article-50-article-5/);
  Cobalt, "Digital omnibus on AI" (https://www.cobalt.legal/news-cases/digital-omnibus-on-ai/).
- Verordening (EU) 2024/1689 (AI Act), art. 4 en 50: https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- IPTC Digital Source Type: http://cv.iptc.org/newscodes/digitalsourcetype/
- Auteursrecht en AI: Kluwer Copyright Blog over AG Szpunar in Like Company C-250/25
  (https://legalblogs.wolterskluwer.com/copyright-blog/first-reflections-on-ag-szpunars-opinion-in-like-company/);
  Pinsent Masons (https://www.pinsentmasons.com/out-law/news/ai-copyright-questions-eu-court);
  Legalink (https://www.legalink.net/en/publications/newsletters/copyright-protection-for-ai-generated-creations-310/7437/).
- Herroepingsrecht digitale diensten: Unizo (https://www.unizo.be/advies/welke-gevallen-heeft-de-consument-geen-herroepingsrecht);
  Test-Aankoop (https://www.test-aankoop.be/familie-prive/webshops/dossier/afzien-van-een-aankoop-een-automatisch-recht);
  Richtlijn 2011/83/EU zoals gewijzigd door 2019/2161 en 2023/2673 (art. 11bis herroepingsfunctie):
  https://eur-lex.europa.eu/eli/dir/2023/2673/oj — Belgische omzetting niet kunnen raadplegen (legalnews.be onbereikbaar
  vanuit deze sessie) **[NAKIJKEN]**.
- Richtlijn 2019/770 (digitale inhoud en diensten), art. 2: https://eur-lex.europa.eu/eli/dir/2019/770/oj
- Btw: Btw-richtlijn 2006/112/EG art. 44, 58, 59quater, 196; Uitv.Vo. 282/2011 art. 7 en 24ter; OSS-info FOD Financiën.
- Basis consumentenrecht, GDPR, bestaande AV/privacybeleid: zie `docs/research/08-juridisch-i18n-a11y.md` en zijn bronnen.
