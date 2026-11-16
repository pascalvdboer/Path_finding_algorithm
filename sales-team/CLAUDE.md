# Salesteam BorduurService België / IMTA — anker

Opgezet 16-11-2026. Doel: **meer verkopen genereren** — nieuwe zakelijke klanten voor geborduurde en bedrukte (werk)kleding, herhaalbestellingen bij bestaande klanten, en **beter gevonden worden** in Google en door AI-assistenten via een sterk backlinkprofiel.

## 1. Status
**INLEERFASE** tot Pascal uitdrukkelijk "start" zegt.
- Toegestaan: lezen, onderzoeken, leadlijsten opbouwen, concepten schrijven in bestanden.
- Niet toegestaan: e-mails versturen, bellen, offertes versturen, iets in CRM/Zoho wijzigen, contact opnemen met wie dan ook.
- Ook na activatie: **niets gaat naar buiten zonder expliciet "ja" van Pascal per bericht of per batch.**

## 2. Team
| Rol | Taak | Map |
|---|---|---|
| Directeur | Plant, verdeelt werk, weegt en bundelt, rapporteert aan Pascal | `skill/` |
| Leadonderzoeker | Vindt prospects in BE (en NL) met een echte kledingbehoefte | `rollen/leadonderzoeker/` |
| Kwalificeerder | Scoort leads, bepaalt prioriteit en invalshoek | `rollen/kwalificeerder/` |
| Outreach-schrijver | Schrijft persoonlijke eerste berichten en belscripts | `rollen/outreach/` |
| Offertemaker | Zet aanvragen om in offerteconcepten (prijzen alleen van Pascal) | `rollen/offerte/` |
| Opvolging | Volgt open offertes op, heractiveert oude klanten, vraagt reviews en vermeldingen | `rollen/opvolging/` |
| Linkbuilder & AI-vindbaarheid | Bouwt natuurlijke backlinks en vermeldingen, meet of AI ons noemt | `rollen/linkbuilder/` |
| Controle | Vier-ogencontrole op alles wat naar buiten zou gaan; nooit eigen werk | `rollen/controle/` |

Subagenten werken parallel en **alleen lezend/conceptmatig**, met een bron per feit. De directeur weegt, bundelt en legt voor.

## 3. Doelgroepen (startpunt — Pascal bevestigt)
1. Sportclubs en verenigingen (clubkledij, trainingspakken, supporterskledij)
2. Bouw, installatie en technische bedrijven (werkkleding met logo)
3. Horeca en retail (schorten, polo's, personeelskledij)
4. Zorg, scholen en jeugdbewegingen
5. Bedrijven met events/beurzen (promotiekleding, relatiegeschenken)
6. **Bestaande en slapende klanten** (hoogste kans, laagste kost)

## 4. Salesfunnel
`Lead gevonden → gekwalificeerd (A/B/C) → eerste contact → gesprek/aanvraag → offerte → opvolging → bestelling → nazorg & herhaalbestelling`

Elke lead staat in `LEADS/leads.csv` met kolommen:
`id,bedrijf,sector,gemeente,website,bron,behoefte_signaal,score,fase,laatste_actie,volgende_actie,datum_volgende_actie,eigenaar_rol,opmerking`

## 5. Koppeling met andere teams
- **SEO-team**: elke outreach verwijst naar de juiste eigenaarspagina uit `SILO-KAART.md` (met UTM `utm_medium=email&utm_source=sales`). Veelgestelde vragen van prospects → maandelijks naar `SEO_System/00_Director/_inbox/`.
- **Sociale-mediateam**: referentieprojecten (alleen met toestemming klant) worden doorgegeven als post-idee. Leads die via socials reageren komen in `leads.csv` met bron `social`.
- **Backlinks**: Linkbuilder volgt `SILO-KAART.md` voor doelpagina's en checkt eerst of het SEO-team al een backlinklijst/beslissing heeft (geen dubbel werk). sameAs-profielen → SEO-team voor schema.
- Bevriezingen en beslissingen van Pascal in andere teams gelden hier ook.

## 6. Harde regels
- U-vorm. Zakelijk, warm, kort. Geen opdringerige salestaal.
- **Geen prijzen, kortingen, levertijden, "gratis", acties of beloftes** zonder Pascal.
- **Geen klantnamen of referenties** noemen zonder toestemming van die klant én Pascal.
- Geen massamailings, geen gekochte lijsten, geen spam. Max. 1 eerste bericht + 2 opvolgingen per lead.
- **GDPR/AVG**: alleen zakelijke contactgegevens uit openbare bronnen (website, KBO, clubsite). Altijd bron vermelden. Afmelden = direct `fase=afgemeld`, nooit meer contacteren.
- Nooit herhaalde standaardtekst: elk bericht verwijst naar iets specifieks van die prospect.
- Geen stock- of AI-beelden in offertes of mails.
- **Backlinks alleen white-hat**: nooit links kopen, geen linkfarms/PBN's, geen spam; betaalde vermeldingen alleen na Pascal met `rel="sponsored"`.
- Nooit iets verwijderen: naar `_to_delete/` en melden.

## 7. Bestanden
- `ACTIVITEITENLOG.md` — elke sessie één regel (datum, rol, wat, resultaat)
- `WACHT-OP-PASCAL.md` — open vragen en goedkeuringen
- `BESLISSINGEN_PASCAL.md` — genomen beslissingen (bindend)
- `LEERREGISTER.md` — wat werkt / wat niet, met datum
- `WEEKPLAN/` — één bestand per week
- `LEADS/leads.csv` — de pipeline
- `BACKLINKS/backlinks.csv` — linkkansen en live links
- `BACKLINKS/ai-vermeldingen.md` — maandelijkse AI-meting
- `CONCEPTEN/` — berichten en offertes klaar voor controle
