# 03 — Verdienmodel en UX: betaalde AI-ontwerpen en diensten (onderzoek nov 2026)

Rol: onderzoeker "verdienmodel". Bron voor de wens: `docs/BRIEF.md`, "WENSEN VAN DE EIGENAAR (17-11)":
*"Super gebruiksvriendelijk moet het zijn. Misschien ook met een ChatGPT grafische ontwerp sectie die ze kunnen
gebruiken tegen betaling. Dan verdienen we daar ook een beetje geld aan. Diensten leveren op de site. Slim."*
Bouwt verder op `docs/ai/01-apis.md` (kost per generatie), `docs/SPEC.md` §7.5/§12 (prijzen, fees),
`docs/research/01-merk-en-site.md` (letterlijke site-claims) en `docs/research/03-concurrenten.md`.

> **Alle bedragen in dit document zijn voorstellen**: instelbaar in de admin, standaardwaarden in
> `config/ai-pricing.json` (voorstel onderaan). Bedragen incl. 21 % btw tenzij "excl." erbij staat.
> Concurrentiecijfers komen uit zoekresultaten van 17-11-2026 (vaak derde partijen, niet de officiële pagina): nakijken
> voor je er publiek naar verwijst.

---

## 0. De kern in 6 zinnen (voor de eigenaar)

1. **Met AI-ontwerpen alleen verdien je weinig.** Grote spelers geven het bijna gratis weg (Vistaprint 64 gratis
   logo-generaties met account, Printful AI-generator gratis in de Design Maker, Canva Free ± 50 credits). Een
   bezoeker betaalt niet graag voor iets wat elders gratis lijkt.
2. **Het geld zit in wat AI veroorzaakt**: iemand zonder logo die vandaag afhaakt, bestelt nu wél patches. Plus
   diensten met echte mensenhanden (hertekenen, staal, spoed), want die zijn niet gratis te krijgen.
3. **Daarom:** 3 gratis rondes (12 voorstellen), daarna een klein pakket (€ 4,95 voor 10 rondes, € 9,95 voor 25), en
   **het volledige bedrag komt terug als korting op je patchbestelling**. Betalen voelt dan niet als een kost maar als
   een voorschot.
4. Kost voor jou: ± € 0,20–0,25 per ronde. Wie niets bestelt, betaalt de API-kosten met marge; wie bestelt, krijgt de
   ontwerpkost geschonken als welkomstkorting (≈ € 2 acquisitiekost voor een bestelling van gemiddeld € 150–250).
5. **Diensten** worden alleen aangeboden als ze een echt probleem oplossen dat de tool meet (wazig bestand, te veel
   detail, gewenste datum te vroeg, grote bestelling), nooit vooraf aangevinkt, maximum één aanbod tegelijk.
6. **Gebruiksvriendelijk:** AI zit ín de studio als eerste keuze "Ik heb nog geen logo", met één tekstvak, en elk
   voorstel gaat met één tik de studio in met de borduurcheck al gedaan.

---

## 1. Wat concurrenten doen (WebSearch, 17-11-2026)

| Speler | AI-aanbod | Gratis | Betaald | Les voor ons |
|---|---|---|---|---|
| **Vistaprint** AI Logomaker | Logo uit tekstprompt, gekoppeld aan drukwerk | 4 zonder account, **64** na registratie (TechRadar, vistaprint.sg) | Logo/drukwerk; "meest genereuze credits" volgens eigen persbericht | Registratie als ruil voor meer gratis rondes werkt; het verdienmodel is het drukwerk, niet de AI |
| **Printful** Design Maker | AI Image Generator met stijlkeuze | Design Maker gratis; aantal AI-generaties niet gevonden | Je betaalt bij fulfilment | Zelfde logica: AI = verkooptrechter voor product |
| **Canva** Magic Media | Beeldgeneratie in de editor | Free: ± 50 credits (bronnen twijfelen: per maand of eenmalig) | Pro ± € 12–15/maand, ± 500 AI-credits/maand | Abonnement past niet bij een patchklant die 1–2× per jaar bestelt |
| **Kittl** | AI-beelden/vectors voor merch | Beperkt | Pro/Expert met maandelijkse credits (200/600 in 2024; nu "tokens") | Credits per maand, reset maandelijks — te complex voor onze klant |
| **Looka** | AI-logo's | Ontwerpen gratis, downloaden betaald | $35 / $72 eenmalig per logopakket (sinds sept. 2026) | Klanten betalen wél eenmalig voor een **bruikbaar resultaat** (bestanden), niet voor het proberen |
| **PatchDesign.AI** (juni 2026) | AI-patchontwerpen (geborduurd, PVC, geweven) + "expert review" | Volgens listings gratis/freemium | Onduidelijk | Directe concurrent voor het AI-gedeelte: wij moeten winnen op "meteen borduurbaar + echte prijs + echte proef" |
| **Patch It Up Pro** (Shopify-app) | AI-patchartwork in een webshop | — | App vanaf $49 voor de winkel | Concept bestaat; geen standaard voor klantprijzen |
| Patchmakers (GS-JJ, Superlabelstore, patchstore24 — zie 03-concurrenten.md) | Gratis ontwerp/hertekening bij bestelling, gratis digitale proef, soms gratis staal(foto) | Ontwerp en proef bij opdracht | Spoed met meerprijs (GS-JJ $85/ontwerp) | Hertekenen "inbegrepen bij bestelling" is de norm voor eenvoudige logo's: betaald hertekenen alleen voor écht werk |

