'use strict';
/**
 * Shared helpers for the proof e2e scenarios (05 admin fiche, 18 proof): seed orders without the cart flow
 * (tests/e2e/mu/p05-proof-seed.php), read the proof of an order line, find proof links in captured mails.
 */
const fs = require('fs');
const path = require('path');
const { ENV, wpEvalJson } = require('../e2e/lib/harness.cjs');

const MU_SRC = path.join(__dirname, '..', 'e2e', 'mu', 'p05-proof-seed.php');
const MU_DST = path.join(ENV.site, 'wp-content', 'mu-plugins', 'p05-proof-seed.php');
const FIXTURE = path.join(__dirname, '..', 'e2e', 'fixtures', 'logo-flat.png');

/** Make sure the seed helper is loaded on the test site (copied when the mu folder is not loaded yet). */
function ensureSeed() {
  const loaded = wpEvalJson("echo wp_json_encode( array( 'ok' => function_exists( 'bps_p05_seed_order' ) ) );");
  if (!loaded.ok) {
    fs.mkdirSync(path.dirname(MU_DST), { recursive: true });
    fs.copyFileSync(MU_SRC, MU_DST);
  }
  wpEvalJson(`update_option( 'bps_p05_fixture', ${JSON.stringify(FIXTURE)}, false ); echo '{}';`);
  return wpEvalJson("echo wp_json_encode( array( 'ok' => function_exists( 'bps_p05_seed_order' ) ) );").ok;
}

/** Seed an order (see bps_p05_seed_order() for args). */
function seedOrder(args = {}) {
  const php = `echo wp_json_encode( bps_p05_seed_order( json_decode( ${JSON.stringify(JSON.stringify(args))}, true ) ) );`;
  return wpEvalJson(php);
}

/** Normalized proof of an order line. */
function proofOf(orderId, itemId) {
  return wpEvalJson(`$o = wc_get_order(${Number(orderId)}); $i = $o ? $o->get_item(${Number(itemId)}) : null; echo wp_json_encode( $i ? \\BorduurPatchStudio\\Proof\\Proof_Service::get( $i ) : null );`);
}

/** Order notes (newest first). */
function notesOf(orderId) {
  return wpEvalJson(`echo wp_json_encode( array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => ${Number(orderId)} ) ) ) );`);
}

/** Mails captured by the test site since a set of file names. */
function mailsSince(before) {
  const dir = path.join(ENV.site, 'wp-content', 'bps-mails');
  return (fs.existsSync(dir) ? fs.readdirSync(dir) : []).filter((f) => !before.has(f)).sort()
    .map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')));
}
function mailSnapshot() {
  const dir = path.join(ENV.site, 'wp-content', 'bps-mails');
  return new Set(fs.existsSync(dir) ? fs.readdirSync(dir) : []);
}
/** href values of a mail that match a pattern (entities decoded). */
function links(mail, re) {
  return [...mail.message.matchAll(/href=["']([^"']*)["']/g)].map((m) => m[1].replace(/&amp;|&#038;/g, '&')).filter((l) => re.test(l));
}

module.exports = { ensureSeed, seedOrder, proofOf, notesOf, mailsSince, mailSnapshot, links };
