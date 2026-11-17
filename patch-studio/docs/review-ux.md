# Review UX, merk en copy (reviewer "ux", 17-11-2026)

Getest op de dev-pagina (`npm run dev`, poort 8793) met Playwright/Chromium op desktop (1366 × 900) en gsm (390 × 844, touch).
Ik doorliep alle 5 stappen met deze bestanden:
- `eigenaar-ronde-patch.png` en `eigenaar-foto-patch.jpg` (gsm-foto);
- de ingebouwde voorbeelden;
- "Start met tekst".

Ik vergeleek met onderzoek 01 (merk) en 05 (conversie). Screenshots staan in `docs/review-ux/`.

## Oordeel in het kort
- **Flow:** goed. Je ziet meteen een volle patch, vinkjes met gemeten feiten en een donkere adviesbox "Advies van ons atelier in Kalmthout". Daarna volgen drie kaarten met status, woord, reden en prijs, en altijd een volgende knop. De prijs staat er live bij, inclusief instelkosten en verzending. Het overzicht toont "Wat er nu gebeurt" en de digitale proef onder de bestelknop. Dit volgt 05 R1–R16.
- **Merk:**
  - knoppen in blauw `#0b74a6`/`#21A9E2`, navy-accenten, Sora in de koppen;
  - geen hoofdletters als nadruk, geen uitroeptekens;
  - de vertrouwenssignalen zijn echt: vanaf 1 stuk, digitale proef, Kalmthout, WhatsApp.
- **Eerlijkheid:** geen sterren, aftellers of schaarste. Je ziet staffelkorting en alternatieven, en de goedkopere techniek staat eerlijk naast de aanbevolen techniek.
- **Toegankelijkheid:**
  - axe-core in elke stap: 0 overtredingen na mijn fix;
  - focus volgt bij een stapwissel naar de h2 en de focusring is overal zichtbaar (3 px);
  - de bestelknop werkt met het toetsenbord en de rechtencheck geeft fout + focus;
  - geen horizontale scroll op 390 px en geen consolefouten.

## Wat ik gevonden en zelf verbeterd heb
| # | Probleem (gezien in de browser) | Fix |
|---|---|---|
| 1 | **Gesublimeerd werd zwart/donkergroen** in het voorbeeld en in "Vergelijk naast elkaar" bij TEAM 24, omdat de automatische stofkleur flessengroen was. Er stond ook "Drager: glad wit polyester" naast "Stofkleur flessengroen", wat tegenstrijdig en misleidend is. | `ui/derive.js` `fabricList()`: bij sublimatie kan je alleen wit kiezen. `ui/step-technique.js`: de vergelijkingsthumbs gebruiken per techniek een stof die echt kan. `ui/step-finish.js`: "Stofkleur" verdwijnt als er maar één stof mogelijk is. |
| 2 | Bij de ronde patch van de eigenaar stond eerst "Gekleurde achtergrond herkend: **die wordt de achtergrond van je patch**". De navy rand rond de cirkel wordt echter juist weggelaten (de vorm vult de patch). | Nieuwe sleutel `analysis.found.background.solid_dropped` (NL+FR) als `type:'solid'` en `keep:false`: "Effen achtergrond rond je patch herkend: die laten we weg." |
| 3 | In stap 4 stond bij een ontwerp dat de hele patch vult "We kozen wit: daarop komen je kleuren het best uit", wat irrelevant was. | Nieuwe zin `ui.s4.artFills`: "Je ontwerp vult de hele patch, dus van de stofkleur zie je weinig of niets." Die verschijnt enkel bij een herkende vorm, als de vorm niet manueel gewijzigd is en de schaal ≥ 100 % is. |
| 4 | "Alles inbegrepen" had twee betekenissen. De kaart toonde "€ 84,70 alles inbegrepen" (met verzending), de prijskaart "€ 7,26 per stuk, alles inbegrepen" (zonder verzending). | Kaart: "€ 4,84 per stuk · totaal € 84,70, alles inbegrepen". Prijskaart: "€ 7,26 per stuk, incl. eenmalige kosten". FR mee aangepast. |
| 5 | "Instelkosten (eenmalig) eenmalig": dubbel woord in de prijskaart. | `ui/price.js`: het label "eenmalig" komt er enkel bij als het nog niet in de naam staat. |
| 6 | Gesublimeerd en bedrukt gaven exact dezelfde reden. Zo zie je het verschil niet. | `advice.reason.exact_sublimated` (NL+FR): "De inkt trekt in de stof: elke schaduw en elk verloop komt er exact op, en je patch blijft zacht en wasvast." |
| 7 | Het eigen voorbeeld "tekstlogo" (TEAM 24) kreeg als advies **bedrukken**, met de melding "Geborduurd: fijnste lijn 0,3 mm". Een voorbeeld van een borduuratelier dat borduren afraadt, is een slechte eerste indruk. | `ui/samples.js`: de witte binnenrand gaat van 12 naar 22 px. Het advies wordt nu "Wij zouden het borduren, op 8 cm. Een helder logo met 2 kleuren. Dit is precies waar borduren voor gemaakt is." |
| 8 | De schuif "Aantal garenkleuren" (6–12) toonde "2 garenkleuren" helemaal links bij een ontwerp met 2 kleuren. Dat verwart en de schuif doet dan niets. | Schuif en schaal verbergen als het ontwerp ≤ 6 kleuren heeft. In de plaats komt: "Je ontwerp telt 2 kleuren: we borduren het in 2 garenkleuren, niets valt weg." (`ui.s2.threadsFew`, NL+FR, meervoud) |
| 9 | Bij een gsm-foto stond "We hebben je patch uit de foto geknipt…" twee keer onder elkaar. | `analysis.found.cut` is korter gemaakt: "Foto van een patch herkend: we hebben de patch uitgeknipt." De melding met de knop Bijstellen blijft staan. |
| 10 | "Je verplaatst nu je ontwerp" onder het voorbeeld klonk als een status, niet als een uitnodiging. | "Sleep om je ontwerp te verplaatsen" / "Sleep om "{text}" te verplaatsen" (FR idem). |
| 11 | "Levertijd: We bevestigen de levertijd samen met je digitale proef." Het woord levertijd stond er dubbel in en er stond een hoofdletter na de dubbele punt. | `ui/step-summary.js`: de terugvalzin staat er nu zonder voorvoegsel. |
| 12 | Afwerking in het overzicht toonde "… · Normaal · …", zonder dat duidelijk is waarover het gaat. | `config/defaults.json`: het label is nu "Normale dikte" / "Épaisseur normale". Het id en de prijs zijn ongewijzigd. |
| 13 | axe: `landmark-complementary-is-top-level`, want `<aside>` stond binnen `<main>` van de site. | `ui/app.js`: `aside.bps-side` is nu `div.bps-side`. De prijskaart blijft een `section` met een label. |
| 14 | "kun je" naast "kan je" elders. In Vlaanderen gebruiken we "kan je". | `ui.s5.cancelUntilProof` en `ui.s5.customMore`. De PHP-mailtekst in `class-emails.php` bevat nog "kun je" (zie open punten). |

