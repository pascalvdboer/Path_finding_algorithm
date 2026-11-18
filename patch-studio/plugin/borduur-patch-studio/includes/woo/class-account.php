<?php
/**
 * "Mijn ontwerpen" in WooCommerce My account (klant-account-2).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce account endpoint (key bps-designs, slug from account.slug per site language: mijn-ontwerpen /
 * mes-designs) with a server-rendered list of the customer's designs (template myaccount/designs.php, overridable
 * in the theme as woocommerce/myaccount/designs.php). The list is ONE WP_Query with the post meta cache primed,
 * paginated. Rename, delete and reorder work without JavaScript (nonce-protected forms and links); the same actions
 * exist as REST routes for the studio (Rest\Account_Routes).
 *
 * account.enabled = false: no endpoint (the URL gives a 404), no menu item, the REST account routes answer 404.
 */
final class Account {

	/** Endpoint key (hooks, query var). The URL slug is localised (slug()). */
	const ENDPOINT = 'bps-designs';

	/** Last slug the rewrite rules were flushed for ('' = endpoint off). */
	const REWRITE_OPT = 'bps_account_rewrite';

	/** Cards per page. */
	const PER_PAGE = 12;

	/** Statuses shown in the list (drafts are intermediate saves of the studio, not shown). */
	const LIST_STATUSES = array( 'carted', 'saved', 'quoted', 'ordered' );

