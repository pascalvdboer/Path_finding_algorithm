# Handoff — production (productiefiche + digitale proef)

## Gebouwd
| Bestand | Inhoud |
|---|---|
| `includes/admin/class-order-production.php` | Component `Admin\Order_Production` (SPEC §6.5–6.6). Metabox "Patch Studio · productiefiche" (HPOS + klassiek), verwijzing + proefstatus onder elke orderregel, printfiche (`admin-post.php?action=bps_print_fiche&order=&item=&_wpnonce=`), AJAX `bps_send_proof` / `bps_set_proof_status`, klantpagina `?bps_proof=…` op `template_redirect`, WC-e-mails `BPS_Email_Proof` (klant, taal van het ontwerp) en `BPS_Email_Proof_Response` (winkel), kolom "Patchproef" in de orderlijst, verbergt de `_bps_*`-itemmeta in de itemtabel. |
| `templates/admin/order-metabox.php`, `fiche-card.php`, `proof-status.php` | Kaart per patch: groot voorbeeld met b×h in mm, specs (techniek, maat, vorm, aantal, rand + randkleur met garencode, achterkant/klittenband, dikte, drager, stof), garenkleuren (swatch, Polyneon-code, aandeel), tekstlagen, steekschatting, plaatsing, **aandachtspunten** (berekend uit `techniques.*.rules` + analyse), analyse, klant/advies/rechten, download origineel, garencodes kopiëren, proefblok met geschiedenis en manuele status. |
| `templates/admin/fiche-print.php` | A4, 1 patch per pagina, zwart-wit leesbaar (codes als tekst, "!!"/"!" markeringen), grote ref, QR naar de bestelling, voorbeeld ± 1:1 met 10 mm-schaal, werkvolgorde-afvinklijst. `&autoprint=1` opent meteen het printvenster. |
| `templates/admin/proof-response.php` | Klantpagina (noindex, `DONOTCACHEPAGE`, no-store). In het thema (header/footer) bij klassieke thema's, standalone bij blokthema's (filter `bps_proof_page_use_theme`). |
| `templates/admin/emails/{proof,proof-response}.php` + `plain/` | Overschrijfbaar via `thema/woocommerce/admin/emails/…`. |
| `assets/src/admin/{index.js, production.js, qr.js, admin.css}` | `window.BPSAdmin`; dialoog (wp.media voor eigen proefbeeld), kopiëren, manuele status, QR-encoder (byte mode, niveau M, v1–10). Build: 5,1 kB gzip JS + 8,9 kB CSS. |

## Gedrag digitale proef
- Token = `hash_hmac('sha256', "proof|{designId}|{orderId}|{ronde}", wp_salt('auth'))`. Enkel de laatste ronde is geldig; oudere links tonen "Er is intussen een nieuwere proef verstuurd".
- **GET wijzigt niets** (mailscanners die links openen keuren dus niet per ongeluk goed); pas de POST van het formulier op de bevestigingspagina zet de status. Aanpassing vragen vereist een opmerking.
- Effect: `Designs::update_proof()` (+ `round`, `recipient`, `order_id`, `item_id`, `message`), itemmeta `_bps_proof_status` (voor de orderlijst), ordernotitie, mail naar de winkel, `do_action('bps_proof_sent'|'bps_proof_response')`. Orderstatus wijzigt niet (SPEC open punt 11).
- Klantteksten (mail + pagina): `__()` met ingebouwde Franse terugval (`Order_Production::FR`) zolang `fr_BE.po` ze niet vertaalt.

## Afwijkingen / aanvullingen op SPEC
- Extra sleutels in `_bps_proof`: `round`, `recipient`, `message`, `order_id`, `item_id`; history-acties `sent`, `approved`, `changes`, `manual_*`.
- Kaarten tonen de **order-snapshot `_bps_payload`** als die bestaat (= wat besteld werd), anders de payload van het ontwerp.
- E-mailklassen breiden `Woo\Emails\Base` uit en worden lui gedeclareerd (namespaced functie `declare_emails()`), want WC_Email bestaat pas in `woocommerce_email_classes`.

## Voor de integrator
- `languages/*.pot`: mijn strings mee extraheren (text domain `borduur-patch-studio`); de FR-tabel in `Order_Production::FR` kan als basis voor `fr_BE.po`.
- `Plugin::register_assets()` registreert de `bps-admin`-style enkel als de css al gebouwd is; ik registreer zelf terug als die ontbreekt.
- Hoofdbundel `patch-studio.js` zat bij mijn build boven budget (94 kB gzip, niet van mij).

## Testen (allemaal groen)
Kopie van de woo-testsite in scratch `prod-wp` (WP + Woo 11.3 + SQLite, HPOS aan), `php -S 127.0.0.1:8990 -t site router.php`, orders via `tools/mkorders.php`; `node prod-e2e.cjs` (Playwright, 50+ checks): kaarten en inhoud, download origineel, dialoog + validatie, mail (onderwerp, knoppen, beeld, bericht), ordernotities, GET verandert niets, gemanipuleerd token → 403, goedkeuren met opmerking → winkelmail, "al beantwoord", ronde 2 maakt ronde-1-link ongeldig, manuele status, printpagina (2 pagina's, QR, slechte nonce → 403, PDF), FR-flow (Franse mail, lege opmerking geweigerd), opgeruimd ontwerp → snapshotkaart, orderlijstkolom, geen eigen console-fouten/PHP-notices. QR gedecodeerd met jsQR (v1–v10, unicode). `php -l` op alle eigen bestanden: OK.

## Open punten
1. Orderstatus automatisch zetten na goedkeuring? (SPEC 11) — hook `bps_proof_response` staat klaar.
2. Proefbeeld = preview-PNG uit de tool; het echte borduurproefbeeld (uit de digitaliseersoftware) kies je via "Andere afbeelding kiezen" (mediabibliotheek → publieke URL).
3. Franse teksten laten nalezen.