Prijzen van vectoriseren/digitaliseren als losse dienst (Fiverr, NL): vectortracering ± $ 10–50 eenvoudig,
$ 50–100 met hertekenen; borduurdigitalisering $ 10–100 per ontwerp, vaak ± $ 50. Een Belgisch atelier met
Nederlandstalige service, garantie en proef mag daar boven zitten.

**Conclusie:** betaalde AI moet (a) een gratis kennismaking hebben die echt iets oplevert, (b) eenmalig en klein zijn
(geen abonnement), (c) terugverdiend worden bij bestelling. Dat is meteen ons eerlijk verkoopargument.

Bronnen: [TechRadar Vistaprint review](https://www.techradar.com/pro/vistaprint-ai-logo-maker-review),
[vistaprint.sg/logomaker](https://vistaprint.sg/logomaker),
[Printful help: AI Image Generator](https://support.printful.com/hc/en-us/articles/41396543812625),
[Printful Design Maker](https://support.printful.com/hc/en-us/articles/41396532234129),
[Canva pricing 2026 (Piktochart)](https://piktochart.com/blog/canva-pricing-2026-costs-raised/),
[Kittl AI credit system](https://kittl.com/article/kittl-new-monthly-ai-credit-system),
[Looka pricing (PricingSaaS)](https://pricingsaas.com/companies/looka),
[PatchDesign.AI (theresanaiforthat)](https://theresanaiforthat.com/ai/patchdesign-ai/),
[Patch It Up Pro](https://apps.shopify.com/patch-it-up-pro),
[Fiverr vectoriseren (voorbeeld)](https://nl.fiverr.com/pv_design/do-awesome-vector-tracing-from-any-image-in-2-hours),
[Beancount: digitizing $10–100](https://beancount.io/nl/blog/2026/10/07/custom-apparel-decorator-bookkeeping-setup-fees-digitizing-tariff-job-quotes-guide).

---

## 2. Eenheid: "ontwerpronde" (geen tokens, geen credits-jargon)

Klanttaal: **"ronde"** (FR: **"essai"**). Eén ronde = één keer op "Maak voorstellen" of "Pas aan" drukken.

| Actie | Kost voor de klant | Wat de klant krijgt | Kost voor ons (01-apis.md, schatting) |
|---|---|---|---|
| Maak voorstellen | 1 ronde | 4 voorstellen, borduurstijl, transparante achtergrond | 4 × $ 0,05 ≈ $ 0,20 |
| Pas aan ("maak de wolf bozer") | 1 ronde | 2 aangepaste versies van het gekozen voorstel | 2 × $ 0,06 ≈ $ 0,12 |
| Gebruik dit ontwerp → studio | **gratis** | vectorisatie + borduurcheck | $ 0,01 |
| Mislukt / geweigerd / time-out | **gratis**, ronde automatisch terug | duidelijke melding | 0 – $ 0,20 (voor ons) |
| Inhoudsweigering (merk, persoon, haat) | **gratis** | uitleg + alternatief ("beschrijf je eigen idee") | soms een moderatiecall |

Waarom geen aparte prijzen voor "aanpassen" vs "nieuw": één eenheid is het eenvoudigst uit te leggen ("je hebt nog 7
rondes"). Rekenen met **€ 0,25 per ronde** (veilige buffer boven ± € 0,19; wisselkoers en tokenverbruik schommelen).

---

## 3. Gratis proeven: hoeveel en voor wie

| Fase | Gratis | Voorwaarde | Waarom |
|---|---|---|---|
| Anoniem | **1 ronde** (4 voorstellen) | niets; limiet per browser + gehashte IP (zie §6) | Meteen "wauw" zonder drempel |
| Met e-mailadres | **+2 rondes** (totaal 3 = 12 voorstellen + aanpassingen) | e-mail ingeven (ook om het ontwerp te bewaren); marketing-opt-in **apart en niet aangevinkt** | Eerlijke ruil, Vistaprint-model; e-mail blijft bij ons, gaat nooit naar de AI |
| Klant met patch in winkelmand of eerdere bestelling | **+5 rondes** per bestelling (fair use) | ingelogd of bestelnummer + e-mail | Wie koopt, mag rustig verfijnen |

Kost gratis proef: max 3 × € 0,25 = **€ 0,75 per e-mailadres**. Bij 300 proevers per maand ≈ € 225 — daarom het
maandplafond (§6). Lager instellen kan altijd (bv. 1 + 1).

---

## 4. Pakketten en verrekening

### 4.1 Prijsvoorstel

| Pakket (Woo-product, virtueel) | Prijs incl. | Excl. | Rondes | Per ronde | Ons kost bij 100 % verbruik | Marge excl. bij 100 % verbruik (na betaalkost ± € 0,35) | Marge bij 60 % verbruik |
|---|---|---|---|---|---|---|---|
| **Ontwerppakket** | **€ 4,95** | € 4,09 | 10 | € 0,50 | € 2,50 | **€ 1,24** | € 2,24 |
| **Clubpakket** | **€ 9,95** | € 8,22 | 25 | € 0,40 | € 6,25 | **€ 1,62** | € 4,12 |

- Geen kleiner pakket (bv. € 1,95): de betaalkost (Bancontact/kaart ± € 0,25–0,40 per transactie, nakijken bij jullie
  betaalprovider) eet dan de marge op.
- Geen abonnement: patchklanten bestellen 1–2× per jaar.
- Rondes blijven **24 maanden** geldig (eenvoudig, eerlijk; geen druk om "snel op te gebruiken").

### 4.2 Terug als korting bij patchbestelling (de motor)

- **Het volledige pakketbedrag** (€ 4,95 / € 9,95) wordt automatisch als korting gezet op de **eerste patchbestelling**
  vanaf **€ 50 artikelwaarde** binnen 12 maanden, met hetzelfde e-mailadres/account. Technisch: Woo-coupon per klant,
  "individual use" uit, niet stapelbaar met een tweede ontwerppakket-coupon.
- Klanttekst (zie §8): *"Je krijgt de € 4,95 volledig terug als korting op je patches."* Geen "gratis"-truc: het pakket
  kost echt geld, en het komt echt terug.
- Gevolg voor de marge: wie bestelt, heeft netto € 0 betaald voor AI; wij dragen ± € 1,50–2,85 API- en betaalkost. Dat
  is goedkoper dan een advertentieklik en levert een bestelling op.
- **Instelbaar:** percentage terug (100 / 50 / 0 %), minimumbedrag, geldigheid.

> **Btw / boekhouding (eigenaar + boekhouder laten bevestigen):** voorstel = het pakket is een elektronische dienst met
> 21 % btw bij verkoop; de latere korting is een handelskorting op de patchfactuur (verlaagt de maatstaf van heffing
> van die bestelling). Niet behandelen als "waardebon" (EU-richtlijn 2016/1065 enkelvoudig/meervoudig doeleinde)
> zonder advies.

### 4.3 Wettelijk / eerlijk (België, EU)

- **Herroepingsrecht digitale inhoud** (WER art. VI.53, 13°): bij het kopen vinkt de klant aan *"Ik wil meteen
  beginnen en weet dat ik daardoor mijn herroepingsrecht verlies voor de rondes die ik gebruik."* Onze extra
  (vrijwillige) belofte: **ongebruikte rondes terugbetaald op vraag binnen 14 dagen.** Instelbaar, standaard aan.
- **AI-label** (EU AI-verordening art. 50, transparantieplichten vanaf 2-8-2026): elk voorstel krijgt het kleine label
  "Gemaakt met AI" en de pagina zegt welke dienst gebruikt wordt (bv. "beeldmodel van OpenAI").
- **Gebruiksrechten**: "Je mag de ontwerpen vrij gebruiken, ook commercieel. Wij claimen er geen rechten op.
  Let op: voor AI-beelden zonder menselijke bijdrage is auteursrechtelijke bescherming niet gegarandeerd, en jij blijft
  verantwoordelijk dat je ontwerp geen merk of werk van iemand anders namaakt." Wordt het ontwerp door onze ontwerper
  afgewerkt (§5), dan is er wel menselijke bijdrage.
- **Geen merken namaken**: prompt- en beeldfilter (OpenAI `moderation:auto` + eigen woordenlijst); weigering kost
  geen ronde.
- **Privacy**: naar de AI gaat alleen de ontwerpbeschrijving (+ eventueel geüpload ontwerpbeeld bij "pas aan"), nooit
  naam, e-mail, adres of bestelgegevens; vrije tekst wordt gefilterd op e-mailadressen/telefoonnummers vóór verzending.

---

## 5. Diensten: wat verkopen, voor hoeveel, en wanneer slim tonen

Uitgangspunt: wat de site al **gratis** belooft, blijft gratis — "Gratis advies voor het optimaliseren van je logo",
"Digitale proef vóór productie", digitaliseren zit in de **borduurkaart** (€ 45, 1× per bestelling). We verkopen
alleen wat **extra mensenwerk of extra materiaal** kost.

### 5.1 Dienstenlijst met prijsvoorstel

| # | Dienst (NL / FR) | Prijs incl. (voorstel) | Wat inbegrepen | Levertijd | Wie levert |
|---|---|---|---|---|---|
| D1 | **AI-ontwerp laten afwerken door onze ontwerper** / *Finition par notre graphiste* | **€ 19,95** | Gekozen AI-voorstel netjes hertekend: lijnen, tekst, kleuren naar garens, borduurbaar op de gekozen maat; 1 aanpassingsronde | 2 werkdagen | ontwerper |
| D2 | **Logo hertekenen (vectoriseren)** — eenvoudig / *Redessiner votre logo* | **€ 24,95** | Wazige/kleine JPG of foto van een logo → strakke vector (SVG/PDF/AI), tot 4 kleuren; je krijgt het bestand ook voor ander drukwerk | 2–3 werkdagen | ontwerper |
| D2b | Logo hertekenen — complex (illustratie, veel detail, schets op papier) | **€ 49** (of "prijs na bekijken" boven die grens) | idem, onbeperkt kleuren, 2 aanpassingsrondes | 3–5 werkdagen | ontwerper |
| D3 | **Borduurbestand voor je eigen machine** (DST/PES/EMB) / *Fichier de broderie pour votre machine* | **€ 39** tot ± 10.000 steken · **€ 59** groter | Digitalisering + testborduring-foto; voor wie zelf borduurt of elders laat borduren | 3 werkdagen | digitaliseerder |
| D3b | Borduurbestand erbij bij een patchbestelling | **€ 15** | Het bestand dat we toch al maken (borduurkaart) ook als download | met de proef | digitaliseerder |
| D4 | **Spoedlevering** / *Livraison express* | **+ 25 % op de patches, min. € 25** | Proef binnen 1 werkdag, productie voorrang → levering ± 1–2 weken na je akkoord (site: "Spoed mogelijk binnen 1-2 weken") | — | atelier |
| D5 | **Extra proefronde na goedkeuring** / *Épreuve supplémentaire* | **€ 9,95** | Nieuwe digitale proef voor een **inhoudelijke** wijziging nadat je de proef al goedkeurde | 1 werkdag | digitaliseerder |
| D6 | **Fysiek staal** / *Échantillon physique* | **€ 19,95** incl. verzending (BE/NL) — **volledig terug** bij bestelling ≥ 25 stuks van dat ontwerp | 1 echte patch in je stof/garen/rand, opgestuurd | ± 1–2 weken | atelier |
| D7 | Foto van een testborduring / *Photo d'un test brodé* | **gratis vanaf 25 st.**, anders € 9,95 (instelbaar) | Foto van een echte proefnaaiing vóór productie (Superlabelstore-patroon) | +2 werkdagen | atelier |

Regels:
- **Eigenaar beslist** of D5 bestaat: als "aanpassingen aan de proef zijn gratis" de huidige praktijk is, blijft dat
  zo (dan is D5 alleen voor wijzigingen **na** goedkeuring). Nooit kleine correcties aanrekenen.
- **D4 alleen tonen als de schakelaar "Spoed beschikbaar" aan staat** (capaciteit!) en per techniek (PVC: standaard uit,
  mal duurt langer).
- Alle diensten = **instelbare Woo-producten** (virtueel, of optie op de patchregel voor D3b/D4/D7) met aan/uit, prijs,
  NL/FR-tekst, levertijd en "terug bij bestelling"-regel. Zie §9 voor de JSON.

### 5.2 Wanneer tonen (triggers) — eerlijk en relevant

Algemene regels: **max. 1 dienstaanbod tegelijk** in beeld; nooit vooraf aangevinkt; "Nee, dank je" onthouden voor
dat ontwerp; altijd uitleg *waarom* (de gemeten reden); altijd ook het gratis alternatief noemen als dat bestaat.

| Trigger (gemeten door analyse/advies/prijs) | Aanbod | Gratis alternatief dat we ook tonen | Plek |
|---|---|---|---|
| Upload < 300 px korte zijde, sterke JPEG-ruis, of foto van een logo (scheef, schaduw) | D2 "Laat ons je logo hertekenen" | "Heb je een beter bestand (PDF/SVG/AI)? Upload dat." + "Wij bekijken het gratis bij je proef — is het te moeilijk, dan laten we het je weten." | assistent-kaart na analyse |
| "Ik heb enkel een schets / idee" (knop) | AI-studio (gratis rondes) **of** D2b | — | lege toestand studio |
| Borduurcheck rood/oranje op een AI-voorstel (te fijne lijnen, te veel kleuren) én automatische fix lost het niet op | D1 "Laat onze ontwerper dit afwerken" | "Vereenvoudig automatisch" (fix-knop) blijft eerst staan | voorstelkaart in AI-paneel |
| Klant kiest leverdatum/zegt "nodig tegen" en die valt vóór de normale levering, en spoed is aan | D4 met de **nieuwe** verwachte datum | "Met normale levering: rond <datum>" | stap levering / prijskaart |
| Bestelling ≥ 50 st. of ≥ € 250, of techniek met mal (PVC) | D6 staal ("Zeker zijn vóór je 100 stuks bestelt?") | gratis digitale proef staat al vermeld | bestelstap, onder de knop, klein |
| Gebruiker vinkte "Ik borduur zelf" / komt via pagina "digitaliseren" | D3 | — | aparte dienstpagina |
| Na bestelling (bedankpagina) | D3b "Wil je je borduurbestand ook?" | — | bedankpagina / mail met proef |

**Niet doen:** aftelklokken, "nog 2 plaatsen vrij", vooraf aangevinkte vakjes, diensten tonen als de analyse geen
probleem ziet, prijzen pas in de winkelmand tonen.

---

## 6. Limieten, kostenbewaking en misbruik

| Instelling | Standaard | Doel |
|---|---|---|
| Dagbudget AI (alle klanten) | € 10 | Bij overschrijding: gratis rondes pauzeren, betaalde rondes blijven werken (of "Probeer morgen opnieuw" als ook het harde plafond bereikt is) |
| Maandbudget AI | € 150 (hard plafond € 250) | Mail naar de eigenaar bij 80 % |
| Max rondes per klant per dag | 20 | Ook betaald; voorkomt scripts |
| Min. tijd tussen rondes | 8 s | Rate-limit per sessie/IP |
| Anonieme limiet | 1 ronde per browser en per gehashte IP per 24 u (max 3 per IP voor gedeelde netwerken) | IP alleen gehasht (salt), 30 dagen bewaard |
| Prompt-lengte | 400 tekens | Kost en misbruik |
| Logboek per generatie | provider, model, aantal beelden, werkelijke kost (usage), duur, uitkomst, klant-id (intern) | Kost per ronde meten → prijzen bijstellen |
| Mislukte/geweigerde rondes | automatisch teruggeboekt | Eerlijk |

Kengetallen in de admin: proevers/week, % dat een voorstel naar de studio stuurt, % dat daarna bestelt, verkochte
pakketten, ingewisselde kortingen, gemiddelde kost per ronde, AI-kost per bestelling.
**Eerste evaluatie na 4 weken:** als < 5 % van de pakketkopers bestelt, de korting verlagen naar 50 % of het gratis
aantal verlagen; als de AI-kost per bestelling < € 3 blijft, is het model gezond.

---

## 7. UX: één schermflow voor gsm en desktop

Principes (BRIEF punt 1 + STUDIO-REDESIGN gsm-eis): één tekstvak, slimme standaarden, de patch blijft altijd in beeld,
betalen zonder de studio te verlaten, nooit werk verliezen.

### 7.1 De flow in 5 stappen

```
[A] Ingang            [B] Beschrijven            [C] Voorstellen                [D] Verfijnen (optioneel)   [E] In de studio
"Nog geen logo?    →  1 tekstvak + 3 optionele →  4 kaarten met borduurcheck →  "Pas aan: maak de wolf   →  ontwerp staat op het canvas,
 Laat AI een          chips (vorm, kleuren,       + "Gebruik dit" (1 tik)       bozer" → 2 versies          prijs live, gewone flow:
 ontwerp maken"       stijl). Knop "Maak          rondeteller zichtbaar                                     maat, aantal, bestellen
                      4 voorstellen"
                                 └─ op = gratis op → [P] Pakket-sheet (betalen, terug naar waar je was)
```

### 7.2 Gsm (390 × 844) — patch/voorstel blijft bovenaan zichtbaar

```
┌──────────────────────────────┐   ┌──────────────────────────────┐   ┌──────────────────────────────┐
│ Patch Studio          NL|FR  │   │ ← Ontwerp met AI   🅘 3 gratis│   │ ← Kies een voorstel  2 over  │
│┌────────────────────────────┐│   │┌────────────────────────────┐│   │┌────────────┐┌────────────┐ │
││                            ││   ││  (lege patch-silhouet,     ││   ││  [wolf 1]  ││  [wolf 2]  │ │
││   lege patch / voorbeeld   ││   ││   voorbeeld van stijl)     ││   ││ ✓ borduur- ││ ! te fijn  │ │
││                            ││   │└────────────────────────────┘│   ││   baar     ││   detail   │ │
│└────────────────────────────┘│   │ Wat wil je op je patch?      │   │└────────────┘└────────────┘ │
│ Hoe wil je beginnen?         │   │┌────────────────────────────┐│   │┌────────────┐┌────────────┐ │
│ ┌──────────────────────────┐ │   ││Een wolf met een bijl voor  ││   ││  [wolf 3]  ││  [wolf 4]  │ │
│ │ ⬆ Ik heb een logo        │ │   ││onze motorclub, zwart-rood  ││   │└────────────┘└────────────┘ │
│ └──────────────────────────┘ │   │└────────────────────────────┘│   │ Gemaakt met AI · tik = groot │
│ ┌──────────────────────────┐ │   │ Vorm  [Rond✓][Schild][Vrij] │   │┌────────────────────────────┐│
│ │ ✨ Nog geen logo?         │ │   │ Kleuren [Laat AI kiezen ✓]  │   ││ Pas aan… "maak hem bozer"  ││
│ │   Laat AI iets maken     │ │   │ Stijl [Embleem✓][Strak][Retro]│   │└────────────────────────────┘│
│ │   3 rondes gratis        │ │   │                              │   │                              │
│ └──────────────────────────┘ │   │ ┌──────────────────────────┐ │   │ ┌──────────────────────────┐ │
│ ┌──────────────────────────┐ │   │ │   Maak 4 voorstellen     │ │   │ │ Gebruik wolf 1 →         │ │
│ │ Aa Alleen tekst / naam   │ │   │ └──────────────────────────┘ │   │ └──────────────────────────┘ │
│ └──────────────────────────┘ │   │ Kost 1 ronde · geen merken   │   │                              │
└──────────────────────────────┘   └──────────────────────────────┘   └──────────────────────────────┘
        [A] Ingang                          [B] Beschrijven                   [C] Voorstellen
```

- [B] Toetsenbord open: het voorbeeld krimpt (visualViewport), de knop blijft boven het toetsenbord.
- Wachten (± 10–20 s): de 4 kaarten verschijnen als "wordt geborduurd…"-skeletten met een rustige steek-animatie
  (reduced-motion: statisch) en de tekst "Even geduld, we tekenen 4 voorstellen (± 15 s)". Annuleren kan; dan gaat
  de ronde terug als er nog niets gegenereerd werd.
- [C] Tik op een kaart = groot op de patch-plek bovenaan (op de echte patch-render), swipe tussen voorstellen.
- [E] "Gebruik dit" sluit het AI-paneel; het ontwerp staat op het canvas, borduurcheck en prijs zijn er meteen.
  Het AI-paneel blijft bereikbaar via de tab **Ontwerp** ("✨ Nieuwe voorstellen").

**[P] Pakket-sheet (bottom sheet, gsm) — alleen als de gratis rondes op zijn:**

```
┌──────────────────────────────┐
│ Je gratis rondes zijn op     │
│ Je ontwerpen blijven bewaard.│
│                              │
│ ◉ 10 rondes      € 4,95      │
│ ○ 25 rondes      € 9,95      │
│                              │
│ ✓ Je krijgt het bedrag terug │
│   als korting op je patches  │
│   (vanaf € 50, 12 maanden).  │
│                              │
│ ☐ Ik wil meteen beginnen en  │
│   weet dat ik daardoor mijn  │
│   herroepingsrecht verlies   │
│   voor gebruikte rondes.     │
│ ┌──────────────────────────┐ │
│ │ Betaal € 4,95            │ │  ← Bancontact / Apple Pay / kaart
│ └──────────────────────────┘ │
│ Liever niet? Ga verder met   │
│ een voorstel, upload je logo,│
│ of laat onze ontwerper het   │
│ maken (€ 19,95).             │
└──────────────────────────────┘
```

Betaling: Woo-bestelling voor het pakketproduct + betaalpagina in een **nieuw venster/overlay**; het ontwerp is vóór
het betalen server-side bewaard (bestaande "bewaar ontwerp"-functie), na betaling keert de klant terug naar exact
dezelfde toestand en zijn de rondes er (webhook `payment_complete` → saldo).

### 7.3 Desktop (≥ 1025 px)

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│ Patch Studio                                                                         NL | FR     │
├────────┬──────────────────────────────────────────────────────┬──────────────────────────────────┤
│ Ontwerp│                                                      │ ✨ Ontwerp met AI    3 gratis    │
│ ✨ AI  │                                                      │ Wat wil je op je patch?          │
│ Tekst  │              GROOT CANVAS: patch-render              │ ┌──────────────────────────────┐ │
│ Vorm   │          (gekozen voorstel live op de patch,         │ │ Een wolf met een bijl …      │ │
│ Rand   │           close-up / op jas / op pet)                │ └──────────────────────────────┘ │
│ Kleuren│                                                      │ Vorm [Rond✓] [Schild] [Vrij]     │
│        │                                                      │ Kleuren [Laat AI kiezen ✓]       │
│        │                                                      │ [ Maak 4 voorstellen ]  1 ronde  │
│        │                                                      │ ┌──────┐┌──────┐┌──────┐┌──────┐ │
│        │                                                      │ │ ✓ 1  ││ ! 2  ││ ✓ 3  ││ ✓ 4  │ │
│        │                                                      │ └──────┘└──────┘└──────┘└──────┘ │
│        │                                                      │ Hover = op canvas · klik = kies  │
│        │                                                      │ Pas aan… [               ] [→]   │
│        │                                                      │ [ Gebruik voorstel 1 → ]         │
├────────┴──────────────────────────────────────────────────────┴──────────────────────────────────┤
│ 8 cm · 25 st. · € 5,66/st. · totaal € 186,50 incl. btw            [ Bestel deze patch ]          │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

Het AI-paneel vervangt tijdelijk de assistent-kolom rechts (zelfde plaats, zelfde breedte); na "Gebruik dit" komt de
assistent terug met de borduuradviezen voor het nieuwe ontwerp.

### 7.4 Microcopy-regels

- Rondeteller altijd zichtbaar en in gewone woorden: "3 gratis rondes", "Nog 7 rondes". Nooit "credits/tokens".
- Vóór elke betaalde ronde **geen** bevestigingspopup (frictie); de knop zegt "1 ronde".
- Voorbeeldprompts als placeholder, roterend: "Een leeuw met een kroon voor onze voetbalclub, blauw-wit",
  "Een bergsilhouet met 'Scouts Kalmthout' in een boog". (FR: "Un lion couronné pour notre club de foot, bleu et blanc".)
- Fout: "Dat lukte niet, je ronde is teruggezet. Probeer het opnieuw." Weigering: "Dit kunnen we niet maken: het lijkt
  op een bestaand merk of een echte persoon. Beschrijf je eigen idee, dan helpen we graag."

---

## 8. Klantteksten (NL "je" / FR "vous")

| Sleutel | NL | FR |
|---|---|---|
| ai.entry.title | Nog geen logo? Laat AI iets maken | Pas encore de logo ? Laissez l'IA vous en créer un |
| ai.entry.sub | 3 rondes gratis · meteen borduurbaar | 3 essais gratuits · directement brodable |
| ai.prompt.label | Wat wil je op je patch? | Que voulez-vous sur votre écusson ? |
| ai.generate | Maak 4 voorstellen | Créer 4 propositions |
| ai.cost | Kost 1 ronde | Coûte 1 essai |
| ai.free_left | {n} gratis rondes | {n} essais gratuits |
| ai.left | Nog {n} rondes | Encore {n} essais |
| ai.use | Gebruik dit ontwerp | Utiliser ce design |
| ai.refine | Pas aan… (bv. "maak de wolf bozer") | Modifier… (p. ex. « rendez le loup plus féroce ») |
| ai.label | Gemaakt met AI | Créé avec l'IA |
| ai.email_gate | Geef je e-mailadres en krijg 2 extra rondes. We bewaren je ontwerpen ook voor je. | Indiquez votre e-mail et recevez 2 essais en plus. Nous gardons aussi vos designs. |
| ai.pack.title | Je gratis rondes zijn op | Vos essais gratuits sont épuisés |
| ai.pack.refund | Je krijgt het bedrag terug als korting op je patches (vanaf € 50, 12 maanden geldig). | Le montant vous est rendu en réduction sur vos écussons (dès 50 €, valable 12 mois). |
| ai.pack.withdraw | Ik wil meteen beginnen en weet dat ik daardoor mijn herroepingsrecht verlies voor de rondes die ik gebruik. Ongebruikte rondes betalen we op vraag terug binnen 14 dagen. | Je souhaite commencer tout de suite et je sais que je perds mon droit de rétractation pour les essais utilisés. Les essais non utilisés sont remboursés sur demande sous 14 jours. |
| ai.error | Dat lukte niet, je ronde is teruggezet. | Cela n'a pas fonctionné, votre essai a été recrédité. |
| ai.refused | Dit kunnen we niet maken: het lijkt op een bestaand merk of een echte persoon. Beschrijf je eigen idee. | Nous ne pouvons pas créer ceci : cela ressemble à une marque existante ou à une personne réelle. Décrivez votre propre idée. |
| svc.d1 | Laat onze ontwerper dit afwerken · € 19,95 — strakke lijnen, garenkleuren, klaar om te borduren. | Faites finaliser par notre graphiste · 19,95 € — lignes nettes, couleurs de fil, prêt à broder. |
| svc.d2.reason | Je bestand is klein of wazig ({px} px). Wij bekijken het gratis bij je proef. Wil je zeker een strak resultaat? | Votre fichier est petit ou flou ({px} px). Nous le vérifions gratuitement lors de l'épreuve. Vous voulez être sûr d'un résultat net ? |
| svc.d2 | Laat ons je logo hertekenen · € 24,95 — je krijgt ook het vectorbestand. | Faites redessiner votre logo · 24,95 € — vous recevez aussi le fichier vectoriel. |
| svc.d4 | Spoed: levering rond {date} in plaats van {normalDate} · + {amount} | Express : livraison vers le {date} au lieu du {normalDate} · + {amount} |
| svc.d6 | Zeker zijn vóór je {qty} stuks bestelt? Vraag een echt staal · € 19,95, terug bij je bestelling. | Vous voulez être sûr avant de commander {qty} pièces ? Demandez un échantillon · 19,95 €, remboursé à la commande. |
| svc.no | Nee, dank je | Non merci |

---

## 9. Voorstel `config/ai-pricing.json` (standaardwaarden, alles instelbaar)

```json
{
  "unitLabel": { "nl": "ronde", "fr": "essai" },
  "free": { "anonymous": 1, "withEmail": 2, "perOrder": 5 },
  "costs": { "generate": 1, "refine": 1, "useDesign": 0, "refundOnError": true },
  "packs": [
    { "id": "ai_pack_10", "rounds": 10, "priceIncl": 4.95, "enabled": true },
    { "id": "ai_pack_25", "rounds": 25, "priceIncl": 9.95, "enabled": true }
  ],
  "validityMonths": 24,
  "refundUnusedDays": 14,
  "orderCredit": { "percent": 100, "minArticleIncl": 50, "validMonths": 12, "stackable": false },
  "limits": {
    "dailyBudgetEur": 10, "monthlyBudgetEur": 150, "monthlyHardCapEur": 250, "alertAtPercent": 80,
    "perCustomerPerDay": 20, "minSecondsBetween": 8, "anonPerIpPerDay": 3, "promptMaxChars": 400
  },
  "estCostPerRoundEur": 0.25,
  "services": {
    "d1_finish":      { "enabled": true,  "priceIncl": 19.95, "leadDays": 2 },
    "d2_redraw":      { "enabled": true,  "priceIncl": 24.95, "leadDays": 3, "minPxTrigger": 300 },
    "d2b_redraw_xl":  { "enabled": true,  "priceIncl": 49.00, "leadDays": 5 },
    "d3_file":        { "enabled": true,  "priceIncl": 39.00, "priceInclLarge": 59.00, "largeFromStitches": 10000 },
    "d3b_file_addon": { "enabled": true,  "priceIncl": 15.00 },
    "d4_rush":        { "enabled": false, "percent": 25, "minIncl": 25.00, "techniques": ["embroidered","printed","sublimated"] },
    "d5_extra_proof": { "enabled": false, "priceIncl": 9.95 },
    "d6_sample":      { "enabled": true,  "priceIncl": 19.95, "refundFromQty": 25, "offerFromQty": 50, "offerFromTotalIncl": 250 },
    "d7_photo_test":  { "enabled": true,  "priceIncl": 9.95, "freeFromQty": 25 }
  },
  "offerRules": { "maxVisible": 1, "neverPreselected": true, "rememberDismiss": true }
}
```

`d4_rush` en `d5_extra_proof` staan standaard **uit** tot de eigenaar capaciteit en proefbeleid bevestigt.

---

## 10. Wat de integratie nodig heeft (voor `docs/handoff/ai-services-needs.md`)

1. **Studio-UI (studio-team):** ingangskaart "Nog geen logo?" in de lege toestand; tab/knop "✨ AI" in de
   gereedschapsbalk (desktop) en in de tab **Ontwerp** (gsm-bottom-sheet); AI-paneel op de plaats van de
   assistent-kolom; "Gebruik dit" laadt de SVG als ontwerplaag en start de bestaande analyse/borduurcheck.
2. **Dienstkaarten** via het bestaande advies-/assistentkanaal: één kaart met reden + prijs + "Nee, dank je".
3. **Woo (premium-team):** virtuele producten voor pakketten en diensten; optie-regels D3b/D4/D7 op de patchregel;
   webhook betaalde bestelling → rondesaldo; coupon per klant voor de verrekening; checkbox herroepingsrecht in de
   pakket-checkout; "terug bij bestelling"-logica voor D6.
4. **Admin:** tab "AI & diensten": pakketten, gratis aantallen, budgetten, dienstprijzen/aan-uit, kengetallen (§6).
5. **Juridisch:** voorwaarden-paragraaf AI (gebruiksrechten, AI-label, geen merken), privacyverklaring (welke
   provider, welke data), btw-behandeling met boekhouder.

## 11. Open punten voor de eigenaar

1. Klopt de richting "klein betalen, volledig terug bij bestelling"? Of liever **volledig gratis met e-mail** (zoals
   Vistaprint) en alleen verdienen op diensten? (Beide instelbaar: pakketten uit + `free.withEmail` hoger.)
2. Prijzen D1–D7: kloppen ze met het uurloon van jullie ontwerper/digitaliseerder? (D2 € 24,95 ≈ 20–30 min werk.)
3. Spoed: echt leverbaar? Welke meerprijs en voor welke technieken?
4. Zijn aanpassingen aan de proef vandaag onbeperkt gratis? (bepaalt of D5 bestaat)
5. Fysiek staal: kan het atelier één stuk produceren zonder de planning te storen?
6. Wie volgt de AI-kosten op (mail bij 80 % budget naar welk adres)?
7. Btw-behandeling pakket + korting: boekhouder laten bevestigen.
