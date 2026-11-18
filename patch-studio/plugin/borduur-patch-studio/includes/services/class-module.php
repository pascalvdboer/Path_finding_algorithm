<?php
/**
 * Bootstrap of the commerce module: design rounds, packs, credit coupons and services (SPEC-AI-DIENSTEN §2, §16).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded through Plugin::COMPONENTS (handoff: docs/handoff/ai-services-needs.md). Registers itself with hooks only;
 * no existing file changes. Works without the AI module (services, packs bought in advance) and without WooCommerce
 * (REST catalogue only).
 */
final class Module {

	const VERSION     = '1.0.0';
	const VERSION_OPT = 'bps_svc_db_version';
	const CRON        = 'bps_svc_daily';

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		Rest::register();
		Admin_Sections::register();
		add_filter( 'bps_public_config', array( __CLASS__, 'public_config' ), 20, 2 );
		add_filter( 'bps_ai_credits', array( __CLASS__, 'credits_backend' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'bps_activate', array( __CLASS__, 'install' ) );
		add_action( 'bps_deactivate', array( __CLASS__, 'deactivate' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );

		if ( self::woo() ) {
			Products::register();
			Services::register();
			Order_Credit::register();
			Checkout::register();
			Fulfilment::register();
			Service_Status::register();
			Withdrawal::register();
			add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compat' ) );
		}
	}

	/**
	 * WooCommerce active?
	 */
	public static function woo(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Hand our Credits to AI\Credits_Gateway (it also finds Credits::instance() by itself; this keeps tests explicit).
	 *
	 * @param mixed $backend Current backend.
	 */
	public static function credits_backend( $backend ) {
		return is_object( $backend ) ? $backend : Credits::instance();
	}

	/**
	 * HPOS + cart/checkout blocks compatible (we only use the order/item CRUD API and Store API hooks).
	 */
	public static function declare_compat(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && defined( 'BPS_FILE' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BPS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BPS_FILE, true );
		}
	}

	/* ---------------------------------------------------------------------
	 * Install / cron / uninstall
	 * ------------------------------------------------------------------- */

	/**
	 * Install when the stored version differs (cheap option read on admin_init).
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPT ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Tables (lots + ledger) through AI\Schema when present, else our own identical dbDelta.
	 */
	public static function install(): void {
		if ( class_exists( '\BorduurPatchStudio\AI\Schema' ) && method_exists( '\BorduurPatchStudio\AI\Schema', 'install' ) ) {
			\BorduurPatchStudio\AI\Schema::install();
		} else {
			Credits_Store_Wpdb::install();
		}
		update_option( self::VERSION_OPT, self::VERSION, false );
		delete_option( Withdrawal::REWRITE_OPT ); // Re-flush the account endpoint once.
		self::schedule();
	}

	/**
	 * Daily cron event.
	 */
	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Daily: expire lots, start waiting services. Both idempotent.
	 */
	public static function daily(): void {
		Credits::instance()->expire();
		if ( self::woo() ) {
			Service_Status::release_waiting();
		}
	}

	/**
	 * Deactivation: stop the cron (data stays).
	 */
	public static function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Uninstall (called from uninstall.php when "delete data" is on, handoff): our options; tables only when the AI
	 * module does not drop them itself. Woo products and coupons stay (orders refer to them).
	 */
	public static function uninstall(): void {
		self::deactivate();
		foreach ( array( self::VERSION_OPT, Products::OPTION, Withdrawal::REWRITE_OPT, 'bps_svc_products_lock' ) as $opt ) {
			delete_option( $opt );
		}
		if ( ! class_exists( '\BorduurPatchStudio\AI\Schema' ) ) {
			Credits_Store_Wpdb::uninstall();
		}
	}

	/* ---------------------------------------------------------------------
	 * Public config + privacy
	 * ------------------------------------------------------------------- */

	/**
	 * config.services for the studio (catalogue, offer rules, REST paths). No prices beyond what the offer shows.
	 *
	 * @param array  $config Public config.
	 * @param string $locale Locale.
	 */
	public static function public_config( $config, $locale = '' ) {
		$config = is_array( $config ) ? $config : array();
		$lang   = Text::lang( is_string( $locale ) && '' !== $locale ? $locale : null );
		$cat    = Services::catalog( $lang );
		if ( $cat ) {
			$config['services'] = array(
				'catalog'    => $cat,
				'offerRules' => Config::offer_rules(),
				'routes'     => array(
					'cart'    => 'services/cart',
					'dismiss' => 'services/dismiss',
				),
			);
		}
		return $config;
	}

	/**
	 * Personal data exporter for rounds (only when AI\Privacy does not export them already through the gateway).
	 *
	 * @param array $exporters Exporters.
	 */
	public static function exporters( $exporters ) {
		$exporters = is_array( $exporters ) ? $exporters : array();
		if ( class_exists( '\BorduurPatchStudio\AI\Privacy' ) ) {
			return $exporters;
		}
		$exporters['bps-design-rounds'] = array(
			'exporter_friendly_name' => __( 'Patch Studio: ontwerprondes', 'borduur-patch-studio' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Export rounds for an e-mail address (account and e-mail owner keys).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function export( $email, $page = 1 ): array {
		unset( $page );
		$keys = array( Owner::for_email( (string) $email ) );
		$user = get_user_by( 'email', (string) $email );
		if ( $user ) {
			$keys[] = Owner::for_user( (int) $user->ID );
		}
		$data = array();
		foreach ( $keys as $k ) {
			foreach ( Credits::instance()->export_owner( $k ) as $i => $row ) {
				$data[] = array(
					'group_id'    => 'bps-design-rounds',
					'group_label' => __( 'Ontwerprondes', 'borduur-patch-studio' ),
					'item_id'     => 'lot-' . md5( $k ) . '-' . $i,
					'data'        => $row,
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}
}
