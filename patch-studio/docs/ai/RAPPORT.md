# AI-ontwerpstudio en diensten: rapport voor de eigenaar

*18 november 2026. In gewone taal. Technische details: `docs/ai/SPEC-AI-DIENSTEN.md` en
`docs/handoff/ai-services-needs.md`.*

---

## 1. In het kort

- Klanten **zonder logo** beschrijven in de studio wat ze willen ("een wolf met een bijl voor onze motorclub, zwart en
  rood"). Ze krijgen **3 voorstellen** in ± 15 seconden. Ze kiezen er één en zien het meteen op hun patch. Daarna
  passen ze het verder aan zoals een gewone upload: tekst, kleuren, vorm, maat.
- De eerste **3 rondes zijn gratis** (1 meteen, 2 extra als ze hun e-mailadres geven). Daarna kopen ze een klein
  pakket: **€ 4,95 voor 10 rondes** of **€ 9,95 voor 25 rondes**.
- Wie daarna patches bestelt, krijgt het **volledige pakketbedrag terug als korting** (bestelling vanaf € 50, 12
  maanden geldig). Zo is het pakket geen drempel, en verdien je vooral aan de patches.
- Daarnaast verkoopt de studio **diensten van je atelier**, op het juiste moment en nooit opdringerig: AI-ontwerp laten
  afwerken, logo hertekenen, borduurbestand voor eigen machine, staal, spoed.
- De code is klaar en getest. Er is nog **geen echte AI-sleutel**: alles draait nu op voorbeeldontwerpen (testmodus).
  Twee dingen moeten nog ingebouwd worden door de andere teams: de **knoppen in de studio** en een **schakelaar in het
  beheer**. Zie §6.

## 2. Hoe het werkt (voor de klant)

1. In de studio tikt de klant op **"Nog geen logo? Laat AI iets maken"** (startscherm) of op **"Ontwerp met AI"**
   (in het paneel Ontwerp).
2. Hij typt wat hij wil. Tekst voor op de patch (clubnaam, jaartal) typt hij in een **apart vak**: die zetten we zelf
   in de studio, zodat hij altijd juist gespeld is (AI maakt vaak spellingsfouten in tekst).
3. Hij kan een stijl kiezen (club, mascotte, vintage, minimalistisch, militair, embleem, letters) of "Laat ons kiezen".
4. Na ± 15 s staan er 3 voorstellen. Bij elk voorstel zegt de **borduurcheck** of het goed borduurbaar is.
5. "Gebruik in mijn patch" zet het ontwerp in de studio. Kleuren worden omgezet naar echte Polyneon-garens, zoals bij
   een upload.
6. Iets kleins aanpassen ("andere kleur", "andere tekst") gebeurt **gratis in de studio**. Een nieuwe tekening
   ("dikkere lijnen", "voeg een kroon toe") kost 1 ronde.
7. Mislukt iets (storing, time-out, annuleren), dan krijgt de klant zijn ronde **altijd terug**.

**Wat de klant altijd ziet** (wettelijk verplicht of eerlijk): het label "Gemaakt met AI" op elk voorstel; dat de
beschrijving naar een AI-dienst gaat; dat we geen merken, officiële emblemen of echte personen maken; dat hij vóór
productie altijd een gratis digitale proef krijgt.

## 3. Wat het jou kost (inkoop)

De AI-dienst is standaard **OpenAI** (model `gpt-image-2`, kwaliteit "medium", 1024 × 1024 px, transparante
achtergrond). Je betaalt OpenAI per gemaakt beeld.

| Wat | Hoeveel beelden | Kost (schatting) |
|---|---|---|
| 1 ronde = 3 voorstellen | 3 × ± $ 0,05 | **± € 0,14** |
| 1 verfijning ("dikkere lijnen") | 2 × ± $ 0,05 + het voorbeeldbeeld | **± € 0,11** |
| Controle op ongepaste inhoud (moderatie) | – | gratis |
| Optioneel: omzetten naar vectorbestand (Recraft) | 1 | ± € 0,01 |
| **Waarmee de plugin rekent (veilige marge)** | | **€ 0,25 per ronde** |

- Prijzen zijn in dollar en kunnen veranderen. Ze staan in `config/ai-providers.json` met `verified: false`: kijk ze
  één keer na op de officiële prijspagina van OpenAI en zet ze daarna op "nagekeken".
- De plugin **meet de echte kost** van elke ronde (uit het antwoord van OpenAI) en toont dat in het beheer
  (*WooCommerce → Patch Studio AI → Limieten & kosten*, met CSV-export). Na een maand weet je de echte gemiddelde kost.
- **Plafonds** (instelbaar): max. **€ 10 per dag** en **€ 150 per maand**; hard plafond € 250. Mail naar jou bij 80 %.
  Is het dagbudget op, dan pauzeren eerst de gratis rondes; betaalde rondes blijven werken.
- Misbruik wordt afgeremd: max. 20 rondes per klant per dag, 3 gratis per internetadres per dag, wachttijd tussen
  rondes, pauze bij herhaalde geweigerde vragen.

**Gratis proefrondes:** 3 per persoon × € 0,14 = **± € 0,42** (max. € 0,75 met de veilige schatting). Bij 300 proevers
per maand is dat ± € 125–225. Daarom staat het maandplafond op € 150: verhoog het pas als je ziet dat de proevers
ook bestellen.

## 4. Voorgestelde verkoopprijzen en marge

### 4.1 Ontwerprondes (AI)

| Pakket | Prijs incl. btw | Excl. btw | Kost bij volledig verbruik (€ 0,14 – 0,25/ronde) | Betaalkost (± ) | Marge excl. btw |
|---|---|---|---|---|---|
| Ontwerppakket · 10 rondes | **€ 4,95** | € 4,09 | € 1,40 – 2,50 | € 0,35 | **€ 1,24 – 2,34** |
| Clubpakket · 25 rondes | **€ 9,95** | € 8,22 | € 3,50 – 6,25 | € 0,35 | **€ 1,62 – 4,37** |

In de praktijk gebruikt niet iedereen al zijn rondes; dan is de marge hoger. Maar het doel is **niet** om aan de
rondes te verdienen. Wie bestelt, krijgt het pakketbedrag terug als korting: dan kost een AI-klant je **± € 1,50 – 3**
(AI + betaalkost) voor een bestelling van gemiddeld € 150 – 250. Dat is een goedkope manier om een klant zonder logo
binnen te halen die anders wegklikt. Vuistregel: **blijft de AI-kost per bestelling onder € 3, dan is het gezond.**

Liever geen pakket onder € 4,95: de betaalkost (Bancontact/kaart) eet de marge dan op.

### 4.2 Diensten van je atelier

Hier zit de echte marge: het is jouw vakwerk, de software verkoopt het enkel op het juiste moment. Voorstel
(alles instelbaar in *WooCommerce → Patch Studio AI → Diensten*):

| Dienst | Prijs incl. | Excl. | Wanneer de studio het voorstelt | Jouw werk (schatting) |
|---|---|---|---|---|
| AI-ontwerp laten afwerken door je ontwerper (1 aanpassingsronde) | € 19,95 | € 16,49 | AI-voorstel dat de borduurcheck niet helemaal haalt, of als de klant erom vraagt | 15–25 min |
| Logo hertekenen (tot 4 kleuren, vectorbestand erbij) | € 24,95 | € 20,62 | klein, wazig of gefotografeerd logo | 20–30 min |
| Schets of illustratie uittekenen (2 rondes) | € 49,00 | € 40,50 | upload lijkt een schets op papier | 45–60 min |
| Borduurbestand voor eigen machine (DST/PES/EMB, met testfoto) | € 39,00 | € 32,23 | klant zegt dat hij zelf borduurt | digitaliseren + test |
| Borduurbestand erbij bij een bestelling | € 15,00 | € 12,40 | na "In winkelmand" | bijna niets (bestand bestaat al) |
| Fysiek staal (terug bij bestelling vanaf 25 stuks) | € 19,95 | € 16,49 | grote bestelling (≥ 50 stuks of ≥ € 250) of PVC | 1 patch + verzending |
| Foto van een testborduring | € 9,95 (gratis vanaf 25 st.) | € 8,22 | niet automatisch, enkel als optie | korte test |
| Spoedlevering | + 25 %, min. € 25 | | enkel als je het aanzet en de datum het nodig maakt | voorrang |

**Reken zelf na:** deel de prijs excl. btw door de tijd. Voorbeeld: logo hertekenen € 20,62 voor 25 minuten = ± € 50
per uur. Is dat te weinig voor je ontwerper, verhoog de prijs.

Eerlijk verkopen zit ingebouwd: maximaal 1 voorstel tegelijk, nooit vooraf aangevinkt, "Nee, dank je" even groot als
"Voeg toe", en altijd het **gratis alternatief** erbij ("Probeer eerst Vereenvoudig automatisch: dat is gratis").
Geen afteltimers, geen "nog maar 2 plaatsen", geen nep-reviews.

## 5. Wat jij moet doen

### Stap 1 — OpenAI-account en sleutel (± 20 minuten)
1. Maak een account op **platform.openai.com** met je bedrijfsgegevens (niet je persoonlijke ChatGPT-account).
2. Voor de beeldmodellen vraagt OpenAI soms een **verificatie van je organisatie** (*Settings → Organization →
   Verify*). Doe dat meteen; het kan even duren.
3. *Settings → Billing*: voeg een betaalkaart toe, laad bv. **$ 20** op en zet een **maandlimiet** (bv. $ 50). Zo kan
   de rekening nooit ontsporen, ook niet bij een fout.
4. *Settings → Data controls*: kijk na dat je gegevens **niet gebruikt worden om modellen te trainen** (standaard zo
   voor de API) en aanvaard de **verwerkersovereenkomst (DPA)**.
5. *API keys → Create new secret key*, in een apart project "Patch Studio". Geef de sleutel indien mogelijk enkel
   rechten op **Images** en **Moderations**.
6. Zet de sleutel in WordPress. **Beste manier:** laat je hosting/webbouwer in `wp-config.php` deze regel toevoegen:
   `define( 'BPS_OPENAI_API_KEY', 'sk-…' );`
   Of plak hem in *WooCommerce → Patch Studio AI → API-sleutels* (wordt versleuteld bewaard; je ziet enkel de laatste
   4 tekens). De sleutel gaat **nooit** naar de browser van een klant.
7. Plak de sleutel nooit in een mail, chat of document. Uitgelekt? Meteen intrekken bij OpenAI en een nieuwe maken.

### Stap 2 — Prijzen kiezen
- *WooCommerce → Patch Studio AI → Rondes & pakketten*: pakketprijzen (voorstel € 4,95 / € 9,95), aantal gratis rondes
  (voorstel 1 + 2 met e-mail), korting-terug (voorstel 100 %, vanaf € 50).
- *… → Diensten*: prijzen en welke diensten aan staan. Spoed en extra proefronde pas aanzetten als je de capaciteit hebt.
- *… → Limieten & kosten*: dag- en maandbudget (voorstel € 10 / € 150) en het e-mailadres voor waarschuwingen.

### Stap 3 — Eerst testen, dan live
1. Zet de module **AI-ontwerpstudio** aan in de Patch Studio-instellingen (die schakelaar wordt nog ingebouwd, §6).
2. *Patch Studio AI → Aan/uit* → **Test**: enkel jij (ingelogd als beheerder) ziet de AI-knop, met voorbeeldontwerpen
   en zonder kosten. Probeer alles op je gsm.
3. Werkt alles, en staat de sleutel erin: **Live**. Kijk de eerste weken dagelijks naar *Kosten vandaag*.

### Stap 4 — Papierwerk
- Algemene voorwaarden: artikel over ontwerprondes (herroeping, 24 maanden geldig) en AI-ontwerpen. Tekst staat klaar
  in `docs/ai/04-juridisch.md` §4.7. De privacytekst (§6.7) stelt de plugin voor in *Instellingen → Privacy → Beleidsgids*: kopieer die naar je privacypagina.
- Laat een jurist kort nakijken: de knop om de overeenkomst te herroepen en de doorgifte van beschrijvingen naar de VS
  (OpenAI). Laat de Franse teksten nalezen door een moedertaalspreker.
- Boekhouder: btw op pakketten en de korting-terug, en of je online betaalprovider automatisch terugbetaalt.
- Bij elke AI-bestelling: vink in het orderscherm **"AI-ontwerp nakijken"** aan nadat je gekeken hebt dat er geen merk,
  officieel embleem of echte persoon in staat. De productiefiche toont een waarschuwing zolang dat niet gebeurd is.

## 6. Wat nog moet gebeuren (door de andere teams)

| Wie | Wat | Waarom belangrijk |
|---|---|---|
| Studio-team | De knoppen **"Nog geen logo? Laat AI iets maken"** (startscherm) en **"Ontwerp met AI"** (paneel Ontwerp, desktop en gsm) toevoegen, en de dienstkaarten op 4 momenten tonen | Zonder knoppen ziet de klant de AI-studio niet |
| Premium-team | Schakelaar voor de modules in het beheer | Zonder schakelaar kan je de AI-studio niet aanzetten |
| Premium-team | Testscript aanvullen, diensten ook zonder AI-module laten werken (aangeraden) | Diensten verdienen ook zonder AI-kost |

Alles staat precies beschreven in `docs/handoff/ai-services-needs.md` (bovenaan: "Integratie-checklist").

## 7. Risico's en wat we eraan doen

| Risico | Hoe groot | Wat er al ingebouwd is | Wat jij doet |
|---|---|---|---|
| **Kosten lopen op** | klein | dag-, maand- en hard plafond, mail bij 80 %, limieten per klant en per internetadres | maandlimiet bij OpenAI zetten; maandelijks de kost per bestelling bekijken |
| **Klant vraagt een merk of andermans logo** (Nike, voetbalclub, stadswapen) | middel | woordfilter + moderatie weigeren dit; geen ronde aangerekend; knop "Mijn eigen logo opladen" | nakijkvinkje bij elke AI-bestelling |
| **AI-ontwerp niet mooi borduurbaar** (te fijn, te veel kleuren) | middel | instructies voor eenvoudige, vlakke ontwerpen; borduurcheck per voorstel; dienst "laten afwerken" | je digitaliseerder kijkt zoals altijd elke proef na |
| **Spelfouten in tekst** | middel | tekst komt standaard in de studio, niet in de AI-tekening | – |
| **Klant verwacht te veel** | middel | duidelijke teksten: "voorstel", gratis digitale proef vóór productie | – |
| **OpenAI wijzigt prijs of model, of ligt plat** | middel | prijzen en model instelbaar; reserve-dienst Recraft mogelijk (aparte sleutel); bij storing ronde terug | prijzen 1× per kwartaal nakijken |
| **Privacy** | klein | enkel de gezuiverde beschrijving gaat naar OpenAI; nooit naam, e-mail, IP of bestelnummer; namen/e-mails in de beschrijving worden eruit gehaald; voorstellen 30 dagen bewaard | DPA aanvaarden; privacytekst laten staan |
| **Wetgeving AI (Europese AI-verordening)** | klein | label "Gemaakt met AI" op voorstellen; markering in het beeldbestand | – |
| **Herroeping van pakketten** | klein | knop in Mijn account; terugbetaling naar verhouding; pakket waarvan de korting al gebruikt is, kan niet meer | laten bevestigen door jurist |
| **Te weinig klanten gebruiken het** | onbekend | de plugin meet hoeveel proevers bestellen | na 2–3 maanden evalueren; gratis rondes of prijzen bijstellen |

## 8. Hoe weet je of het werkt?

Na een maand live, kijk in het beheer naar:
- **gemiddelde kost per ronde** (verwacht € 0,12 – 0,20);
- **hoeveel AI-gebruikers bestellen** en de **AI-kost per bestelling** (doel: < € 3);
- **hoeveel diensten** verkocht worden (vooral "laten afwerken" en "logo hertekenen").

Kost per bestelling hoger dan € 3? Verlaag het aantal gratis rondes naar 1 + 1, of zet de kwaliteit op "low"
(*Patch Studio AI → AI-dienst*; goedkoper, iets minder fijn). Weinig bestellingen? Dan helpt een AI-studio niet bij jouw klanten en zet je ze gewoon uit: de diensten
blijven werken.
