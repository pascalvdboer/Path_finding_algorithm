<?php
/**
 * P06 designs-account: REST integration suite against a REAL WordPress + WooCommerce test site (tests/e2e/start-wp.sh).
 * Runs inside WordPress through WP-CLI (rest_do_request: real routes, permission callbacks, Guard, WooCommerce cart,
 * orders, Action Scheduler, mails caught by the test-site mu-plugin):
 *
 *   BPS_WP_DIR=… BPS_WP_PORT=… bash tests/e2e/start-wp.sh wp eval-file tests/woo/run-woo-wp.php
 *
 * Normally started by tests/e2e/11-account.e2e.cjs, which turns every line into a check. Output: "OK <msg>" /
 * "FAIL <msg> | <detail>" lines and a final "RESULT <passed>/<total>". Fixed clock through the bps_now filter.
 *
 * Covers the acceptance criteria of klant-account-1/-2/-3/-5, security-3/-4, prestatie-1 and concurrentie-features-8.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

use BorduurPatchStudio\Rest\Guard;
use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Uploads;
use BorduurPatchStudio\Woo\Account;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Order;
use BorduurPatchStudio\Woo\Reminder;

if ( ! defined( 'BPS_TESTING' ) ) {
	define( 'BPS_TESTING', true ); // Files fed by this suite were not uploaded through HTTP.
}
ob_start(); // WooCommerce sets cookies; keep CLI output out of the way until the end.

$GLOBALS['p06'] = array(
	'pass'  => 0,
	'total' => 0,
	'lines' => array(),
	'mails' => array(),
	'clock' => null,
);
function p06_ok( $cond, string $msg, $detail = null ): void {
	++$GLOBALS['p06']['total'];
	if ( $cond ) {
		++$GLOBALS['p06']['pass'];
		$GLOBALS['p06']['lines'][] = 'OK ' . $msg;
	} else {
		$GLOBALS['p06']['lines'][] = 'FAIL ' . $msg . ( null !== $detail ? ' | ' . substr( wp_json_encode( $detail ), 0, 600 ) : '' );
	}
}
function p06_settings( array $patch ): void {
	$o = get_option( 'bps_settings', array() );
	$o = is_array( $o ) ? $o : array();
	$o = array_replace_recursive( $o, $patch );
	update_option( 'bps_settings', $o );
	Settings::flush();
	\BorduurPatchStudio\Woo\Config::reset_cache();
}
function p06_user( string $login ): int {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		return (int) $u->ID;
	}
	return (int) wp_create_user( $login, wp_generate_password( 20 ), $login . '@example.test' );
}
/** REST call as the current user. Public write routes get a valid X-BPS-Token for that user. */
function p06_rest( string $method, string $route, array $params = array(), array $files = array(), array $headers = array() ) {
	$r = new WP_REST_Request( $method, '/patch-studio/v1' . $route );
	foreach ( $headers as $k => $v ) {
		$r->set_header( $k, $v );
	}
	if ( ! isset( $headers['x-bps-token'] ) ) {
		$r->set_header( 'x-bps-token', wp_create_nonce( Guard::NONCE_ACTION ) );
	}
	if ( 'GET' === $method ) {
		$r->set_query_params( $params );
	} elseif ( $files ) {
		$r->set_body_params( $params );
		$r->set_file_params( $files );
	} else {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $params ) );
	}
	$res = rest_do_request( $r );
	$res = rest_get_server()->response_to_data( $res, false ) !== null ? $res : $res;
	return array(
		'status'  => $res->get_status(),
		'body'    => $res->get_data(),
		'headers' => $res->get_headers(),
	);
}
function p06_err( array $res ): string {
	return is_array( $res['body'] ) ? (string) ( $res['body']['code'] ?? '' ) : '';
}
function p06_tmp( string $content, string $name ): array {
	$path = get_temp_dir() . 'bps-p06-' . uniqid() . '-' . $name;
	file_put_contents( $path, $content );
	return array(
		'name'     => $name,
		'type'     => 'application/octet-stream',
		'tmp_name' => $path,
		'size'     => strlen( $content ),
		'error'    => 0,
	);
}
function p06_png( int $w = 700, int $h = 700 ): string {
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 33, 169, 226 ) );
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w * 0.6 ), (int) ( $h * 0.6 ), imagecolorallocate( $im, 230, 40, 40 ) );
	ob_start();
	imagepng( $im );
	imagedestroy( $im );
	return (string) ob_get_clean();
}
function p06_payload( array $extra = array() ): array {
	return array_merge(
		array(
			'v'         => 1,
			'technique' => 'embroidered',
			'shape'     => 'circle',
			'sizeCm'    => 8,
			'qty'       => 25,
			'locale'    => 'nl-BE',
			'options'   => \BorduurPatchStudio\Pricing::default_options( Settings::get(), 'embroidered' ),
			'texts'     => array(
				array(
					'text' => 'Club Kalmthout',
					'font' => 'sora',
				),
			),
		),
		$extra
	);
}
function p06_create( array $extra = array(), ?array $original = null ): array {
	$files = array( 'preview' => p06_tmp( p06_png(), 'preview.png' ) );
	if ( $original ) {
		$files['original'] = $original;
	}
	return p06_rest( 'POST', '/designs', array( 'design' => wp_json_encode( p06_payload( $extra ) ) ), $files );
}
function p06_cart_lines(): array {
	$out = array();
	foreach ( WC()->cart->get_cart() as $k => $item ) {
		if ( ! empty( $item['bps'] ) ) {
			$out[ $k ] = $item;
		}
	}
	return $out;
}
function p06_order_from_cart( string $email, int $customer = 0 ): WC_Order {
	WC()->cart->calculate_totals();
	$oid = WC()->checkout()->create_order(
		array(
			'payment_method'     => 'bacs',
			'billing_email'      => $email,
			'billing_first_name' => 'Test',
			'billing_country'    => 'BE',
		)
	);
	$o = wc_get_order( $oid );
	if ( $customer > 0 ) {
		$o->set_customer_id( $customer );
		$o->save();
	}
	Order::mark_ordered( $o );
	WC()->cart->empty_cart();
	return $o;
}
function p06_line_design( WC_Order $o ): int {
	foreach ( $o->get_items() as $it ) {
		return (int) $it->get_meta( '_bps_design_id' );
	}
	return 0;
}

