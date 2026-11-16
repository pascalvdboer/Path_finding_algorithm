# Uurwerk — sociale-mediateam BorduurService België / IMTA

Opgezet 16-11-2026. **Doel: de sociale media draaien elke week vanzelf, ook als Pascal geen tijd heeft.**
Pascal doet maar twee dingen: **foto's in de fotomand gooien** en **één keer per week "ja" zeggen** (± 10 minuten).

Kopieer deze map naar `C:\Users\pasca\Documents\Claude\Projects\sociale media team\UURWERK\`.

---

## 1. De klok (vaste momenten, elke week)

| Wanneer | Taak (geplande taak in Claude) | Wie | Resultaat |
|---|---|---|---|
| **Maandag 07:40** | `01-weekstart` | Directeur + Strateeg | Cijfers vorige week, weekplan, **WEEKBUNDEL** voor Pascal klaar |
| **Maandag** (wanneer het u past) | — | **Pascal, 10 min** | Weekbundel lezen, antwoorden met **"ja"** of **"ja, behalve 2 en 5"** |
| **Dinsdag 09:10** | `02-inplannen` | Directeur | Goedgekeurde posts ingepland in Zoho Social (na activatie) |
| **Woensdag 16:20** | `03-reacties` | Community | Reacties/berichten gelezen, antwoordconcepten, vragen → SEO |
| **Donderdag 10:50** | `04-productie` | Contentmaker + Beeld + SEO-brug | Posts voor **over 2 weken** gemaakt uit de fotomand |
| **Donderdag 14:30** | `05-controle` | Controle (vier ogen) | Elke conceptpost GOED of TERUG |
| **Vrijdag 15:10** | `06-weekafsluiting` | Directeur | Log, leerregister, fotoverzoek voor volgende week |
| **1e maandag v.d. maand** | `07-maandrapport` | Strateeg | 1 pagina: wat werkte, wat niet, 3 bijsturingen, vragen → SEO-inbox |
| **Maandag 08:50** | `09-ads-weekcheck` | Google Ads-team | 3 regels "Ads deze week" in de weekbundel; stop-test bewaakt |
| **1e maandag 09:30** | `10-ads-maandrapport` | Google Ads-team | Uitgaven vs. aanvragen, stand afbouw |
| **Eenmalig** | `08-ads-doorlichting` | Google Ads-team | Advies stoppen/afslanken/verbeteren/houden (1 pagina) |

De tijden zijn bewust niet op het hele uur (minder vertraging bij geplande taken).

## 2. Het buffer-principe (waarom het nooit stilvalt)
- Het team werkt altijd **2 weken vooruit**. In Zoho staan altijd minstens 2 weken goedgekeurde posts klaar.
- Zegt Pascal een week niets? → De buffer loopt gewoon door, niets valt stil. De nieuwe bundel schuift door naar volgende maandag.
- Zegt Pascal **2 weken** niets? → Directeur zet één korte herinnering bovenaan de weekbundel ("buffer loopt over 7 dagen leeg").
- Er gaat **nooit** iets online zonder "ja" — ook niet als de buffer leeg is. Leeg = pauze, geen gok.

## 3. Wat er elke week verschijnt (ritme)
Per week **3 posts** per hoofdkanaal, elk kanaal een eigen versie (nooit één tekst naar alle 7 kanalen).

| Dag | Pijler | Voorbeeld | Link (SEO) |
|---|---|---|---|
| Dinsdag | **Vakmanschap** | Korte video van de borduurmachine aan het werk, close-up van een logo dat ontstaat | Pagina borduren |
| Donderdag | **Uitleg / tip** | "Borduren of bedrukken? Wat past bij uw werkkleding?" | Eigenaarspagina uit SILO-KAART |
| Zaterdag | **Mensen & projecten** | Achter de schermen, team, afgewerkt project (klantnaam alleen met toestemming) | Relevante categoriepagina |

Afwisseling wordt bewaakt: dezelfde pijler nooit 2× na elkaar op hetzelfde kanaal, geen beeld ooit hergebruikt (check in `BEELDREGISTER.csv`).

## 4. De fotomand (enige echte afhankelijkheid)
Geen stock- of AI-beelden → het uurwerk draait op **echte foto's en filmpjes uit het atelier**.
- Eén map: `UURWERK\FOTOMAND\` (bv. gesynchroniseerd via OneDrive/Google Drive met de gsm van Pascal of het personeel).
- **Gewoon erin gooien**, geen uitleg nodig. Beeld zoekt zelf uit wat bruikbaar is, Contentmaker vraagt context alleen als het echt moet.
- Elke vrijdag maakt het team een **fotoverzoek**: max. 3 concrete shots ("close-up van de naald bij een rugborduring", "stapel afgewerkte polo's op de werktafel", "15 sec. video machine start").
- Gebruikte foto's → `FOTOMAND\_gebruikt\` + regel in `BEELDREGISTER.csv`.
- Is de fotomand leeg? Dan meldt de weekbundel dat, en het team zet in op uitleg-posts met eigen grafische opmaak (huisstijl uit de galerij, geen AI-beelden).

## 5. Rollen in het uurwerk
Ongewijzigd t.o.v. het team: Directeur · Strateeg/Analist · SEO-brug · Contentmaker · Beeld · Controle · Community. Elke rol volgt `rollen/<rol>/AGENT_SOP.md`. Het uurwerk bepaalt alleen **wanneer** en **wat er klaar moet zijn**.

## 6. Definitie van "klaar" per post
- Tekst in u-vorm, per kanaal aangepast (lengte, toon, hashtags max. 3–5, geen emoji-reeksen)
- Eén link naar de juiste SEO-pagina met UTM (`utm_source=<kanaal>&utm_medium=social&utm_campaign=uurwerk-JJJJ-WW`)
- Echt beeld uit de fotomand, niet eerder gebruikt
- Geen prijzen, "gratis", levertijden, acties, klantnamen zonder toestemming
- Door Controle goedgekeurd

## 7. Meten (maandrapport)
Per kanaal: bereik, interacties, % posts **met** interactie (doel: duidelijk hoger dan in 2025), klikken naar de site (UTM in Analytics), nieuwe volgers, vragen/aanvragen via socials (→ salesteam `leads.csv`, bron `social`).
Eén zin conclusie + 3 bijsturingen. Werkt een pijler 2 maanden slecht → voorstel om te vervangen.

## 8. Samen met het Google Ads-team
Het Google Ads-team (`google-ads-team\`) draait mee op dezelfde klok en gebruikt **dezelfde weekbundel**: Pascal heeft dus nog altijd maar één leesmoment per week. Als de ads worden afgebouwd, krijgen onderwerpen en zoektermen die vroeger via ads binnenkwamen voorrang in de pijler "Uitleg / tip" (met link naar de SEO-pagina), zodat socials en SEO dat verkeer overnemen.

## 9. Wat Pascal **niet** meer hoeft te doen
Ideeën bedenken, teksten schrijven, kanalen aanpassen, inplannen, cijfers bijhouden, reacties opvolgen (alleen goedkeuren als het gevoelig is).
