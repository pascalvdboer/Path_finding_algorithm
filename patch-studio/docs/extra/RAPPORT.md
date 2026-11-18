# Rapport voor de eigenaar: naamstroken en "zelfde ontwerp, meer producten"

18 november 2026. In gewone woorden: wat er gebouwd is, wat het kost, wat je eraan kunt verdienen en wat **jij** nog moet beslissen.
Alle prijzen hieronder zijn **voorstellen**. Je past ze zelf aan in WordPress, zonder programmeur
(WooCommerce › Patch Studio · Naamstroken en WooCommerce › Patch Studio · Cross-sell).

---

## 1. Kort

- **Naamstroken met klittenband**: een aparte, snelle bestelpagina. De klant typt een naam (of plakt een hele lijst met namen),
  kiest een kleur en ziet meteen hoe elke strook eruitziet. Een strook van 10 × 2,5 cm kost **€ 5,45** (met btw), **zonder
  borduurkaart van € 45**. Vandaag kost één naamstrook bij jou € 11,17 + € 54,45: veel te duur voor de markt.
- **Zelfde ontwerp, meer producten**: nadat iemand patches in de winkelmand legt, stelt de site **hoogstens 3** passende extra's
  voor met hetzelfde ontwerp: op een pet, muts of hoodie genaaid, als lederen of PVC-sleutelhanger, of ingekaderd als cadeau.
  Niets staat vooraf aangevinkt, "Nee, bedankt" is even groot als "Toevoegen". Alleen wat technisch past wordt getoond.
- Alles werkt in het Nederlands en het Frans, op gsm en computer. Geen nep-reviews, geen aftelklokken, geen "nog maar 2 op voorraad".
- **Stand:** gebouwd en getest (meer dan 450 automatische controles, alle in orde). Je programmeur moet het nog **aansluiten** op de
  bestaande plugin (een handvol kleine aanpassingen, beschreven in `docs/handoff/extra-products-needs.md`). Daarna staat het live.

---

## 2. Naamstroken: hoe het werkt voor de klant

Drie korte schermen, ook op gsm:

1. **Namen**: één naam typen, of "Meerdere namen? Typ of plak je lijst" (bv. uit Excel: één naam per regel, eventueel met
   bloedgroep en aantal). Tien namen plakken duurt ongeveer 15 seconden. Elke strook verschijnt meteen als voorbeeld.
2. **Stijl**: kant-en-klare combinaties ("Klassiek zwart/wit", "Navy/wit (hulpdienst)", "Olijf/tan (tactisch)", "Fluo geel/zwart"),
   of zelf stofkleur, letterkleur, lettertype en rand kiezen. Formaat: 10 × 2,5 · 12 × 3 · 8 × 5 · 5 × 2,5 cm of een eigen maat.
3. **Controle**: alle stroken op een rij. Is een naam te lang voor het formaat, dan kleurt die strook rood met een eenvoudige
   oplossing ("kies smallere letters" of "kies 12 × 3 cm"). Pas als alles klopt gaat de knop "In winkelmand" aan.

Voor jou in de winkel:
- De hele lijst is **één regel** in de winkelmand en de bestelling (bv. "24 naamstroken"), met een **productielijst** (afdrukken of
  PDF) en een **CSV** voor je borduursoftware: per naam letterhoogte in mm, kleuren, rand, aantal.
- Namen met woorden als POLITIE, POLICE, DOUANE krijgen een **controle-vermelding** in de bestelling, zodat jij eerst kijkt.
- De lijst van zulke woorden pas je zelf aan.

### Voorgestelde prijzen naamstroken (per stuk, met klittenband haakzijde, geborduurde rand)

Staffel = jouw bestaande patchstaffel, **opgeteld over alle namen samen** (25 verschillende namen = 25 stuks).

| Formaat | 1–9 st. | 10–24 (−5 %) | 25–49 (−10 %) | 50–99 (−15 %) | 100+ (−20 %) |
|---|---|---|---|---|---|
| 5 × 2,5 cm (bloedgroep, kort) | € 3,63 | € 3,45 | € 3,27 | € 3,09 | € 2,90 |
| **10 × 2,5 cm** (1 regel, standaard) | **€ 5,45** | € 5,17 | **€ 4,90** | € 4,63 | € 4,36 |
| 12 × 3 cm (naam + functie) | € 6,05 | € 5,75 | € 5,45 | € 5,14 | € 4,84 |
| 8 × 5 cm (ID, tot 3 regels) | € 7,02 | € 6,67 | € 6,32 | € 5,97 | € 5,61 |
| Eigen maat tot 15 × 3 / 15 × 5 cm | € 6,66 / € 7,87 | staffel idem | | | |