// Environment: no rate limits inside this process, mails recorded, a movable clock, empty cart.
add_filter( 'bps_rate_limit', '__return_zero', 999 );
add_filter(
	'wp_mail',
	static function ( $atts ) {
		$GLOBALS['p06']['mails'][] = array(
			'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
			'subject' => (string) $atts['subject'],
			'headers' => $atts['headers'],
			'message' => (string) $atts['message'],
		);
		return $atts;
	}
);
add_filter( 'bps_now', static fn( $now ) => $GLOBALS['p06']['clock'] ?? $now );
wc_load_cart();
WC()->cart->empty_cart();
$saved_settings = get_option( 'bps_settings', array() );
p06_settings(
	array(
		'reminder' => array(
			'enabled'   => true,
			'delayDays' => 3,
		),
		'account'  => array( 'enabled' => true ),
		'woo'      => array( 'maxDraftStorageMb' => 2048 ),
		'promises' => array( 'reorderKeepsSetup' => false ),
	)
);
$uid_a = p06_user( 'p06klanta' );
$uid_b = p06_user( 'p06klantb' );
$pdf   = '%PDF-1.4' . "\n" . str_repeat( 'P06 original ', 2000 );

try {
	/* ------------------------------------------------------------------
	 * klant-account-3: revise
	 * ---------------------------------------------------------------- */
	wp_set_current_user( 0 );
	$c = p06_create( array( 'qty' => 25 ), p06_tmp( $pdf, 'logo.pdf' ) );
	p06_ok( 201 === $c['status'], 'POST /designs with a PDF original → 201', $c );
	$id  = (int) $c['body']['designId'];
	$tok = (string) $c['body']['token'];
	$o1  = Designs::get( $id )['original'];
	$sha = sha1_file( $o1['path'] );
	$rv  = p06_rest(
		'POST',
		"/designs/$id/revise",
		array(
			'token'  => $tok,
			'design' => wp_json_encode( p06_payload( array( 'qty' => 50 ) ) ),
		),
		array( 'preview' => p06_tmp( p06_png(), 'preview.png' ) )
	);
	p06_ok( 200 === $rv['status'] && $id === (int) $rv['body']['designId'] && false === $rv['body']['cloned'], 'revise of a draft without file: 200, same id, not cloned', $rv );
	p06_ok( $tok === ( $rv['body']['token'] ?? '' ), 'revise: same token' );
	$d = Designs::get( $id );
	p06_ok( 50 === (int) $d['payload']['qty'], 'revise: payload.qty = 50' );
	p06_ok( $d['original']['path'] === $o1['path'] && sha1_file( $d['original']['path'] ) === $sha, 'revise: original path and sha1 unchanged' );
	$ct = p06_rest(
		'POST',
		'/cart',
		array(
			'designId'      => $id,
			'token'         => $tok,
			'rightsConsent' => array(
				'v'        => 'x',
				'accepted' => true,
			),
		)
	);
	$lines = p06_cart_lines();
	$line  = reset( $lines );
	p06_ok( 200 === $ct['status'] && $line && 50 === (int) $line['quantity'], '/cart after revise: a line of 50', array( $ct['status'], $line ? $line['quantity'] : null ) );
	$bad = p06_rest( 'POST', "/designs/$id/revise", array( 'token' => str_repeat( 'a', 64 ), 'design' => wp_json_encode( p06_payload() ) ), array( 'preview' => p06_tmp( p06_png(), 'p.png' ) ) );
	p06_ok( 403 === $bad['status'], 'revise with a wrong token → 403', $bad['status'] );

	/* ------------------------------------------------------------------
	 * security-4: re-ordering an ordered design clones it
	 * ---------------------------------------------------------------- */
	$order_a = p06_order_from_cart( 'p06-order-a@example.test' );
	Designs::update_proof(
		$id,
		array(
			'status'      => 'approved',
			'history_add' => array(
				'answer' => 'approved',
				'at'     => gmdate( 'c' ),
			),
		)
	);
	$ref_a = (string) wc_get_order_item_meta( key( $order_a->get_items() ), 'bps_ref' );
	$ct2   = p06_rest(
		'POST',
		'/cart',
		array(
			'designId'      => $id,
			'token'         => $tok,
			'rightsConsent' => array(
				'v'        => 'x',
				'accepted' => true,
			),
		)
	);
	$b2 = $ct2['body'];
	p06_ok( 200 === $ct2['status'] && true === ( $b2['cloned'] ?? false ) && (int) $b2['designId'] !== $id, '/cart with an ordered design → cloned, new designId', $b2 );
	p06_ok( ! empty( $b2['token'] ) && $b2['token'] !== $tok && Designs::verify_token( (int) $b2['designId'], (string) $b2['token'] ), 'clone: new valid token in the response' );
	p06_ok( ! empty( $b2['ref'] ) && $b2['ref'] !== $ref_a, 'clone: new ref', array( $b2['ref'] ?? null, $ref_a ) );
	$order_b = p06_order_from_cart( 'p06-order-b@example.test' );
	$id_b    = p06_line_design( $order_b );
	p06_ok( $id_b === (int) $b2['designId'] && $id_b !== $id, 'order B has another _bps_design_id', array( $id_b, $id ) );
	p06_ok( (string) wc_get_order_item_meta( key( $order_b->get_items() ), 'bps_ref' ) !== $ref_a, 'order B has another bps_ref' );
	p06_ok( 'approved' === Designs::get( $id )['proof']['status'] && (int) $order_a->get_id() === (int) Designs::get( $id )['order_id'], 'order A keeps its design, order link and approved proof' );
	p06_ok( 'none' === Designs::get( $id_b )['proof']['status'], 'design of order B: proof none ("Nog niet verstuurd")' );
	$b_files = array( Designs::get( $id_b )['original']['path'], get_post_meta( $id_b, '_bps_preview', true )['path'] );
	\BorduurPatchStudio\Woo\Privacy::erase( 'p06-order-a@example.test', 1 );
	p06_ok( file_exists( $b_files[0] ) && file_exists( $b_files[1] ), 'eraser for the e-mail of A leaves the files of B', $b_files );

	/* ------------------------------------------------------------------
	 * klant-account-3 (copy branch): revise of an ordered design
	 * ---------------------------------------------------------------- */
	$tok_b = Designs::token_for( $id_b );
	$item  = (int) get_post_meta( $id_b, '_bps_order_item_id', true );
	$rv2   = p06_rest( 'POST', "/designs/$id_b/revise", array( 'token' => $tok_b, 'design' => wp_json_encode( p06_payload( array( 'qty' => 75 ) ) ) ), array( 'preview' => p06_tmp( p06_png(), 'p.png' ) ) );
	$new   = (int) ( $rv2['body']['designId'] ?? 0 );
	p06_ok( 200 === $rv2['status'] && true === $rv2['body']['cloned'] && $new !== $id_b, 'revise of an ordered design → new design', $rv2 );
	p06_ok( $id_b === (int) get_post_meta( $new, '_bps_parent_id', true ) && 'none' === Designs::get( $new )['proof']['status'], 'new design: _bps_parent_id + proof none' );
	p06_ok( 50 === (int) Designs::get( $id_b )['payload']['qty'] && $id_b === (int) wc_get_order_item_meta( $item, '_bps_design_id' ), 'ordered design payload and order line unchanged' );

	/* ------------------------------------------------------------------
	 * klant-account-2: save without e-mail, account list, ownership, claim
	 * ---------------------------------------------------------------- */
	wp_set_current_user( $uid_a );
	$ca  = p06_create();
	$ida = (int) $ca['body']['designId'];
	p06_ok( $uid_a === Designs::owner( $ida ), 'POST /designs as a logged-in customer: post_author = customer' );
	$mails = count( $GLOBALS['p06']['mails'] );
	$sv    = p06_rest( 'POST', "/designs/$ida/save", array( 'token' => $ca['body']['token'], 'title' => 'Clubpatch 2027' ) );
	p06_ok( 200 === $sv['status'] && 'saved' === Designs::get( $ida )['status'] && 'Clubpatch 2027' === $sv['body']['title'], 'save (logged in, no e-mail): 200, saved, title', $sv );
	p06_ok( count( $GLOBALS['p06']['mails'] ) === $mails, 'save without e-mail sends 0 mails' );
	p06_ok( false !== strpos( (string) ( $sv['headers']['Cache-Control'] ?? '' ), 'no-store' ), 'answers carry Cache-Control: no-store' );
	$list = p06_rest( 'GET', '/account/designs' );
	p06_ok( 200 === $list['status'] && in_array( $ida, array_column( $list['body']['designs'], 'id' ), true ), 'GET /account/designs (A) lists the design', $list['status'] );
	$card = current( array_filter( $list['body']['designs'], static fn( $x ) => $x['id'] === $ida ) );
	p06_ok( $card && $card['ref'] === Designs::get( $ida )['ref'] && preg_match( '/-300\.webp$/', $card['preview'] ), 'card: ref + 300 px webp preview', $card );

	wp_set_current_user( $uid_b );
	$lb = p06_rest( 'GET', '/account/designs' );
	p06_ok( ! in_array( $ida, array_column( $lb['body']['designs'], 'id' ), true ), 'design of A is not in the list of B' );
	$pb = p06_rest( 'PATCH', "/account/designs/$ida", array( 'title' => 'hack' ) );
	$db = p06_rest( 'DELETE', "/account/designs/$ida" );
	p06_ok( 404 === $pb['status'] && 404 === $db['status'] && null !== get_post( $ida ), 'B: PATCH and DELETE on the design of A → 404, design intact', array( $pb['status'], $db['status'] ) );
	p06_ok( 404 === p06_rest( 'PATCH', '/account/designs/99999999', array( 'title' => 'x' ) )['status'], 'unknown id → 404' );
	$cb = p06_rest( 'POST', '/account/claim', array( 'designId' => $ida, 'token' => $ca['body']['token'] ) );
	p06_ok( 404 === $cb['status'] && $uid_a === Designs::owner( $ida ), 'B cannot claim the design of A, even with its token' );
	wp_set_current_user( 0 );
	p06_ok( 401 === p06_rest( 'GET', '/account/designs' )['status'], 'account route without login/nonce → 401' );
	$gs = p06_rest( 'POST', "/designs/$ida/save", array( 'token' => $ca['body']['token'] ) );
	p06_ok( 401 === $gs['status'], 'guest save → 401 (guests use /email)', $gs['status'] );
	$ge = p06_rest( 'POST', "/designs/$ida/email", array( 'token' => $ca['body']['token'] ) );
	p06_ok( 400 === $ge['status'], 'guest /email without e-mail → 400', $ge['status'] );

	// Guest design, then login + claim.
	$cg  = p06_create();
	$idg = (int) $cg['body']['designId'];
	wp_set_current_user( $uid_a );
	$bad = p06_rest( 'POST', '/account/claim', array( 'designId' => $idg, 'token' => str_repeat( 'b', 64 ) ) );
	p06_ok( 404 === $bad['status'] && 0 === Designs::owner( $idg ), 'claim with a wrong token → 404, nothing linked' );
	$cl = p06_rest( 'POST', '/account/claim', array( 'designId' => $idg, 'token' => $cg['body']['token'] ) );
	p06_ok( 200 === $cl['status'] && $uid_a === Designs::owner( $idg ), 'guest design claimed with its token after login' );
	Designs::set_status( $idg, 'saved' );
	p06_ok( in_array( $idg, array_column( p06_rest( 'GET', '/account/designs' )['body']['designs'], 'id' ), true ), 'claimed design is in the list' );

	// Registering with someone else's e-mail address links nothing.
	wp_set_current_user( 0 );
	$cz  = p06_create();
	$idz = (int) $cz['body']['designId'];
	p06_rest( 'POST', "/designs/$idz/email", array( 'token' => $cz['body']['token'], 'email' => 'p06-victim@example.test' ) );
	$uz = (int) wp_create_user( 'p06attacker' . wp_rand( 1000, 9999 ), wp_generate_password(), 'p06-victim-' . wp_rand( 1000, 9999 ) . '@example.test' );
	wp_update_user(
		array(
			'ID'         => $uz,
			'user_email' => 'p06-victim@example.test',
		)
	);
	wp_set_current_user( $uz );
	p06_ok( 0 === Designs::owner( $idz ) && ! in_array( $idz, array_column( p06_rest( 'GET', '/account/designs' )['body']['designs'], 'id' ), true ), 'an account with the same e-mail address gets nothing linked' );
	wp_set_current_user( 0 );
	wp_delete_user( $uz );

	// PATCH / DELETE by the owner; ordered design only hidden.
	wp_set_current_user( $uid_a );
	$pa = p06_rest( 'PATCH', "/account/designs/$ida", array( 'title' => 'Nieuwe naam' ) );
	p06_ok( 200 === $pa['status'] && 'Nieuwe naam' === Designs::get( $ida )['title'], 'owner renames (PATCH) → 200' );
	$ida_paths = array( get_post_meta( $ida, '_bps_preview', true )['path'] );
	$dl        = p06_rest( 'DELETE', "/account/designs/$ida" );
	p06_ok( 200 === $dl['status'] && 'deleted' === $dl['body']['result'] && null === get_post( $ida ) && ! file_exists( $ida_paths[0] ), 'owner deletes a saved design: post and files gone' );

	/* ------------------------------------------------------------------
	 * klant-account-5: reorder
	 * ---------------------------------------------------------------- */
	WC()->cart->empty_cart();
	$cr  = p06_create( array( 'qty' => 25 ) );
	$idr = (int) $cr['body']['designId'];
	p06_rest( 'POST', '/cart', array( 'designId' => $idr, 'token' => $cr['body']['token'], 'rightsConsent' => array( 'v' => 'x', 'accepted' => true ) ) );
	$order_x = p06_order_from_cart( 'p06klanta@example.test', $uid_a );
	$order_x->update_status( 'completed' );
	WC()->cart->empty_cart();
	$ro    = p06_rest( 'POST', "/designs/$idr/reorder", array( 'qty' => 50 ) );
	$lines = p06_cart_lines();
	$line  = reset( $lines );
	p06_ok( 200 === $ro['status'] && 1 === count( $lines ) && 50 === (int) $line['quantity'], 'reorder {qty:50} as owner: 1 cart line of 50', array( $ro['status'], count( $lines ), $ro['body'] ) );
	p06_ok( ! empty( $ro['body']['ref'] ) && $ro['body']['ref'] !== Designs::get( $idr )['ref'] && (int) $ro['body']['designId'] !== $idr, 'reorder: new design and ref' );
	p06_ok( $line && (int) $line['bps']['reorder_of']['order_id'] === (int) $order_x->get_id(), 'cart line refers to order X (reorder_of)' );
	$q = \BorduurPatchStudio\Pricing::quote(
		array(
			'technique' => 'embroidered',
			'sizeCm'    => 8,
			'qty'       => 50,
			'options'   => p06_payload()['options'],
			'locale'    => 'nl-BE',
		)
	);
	$unit_r = $ro['body']['quote']['unitIncl'] ?? null;
	p06_ok( null !== $unit_r && isset( $q['unitIncl'] ) && $q['unitIncl'] > 0 && abs( (float) $unit_r - (float) $q['unitIncl'] ) < 0.011, 'reorder price = Pricing::quote (server unit price)', array( $unit_r, $q['unitIncl'] ?? null ) );
	$fees = static function () {
		WC()->cart->calculate_totals();
		return implode( '|', array_map( static fn( $f ) => $f->name, WC()->cart->get_fees() ) );
	};
	p06_ok( false !== stripos( $fees(), 'Borduurkaart' ), 'reorderKeepsSetup=false: setup fee charged', $fees() );
	p06_settings( array( 'promises' => array( 'reorderKeepsSetup' => true ) ) );
	p06_ok( false === stripos( $fees(), 'Borduurkaart' ), 'reorderKeepsSetup=true: no setup fee', $fees() );
	p06_settings( array( 'promises' => array( 'reorderKeepsSetup' => false ) ) );
	WC()->cart->empty_cart();
	wp_set_current_user( $uid_b );
	p06_ok( 404 === p06_rest( 'POST', "/designs/$idr/reorder", array() )['status'] && 0 === count( p06_cart_lines() ), 'another customer without token → 404' );
	wp_set_current_user( $uid_a );
	\BorduurPatchStudio\Woo\Cleanup::expire_ordered( $idr );
	$ro2 = p06_rest( 'POST', "/designs/$idr/reorder", array( 'qty' => 30 ) );
	p06_ok( 200 === $ro2['status'] && 30 === (int) current( p06_cart_lines() )['quantity'], 'reorder still works after Cleanup::expire_ordered (copy from the order line payload)', $ro2 );
	WC()->cart->empty_cart();
	$cn = p06_create();
	p06_ok( 400 === p06_rest( 'POST', '/designs/' . (int) $cn['body']['designId'] . '/reorder', array() )['status'], 'reorder of a design that was never ordered → 400' );
	// Ordered design deleted from the list: only hidden, order line + design stay.
	$dh = p06_rest( 'DELETE', "/account/designs/$idr" );
	p06_ok( 'hidden' === ( $dh['body']['result'] ?? '' ) && null !== get_post( $idr ) && ! in_array( $idr, array_column( p06_rest( 'GET', '/account/designs' )['body']['designs'], 'id' ), true ), 'DELETE of an ordered design: hidden from the list, design kept' );
	p06_ok( $idr === p06_line_design( $order_x ), 'order line still points to the hidden design' );

	/* ------------------------------------------------------------------
	 * klant-account-2: 200 designs → the list costs ≤ 3 queries
	 * ---------------------------------------------------------------- */
	$uid_c = p06_user( 'p06klantc' );
	if ( count( get_posts( array( 'post_type' => 'bps_design', 'author' => $uid_c, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) ) === 0 ) {
		for ( $i = 0; $i < 200; $i++ ) {
			$pid = wp_insert_post( array( 'post_type' => 'bps_design', 'post_status' => 'private', 'post_title' => 'PS-LOAD-' . $i, 'post_author' => $uid_c ) );
			update_post_meta( $pid, '_bps_status', 'saved' );
			update_post_meta( $pid, '_bps_created_gmt', gmdate( 'Y-m-d H:i:s' ) );
			update_post_meta( $pid, '_bps_payload', wp_slash( wp_json_encode( p06_payload() ) ) );
		}
	}
	Account::designs_for( $uid_a ); // Warm the option caches (studio URL, date format).
	wp_cache_flush_group( 'post_meta' );
	global $wpdb;
	$before = $wpdb->num_queries;
	$page   = Account::designs_for( $uid_c, 1 );
	$used   = $wpdb->num_queries - $before;
	p06_ok( 200 === $page['total'] && 12 === count( $page['items'] ) && $used <= 3, "list of 200 designs: 12 cards, total 200, $used DB queries (≤ 3)", array( $page['total'], count( $page['items'] ), $used ) );

	/* ------------------------------------------------------------------
	 * klant-account-1: the reminder
	 * ---------------------------------------------------------------- */
	wp_set_current_user( 0 );
	$now                      = time();
	$GLOBALS['p06']['clock'] = $now;
	$cm                      = p06_create();
	$idm                     = (int) $cm['body']['designId'];
	$em                      = p06_rest( 'POST', "/designs/$idm/email", array( 'token' => $cm['body']['token'], 'email' => 'p06-reminder@example.test', 'reminder' => true ) );
	$rem                     = Reminder::get( $idm );
	p06_ok( 200 === $em['status'] && $rem && gmdate( 'Y-m-d H:i:s', $now ) === $rem['consent_at'], 'email_design with reminder:true stores consent_at', $em );
	$pending = as_get_scheduled_actions(
		array(
			'hook'     => Reminder::HOOK,
			'args'     => array( $idm ),
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 10,
		),
		'ids'
	);
	p06_ok( 1 === count( $pending ), 'exactly 1 pending action', count( $pending ) );
	$next = as_next_scheduled_action( Reminder::HOOK, array( $idm ), Reminder::GROUP );
	p06_ok( abs( (int) $next - ( $now + 3 * DAY_IN_SECONDS ) ) <= 2, 'action planned at +delayDays (3 days)', array( $next, $now + 3 * DAY_IN_SECONDS ) );
	$GLOBALS['p06']['clock'] = $now + 3 * DAY_IN_SECONDS;
	$mails                   = count( $GLOBALS['p06']['mails'] );
	do_action( Reminder::HOOK, $idm );
	$sent = array_slice( $GLOBALS['p06']['mails'], $mails );
	p06_ok( 1 === count( $sent ), 'running the action: 1 mail', count( $sent ) );
	$m0 = $sent[0] ?? array();
	p06_ok( $m0 && false !== strpos( (string) $m0['message'], 'reminder/unsubscribe' ), 'mail contains the unsubscribe link' );
	p06_ok( $m0 && false !== stripos( is_array( $m0['headers'] ) ? implode( "\n", $m0['headers'] ) : (string) $m0['headers'], 'List-Unsubscribe:' ), 'mail has a List-Unsubscribe header' );
	p06_ok( $m0 && false !== strpos( (string) $m0['message'], 'enige herinnering' ), 'mail says honestly it is the only reminder' );
	do_action( Reminder::HOOK, $idm );
	p06_ok( count( $GLOBALS['p06']['mails'] ) === $mails + 1, 'running it again: 0 mails' );

	// Carted meanwhile → nothing, cancelled.
	$GLOBALS['p06']['clock'] = $now;
	$cc                      = p06_create();
	$idc                     = (int) $cc['body']['designId'];
	p06_rest( 'POST', "/designs/$idc/email", array( 'token' => $cc['body']['token'], 'email' => 'p06-cart@example.test', 'reminder' => true ) );
	p06_rest( 'POST', '/cart', array( 'designId' => $idc, 'token' => $cc['body']['token'], 'rightsConsent' => array( 'v' => 'x', 'accepted' => true ) ) );
	WC()->cart->empty_cart();
	$GLOBALS['p06']['clock'] = $now + 3 * DAY_IN_SECONDS;
	$mails                   = count( $GLOBALS['p06']['mails'] );
	do_action( Reminder::HOOK, $idc );
	p06_ok( count( $GLOBALS['p06']['mails'] ) === $mails && ! empty( Reminder::get( $idc )['cancelled'] ), 'carted meanwhile: 0 mails, cancelled set', Reminder::get( $idc ) );
	p06_ok( false === as_next_scheduled_action( Reminder::HOOK, array( $idc ), Reminder::GROUP ), 'cancelled reminder has no pending action' );

	// Ordered meanwhile.
	$GLOBALS['p06']['clock'] = $now;
	$co                      = p06_create();
	$ido                     = (int) $co['body']['designId'];
	p06_rest( 'POST', "/designs/$ido/email", array( 'token' => $co['body']['token'], 'email' => 'p06-ordered@example.test', 'reminder' => true ) );
	p06_rest( 'POST', '/cart', array( 'designId' => $ido, 'token' => $co['body']['token'], 'rightsConsent' => array( 'v' => 'x', 'accepted' => true ) ) );
	p06_order_from_cart( 'p06-ordered@example.test' );
	$GLOBALS['p06']['clock'] = $now + 3 * DAY_IN_SECONDS;
	$mails                   = count( $GLOBALS['p06']['mails'] );
	do_action( Reminder::HOOK, $ido );
	p06_ok( count( $GLOBALS['p06']['mails'] ) === $mails && ! empty( Reminder::get( $ido )['cancelled'] ), 'ordered meanwhile: 0 mails, cancelled set' );

	// Unsubscribe link → 200 NL/FR, later reminder to the same address sends nothing.
	$GLOBALS['p06']['clock'] = $now;
	$cu                      = p06_create();
	$idu                     = (int) $cu['body']['designId'];
	p06_rest( 'POST', "/designs/$idu/email", array( 'token' => $cu['body']['token'], 'email' => 'p06-unsub@example.test', 'reminder' => true ) );
	parse_str( (string) wp_parse_url( Reminder::unsubscribe_url( $idu ), PHP_URL_QUERY ), $qs );
	$un = p06_rest( 'GET', '/reminder/unsubscribe', array( 'd' => $qs['d'], 's' => $qs['s'] ) );
	p06_ok( 200 === $un['status'] && ! empty( $un['body']['message']['nl'] ) && ! empty( $un['body']['message']['fr'] ), 'unsubscribe link → 200 with NL and FR text', $un );
	p06_ok( 400 === p06_rest( 'GET', '/reminder/unsubscribe', array( 'd' => $qs['d'], 's' => str_repeat( 'c', 64 ) ) )['status'], 'forged unsubscribe signature → 400' );
	$cu2  = p06_create();
	$idu2 = (int) $cu2['body']['designId'];
	p06_rest( 'POST', "/designs/$idu2/email", array( 'token' => $cu2['body']['token'], 'email' => 'p06-unsub@example.test', 'reminder' => true ) );
	$GLOBALS['p06']['clock'] = $now + 3 * DAY_IN_SECONDS;
	$mails                   = count( $GLOBALS['p06']['mails'] );
	do_action( Reminder::HOOK, $idu2 );
	p06_ok( count( $GLOBALS['p06']['mails'] ) === $mails, 'after unsubscribing, a later reminder sends 0 mails' );

	// Without reminder:true, and with reminder.enabled = false.
	$GLOBALS['p06']['clock'] = $now;
	$cw                      = p06_create();
	$idw                     = (int) $cw['body']['designId'];
	p06_rest( 'POST', "/designs/$idw/email", array( 'token' => $cw['body']['token'], 'email' => 'p06-none@example.test' ) );
	p06_ok( null === Reminder::get( $idw ) && false === as_next_scheduled_action( Reminder::HOOK, array( $idw ), Reminder::GROUP ), 'without reminder:true: no action' );
	p06_settings( array( 'reminder' => array( 'enabled' => false ) ) );
	$cx  = p06_create();
	$idx = (int) $cx['body']['designId'];
	p06_rest( 'POST', "/designs/$idx/email", array( 'token' => $cx['body']['token'], 'email' => 'p06-off@example.test', 'reminder' => true ) );
	p06_ok( false === as_next_scheduled_action( Reminder::HOOK, array( $idx ), Reminder::GROUP ), 'reminder.enabled=false: no action' );
	$pub = Settings::public_config( 'nl-BE' );
	p06_ok( false === ( $pub['features']['reminder'] ?? null ), 'reminder.enabled=false: features.reminder === false in public_config', $pub['features'] ?? null );
	p06_settings( array( 'reminder' => array( 'enabled' => true ) ) );
	$GLOBALS['p06']['clock'] = null;

	/* ------------------------------------------------------------------
	 * account.enabled = false
	 * ---------------------------------------------------------------- */
	wp_set_current_user( $uid_a );
	p06_settings( array( 'account' => array( 'enabled' => false ) ) );
	p06_ok( 404 === p06_rest( 'GET', '/account/designs' )['status'], 'account.enabled=false: account routes → 404' );
	p06_ok( ! isset( apply_filters( 'woocommerce_account_menu_items', array( 'orders' => 'Bestellingen' ), array() )[ Account::ENDPOINT ] ), 'account.enabled=false: no menu item' );
	p06_settings( array( 'account' => array( 'enabled' => true ) ) );
	p06_ok( isset( apply_filters( 'woocommerce_account_menu_items', array( 'orders' => 'Bestellingen' ), array() )[ Account::ENDPOINT ] ), 'account.enabled=true: menu item "Mijn ontwerpen"' );

	/* ------------------------------------------------------------------
	 * security-3: storage ceiling through POST /designs
	 * ---------------------------------------------------------------- */
	wp_set_current_user( 0 );
	$used0 = Uploads::storage_used();
	p06_settings( array( 'woo' => array( 'maxDraftStorageMb' => ( $used0 + 1048576 ) / 1048576 ) ) );
	$big = '%PDF-1.4' . str_repeat( 'Z', 838860 );
	$s1  = p06_create( array(), p06_tmp( $big, 'a.pdf' ) );
	p06_ok( 201 === $s1['status'], 'storage: first 0,8 MB original fits', $s1['status'] );
	$count = count( glob( Uploads::private_dir() . '*/*/*' ) );
	$s2    = p06_create( array(), p06_tmp( $big, 'b.pdf' ) );
	p06_ok( 507 === $s2['status'] && 'bps_storage' === p06_err( $s2 ), 'storage: second original → 507 bps_storage', $s2 );
	p06_ok( count( glob( Uploads::private_dir() . '*/*/*' ) ) === $count, 'storage: no file added in private/' );
	wp_delete_post( (int) $s1['body']['designId'], true );
	p06_ok( Uploads::storage_used() === $used0, 'storage: counter back down after deleting the design', array( Uploads::storage_used(), $used0 ) );
	p06_settings( array( 'woo' => array( 'maxDraftStorageMb' => 2048 ) ) );
	delete_transient( Uploads::STORAGE_ALERT );

	/* ------------------------------------------------------------------
	 * concurrentie-features-8 + prestatie-1 through the real routes
	 * ---------------------------------------------------------------- */
	$dsth = str_pad( 'LA:' . str_pad( 'P06TEST', 16 ) . "\rST:   9876\rCO:  3\r+X:  400\r-X:  400\r+Y:  250\r-Y:  250\rAX:+    0\rAY:+    0\rMX:+    0\rMY:+    0\rPD:******\r\x1a", 512, ' ' ) . str_repeat( "\x01\x01\x03", 10 ) . "\x00\x00\xf3";
	$cd   = p06_create( array(), p06_tmp( $dsth, 'clublogo.dst' ) );
	$dd   = 201 === $cd['status'] ? Designs::get( (int) $cd['body']['designId'] ) : null;
	p06_ok( $dd && 9876 === ( $dd['payload']['embFile']['stitches'] ?? null ) && 80.0 === (float) $dd['payload']['embFile']['widthMm'] && 50.0 === (float) $dd['payload']['embFile']['heightMm'], 'DST upload: embFile {stitches, colorChanges, widthMm, heightMm} in the payload', $dd['payload']['embFile'] ?? $cd );
	$pe = p06_create( array(), p06_tmp( p06_png( 64, 64 ), 'logo.pes' ) );
	p06_ok( 415 === $pe['status'] && false !== strpos( (string) ( $pe['body']['message'] ?? '' ), 'PES' ), 'renamed PNG as .pes → 415 with a clear message', $pe );
	p06_ok( $dd && preg_match( '/-300\.webp$/', Designs::preview_url( (int) $dd['id'], 'cart' ) ) && preg_match( '/-600\.jpg$/', Designs::preview_url( (int) $dd['id'], 'mail' ) ), 'new design: webp cart variant and jpg mail variant' );
} catch ( \Throwable $e ) {
	p06_ok( false, 'suite crashed: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine() );
}

// Restore.
update_option( 'bps_settings', $saved_settings );
Settings::flush();
WC()->cart->empty_cart();
wp_set_current_user( 0 );
ob_end_clean();
foreach ( $GLOBALS['p06']['lines'] as $l ) {
	echo $l . "\n";
}
echo 'RESULT ' . $GLOBALS['p06']['pass'] . '/' . $GLOBALS['p06']['total'] . "\n";
