<?php
/**
 * One uninstall run in a fresh process (uninstall.php declares functions, so it can only be included once).
 * Usage: php tests/core/uninstall-scenario.php wipe|nowipe   → prints the state that is left as JSON.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

require __DIR__ . '/wp-stubs.php';

$plugin = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/';
define( 'WP_UNINSTALL_PLUGIN', 'borduur-patch-studio/borduur-patch-studio.php' );
define( 'BPS_DIR', $plugin );
define( 'BPS_FILE', $plugin . 'borduur-patch-studio.php' );
define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
define( 'BPS_VERSION', '1.0.0' );
define( 'BPS_DB_VERSION', 1 );

$wipe = in_array( 'wipe', $argv, true );

// Uploads with one stored original per design.
$base = $GLOBALS['bps_t']['uploads'] . '/patch-studio/private';
@mkdir( $base, 0777, true );
@mkdir( $GLOBALS['bps_t']['uploads'] . '/patch-studio/ai', 0777, true );
file_put_contents( $GLOBALS['bps_t']['uploads'] . '/patch-studio/ai/x.png', 'x' );
file_put_contents( $GLOBALS['bps_t']['uploads'] . '/keep-me.txt', 'not ours' );

$designs = array();
foreach ( array( 'draft', 'carted', 'saved', 'ordered', 'quoted', null ) as $status ) {
	$file = $base . '/' . ( $status ?? 'nostatus' ) . '.png';
	file_put_contents( $file, 'png' );
	$meta = array( '_bps_original' => array( 'path' => $file ) );
	if ( null !== $status ) {
		$meta['_bps_status'] = $status;
	}
	$designs[ $status ?? 'nostatus' ] = array(
		'id'   => bps_t_insert_post( 'bps_design', $meta ),
		'file' => $file,
	);
}
$quote        = bps_t_insert_post( 'bps_quote' );
$patch        = bps_t_insert_post( 'product', array( '_bps_patch_product' => '1' ) );
$nt_product   = bps_t_insert_post( 'product', array( '_bps_nametape_product' => '1' ) );
$foreign      = bps_t_insert_post( 'product', array( '_sku' => 'ANDER' ) );
$own_page     = bps_t_insert_post( 'page', array( '_bps_created_page' => '1' ) );
$owner_page   = bps_t_insert_post( 'page', array() );

$options = array(
	'bps_settings'                              => array( 'admin' => array( 'deleteDataOnUninstall' => $wipe ) ),
	'bps_db_version'                            => 1,
	'bps_version'                               => '1.0.0',
	'bps_product_id'                            => $patch,
	'bps_studio_page_id'                        => $own_page,
	'bps_product_lock'                          => 1,
	'bps_ref_seq'                               => 12,
	'bps_quote_seq'                             => 3,
	'bps_ai_settings'                           => array( 'mode' => 'live' ),
	'bps_ai_secrets'                            => array( 'openai' => 'enc:sk-geheimeSleutel1234567890' ),
	'bps_ai_log'                                => array( array( 'msg' => 'x' ) ),
	'bps_ai_db_version'                         => 1,
	'bps_ai_alerts_sent'                        => array(),
	'bps_ai_refusal_counts'                     => array(),
	'bps_svc_products'                          => array(),
	'bps_svc_products_lock'                     => 1,
	'bps_svc_db_version'                        => '1.0.0',
	'bps_svc_rewrite_v'                         => 1,
	'bps_nametape_settings'                     => array(),
	'bps_nametape_product_id'                   => $nt_product,
	'bps_nametape_page_id'                      => 0,
	'bps_crosssell_settings'                    => array(),
	'bps_cs_stats'                              => array(),
	'bps_license'                               => array( 'key' => 'ABCD-1234-EFGH-5678' ),
	'bps_modules_state'                         => array( 'ai' => true ),
	'bps_something_new'                         => 1,
	'woocommerce_bps_proof_settings'            => array( 'enabled' => 'yes' ),
	'woocommerce_bps_quote_customer_settings'   => array( 'enabled' => 'yes' ),
	'woocommerce_bacs_settings'                 => array( 'enabled' => 'yes' ),
	'_transient_bps_uploads_selftest'           => array( 'ok' => true ),
	'_transient_timeout_bps_uploads_selftest'   => time() + 100,
	'_transient_bps_rl_abc'                     => 1,
	'blogname'                                  => 'Shop',
);
foreach ( $options as $k => $v ) {
	$GLOBALS['bps_t']['options'][ $k ] = $v;
}
foreach ( array( 'bps_ai_owners', 'bps_ai_jobs', 'bps_ai_costs', 'bps_ai_lots', 'bps_ai_ledger', 'wc_orders' ) as $t ) {
	$GLOBALS['wpdb']->tables[ 'wp_' . $t ] = true;
}
wp_schedule_event( time() + 60, 'daily', 'bps_cleanup' );
wp_schedule_single_event( time() + 60, 'bps_cleanup', array( 1 ) );
wp_schedule_event( time() + 60, 'daily', 'bps_ai_cleanup' );
wp_schedule_event( time() + 60, 'daily', 'bps_svc_daily' );
wp_schedule_event( time() + 60, 'daily', 'bps_nt_cleanup' );
wp_schedule_event( time() + 60, 'daily', 'bps_future_hook' );
wp_schedule_event( time() + 60, 'daily', 'wc_other_plugin' );

$GLOBALS['as_unscheduled'] = array();
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	$GLOBALS['as_unscheduled'][] = array( $hook, $group );
}

include $plugin . 'uninstall.php';

$left_designs = array();
foreach ( $designs as $status => $d ) {
	$left_designs[ $status ] = array(
		'post' => isset( $GLOBALS['bps_t']['posts'][ $d['id'] ] ),
		'file' => is_file( $d['file'] ),
	);
}
echo json_encode(
	array(
		'options'       => array_keys( $GLOBALS['bps_t']['options'] ),
		'tables'        => array_keys( $GLOBALS['wpdb']->tables ),
		'cron'          => cron_hooks(),
		'as'            => $GLOBALS['as_unscheduled'],
		'designs'       => $left_designs,
		'quote'         => isset( $GLOBALS['bps_t']['posts'][ $quote ] ),
		'patch'         => isset( $GLOBALS['bps_t']['posts'][ $patch ] ),
		'nt_product'    => isset( $GLOBALS['bps_t']['posts'][ $nt_product ] ),
		'foreign'       => isset( $GLOBALS['bps_t']['posts'][ $foreign ] ),
		'own_page'      => isset( $GLOBALS['bps_t']['posts'][ $own_page ] ),
		'owner_page'    => isset( $GLOBALS['bps_t']['posts'][ $owner_page ] ),
		'uploads_dir'   => is_dir( $GLOBALS['bps_t']['uploads'] . '/patch-studio' ),
		'foreign_file'  => is_file( $GLOBALS['bps_t']['uploads'] . '/keep-me.txt' ),
	)
);

// Clean the temp uploads folder.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['bps_t']['uploads'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) {
	$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
}
rmdir( $GLOBALS['bps_t']['uploads'] );
