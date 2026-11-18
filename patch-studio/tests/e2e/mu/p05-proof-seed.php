<?php
/**
 * Plugin Name: Patch Studio e2e seed (P05 proof workflow)
 * Description: Only for the local e2e site. Defines bps_p05_seed_order() for scenarios 05 and 18 (called through
 *              `wp eval`), so the proof tests do not depend on the cart flow. Defines nothing else and adds no
 *              hooks. Never ship this file.
 *
 * @package BorduurPatchStudio
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'bps_p05_seed_order' ) ) {
	return;
}

/**
 * Create a design post (with a preview and an original file copied from the e2e fixture).
 *
 * The fixture path comes from the option `bps_p05_fixture` (set by the scenario; the file may be copied into
 * mu-plugins) or the tests/e2e/fixtures folder next to this file.
 *
 * @param string $locale nl-BE|fr-BE.
 * @param string $notes  Customer note.
 * @return array{id:int, ref:string, preview:string, payload:array}
 */
function bps_p05_seed_design( string $locale = 'nl-BE', string $notes = '' ): array {
	$fixture = (string) get_option( 'bps_p05_fixture', '' );
	if ( ! is_readable( $fixture ) ) {
		$fixture = dirname( __DIR__ ) . '/fixtures/logo-flat.png';
	}
	$base    = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'patch-studio/';
	$url     = trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . 'patch-studio/';
	wp_mkdir_p( $base . 'previews' );
	wp_mkdir_p( $base . 'private' );
	$name = 'p05-' . wp_generate_password( 12, false, false ) . '.png';
	copy( $fixture, $base . 'previews/' . $name );
	copy( $fixture, $base . 'private/' . $name );
	$seq = (int) get_option( 'bps_p05_seq', 0 ) + 1;
	update_option( 'bps_p05_seq', $seq, false );
	$ref     = sprintf( 'PS-E2E-%04d', $seq );
	$payload = array(
		'schema'    => 1,
		'locale'    => $locale,
		'technique' => 'embroidered',
		'sizeCm'    => 8,
		'widthMm'   => 80,
		'heightMm'  => 80,
		'shape'     => 'circle',
		'options'   => array(),
		'fabric'    => array(
			'id'  => 'black',
			'hex' => '#111111',
		),
		'threads'   => array(
			array(
				'code'  => '1801',
				'name'  => 'Wit',
				'hex'   => '#ffffff',
				'share' => 0.4,
			),
			array(
				'code'  => '1747',
				'name'  => 'Rood',
				'hex'   => '#c8102e',
				'share' => 0.6,
			),
		),
		'texts'     => array(
			array(
				'text'       => "D'Hondt",
				'font'       => 'Oswald',
				'heightMm'   => 8,
				'path'       => 'straight',
				'threadCode' => '1801',
			),
		),
		'analysis'  => array(
			'kind'     => 'flat',
			'colors'   => 3,
			'stitches' => array(
				'min' => 9000,
				'max' => 12000,
			),
		),
		'source'    => array(
			'kind'     => 'upload',
			'fileName' => 'logo-flat.png',
			'fileType' => 'image/png',
		),
		'notes'     => $notes,
	);
	$id      = wp_insert_post(
		array(
			'post_type'   => 'bps_design',
			'post_status' => 'private',
			'post_title'  => $ref,
		)
	);
	update_post_meta( $id, '_bps_created_gmt', gmdate( 'Y-m-d H:i:s' ) );
	update_post_meta( $id, '_bps_payload', wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
	update_post_meta(
		$id,
		'_bps_preview',
		array(
			'path' => $base . 'previews/' . $name,
			'url'  => $url . 'previews/' . $name,
		)
	);
	update_post_meta(
		$id,
		'_bps_original',
		array(
			'path'  => $base . 'private/' . $name,
			'name'  => 'logo-flat.png',
			'ext'   => 'png',
			'bytes' => (int) filesize( $fixture ),
		)
	);
	update_post_meta( $id, '_bps_status', 'ordered' );
	update_post_meta( $id, '_bps_proof', array( 'status' => 'none', 'history' => array() ) );
	return array(
		'id'      => (int) $id,
		'ref'     => $ref,
		'preview' => $url . 'previews/' . $name,
		'payload' => $payload,
	);
}

/**
 * Create an order with N patch lines (or lines of an existing design).
 *
 * Args: lines (int, 1), design_id (int: reuse this design for every line), locale, status ('on-hold'),
 * email, customer_id, payment ('bacs'), rush (bool), first_name, phone.
 *
 * @param array $args Args.
 * @return array{order_id:int, edit_url:string, items:array}
 */
function bps_p05_seed_order( array $args = array() ): array {
	$args    = array_merge(
		array(
			'lines'       => 1,
			'design_id'   => 0,
			'locale'      => 'nl-BE',
			'status'      => 'on-hold',
			'email'       => 'klant.proef@example.test',
			'customer_id' => 0,
			'payment'     => 'bacs',
			'rush'        => false,
			'first_name'  => 'Jan',
			'phone'       => '0470 12 34 56',
		),
		$args
	);
	$product = (int) get_option( 'bps_product_id', 0 );
	$order   = wc_create_order( array( 'customer_id' => (int) $args['customer_id'] ) );
	$order->set_billing_first_name( (string) $args['first_name'] );
	$order->set_billing_last_name( "D'Hondt" );
	$order->set_billing_email( (string) $args['email'] );
	$order->set_billing_phone( (string) $args['phone'] );
	$order->set_billing_country( 'BE' );
	$order->set_payment_method( (string) $args['payment'] );
	$order->set_payment_method_title( 'bacs' === $args['payment'] ? 'Overschrijving' : (string) $args['payment'] );
	$items = array();
	for ( $i = 0; $i < max( 1, (int) $args['lines'] ); $i++ ) {
		if ( (int) $args['design_id'] > 0 ) {
			$post = get_post( (int) $args['design_id'] );
			$d    = array(
				'id'      => (int) $args['design_id'],
				'ref'     => (string) $post->post_title,
				'preview' => (string) ( get_post_meta( (int) $args['design_id'], '_bps_preview', true )['url'] ?? '' ),
				'payload' => (array) json_decode( (string) get_post_meta( (int) $args['design_id'], '_bps_payload', true ), true ),
			);
		} else {
			$d = bps_p05_seed_design( (string) $args['locale'] );
		}
		$item = new WC_Order_Item_Product();
		$item->set_name( 'Patch op maat' );
		if ( $product > 0 ) {
			$item->set_product_id( $product );
		}
		$item->set_quantity( 10 );
		$item->set_subtotal( 100 );
		$item->set_total( 100 );
		$item->add_meta_data( 'bps_ref', $d['ref'], true );
		$item->add_meta_data( 'bps_technique', 'Geborduurd', true );
		$item->add_meta_data( '_bps_design_id', $d['id'], true );
		$item->add_meta_data( '_bps_payload', wp_json_encode( $d['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
		$item->add_meta_data( '_bps_preview_url', $d['preview'], true );
		$item->add_meta_data( '_bps_technique_id', 'embroidered', true );
		$item->add_meta_data( '_bps_size_cm', 8, true );
		$item->add_meta_data( '_bps_shape_id', 'circle', true );
		$order->add_item( $item );
		$items[] = array(
			'design_id' => $d['id'],
			'ref'       => $d['ref'],
		);
	}
	if ( ! empty( $args['rush'] ) ) {
		$rush = new WC_Order_Item_Product();
		$rush->set_name( 'Spoedlevering' );
		$rush->set_quantity( 1 );
		$rush->set_total( 25 );
		$rush->add_meta_data(
			'_bps_service',
			array(
				'id'        => 'd4_rush',
				'designRef' => $items[0]['ref'],
				'linkedRef' => $items[0]['ref'],
				'leadDays'  => 0,
			),
			true
		);
		$order->add_item( $rush );
	}
	$rate = new WC_Order_Item_Shipping();
	$rate->set_method_title( 'Vast tarief' );
	$rate->set_method_id( 'flat_rate' );
	$rate->set_total( 10 );
	$order->add_item( $rate );
	$order->calculate_totals( false );
	$order->set_status( (string) $args['status'] );
	$order->save();
	$n = 0;
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		if ( (int) $item->get_meta( '_bps_design_id' ) > 0 && isset( $items[ $n ] ) ) {
			$items[ $n ]['item_id'] = (int) $item_id;
			if ( (int) $args['design_id'] <= 0 ) {
				update_post_meta( $items[ $n ]['design_id'], '_bps_order_id', (int) $order->get_id() );
				update_post_meta( $items[ $n ]['design_id'], '_bps_order_item_id', (int) $item_id );
			}
			++$n;
		}
	}
	return array(
		'order_id' => (int) $order->get_id(),
		'edit_url' => $order->get_edit_order_url(),
		'key'      => $order->get_order_key(),
		'items'    => $items,
	);
}