Na de wijzigingen:
- `npm run build`: groen, `patch-studio.js` 95,0 kB gzip, onder het budget van 100 kB;
- `npm test`: 172/172;
- `npm run test:php`: 47 vectoren groen;
- mijn walkthroughs (ronde patch, gsm-foto, TEAM 24, tekst-only) op desktop en gsm: geen consolefouten, 0 axe-overtredingen, geen horizontale scroll.

## Open punten (niet zelf aangepakt: buiten mijn rol of groter)
1. **Analyse (fijnste lijn):** de oude witte binnenrand van TEAM 24 is 12 px op een breedte van 920 px. Dat is ±1,0 mm op 8 cm, maar de analyse meet **0,3 mm**. Waarschijnlijk telt de analyse anti-aliasing of de afgeronde hoeken mee. Die onderschatting kan bij echte klantlogo's borduren onterecht naar "Kan, met aanpassing" duwen. Dit is te bekijken door de analysis- en advice-reviewer.
2. **Sublimatie en randkleur/stofkleur in PHP:** de woo- en production-kant valideert de stofkleur via `class-settings.php`. Die kant beperkt sublimatie nog niet tot wit. Een oude sessie met `fabric: black` + sublimatie valt in de UI terug op wit, maar controleer dat de payload `fabric.id` dan ook `white` is (`derive.js` doet dat via `fabric`).
3. **Mobiel:** in stap 2 en volgende staat het grote voorbeeld (± 360 px) boven het advies. Het advies valt daardoor net onder de vouw. Te overwegen is het voorbeeld vanaf stap 2 op gsm kleiner te tonen (bv. max 45 vh), zodat de adviesbox meteen zichtbaar is.
4. **Drie keer "Aanrader"** bij illustraties (sublimeren, bedrukt, geweven) is eerlijk, maar maakt de keuze minder richtinggevend. Het "Ons advies"-label doet het werk. Laat dit zo, tenzij de eigenaar sterker wil sturen.
5. **"kun je" in de PHP-mails** (`includes/woo/class-emails.php:134`): gelijktrekken met de UI ("kan je") in een volgende `.pot`-ronde.
6. **Demo opnieuw bouwen:** `npm run demo` heb ik niet opnieuw gedraaid. `dist/patch-studio-demo.html` bevat mijn copy-fixes dus pas na een nieuwe build.
7. **De focuskleur is paars `#5926C5`** (primary uit de kit). Het contrast is goed. Wie het strikt in de blauwe huisstijl wil, kan navy `#0A1F44` nemen.