	/**
	 * Hooks (registered by Designs::register()).
	 */
	public static function register(): void {
		add_filter( 'bps_public_config', array( __CLASS__, 'public_config' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_actions' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * account.enabled (default on).
	 */
	public static function enabled(): bool {
		$v = Config::path( 'account.enabled', true );
		return ! ( false === $v || 0 === $v || '0' === $v || 'no' === $v );
	}

	/**
	 * URL slug: account.slug as string or {nl, fr} (site language), filter bps_account_slug.
	 */
	public static function slug(): string {
		$cfg  = Config::path( 'account.slug', array() );
		$lang = Config::lang();
		$def  = 'fr' === $lang ? 'mes-designs' : 'mijn-ontwerpen';
		if ( is_string( $cfg ) ) {
			$slug = $cfg;
		} elseif ( is_array( $cfg ) ) {
			$slug = (string) ( $cfg[ $lang ] ?? ( $cfg['nl'] ?? '' ) );
		} else {
			$slug = '';
		}
		$slug = sanitize_title( (string) apply_filters( 'bps_account_slug', '' !== trim( $slug ) ? $slug : $def, $lang ) );
		return '' !== $slug ? $slug : $def;
	}

	/**
	 * URL of "Mijn ontwerpen" ('' when off or WooCommerce is missing). Used by P07 in the saved-design mail.
	 */
	public static function url(): string {
		if ( ! self::enabled() || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return '';
		}
		return (string) wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce wiring
	 * ------------------------------------------------------------------- */

	/**
	 * Register the endpoint with WooCommerce (it adds the rewrite endpoint and the query var).
	 *
	 * @param mixed $vars Query vars key => slug.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[ self::ENDPOINT ] = self::slug();
		}
		return $vars;
	}

	/**
	 * Flush the rewrite rules once when the slug or account.enabled changed (never on every request).
	 */
	public static function maybe_flush(): void {
		$want = self::enabled() ? self::slug() : '';
		if ( (string) get_option( self::REWRITE_OPT, '__none__' ) === $want ) {
			return;
		}
		update_option( self::REWRITE_OPT, $want, false );
		flush_rewrite_rules( false );
	}

	/**
	 * Menu item after "Bestellingen".
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu_items( $items ) {
		if ( ! is_array( $items ) || ! self::enabled() ) {
			return $items;
		}
		$label = __( 'Mijn ontwerpen', 'borduur-patch-studio' );
		$out   = array();
		foreach ( $items as $key => $text ) {
			$out[ $key ] = $text;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = $label;
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$logout = isset( $out['customer-logout'] ) ? array( 'customer-logout' => $out['customer-logout'] ) : array();
			unset( $out['customer-logout'] );
			$out = $out + array( self::ENDPOINT => $label ) + $logout;
		}
		return $out;
	}

	/**
	 * Page title of the endpoint.
	 */
	public static function title(): string {
		return __( 'Mijn ontwerpen', 'borduur-patch-studio' );
	}

	/* ---------------------------------------------------------------------
	 * Listing
	 * ------------------------------------------------------------------- */

	/**
	 * One page of the customer's designs, newest first. Exactly one WP_Query (posts + found rows) and one meta query
	 * (update_post_meta_cache), so the list costs three database queries whatever its length.
	 *
	 * @param int $user_id  Customer.
	 * @param int $page     Page (1-based).
	 * @param int $per_page Cards per page.
	 * @return array{items: array<int, array>, total: int, pages: int, page: int}
	 */
	public static function designs_for( int $user_id, int $page = 1, int $per_page = self::PER_PAGE ): array {
		$empty = array(
			'items' => array(),
			'total' => 0,
			'pages' => 0,
			'page'  => 1,
		);
		if ( $user_id <= 0 ) {
			return $empty;
		}
		$page = max( 1, $page );
		// One SELECT posts.* instead of WordPress' split "ids, then posts" (saves a query per page view).
		add_filter( 'split_the_query', '__return_false', 99 );
		$q = new \WP_Query(
			array(
				'post_type'              => Designs::CPT,
				'post_status'            => 'any',
				'author'                 => $user_id,
				'posts_per_page'         => max( 1, min( 100, $per_page ) ),
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => '_bps_status',
						'value'   => self::LIST_STATUSES,
						'compare' => 'IN',
					),
					array(
						'key'     => '_bps_hidden_gmt',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		remove_filter( 'split_the_query', '__return_false', 99 );
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = self::card( $post );
		}
		return array(
			'items' => $items,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'page'  => $page,
		);
	}

	/**
	 * Card data for one design (only reads the primed meta cache).
	 *
	 * @param \WP_Post $post Design post.
	 */
	public static function card( \WP_Post $post ): array {
		$id       = (int) $post->ID;
		$payload  = json_decode( (string) get_post_meta( $id, '_bps_payload', true ), true );
		$payload  = is_array( $payload ) ? $payload : array();
		$status   = (string) ( get_post_meta( $id, '_bps_status', true ) ?: 'draft' );
		$title    = (string) get_post_meta( $id, '_bps_title', true );
		$order_id = (int) get_post_meta( $id, '_bps_order_id', true );
		$locale   = Config::public_locale();
		$tech     = (string) ( $payload['technique'] ?? '' );
		$created  = (string) get_post_meta( $id, '_bps_created_gmt', true );
		$ordered  = 'ordered' === $status || $order_id > 0;
		return array(
			'id'          => $id,
			'ref'         => (string) $post->post_title,
			'title'       => $title,
			'label'       => '' !== $title ? $title : (string) $post->post_title,
			'status'      => $status,
			'statusLabel' => self::status_label( $status ),
			'technique'   => '' !== $tech ? Config::technique_label( $tech, $locale ) : '',
			'size'        => isset( $payload['sizeCm'] ) ? Config::size_text( (float) $payload['sizeCm'], (float) ( $payload['widthMm'] ?? 0 ), (float) ( $payload['heightMm'] ?? 0 ) ) : '',
			'qty'         => (int) ( $payload['qty'] ?? 0 ),
			'preview'     => Designs::preview_url( $id, 'cart' ),
			'preview2x'   => Designs::preview_url( $id, 'cart2x' ),
			'date'        => '' !== $created ? (string) get_date_from_gmt( $created, get_option( 'date_format' ) ) : '',
			'orderId'     => $order_id > 0 ? $order_id : null,
			'orderUrl'    => $order_id > 0 && function_exists( 'wc_get_endpoint_url' ) ? (string) wc_get_endpoint_url( 'view-order', (string) $order_id, wc_get_page_permalink( 'myaccount' ) ) : '',
			'restoreUrl'  => Designs::restore_url( $id ),
			'reorderUrl'  => $ordered ? self::reorder_url( $id ) : '',
			'canDelete'   => true,
		);
	}

	/**
	 * Customer-facing status label.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'carted':
				return __( 'In je winkelmand', 'borduur-patch-studio' );
			case 'saved':
				return __( 'Bewaard', 'borduur-patch-studio' );
			case 'quoted':
				return __( 'Offerte aangevraagd', 'borduur-patch-studio' );
			case 'ordered':
				return __( 'Besteld', 'borduur-patch-studio' );
			default:
				return __( 'Concept', 'borduur-patch-studio' );
		}
	}

	/**
	 * One-click reorder link (GET with nonce, like WooCommerce's own "Order again").
	 *
	 * @param int $id Design id.
	 */
	public static function reorder_url( int $id ): string {
		return wp_nonce_url( add_query_arg( 'bps_reorder', $id, self::url() ), 'bps_reorder_' . $id );
	}

	/**
	 * Render the endpoint.
	 *
	 * @param mixed $value Endpoint value (page number "pagina/2" is not used; ?bps_page= is).
	 */
	public static function render( $value = '' ): void {
		unset( $value );
		if ( ! self::enabled() ) {
			return;
		}
		$uid  = get_current_user_id();
		$page = isset( $_GET['bps_page'] ) ? absint( wp_unslash( $_GET['bps_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$list = self::designs_for( $uid, max( 1, $page ) );
		wc_get_template(
			'myaccount/designs.php',
			array(
				'designs'    => $list['items'],
				'total'      => $list['total'],
				'pages'      => $list['pages'],
				'page'       => $list['page'],
				'base_url'   => self::url(),
				'studio_url' => Product::studio_url(),
			),
			'',
			BPS_DIR . 'templates/'
		);
	}

	/* ---------------------------------------------------------------------
	 * No-JS actions: rename, delete (POST forms) and reorder (GET link), all with a nonce
	 * ------------------------------------------------------------------- */

	/**
	 * Handle the forms and the reorder link on the account page.
	 */
	public static function handle_actions(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- every branch verifies its own nonce below.
		if ( isset( $_GET['bps_reorder'] ) ) {
			$id = absint( wp_unslash( $_GET['bps_reorder'] ) );
			check_admin_referer( 'bps_reorder_' . $id );
			self::reorder_and_redirect( $id );
			return;
		}
		if ( empty( $_POST['bps_account_action'] ) || empty( $_POST['bps_design'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['bps_account_action'] ) );
		$id     = absint( wp_unslash( $_POST['bps_design'] ) );
		// phpcs:enable WordPress.Security.NonceVerification
		check_admin_referer( 'bps_account_design_' . $id, 'bps_nonce' );
		if ( Designs::owner( $id ) !== get_current_user_id() ) {
			wc_add_notice( __( 'We vonden dit ontwerp niet terug.', 'borduur-patch-studio' ), 'error' );
		} elseif ( 'rename' === $action ) {
			$title = isset( $_POST['bps_title'] ) ? sanitize_text_field( wp_unslash( $_POST['bps_title'] ) ) : '';
			Designs::set_title( $id, $title );
			wc_add_notice( __( 'De naam van je ontwerp is aangepast.', 'borduur-patch-studio' ) );
		} elseif ( 'delete' === $action ) {
			Designs::remove_for_customer( $id );
			wc_add_notice( __( 'Het ontwerp staat niet meer in je lijst.', 'borduur-patch-studio' ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Reorder one of the customer's ordered designs and go to the cart.
	 *
	 * @param int $id Design id.
	 */
	private static function reorder_and_redirect( int $id ): void {
		$uid = get_current_user_id();
		$ok  = Designs::owner( $id ) === $uid || self::is_order_customer( $id, $uid );
		$res = $ok ? self::reorder( $id, 0, array( 'accepted' => true ) ) : new \WP_Error( 'bps_not_found', __( 'We vonden dit ontwerp niet terug.', 'borduur-patch-studio' ) );
		if ( is_wp_error( $res ) ) {
			wc_add_notice( $res->get_error_message(), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}
		wc_add_notice( __( 'Je herbestelling ligt in je winkelmand. Controleer het aantal en reken af wanneer het je past.', 'borduur-patch-studio' ) );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Reorder through WooCommerce's cart (contract C5: Order_Again::reorder_design clones the design via C3 and
	 * adds it with reorder_of). The caller checked ownership or the token.
	 *
	 * @param int   $id     Design id.
	 * @param int   $qty    Quantity (0 = as before).
	 * @param array $rights Rights {v, accepted}.
	 * @return array|\WP_Error
	 */
	public static function reorder( int $id, int $qty, array $rights ) {
		if ( ! function_exists( 'wc_load_cart' ) ) {
			return new \WP_Error( 'bps_unavailable', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), array( 'status' => 503 ) );
		}
		wc_load_cart();
		if ( ! WC()->cart ) {
			return new \WP_Error( 'bps_unavailable', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), array( 'status' => 503 ) );
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		try {
			return Order_Again::reorder_design( $id, $qty, $rights );
		} catch ( \Throwable $e ) {
			Logger::error(
				'account_reorder_failed',
				array(
					'design' => $id,
					'error'  => $e->getMessage(),
				)
			);
			return new \WP_Error( 'bps_store', __( 'We konden je ontwerp niet opnieuw klaarzetten. Probeer het nog eens of contacteer ons.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Is the user the customer of the order that ordered this design?
	 *
	 * @param int $id      Design id.
	 * @param int $user_id User.
	 */
	public static function is_order_customer( int $id, int $user_id ): bool {
		$order_id = (int) get_post_meta( $id, '_bps_order_id', true );
		if ( $user_id <= 0 || $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id;
	}

	/* ---------------------------------------------------------------------
	 * Studio config (bps_public_config)
	 * ------------------------------------------------------------------- */

	/**
	 * Tell the studio whether the visitor is logged in and how to reach the account routes. The X-WP-Nonce is only
	 * added for logged-in visitors (their pages are not cached); guests get no nonce.
	 *
	 * @param mixed  $config Public config.
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public static function public_config( $config, $locale = '' ) {
		unset( $locale );
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$on        = self::enabled();
		$logged_in = $on && is_user_logged_in();
		$account   = array(
			'enabled'  => $on,
			'loggedIn' => $logged_in,
			'url'      => $on ? self::url() : '',
		);
		if ( $logged_in ) {
			$account['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		$config['account'] = $account;
		// Contract §4: P08 adds these keys; set them here only when missing so the studio can rely on them.
		$config['features'] = isset( $config['features'] ) && is_array( $config['features'] ) ? $config['features'] : array();
		if ( ! array_key_exists( 'reminder', $config['features'] ) ) {
			$config['features']['reminder'] = Reminder::enabled();
		}
		if ( ! array_key_exists( 'account', $config['features'] ) ) {
			$config['features']['account'] = $on;
		}
		if ( ! isset( $config['reminder'] ) || ! is_array( $config['reminder'] ) ) {
			$config['reminder'] = array(
				'enabled'   => Reminder::enabled(),
				'delayDays' => Reminder::delay_days(),
			);
		}
		return $config;
	}
}
