# Herontwerp: van formulier naar ontwerpstudio

## Oordeel van de eigenaar over versie 1.0 (dist/patch-studio-demo.html)
"Het is te basic en niet goed doordacht." Op de vraag wat het meest stoort: **"Ziet er niet indrukwekkend uit"**.
Gewenst gevoel: **Ontwerpstudio** — groot canvas centraal, alles live aanpasbaar, advies als slimme assistent ernaast.
"Zoals Canva, maar voor patches." Geen voorbeeldsite: "verras me, maar duidelijk beter dan de concurrenten".

## Wat er nu mis is (eigen analyse, screenshots in docs/screenshots/)
- Het voelt als een invulformulier met 5 genummerde stappen en veel tekst; het voorbeeld is een kleine, vlakke afbeelding opzij.
- De patch-render oogt vlak: geen echte garenglans, geen steekrichting, geen reliëf, geen licht, geen stofstructuur van dichtbij.
- Advies = lijstje vinkjes. Je ziet nergens zij-aan-zij hoe JOUW patch geborduurd / geweven / bedrukt / PVC / ... wordt.
- De tool kiest zelf een techniek (bv. gesublimeerd) zonder dat de klant het verschil ziet.
- Tegenstrijdige meldingen (groen "uitgeknipt" + oranje "dit lijkt een patch").
- Geen voorbeeld op een kledingstuk dat indruk maakt. Pijltjesknoppen-kruis en schuifbalken ogen als een admin-paneel.
- Prijsblok domineert; de patch zelf moet de held zijn.


## HARDE EIS VAN DE EIGENAAR (gsm) — gaat boven alles
"Op de telefoon zie ik het ontwerp niet eens, want ik moet naar beneden scrollen om aanpassingen te doen."
Op gsm moet de patch ALTIJD zichtbaar blijven terwijl je iets aanpast. Concreet, op 390x844 en 360x740:
- Het voorbeeld staat bovenaan en blijft vast in beeld (sticky of vaste layout), ±40-50% van de schermhoogte.
- Alle bediening zit eronder in een vaste onderste tabbalk (Ontwerp, Tekst, Vorm, Kleuren, Techniek, Prijs) die een
  compacte bottom sheet opent, zó laag dat het voorbeeld er volledig boven zichtbaar blijft. Nooit de pagina laten
  scrollen om een knop te bereiken; als een paneel meer inhoud heeft, scrollt alleen het paneel.
- Elke wijziging (grootte, slepen, tekst typen, kleur, vorm, techniek) is meteen zichtbaar in het voorbeeld zonder scrollen.
- Tijdens tekst typen (toetsenbord open!) moet het voorbeeld nog zichtbaar zijn: verklein het voorbeeld i.p.v. het weg te duwen
  (gebruik visualViewport / dvh).
- Slepen met één vinger op de patch verplaatst; knijpen (pinch) vergroot/verkleint; de pagina zelf scrollt dan niet mee.
- Prijs en "Bestellen" altijd zichtbaar in een smalle balk.
Acceptatietest (Playwright, iPhone 13 + kleine Android): na upload en bij het openen van ELK paneel en tijdens tekstinvoer
ligt de volledige patch binnen het zichtbare scherm (getBoundingClientRect binnen window.visualViewport), en
document.scrollingElement.scrollTop blijft 0. Maak screenshots van elk paneel als bewijs.


## HARDE EISEN VAN DE EIGENAAR (17-11, avond)
"UI moet proactief weergeven wat ze kunnen maken. Ze moeten veel kunnen aanpassen, en bij borduren al werken met de kleuren van Polyneon."
1. **Proactief tonen wat kan** — de klant hoeft niets te weten of te zoeken:
   - Bij openen (vóór upload): een inspirerende galerij/startpunten met echte, mooie voorbeelden van wat ze kunnen maken:
     per techniek (geborduurd, 3D-puff, geweven, bedrukt, chenille, PVC, leder), per toepassing (club, bedrijf, werkkleding,
     motorclub, jeugdbeweging, evenement, naamstrook, cadeau) en per vorm. Klik = start met dat sjabloon, meteen aanpasbaar.
   - Startsjablonen (embleem met boogtekst, schild, banner, naamstrook, rond logo, vintage badge, ...) die je met eigen tekst/logo vult.
   - Tijdens het ontwerpen: contextuele suggesties ("Probeer een metallic garen voor de rand", "Dit ontwerp kan ook als 3D-puff",
     "Ook als sleutelhanger of op een pet") — alleen als het technisch past, nooit opdringerig.