Prijzen met btw. In WordPress staan ze zonder btw (€ 3,00 / 4,50 / 5,00 / 5,80 …).

Opties per stuk: opnaaien (zonder klittenband) **− € 0,91** · opstrijken **− € 0,30** · zacht tegenstuk (lusband) **+ € 1,21** ·
fluo-stof **+ € 0,30** · camo-stof **+ € 0,61** · reflecterende stof **+ € 1,82**. Camo en reflecterend staan **uit** tot jij zegt
dat je die stof hebt.

**Vergelijking met de markt** (gevonden op webshops en eBay, momentopnames): één naamstrook met klittenband kost elders
± € 4 tot € 7; een ID-patch van 8 × 5 cm ± € 7 tot € 10,50. Er bestaat een goedkopere soort (tekst rechtstreeks op klittenband
geborduurd, ± € 2), maar die is minder mooi en minder stevig. Met € 5,45 zit je eerlijk in het midden, met betere afwerking en
een veel makkelijkere manier om veel namen tegelijk te bestellen (dat biedt in België en Nederland bijna niemand aan).

### Wat verdien je eraan? (ruwe schatting, vul je eigen cijfers in)

Ik ken je echte kostprijzen niet. Dit is een rekenvoorbeeld met aannames: stof + klittenband ± € 0,55 per strook,
loon/machine aan € 35 per uur, en ± 5 tot 10 minuten instelwerk per bestelling (namen in de borduursoftware, machine klaarzetten).

| Bestelling | Omzet zonder btw | Geschatte kost | Geschatte brutowinst |
|---|---|---|---|
| 1 strook 10 × 2,5 | € 4,50 | ± € 3,15 + instelwerk ± € 4,50 | **± € 0 (of verlies)** |
| 10 stroken | € 42,75 | ± € 20 + € 4,50 | ± € 18 |
| 25 stroken | € 101,25 | ± € 40 + € 4,50 | ± € 57 |
| 100 stroken | € 360,00 | ± € 135 + € 6 | ± € 220 |

Verzending (€ 12,10, gratis vanaf € 150) komt daar nog bovenop en betaalt de klant. **Conclusie:** grote lijsten (clubs, bedrijven,
hulpdiensten) zijn interessant; losse stroken bijna niet. Daarvoor is er een **instelkost voor kleine bestellingen** ingebouwd
(€ 9,08 met btw onder 5 stuks), die **nu uit staat**. Zie beslissing N2.

---

## 3. Zelfde ontwerp, meer producten: hoe het werkt

Na "In winkelmand" in de studio verschijnt een paneel "Ook op ...?" met maximaal 3 kaarten. Elke kaart toont een **voorbeeldfoto
met het echte ontwerp van de klant** op het product, de prijs per stuk en één knop. Welke kaarten komen, hangt af van de bestelling:

| Situatie | Voorgestelde kaarten |
|---|---|
| Club of team (10–49 patches) | pet, hoodie, muts (muts eerst in oktober–februari) |
| Cadeau (1–3 patches) | kader, lederen sleutelhanger, pet |
| Foto of kleurverloop | kader, lederen sleutelhanger, pet (**geen** PVC: dat kan niet met een foto) |
| Grote bestelling (50+) of PVC | pet, PVC-sleutelhanger, hoodie |
| Anders | pet, hoodie, lederen sleutelhanger |

De volgorde hangt **nooit** af van wat het meest opbrengt, alleen van wat bij de klant past. Past een patch niet op een pet (te groot),
dan stelt de kaart eerlijk een kleinere maat voor met de juiste prijs. In de winkelmand staat ook een klein regeltje
"Ook op pet, muts of in een kader?" voor wie het paneel oversloeg.

De prijs is altijd **uitlegbaar**: blanco product aan je gewone winkelprijs + de patch aan de gewone patchprijs (telt mee in de
staffel) + een klein, zichtbaar bedrag "aanbrengen". De borduurkaart van € 45 wordt **niet** een tweede keer aangerekend
(digitaliseren is al gebeurd: dat is precies het voordeel voor de klant).

### Voorgestelde prijzen cross-sell (per stuk, met btw)

