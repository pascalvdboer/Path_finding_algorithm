# Handoff — pricing

## Gebouwd
| Bestand | Inhoud |
|---|---|
| `assets/src/pricing/index.js` | `quote`, `quoteAll`, `tierFor` (SPEC §7.5) + extra: `savingHint`, `optionEffect`, `errorMessage`, `normalizeQty`, `formatSize`, `LINE_LABELS`, `messages`. Puur (geen DOM), ± 6 kB gzip incl. money/i18n. |
| `assets/src/pricing/messages.js` | Catalogus `pricing.*` NL ("je") + FR ("vous"): prijskaart, staffelhints, foutteksten. |
| `includes/class-pricing.php` | `Pricing::quote`, `round_half_up`, `tier_for`, `cart_unit_excl` (SPEC §6.3) + extra `quote_all`, `default_options`, `normalize_qty`, `format_size`. Filter `bps_price_quote` (alleen in `quote()`). Standalone laadbaar met `BPS_TESTING`. |
| `tests/pricing/vectors.json` | 47 vectoren: de 9 kassavoorbeelden (02 §9) + alle randgevallen uit §6.3 + grens gratis verzending, ladder, volgende trede, FR-labels, config-overrides. Verwachte bedragen met de hand nagerekend. |
| `tests/pricing/pricing.test.js` | Node: vectoren + unit (staffel, quoteAll, hints, optie-effect, prestaties < 5 ms, rommel-invoer). |
| `tests/pricing/parity.test.js` | Node start PHP (`dump-php.php`) en vergelijkt de **volledige** quote (bedragen, labels, fouten, ladder, nextTier) voor alle vectoren + 6000 willekeurige invoer/prijsconfigs (vaste seed) + `quoteAll`. Wordt overgeslagen als `php` ontbreekt. |
| `tests/pricing/run-php.php` (+ `bootstrap.php`) | `npm run test:php`: dezelfde vectoren in PHP + API-checks, exitcode 1 bij verschil. |

Resultaat: `npm test` 154/154 groen (alle modules), `npm run test:php` 47 vectoren / 652 checks groen, `php -l` OK.
Mutatiecheck: PHP-afronding vervangen door gewone `floor(x+0.5)` → de pariteitstest faalt meteen; `>=` → `>` bij gratis verzending → vector faalt.
Woo-e2e van de woo-bouwer (`scratchpad/woo-wp/e2e.mjs`) draait groen met de echte engine (de stub wordt niet meer geladen).

## Afwijkingen van / aanvullingen op SPEC (contract intact)
1. **Blokkerende fouten** (`unknown_technique`, `quote_only`, `size_too_small`, `size_too_large`, of qty < 1) → alle bedragen 0, lege breakdown.
   **Niet-blokkerende fouten** (`below_min_qty`, `qty_step`, opties) → `ok:false`, maar er wordt wél gerekend (ongeldige opties tellen niet mee). Zo kan de UI tonen wat het zou kosten.
2. Geen prijs geconfigureerd voor de maat (of maat > grootste `pricedCm` maar ≤ `maxOrderCm`) → `mode:'quote'` + `quote_only`.
3. `params` is nooit leeg (`unknown_technique`/`quote_only` krijgen `{technique}`, `size_too_small` `{min}`, `size_too_large` `{max}`), zodat PHP ze als JSON-object encodeert.
4. Extra velden in PriceQuote (beide engines): `currency`, `vatRate`, `options` (genormaliseerde lijst die echt geprijsd werd), `basePriceExcl`, `optionsPerPieceExcl`, `optionsPercent`, `pooled`, `perPieceAllInExcl`, `extrasIncl`, `feesIncl`; PriceLine van fees/extra's heeft `help` (ⓘ-uitleg uit de config).
   `ladder[i]` = `{min (= besteld aantal van die trede), discount, unitIncl, perPieceAllInIncl, totalIncl, current}`; treden onder `minQty` worden overgeslagen, aantal afgerond op `qtyStep`.
   `nextTier` = `{min, qty (= min − poolQty, afgerond), discount, unitIncl, perPieceAllInIncl, totalIncl, savingPerPieceIncl, savingPerPieceAllInIncl, extraPieces, extraCostIncl, withinReach (qty ≤ 2× huidig)}`. `null` bij `tiers:false` of `withLadder:false`.
5. Fee-groep met bedrag 0 → geen fee-regel (eigenaar kan zo een fee uitzetten).
6. `quoteAll` zet het aantal per techniek op `minQty`/`qtyStep` (PVC → 50) en markeert `qtyAdjusted:true`; ladder enkel als `input.withLadder === true`.
7. Labels in de quote (itemregel "10 × Geborduurd 7,5 cm", verzending) komen uit `LINE_LABELS` (JS) = `Pricing::LINE_LABELS` (PHP), niet uit `config.strings`.

## Voor ui / advice / integrator
- **Staffelhint**: `savingHint(quote, locale, config.strings?.[locale])` → `null` of `{kind, main, secondary, extra?, nextTier}` (Msg-objecten). Toont alleen als de volgende trede binnen bereik is én echt goedkoper per stuk.
  `kind:'cheaper_total'` = de volgende trede kost **in totaal minder** (bv. 25 geborduurde patches 8 cm = € 208,12 < 24 stuks = € 210,01). Eerlijk én overtuigend; dan geen tegenhint.
  `kind:'next_tier'` geeft ook de eerlijke tegenhint "Heb je er maar 15 nodig? Bestel er dan gewoon 15." Ik schreef bewust **niet** "Bijbestellen kan altijd" (SPEC §1.2): een nabestelling betaalt de eenmalige kosten (fee per bestelling) opnieuw, tenzij de eigenaar dat anders regelt.
- **Opties**: `optionEffect(opt, techId, config, locale)` → "+ € 1,82/st." / "− 10 %" / "inbegrepen" / "+ € 0,91 (eenmalig)" (incl. btw).
- **Fouten**: `errorMessage({code, params}, config, locale)` → klanttekst (groepslabel uit de config).
- **Btw-schakelaar**: alles excl. staat in de quote (`lineExcl`, `subtotalExcl`, `totalExcl`, `perPieceAllInExcl`, `breakdown[].excl`).
- **types.js** (architect): bovenstaande extra velden kunnen in `PriceQuote` gedocumenteerd worden.

## Open punten (eigenaar)
- Fees 1× per bestelling (`fees.scope`) vs. "per ontwerp" op de site; flock `per_line` € 0,75 (waarschijnlijk per stuk bedoeld); gesublimeerd zonder staffel — alles volgt de kassa en is instelbaar (SPEC §12).
- JS `Number("0x1A")` = 26, PHP `is_numeric` = false: hexadecimale strings als getal worden anders gelezen (REST-invoer is altijd decimaal/gecast; geen praktisch risico).
