# 05 · Prompt-adapter en testset (bouwer `prompt`, 18-11-2026)

Uitvoering van SPEC-AI-DIENSTEN §7 ("Openstaande taken prompt"). Bouwt verder op `02-prompting.md`.

## Wat er is

| Onderdeel | Bestand |
|---|---|
| `Prompt_Adapter` (nieuw, puur PHP, geen WP-calls) | `plugin/borduur-patch-studio/includes/ai/class-prompt-adapter.php` |
| Prompt-engine (2 kleine fixes, zie onder) | `includes/ai/class-ai-prompt-engine.php` |
| Data: woordenlijst, `colorNames`, `fabricAliases`, `understoodLabels`, veiligheid | `config/ai-prompts.json` |
| Mock-trefwoorden en extra kleuren | `config/ai-mock.json` |
| Testset van 30 klantvragen + 16 veiligheids- en 16 verfijngevallen | `tests/ai/prompt-vectors.json` |
| Tests | `tests/ai/run-prompt-php.php`, `tests/ai/run-prompt-adapter-php.php`, `tests/ai/prompt-config.test.js`, `tests/ai/prompt-adapter.test.js` |

## De adapter

```
REST-body + studio-context ──Prompt_Adapter::input()──▶ Prompt_Engine::brief() ──▶ Prompt_Engine::build() ──▶ provider
                                                              │
                                                              └─Prompt_Adapter::understood()/alt()/hints()──▶ browser
```

- **`input($rest, $studio_ctx)`**: mapt en valideert enkel keuzevelden; nooit een prompt. Voorrang: keuze van de
  klant > woorden in de beschrijving > wat de studio weet > `defaults.json`. Onbekende waarden vallen stil terug
  (geen foutmelding voor een keuzeveld).
  - Techniek: context of de eerste techniek van de studio. Maat: context (25–300 mm) of `sizes.defaultCm`
    (`defaultTextOnlyCm` bij enkel tekst). Rand: enkel een bestaande rand-optie.
  - Stofkleur: studio-id (`bottle` → `green`, `sand` → `beige`), eigen sleutel of `#hex` (dichtste stofkleur).
  - Vorm uit de studio enkel met `shapeChosen: true` en als de beschrijving zelf geen vorm noemt.
  - **Aantal kleuren uit de beschrijving**: "één kleur", "une seule couleur", "monochroom", "in twee kleuren",
    "trois couleurs" → `maxColors`. Bewust streng: "een kleur die opvalt" of "une couleur vive" telt niet.
  - `textMode=studio` → `reserveText` (lege band voor tekst die de studio later zet).
- **`understood($brief, $lang)`**: de chips "Zo begrepen we je" in de taal van de klant. Het onderwerp staat er in
  de eigen woorden van de klant (per stukje tussen komma's, van het eerste tot het laatste ontwerpwoord), dus geen
  terugvertaling uit het Engels: *"wolf met een bijl voor onze motorclub · rond · zwart en rood · stijl club /
  vereniging"*. Kleuren als label én hex. `notes` geeft de uitleg bij elke `warning` al vertaald. Nooit de prompt,
  nooit Engelse woorden, nooit persoonsgegevens (die zijn er in de brief al uit).
- **`alt()`**: "Voorstel 2: wolf met een bijl voor onze motorclub, rond, zwart en rood" (SPEC §1.4).
- **`hints()`**, **`local_refine()`**, **`public_styles()`**, **`colors_hex()`**: zie de docblocks.

## Verfijnen: lokale acties = studio-acties

`refineLocal.*.action` ⊆ `open_text | open_colors | reduce_colors | open_size | open_shape | open_arrange`
(SPEC §10.3). `Prompt_Adapter::LOCAL_ACTIONS` en een test bewaken dat. **Gevonden bug**: de patronen voor vorm en
verschuiven bevatten `écusson`, `carré`, `déplace`, maar de engine matcht op tekst zonder accenten; die woorden
werkten dus nooit. Rechtgezet, met een test die accenten in alle zulke regexen weigert.

## Mock-trefwoorden

De mock kiest een sjabloon op de **Engelse** onderwerpen van de brief. De trefwoorden in `ai-mock.json` volgen nu
de woordenlijst: elk Engels onderwerp (61) valt op een sjabloon (draak → poot, vuurtoren → anker, ridder → schild,
bij → boom …) en er staan geen dode trefwoorden meer in (bv. `hiking`, `alps`, `sheriff` kwamen nooit voor).

## Testset van 30 klantvragen

T01–T20 (NL en FR, `02-prompting.md` §8.2) + **T21–T30: tien extra Franse vragen** die via de adapter lopen en ook
de samenvatting controleren (renard qui dort, club de tennis, abeille des apiculteurs, dragon qui crache du feu,
phare, hibou militaire, chevalier, brasserie artisanale, vélo de course, fleur de lys « une seule couleur »). Daaruit
kwamen woorden bij (o.a. *bleu nuit, bleu roi, raquette, chope de bière, vélo de course, crache du feu, stylisé,
artisanal, deux/trois*) en twee engine-fixes:

1. Groepen kregen altijd "a": *"for a association"*, *"for a beekeepers"* → nu het juiste lidwoord.
2. Een groep vooraan gevolgd door een voegwoord gaf *"with two crossed tennis rackets…"* → het losse "with" valt weg.

Extra veiligheidsgevallen: *portrait de Stromae* (persoon: portret van iemand met een naam; *portrait de mon chien*
blijft toegelaten) en *feuille de cannabis* (drugs).

## Open punten

- Niet gemeten op een echt model (geen API-sleutel), zie `02-prompting.md` §12.
- De naamfilter blijft een heuristiek; de UI-hint "zet geen namen in je beschrijving" blijft nodig.
- "portret van wolf" (zonder lidwoord) wordt als persoon geweigerd: bewuste, zeldzame vals-positieve.
- Integratiepunten (alt-tekst, `shapeChosen`, hex-stofkleur, `test:php`): `docs/handoff/ai-services-needs.md`
  §"Prompt-adapter".