| Product | Opbouw | Prijs | Ter vergelijking |
|---|---|---|---|
| Trucker of snapback + patch 6 cm | pet € 18,10 + patch € 4,89 + aanbrengen € 4,84 | **€ 27,83** | je supporterspet € 27,77 |
| Muts RC027 + patch 6 cm | muts € 4,60 + patch € 4,89 + aanbrengen € 4,24 | **€ 13,72** | je supportersmuts € 24,14 |
| Hoodie Eco + patch 10 cm (borst) | hoodie € 40,81 + patch € 7,54 + aanbrengen € 4,84 | **€ 53,19** | je supportershoodie € 50,00 |
| Lederen sleutelhanger (kunstleder, gelaserd) | | **€ 8,47** | echt leder + € 1,82, naam achterop + € 1,21 |
| PVC-sleutelhanger, vanaf 50 stuks | € 5,08, in staffel € 4,32 (50+) / € 4,07 (100+) | **+ mal € 96,80 eenmalig** | levertijd ± 4 weken |
| Schaduwdoos 20 × 20 cm met gegraveerd plaatje (2 regels) | | **€ 29,95** | |
| Schaduwdoos 25 × 25 cm met plaatje | | **€ 34,99** | |
| *Uit, klaar om aan te zetten:* lederen tassenlabel met naam | | € 11,50 | |
| *Uit, klaar om aan te zetten:* pet met lederen patch | | € 28,39 | Etsy ± € 17–26 |

Aanbrengtarieven (instelbaar, zonder btw): pet € 4,00 · muts € 3,50 · hoodie borst € 4,00 · rug € 5,00.
Een patch netjes opnaaien duurt ± 5 tot 8 minuten; aan € 35 per uur is dat € 3 tot € 4,70.

### Wat verdien je eraan? (schatting, inkoopprijzen moet jij bevestigen)

| Product | Verkoop zonder btw | Geschatte kost | Geschatte brutowinst per stuk |
|---|---|---|---|
| Pet + patch | € 23,00 | aanbrengen ≈ loon; winst zit in de patch en in je marge op de blanco pet | patchmarge + petmarge (jouw cijfers) |
| Lederen sleutelhanger | € 7,00 | ± € 3–4 (blanco, ring, 2–3 min laser) | **± € 3–4** |
| Schaduwdoos 20 × 20 | € 24,75 | ± € 13–19 (lijst, plaatje, 10 min montage) | **± € 6–12** |
| PVC-sleutelhanger (50 st., staffel) | € 3,57 | onbekend; fabrieksprijs elders ± € 1,50 + transport | **± € 1,50–2** + mal |

Rekenvoorbeeld, **geen belofte**: neemt 1 op de 10 cadeaukopers een kader, dan levert dat per 100 cadeaukopers ± € 120 extra
brutowinst op. Neemt een club met 25 patches er 10 petten bij, dan is dat ± € 270 extra omzet. De module **telt zelf** hoe vaak
elke kaart getoond en gekozen wordt (beheerpagina), zodat je na een paar maanden echte cijfers hebt in plaats van schattingen.

---

## 4. Wat moet er nog gebeuren voor het live staat?

**Je programmeur** (± een halve dag, alles staat stap voor stap in `docs/handoff/extra-products-needs.md`):
1. De twee onderdelen aanzetten in de plugin en de bestanden "bouwen".
2. In de studio het paneel laten openen na "In winkelmand".
3. Kleine verbeteringen: staffel over alles samen tellen, opruimen bij verwijderen, knop "Naamstroken" op de startpagina van de studio.

**Jij** (± 30 minuten, na het aanzetten):
1. WooCommerce › Patch Studio · Naamstroken → **"Maak de naamstrookpagina aan"** en de pagina in je menu zetten.
2. WooCommerce › Patch Studio · Cross-sell → **"Maak ontbrekende producten aan"** (leder, kaders, PVC) en de prijzen nakijken.
3. In de plugin **Conditional Extra Fees**: zorg dat de categorie `naamstroken` en de nieuwe verborgen producten (leder, kader, PVC)
   **niet** onder de regel "Borduurkaart en Instelkosten" vallen. Anders betaalt de klant ten onrechte € 45 extra.
4. Eén testbestelling plaatsen: 1 naamstrook en 25 namen; daarna een patchbestelling met een pet erbij.

---

## 5. Wat moet jij beslissen?

Bij elke vraag staat wat er nu ingesteld is. Doe je niets, dan geldt dat.

