<?php
/**
 * Name tapes: tools on the order screen (SPEC-EXTRA §1.8): name list as CSV, printable production list / digital proof,
 * and the daily clean-up of orphan preview files (cron bps_nt_cleanup).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

/**
 * Order screen buttons + admin-post handlers + cron.
 */
final class Order_Tools {

	const CSV_ACTION   = 'bps_nt_csv';
	const PRINT_ACTION = 'bps_nt_print';
	const CRON         = 'bps_nt_cleanup';
	const KEEP_DAYS    = 7;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'item_tools' ), 10, 3 );
		add_action( 'admin_post_' . self::CSV_ACTION, array( __CLASS__, 'download_csv' ) );
		add_action( 'admin_post_' . self::PRINT_ACTION, array( __CLASS__, 'print_sheet' ) );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
	}

	/**
	 * Admin language (user locale).
	 */
	public static function admin_lang(): string {
		$loc = function_exists( 'get_user_locale' ) ? get_user_locale() : ( function_exists( 'get_locale' ) ? get_locale() : 'nl_BE' );
		return 0 === stripos( (string) $loc, 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * May the current user use the tools?
	 */
	public static function can(): bool {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Snapshot of a name-tape order item, or null.
	 *
	 * @param mixed $item Order item.
	 */
	public static function snapshot( $item ): ?array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return null;
		}
		$raw = (string) $item->get_meta( '_bps_nt_batch' );
		if ( '' === $raw ) {
			return null;
		}
		$nt = json_decode( $raw, true );
		return is_array( $nt ) && ! empty( $nt['rows'] ) ? $nt : null;
	}

	/**
	 * Signed URL for a tool.
	 *
	 * @param string $action   Action.
	 * @param int    $order_id Order id.
	 * @param int    $item_id  Item id.
	 */
	public static function url( string $action, int $order_id, int $item_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'order'  => $order_id,
					'item'   => $item_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $order_id . '_' . $item_id
		);
	}

	/**
	 * Hide our technical meta on the order screen (the admin shows underscore keys unless listed here).
	 *
	 * @param mixed $keys Keys.
	 */
	public static function hidden_meta( $keys ): array {
		$keys   = is_array( $keys ) ? $keys : array();
		foreach ( array( '_bps_nt_batch', '_bps_nt_preview', '_bps_nt_unit_excl', '_bps_nt_line_excl', '_bps_nt_review', '_bps_nt_schema' ) as $k ) {
			$keys[] = $k;
		}
		return $keys;
	}

	/**
	 * Buttons + preview below a name-tape line on the order screen.
	 *
	 * @param int    $item_id Item id.
	 * @param object $item    Item.
	 * @param mixed  $product Product.
	 */
	public static function item_tools( $item_id, $item, $product = null ): void {
		$nt = self::snapshot( $item );
		if ( ! $nt || ! self::can() || ! method_exists( $item, 'get_order_id' ) ) {
			return;
		}
		$fr       = 'fr' === self::admin_lang();
		$order_id = (int) $item->get_order_id();
		$preview  = (string) ( $nt['preview_url'] ?? '' );
		echo '<div class="bps-nt-order-tools" style="margin:8px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
		if ( '' !== $preview ) {
			echo '<img src="' . esc_url( $preview ) . '" alt="" style="height:36px;width:auto;border-radius:4px;border:1px solid #ddd">';
		}
		echo '<a class="button" href="' . esc_url( self::url( self::CSV_ACTION, $order_id, (int) $item_id ) ) . '">' . esc_html( $fr ? 'Liste des noms (CSV)' : 'Namenlijst (CSV)' ) . '</a>';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::url( self::PRINT_ACTION, $order_id, (int) $item_id ) ) . '">' . esc_html( $fr ? 'Liste de production / épreuve' : 'Productielijst / proef afdrukken' ) . '</a>';
		if ( ! empty( $nt['review'] ) ) {
			$words = implode( ', ', array_map( 'strval', (array) ( $nt['review_words'] ?? array() ) ) );
			echo '<span style="color:#b26200;font-weight:600">' . esc_html( ( $fr ? 'Contrôle manuel : ' : 'Manuele controle: ' ) . $words ) . '</span>';
		}
		if ( '' !== (string) ( $nt['org_note'] ?? '' ) ) {
			echo '<span>' . esc_html( ( $fr ? 'Organisation : ' : 'Organisatie: ' ) . (string) $nt['org_note'] ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Verify nonce + capability and return [order, item, snapshot]; dies with a friendly message otherwise.
	 *
	 * @param string $action Action.
	 * @return array{0:\WC_Order,1:\WC_Order_Item_Product,2:array}
	 */
	private static function request( string $action ): array {
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
		$item_id  = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
		$fr       = 'fr' === self::admin_lang();
		if ( ! self::can() ) {
			wp_die( esc_html( $fr ? 'Vous n’avez pas accès à cette commande.' : 'Je hebt geen toegang tot deze bestelling.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action . '_' . $order_id . '_' . $item_id );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$item  = $order ? $order->get_item( $item_id ) : null;
		$nt    = $item ? self::snapshot( $item ) : null;
		if ( ! $order || ! $item || (int) $item->get_order_id() !== $order_id || ! $nt ) {
			wp_die( esc_html( $fr ? 'Ligne de commande introuvable.' : 'Bestelregel niet gevonden.' ), '', array( 'response' => 404 ) );
		}
		return array( $order, $item, $nt );
	}

	/**
	 * Config used for production: the merged config at print time (labels/colours); metrics from the plugin.
	 */
	private static function cfg(): array {
		return Config::get();
	}

	/**
	 * Metrics map (font id → metrics).
	 */
	private static function metrics(): array {
		$m = Config::metrics();
		return isset( $m['fonts'] ) && is_array( $m['fonts'] ) ? $m['fonts'] : array();
	}

	/**
	 * admin-post: CSV download.
	 */
	public static function download_csv(): void {
		list( $order, $item, $nt ) = self::request( self::CSV_ACTION );
		$csv  = Production::csv( $nt, self::cfg(), self::metrics(), self::admin_lang() );
		$name = sanitize_file_name( 'naamstroken-' . $order->get_order_number() . '-' . $item->get_id() . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, cells neutralised by Production::csv_cell().
		exit;
	}

	/**
	 * Data for the print view.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param array                  $nt    Snapshot.
	 */
	public static function sheet_data( $order, $item, array $nt ): array {
		$cfg   = self::cfg();
		$lang  = self::admin_lang();
		$batch = Production::batch_from_data( $nt );
		$rows  = Production::rows( $batch, $cfg, self::metrics() );
		$bf    = Fit::find( $cfg, 'formats', (string) ( $cfg['blood']['separateFormat'] ?? '5x2.5' ) );
		$fmt   = Fit::resolve_format( $batch, $cfg );
		$pub   = array();
		foreach ( array( 'fabrics', 'letterColors', 'borders', 'fonts', 'fit' ) as $k ) {
			$pub[ $k ] = $cfg[ $k ] ?? array();
		}
		$pub['metrics'] = self::metrics();
		return array(
			'lang'   => $lang,
			'order'  => array(
				'number'   => (string) $order->get_order_number(),
				'date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y' ) : '',
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . ( $order->get_billing_company() ? ' · ' . $order->get_billing_company() : '' ) ),
				'item'     => (int) $item->get_id(),
			),
			'style'  => Production::style( $batch, $cfg, $lang ),
			'totals' => Production::totals( $batch ),
			'rows'   => $rows,
			'nt'     => $nt,
			'js'     => array(
				'cfg'         => $pub,
				'style'       => array(
					'fabric'      => $batch['fabric'],
					'letter'      => $batch['letter'],
					'border'      => $batch['border'],
					'borderColor' => $batch['borderColor'],
					'font'        => $batch['font'],
				),
				'format'      => $fmt ? array(
					'w'     => (float) $fmt['w'],
					'h'     => (float) $fmt['h'],
					'lines' => (int) $fmt['lines'],
				) : null,
				'bloodFormat' => $bf ? array(
					'w'     => (float) $bf['w'],
					'h'     => (float) $bf['h'],
					'lines' => (int) $bf['lines'],
				) : null,
				'rows'        => array_map(
					static fn( $r ) => array(
						'lines'     => $r['lines'],
						'capMm'     => $r['capMm'],
						'bloodTape' => $r['bloodTape'],
					),
					$rows
				),
				'fontsUrl'    => defined( 'BPS_URL' ) ? BPS_URL . 'assets/fonts/' : '',
			),
		);
	}

	/**
	 * Script tag for the renderer (bundle when built, else the ES-module sources).
	 */
	public static function script_tag(): string {
		if ( ! defined( 'BPS_URL' ) ) {
			return '';
		}
		$dist = Shortcode::uses_dist();
		$src  = BPS_URL . ( $dist ? Shortcode::DIST : Shortcode::SRC_ENTRY );
		$ver  = defined( 'BPS_VERSION' ) ? BPS_VERSION : '1';
		return '<script ' . ( $dist ? 'defer' : 'type="module"' ) . ' src="' . esc_url( add_query_arg( 'ver', $ver, $src ) ) . '"></script>';
	}

	/**
	 * admin-post: printable production list / proof (HTML, print to PDF from the browser).
	 */
	public static function print_sheet(): void {
		list( $order, $item, $nt ) = self::request( self::PRINT_ACTION );
		$d = self::sheet_data( $order, $item, $nt );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		include __DIR__ . '/views/print.php';
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------- */

	/**
	 * Schedule the daily clean-up (idempotent).
	 */
	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Unschedule.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Delete preview PNGs older than KEEP_DAYS that no order line references (§1.8). Returns the number removed.
	 */
	public static function cleanup(): int {
		global $wpdb;
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) ) {
			return 0;
		}
		$dir = trailingslashit( $up['basedir'] ) . Rest::PREVIEW_DIR;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$limit = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		$old   = array();
		foreach ( (array) glob( $dir . '/*.png' ) as $file ) {
			$base = basename( (string) $file );
			if ( preg_match( '/^[a-f0-9]{32}\.png$/', $base ) && (int) filemtime( (string) $file ) < $limit ) {
				$old[ $base ] = (string) $file;
			}
			if ( count( $old ) >= 500 ) {
				break; // Rest volgende dag.
			}
		}
		if ( ! $old ) {
			return 0;
		}
		$table        = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$placeholders = implode( ',', array_fill( 0, count( $old ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$used = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE meta_key = '_bps_nt_preview' AND meta_value IN ({$placeholders})", array_keys( $old ) ) );
		$used = array_flip( array_map( 'strval', $used ) );
		$n    = 0;
		foreach ( $old as $base => $file ) {
			if ( isset( $used[ $base ] ) ) {
				continue;
			}
			wp_delete_file( $file );
			if ( ! file_exists( $file ) ) {
				++$n;
			}
		}
		return $n;
	}
}
