'use strict';
/**
 * Server-side checks of a placed order via WP-CLI (independent of the admin UI).
 */
const { wpEvalJson } = require('./harness.cjs');

/** Order summary + Patch Studio item meta + design record status. */
function orderInfo(orderId) {
  const php = `
$o = wc_get_order(${Number(orderId)});
if ( ! $o ) { echo wp_json_encode( array( 'found' => false ) ); return; }
$items = array();
foreach ( $o->get_items() as $item_id => $item ) {
  $meta = array();
  foreach ( $item->get_meta_data() as $m ) { $d = $m->get_data(); $meta[ $d['key'] ] = $d['value']; }
  $design_id = (int) $item->get_meta( '_bps_design_id' );
  $design = null;
  if ( $design_id && class_exists( '\\\\BorduurPatchStudio\\\\Woo\\\\Designs' ) ) {
    $rec = \\BorduurPatchStudio\\Woo\\Designs::get( $design_id );
    $design = $rec ? array( 'ref' => $rec['ref'], 'status' => $rec['status'], 'order_id' => $rec['order_id'], 'has_original' => ! empty( $rec['original'] ) ) : null;
  }
  $items[] = array( 'id' => $item_id, 'name' => $item->get_name(), 'qty' => $item->get_quantity(), 'total' => (float) $item->get_total(), 'tax' => (float) $item->get_total_tax(), 'meta' => $meta, 'design' => $design );
}
$fees = array();
foreach ( $o->get_fees() as $f ) { $fees[] = array( 'name' => $f->get_name(), 'total' => (float) $f->get_total(), 'tax' => (float) $f->get_total_tax() ); }
echo wp_json_encode( array(
  'found' => true, 'id' => $o->get_id(), 'status' => $o->get_status(), 'payment' => $o->get_payment_method(),
  'total' => (float) $o->get_total(), 'shipping' => (float) $o->get_shipping_total() + (float) $o->get_shipping_tax(),
  'edit_url' => $o->get_edit_order_url(), 'items' => $items, 'fees' => $fees,
) );`;
  return wpEvalJson(php);
}

module.exports = { orderInfo };