### Naamstroken
| # | Vraag | Nu ingesteld | Mijn advies |
|---|---|---|---|
| N1 | Zijn de prijzen goed (§2)? | € 3,63 / 5,45 / 6,05 / 7,02 met btw | ja; controleer met je eigen kostprijs |
| N2 | Kleine bestellingen: instelkost € 9,08 onder 5 stuks, minimum 2 stuks, of niets? | **niets** (instelkost uit) | aanzetten als je merkt dat losse stroken niets opbrengen |
| N3 | Tellen naamstroken mee met de staffel van gewone patches? | ja | ja (eenvoudiger voor de klant) |
| N4 | Welke stoffen heb je echt op voorraad? | camo en reflecterend **uit**, de rest aan | alleen tonen wat in huis ligt |
| N5 | Welke **borduurlettertypes** heb je in je borduursoftware? | 5 schermletters (blok, smal, college, klassiek, schrijfletter) | het voorbeeld op het scherm moet lijken op wat je borduurt; geef me de namen, dan pas ik aan |
| N6 | Brievenbuspost voor kleine bestellingen? (stroken passen in een envelop) | gewone verzending € 12,10 | sterk aan te raden: € 12,10 verzending voor één strook van € 5,45 schrikt af |
| N7 | Belofte over proef en levertijd tonen? (bv. "meestal binnen 5 werkdagen verzonden") | **niets** tonen | alleen invullen wat je zeker kunt waarmaken |
| N8 | Vlaggen (landenvlag naast de naam)? | uit | later, als je een vlaggenbibliotheek hebt |
| N9 | Infrarood (IR) stroken voor leger/politie? | niet aangeboden | niet online; hoogstens "op aanvraag" voor professionelen |
| N10 | Kan een merrow-rand (omgezoomde rand) op stroken van 2,5 cm hoog? | niet aangeboden | alleen als jouw machine dat kan |

### Zelfde ontwerp, meer producten
| # | Vraag | Nu ingesteld | Mijn advies |
|---|---|---|---|
| C1 | Zijn de aanbrengprijzen goed? | pet € 4,84 · muts € 4,24 · hoodie € 4,84 · rug € 6,05 (met btw) | ja, dekt het naaiwerk |
| C2 | Kloppen de inkoopprijzen voor leder, kader en PVC? (die ken ik niet) | prijzen uit §3 | even narekenen voor je live gaat |
| C3 | Welke producten tonen? | pet, muts, hoodie, lederen sleutelhanger, PVC, kader **aan**; tassenlabel en pet met lederen patch **uit** | zo starten; na 3 maanden de cijfers bekijken |
| C4 | Welke petten/mutsen/hoodies uit je winkel gebruiken? | trucker, snapback, wooly, KP908; mutsen RC027, RC133X, B447, KP884; hoodies Eco, sweatshirt, rits-hoodie | aanpassen als het aanbod verandert |
| C5 | Korting (staffel) ook op de blanco pet/hoodie? | nee, enkel op patch + aanbrengen (zoals nu in je winkel) | zo laten |
| C6 | PVC-mal opnieuw aanrekenen als dezelfde PVC-patch al in de bestelling zit? | ja, mal altijd € 96,80 | als je leverancier dezelfde mal kan gebruiken: "gedeelde mal" aanzetten |
| C7 | Kaarten die bijna nooit gekozen worden automatisch verbergen? | uit (je beslist zelf) | uit laten, eerst de cijfers bekijken |
| C8 | Gebruikt je winkelmand de nieuwe "blokkenmand" van WooCommerce? | het regeltje in de mand werkt zeker in de klassieke mand | laat je programmeur dit nakijken (punt in de handoff) |

### Extra ideeën (nog niet gebouwd, wel kansrijk)
1. **Opnaaiservice op eigen kleding** (motorclubvesten, werkjassen): € 7,26 per patch, leder + € 3,63. Geen voorraad nodig.
2. **"Zet dit logo in je clubwinkel"** voor clubs met 10+ patches: je hebt al fanshops (KFC Stroomvaart, Boxing Gym).
3. **Herbestel-knop** in "Mijn account", zodat de borduurkaart echt niet opnieuw betaald wordt bij herbestelling (dat belooft je site nu al,
   maar de kassa kan het nog niet).

---

## 6. Waar vind je wat?

- Instellingen: WooCommerce › Patch Studio · Naamstroken en WooCommerce › Patch Studio · Cross-sell (alles in het Nederlands, met
  "Herstel standaard"-knoppen).
- Schermafdrukken: `docs/extra/screenshots/` (gsm en computer, Nederlands en Frans).
- Onderzoek met bronnen en concurrentieprijzen: `docs/extra/01-naamstroken.md` en `docs/extra/02-cross-sell.md`.
- Technische details voor je programmeur: `docs/handoff/extra-products-needs.md`.
