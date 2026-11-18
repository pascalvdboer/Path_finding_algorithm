<?php
/**
 * P03 smoke test against a real WordPress + WooCommerce (SQLite) site: the SQL paths that the stub runner cannot
 * cover (AI owner retention, name-tape retention candidates, WC order hooks, user deletion, eraser registry).
 *
 * Run: tests/e2e/start-wp.sh wp eval-file "tests/premium/P03 privacy-retention/wp-smoke.php"
 * Leaves no data behind (everything it creates is deleted at the end). Prints "N/N ok".
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

use BorduurPatchStudio\Woo\Cleanup;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Privacy;
use BorduurPatchStudio\Woo\Quotes;
use BorduurPatchStudio\Nametape\Privacy as NT_Privacy;
use BorduurPatchStudio\Nametape\Order_Tools;

$checks = 0;
$fails  = array();
$ok     = static function ( $cond, string $msg ) use ( &$checks, &$fails ) {
	++$checks;
	if ( ! $cond ) {
		$fails[] = $msg;
		echo "  FAIL {$msg}\n";
	} else {
		echo "  ok   {$msg}\n";
	}
};
$cleanup_posts  = array();
$cleanup_orders = array();
$cleanup_users  = array();

// 1. Registry.
$erasers = array_keys( apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
$ok( in_array( 'borduur-patch-studio', $erasers, true ), 'eraser borduur-patch-studio geregistreerd' );
$ok( in_array( 'borduur-patch-studio-nametape', $erasers, true ), 'eraser borduur-patch-studio-nametape geregistreerd' );

// 2. Name tapes: real order, real itemmeta SQL for the retention candidates.
$order = wc_create_order( array( 'status' => 'pending' ) );
$cleanup_orders[] = $order->get_id();
$order->set_billing_email( 'p03-nt@example.test' );
$item = new WC_Order_Item_Product();
$item->set_name( 'Naamstroken' );
$item->set_quantity( 1 );
$dir  = wp_upload_dir()['basedir'] . '/patch-studio/nametape';
wp_mkdir_p( $dir );
$png  = bin2hex( random_bytes( 16 ) ) . '.png';
file_put_contents( $dir . '/' . $png, 'PNG' );
$item->add_meta_data( '_bps_nt_batch', wp_json_encode( array( 'v' => 1, 'rows' => array( array( 'n' => 'Jan Peeters', 'l2' => '', 'b' => 'O+', 'q' => 1 ) ), 'preview_file' => $png ) ), true );
$item->add_meta_data( 'bps_nt_blood', 'op eigen strook (1×)', true );
$item->add_meta_data( '_bps_nt_preview', $png, true );
$order->add_item( $item );
$order->save();
$order->update_status( 'completed' );
$order = wc_get_order( $order->get_id() );
$order->set_date_completed( time() - 200 * DAY_IN_SECONDS );
$order->save();
$cands = NT_Privacy::candidates( 0, 500 );
$ok( in_array( $order->get_id(), $cands, true ), 'SQL-kandidaten vinden de naamstrookbestelling' );
$ex = NT_Privacy::export( 'p03-nt@example.test', 1 );
$ok( 1 === count( $ex['data'] ) && false !== strpos( wp_json_encode( $ex['data'] ), 'O+' ), 'exporter: 1 item met bloedgroep' );
Order_Tools::cleanup();
$fresh = wc_get_order( $order->get_id() );
$it    = current( $fresh->get_items() );
$ok( false === strpos( (string) $it->get_meta( '_bps_nt_batch' ), 'Jan Peeters' ), 'cron (bps_nt_cleanup) anonimiseerde de naam' );
$ok( '' === (string) $it->get_meta( 'bps_nt_blood' ) && ! file_exists( $dir . '/' . $png ), 'bloedgroep en PNG weg' );
$ok( ! in_array( $order->get_id(), NT_Privacy::candidates( 0, 500 ), true ), 'daarna geen kandidaat meer' );
$ok( file_exists( $dir . '/.htaccess' ) && false !== strpos( (string) file_get_contents( $dir . '/.htaccess' ), 'noindex' ), 'nametape/.htaccess met noindex' );

// 3. Woo: failed order releases its design; WC anonymisation expires it.
$design = wp_insert_post( array( 'post_type' => Designs::CPT, 'post_status' => 'private', 'post_title' => 'PS-P03-1', 'post_author' => 0 ) );
$cleanup_posts[] = $design;
update_post_meta( $design, '_bps_status', 'ordered' );
update_post_meta( $design, '_bps_created_gmt', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) );
update_post_meta( $design, '_bps_payload', wp_slash( wp_json_encode( array( 'texts' => array( array( 'text' => 'FC P03' ) ), 'notes' => 'bel mij' ) ) ) );
update_post_meta( $design, '_bps_email', 'p03-w@example.test' );
$o2 = wc_create_order( array( 'status' => 'pending' ) );
$cleanup_orders[] = $o2->get_id();
$o2->set_billing_email( 'p03-w@example.test' );
$i2 = new WC_Order_Item_Product();
$i2->set_name( 'Patch' );
$i2->add_meta_data( '_bps_design_id', $design, true );
$o2->add_item( $i2 );
$o2->save();
update_post_meta( $design, '_bps_order_id', $o2->get_id() );
update_post_meta( $design, '_bps_order_item_id', $i2->get_id() );
$o2->update_status( 'failed' );
$ok( 'carted' === get_post_meta( $design, '_bps_status', true ), 'failed-bestelling zet het ontwerp op carted' );
$ok( time() - strtotime( get_post_meta( $design, '_bps_created_gmt', true ) . ' UTC' ) < 60, 'created_gmt opnieuw gezet' );

// 4. Eraser over the real WP privacy callback + quote anonymisation through Cleanup.
$q = wp_insert_post( array( 'post_type' => Quotes::CPT, 'post_status' => 'private', 'post_title' => 'OF-P03', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
$cleanup_posts[] = $q;
foreach ( Quotes::CONTACT_META as $k ) {
	update_post_meta( $q, $k, 'x' );
}
update_post_meta( $q, '_bps_email', 'p03-q@example.test' );
$counts = Cleanup::run();
$ok( $counts['quotes'] >= 1 && '' === get_post_meta( $q, '_bps_email', true ) && '' !== get_post_meta( $q, '_bps_anonymised_gmt', true ), 'Cleanup::run anonimiseert de oude offerte (date_query op post_date_gmt)' );
$r = Privacy::erase( 'p03-w@example.test', 1 );
$ok( $r['done'] && $r['items_removed'] && null === get_post( $design ), 'eraser: carted ontwerp van de failed-bestelling verwijderd' );

// 5. User deletion.
$uid = wp_insert_user( array( 'user_login' => 'p03user' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'user_email' => 'p03-u' . wp_rand( 1000, 9999 ) . '@example.test' ) );
$cleanup_users[] = $uid;
$saved = wp_insert_post( array( 'post_type' => Designs::CPT, 'post_status' => 'private', 'post_title' => 'PS-P03-2', 'post_author' => $uid ) );
update_post_meta( $saved, '_bps_status', 'saved' );
$kept = wp_insert_post( array( 'post_type' => Designs::CPT, 'post_status' => 'private', 'post_title' => 'PS-P03-3', 'post_author' => $uid ) );
$cleanup_posts[] = $kept;
update_post_meta( $kept, '_bps_status', 'ordered' );
update_post_meta( $kept, '_bps_title', 'Mijn logo' );
$ex = Privacy::export( get_userdata( $uid )->user_email, 1 );
$ok( 2 === count( $ex['data'] ), 'export via account (post_author) zonder _bps_email: 2 ontwerpen' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );
$ok( null === get_post( $saved ), 'wp_delete_user: bewaard ontwerp weg' );
$ok( null !== get_post( $kept ) && 0 === (int) get_post( $kept )->post_author && '' === get_post_meta( $kept, '_bps_title', true ), 'besteld ontwerp blijft zonder account en titel' );

// 6. AI owner retention SQL (only when the AI tables exist).
global $wpdb;
$owners = $wpdb->prefix . 'bps_ai_owners';
if ( class_exists( '\BorduurPatchStudio\AI\Schema' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $owners ) ) !== $owners ) {
	\BorduurPatchStudio\AI\Schema::install();
}
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $owners ) ) === $owners ) {
	$store = new \BorduurPatchStudio\AI\Store_Wpdb();
	$old   = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
	$keys  = array( 'gone' => 'e:' . str_repeat( 'a', 64 ), 'lot' => 'e:' . str_repeat( 'b', 64 ), 'ledger' => 'e:' . str_repeat( 'c', 64 ), 'anon' => 'a:' . str_repeat( 'd', 64 ) );
	foreach ( $keys as $n => $k ) {
		$wpdb->delete( $owners, array( 'owner_key' => $k ) );
		$wpdb->insert( $owners, array( 'owner_key' => $k, 'kind' => 'anon' === $n ? 'anon' : 'email', 'email' => 'anon' === $n ? null : $n . '@example.test', 'created_at' => $old, 'last_seen_at' => 'anon' === $n ? gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) : $old ) );
	}
	$wpdb->insert( $wpdb->prefix . 'bps_ai_lots', array( 'owner_key' => $keys['lot'], 'source' => 'pack', 'rounds_total' => 10, 'rounds_used' => 1, 'usable_from' => $old, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 99 * DAY_IN_SECONDS ), 'status' => 'active', 'created_at' => $old ) );
	$lot_id = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->prefix . 'bps_ai_ledger', array( 'owner_key' => $keys['ledger'], 'delta' => -1, 'kind' => 'use', 'reason' => 'generate', 'created_at' => $old ) );
	$led_id = (int) $wpdb->insert_id;
	$res = $store->owner_purge( gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s' ), 100 );
	$ok( null === $store->owner_get( $keys['gone'] ), 'AI SQL: eigenaar zonder verwijzingen verwijderd' );
	$ok( 'lot@example.test' === ( $store->owner_get( $keys['lot'] )['email'] ?? '' ), 'AI SQL: lot met saldo ongemoeid' );
	$row = $store->owner_get( $keys['ledger'] );
	$ok( null !== $row && null === $row['email'], 'AI SQL: enkel ledger: sleutel blijft, e-mail NULL' );
	$ok( null === $store->owner_get( $keys['anon'] ), 'AI SQL: anonieme rij van 31 d weg' );
	foreach ( $keys as $k ) {
		$wpdb->delete( $owners, array( 'owner_key' => $k ) );
	}
	$wpdb->delete( $wpdb->prefix . 'bps_ai_lots', array( 'id' => $lot_id ) );
	$wpdb->delete( $wpdb->prefix . 'bps_ai_ledger', array( 'id' => $led_id ) );
} else {
	echo "  skip AI-tabellen ontbreken\n";
}

// Clean up.
foreach ( $cleanup_posts as $p ) {
	wp_delete_post( $p, true );
}
foreach ( $cleanup_orders as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
echo ( count( $fails ) ? 'Mislukt: ' . implode( ' | ', $fails ) . "\n" : '' ) . ( $checks - count( $fails ) ) . "/{$checks} ok\n";
