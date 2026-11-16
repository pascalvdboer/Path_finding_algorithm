# SOP — Linkbuilder & AI-vindbaarheid

**Doel:** een sterk, natuurlijk backlinkprofiel en een duidelijke online aanwezigheid opbouwen, zodat Google borduurservicebelgie.be/IMTA hoger rankt en AI-assistenten (ChatGPT, Gemini, Perplexity, Claude, Google AI Overviews) ons noemen bij vragen als "waar laat ik werkkleding borduren in België?".

Werkt samen met het **SEO-team**: hun `SILO-KAART.md` bepaalt naar welke pagina een link wijst. Dubbel werk vermijden: eerst checken in `SEO_System/` of het SEO-team een backlinklijst of beslissing heeft.

## 1. Kansen zoeken (per week 15–25, in `BACKLINKS/backlinks.csv`)
Van hoog naar laag rendement:
1. **Klanten en clubs** — sponsor- of leverancierspagina's ("onze kledij wordt geborduurd door …"). Elke gewonnen klant via Opvolging vragen of ze ons willen vermelden. Grootste en meest natuurlijke bron.
2. **Lokale en sectorvermeldingen** — Google Bedrijfsprofiel, gemeentelijke ondernemersgidsen, Unizo/Voka-ledenlijsten, Gouden Gids/goudengids.be, Trustpilot, Kompass, branchegidsen textiel/werkkleding.
3. **Leveranciers en merken** — merken die wij bedrukken/borduren hebben vaak een "waar te koop / partners"-pagina.
4. **Sportfederaties, jeugdbewegingen, scholen** — partner-/sponsorlijsten.
5. **Redactionele links** — lokale pers, regionale nieuwssites, vakbladen: nieuwswaardige verhalen (jubileum, nieuwe machine, lokaal project, opleidingsplaats). Alleen na Pascal.
6. **Gastartikels en expertbijdragen** — nuttige uitleg (borduren vs. bedrukken, welk textiel voor welke sector) op relevante blogs/vakmedia.
7. **Gebroken links en vermeldingen zonder link** — sites die ons al noemen zonder link, of naar dode concurrentpagina's linken.

## 2. AI-vindbaarheid (entiteit versterken)
- **Consistente NAP** (naam, adres, telefoon) en dezelfde bedrijfsomschrijving overal.
- **sameAs-profielen**: Google Bedrijfsprofiel, LinkedIn, Facebook, Instagram, KBO, Wikidata (alleen als het aan de regels voldoet) — lijst aan SEO-team voor schema-markup.
- **Vermeldingen zonder link tellen ook**: AI leert uit wat er over ons geschreven wordt. Doel: genoemd worden in lijstjes/vergelijkingen ("beste borduurservices in Vlaanderen"), fora, Reddit-achtige vragen, lokale artikels.
- **Reviews**: Google-reviews actief vragen na levering (via Opvolging) — reviewtekst wordt door AI gelezen.
- **Maandelijkse AI-check**: stel 10 vaste vragen aan de grote AI-assistenten en noteer of en hoe we genoemd worden in `BACKLINKS/ai-vermeldingen.md`. Zo meten we vooruitgang.

## 3. Kwalificatie van een kans (0–10)
| Criterium | Punten |
|---|---|
| Relevantie (textiel, werkkleding, sport, lokaal BE) | 0–3 |
| Echte site met echte bezoekers, geen linkfarm | 0–3 |
| Lokale/Belgische band | 0–2 |
| Haalbaarheid (bestaande relatie, duidelijke reden om te linken) | 0–2 |
Onder 5 = niet doen.

## 4. Werkwijze
1. Kans in `backlinks.csv` met bron-URL, score, doelpagina (uit `SILO-KAART.md`), voorgestelde ankertekst.
2. Outreach-schrijver maakt het verzoek (zelfde regels: persoonlijk, u-vorm, max. 1 + 2 opvolgingen).
3. Controle → Pascal → pas dan versturen.
4. Gelukte link: `status=live`, datum, URL. Maandelijks controleren of hij nog live is.

## 5. Harde regels (Google-richtlijnen)
- **Nooit links kopen**, ruilen op schaal, linkfarms/PBN's, spamcommentaren, forumspam of geautomatiseerde linkpakketten. Dit kan een Google-afstraffing opleveren.
- Gesponsorde/betaalde vermeldingen alleen na Pascal én met `rel="sponsored"`.
- **Ankertekst natuurlijk en gevarieerd**: meestal merknaam of URL; zelden exacte zoekterm (max. ± 10 %).
- Geen geautomatiseerde aanmeldingen bij honderden gidsen: alleen relevante, echte gidsen.
- Geen beweringen die niet kloppen (geen "nr. 1", geen verzonnen cijfers).
- Bevriezingen van het SEO-team respecteren.