2. **Veel kunnen aanpassen** (maar overzichtelijk, progressieve onthulling: eenvoudig standaard, "Meer opties" voor wie wil):
   per element kleur wijzigen (klik op een kleurvlak in het ontwerp → kies garenkleur), tekst (font, grootte, letterafstand,
   boog, contour/omlijning, schaduw), vormen (rond, ovaal, rechthoek, afgeronde rechthoek, schild, ster, contour rond ontwerp,
   eigen vorm), rand (merrow, satijnrand, hot-cut, laser, randdikte, randkleur), achtergrond (stofkleur, twill, volledig
   geborduurd), garensoorten (standaard, metallic goud/zilver, glow, reflecterend, 3D-puff — alleen wat het atelier aanbiedt via
   instellingen), lagen (volgorde, vergrendelen, verbergen), uitlijnen/centreren, spiegelen/draaien, undo/redo.
3. **Borduren = Polyneon-garenkleuren.** Bij borduren kiest de klant ALTIJD uit de echte Madeira Polyneon-kleuren met nummer en
   naam (volledige kaart: docs/research/01-garenkleuren-polyneon.json, 411 kleuren; de config/thread-palette.json is een
   selectie). Kleurkiezer: eerst de populaire/aanbevolen kleuren, zoeken op nummer of naam, groeperen per kleurfamilie, "toon alle
   411". Kleuren uit een geüpload ontwerp worden automatisch omgezet naar de dichtstbijzijnde Polyneon-kleur (ΔE2000) en zo
   getoond ("Rood → Polyneon 1747 …"), klant kan elke toewijzing aanpassen. Het voorbeeld rendert in die exacte garenkleuren.
   Polyneon-nummers komen mee in winkelwagen, bestelling en productiefiche. Bij andere technieken (bedrukt, PVC, …) een passend
   palet of vrije kleur.

## Doel van versie 2
Een klant moet bij het openen denken "wauw". De patch is de held: groot, fotorealistisch, met licht en diepte,
draaibaar/zoombaar, en meteen op een kledingstuk te zien. De studio voelt als een premium creatief product.

### Studio-indeling (desktop)
- Centraal: groot canvas (≥ 60% van de breedte) met de patch in hoge kwaliteit op een mooie, zachte achtergrond (stof/tafel),
  zoomen (scroll/pinch), direct manipuleren (slepen, schaal-handgrepen, tekst dubbelklikken om te bewerken), undo/redo.
- Weergave-schakelaar boven het canvas: "Close-up" / "Op polo" / "Op pet" / "Op jas" / "Op rugzak" (realistische mockups,
  zonder externe afbeeldingen: canvas/SVG met belichting en plooien).
- Links: compacte gereedschapsbalk met iconen (Ontwerp, Tekst, Vorm, Rand, Kleuren, Achterkant) die een paneel openen.
- Rechts: de **assistent** — vakadvies als gesprek/kaarten in de toon van een vakman uit het atelier in Kalmthout,
  met een **techniekvergelijker**: kleine live renders van JOUW ontwerp in elke techniek naast elkaar (klik = kiezen),
  elk met 1 zin waarom, prijs per stuk en levertijd. Aanbeveling duidelijk maar de klant ziet waarom.
- Onder: slanke actiebalk met maat, aantal (staffelhint), totaalprijs en "Bestellen" + "Offerte / proef aanvragen".
- Mobiel: canvas bovenaan (sticky), gereedschap als onderste tabbalk met bottom sheets, assistent als uitklapbare kaart.

### Rendering (de "wow")
- Geborduurd: satijnsteek met steekrichting per vlak (langs de vorm), garenglans die met de lichtrichting meebeweegt
  (normal map / anisotrope highlight), fill-steken voor grote vlakken, lichte reliëfschaduw, onderliggende twill zichtbaar.
- Merrow-rand als echte opgerolde rand met wikkelsteken en volume; hot-cut als strakke geschroeide rand.
- Geweven: fijne, vlakke weefstructuur, scherpe details. Bedrukt/sublimatie: zacht textiel met lichte glans.
- Chenille: pluizige lussen. PVC: rubber met afgeronde randen, zachte glans, 2D/3D niveaus. Leder: geperst/gegraveerd met diepte.
- Subtiele animatie bij wisselen van techniek (crossfade/"stitch-in"), respecteer prefers-reduced-motion.
- Snel: live slepen in "snelle" kwaliteit, bij loslaten hoge kwaliteit (≤ 150 ms bij 1200 px op een gewone laptop).

### Wat blijft
Analyse-, advies-, kleuren- en prijsengines, PHP/WooCommerce-kant, server-side prijs, productiefiche, vertalingen,
alle eisen uit BRIEF.md (vorm herkennen, nooit bijsnijden, achtergrond behouden tenzij wit, zoom/slepen, tekstlagen).
Bestaande unit- en e2e-tests moeten groen blijven (pas e2e-selectors aan waar de UI verandert).
Merk: zie docs/research/01-merk-en-site.md (blauw #21A9E2 / navy #0A1F44, Sora), maar mag premium en eigen ogen.
Geen nep-reviews of valse schaarste.
