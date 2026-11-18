<?php
/**
 * Name tapes: admin page "Patch Studio · Naamstroken" under WooCommerce (SPEC-EXTRA §1.12).
 * Stores only the difference with config/nametape.json in the option bps_nametape_settings.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page + status + quick start.
 */
final class Admin {

	const SLUG     = 'bps-nametape';
	const CAP      = 'manage_woocommerce';
	const SAVE     = 'bps_nt_save';
	const RESET    = 'bps_nt_reset';
	const MAKEPAGE = 'bps_nt_create_page';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::RESET, array( __CLASS__, 'reset' ) );
		add_action( 'admin_post_' . self::MAKEPAGE, array( __CLASS__, 'create_page' ) );
		add_filter( 'bps_status_checks', array( __CLASS__, 'status_checks' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Patch Studio · Naamstroken', 'borduur-patch-studio' ),
			__( 'Patch Studio · Naamstroken', 'borduur-patch-studio' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Page URL with an optional notice.
	 *
	 * @param string $msg Notice code.
	 */
	public static function page_url( string $msg = '' ): string {
		$args = array( 'page' => self::SLUG );
		if ( '' !== $msg ) {
			$args['bps_nt_msg'] = $msg;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Existing shipping classes (slug => name).
	 */
	public static function shipping_classes(): array {
		$out = array();
		if ( taxonomy_exists( 'product_shipping_class' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_shipping_class',
					'hide_empty' => false,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$out[ (string) $t->slug ] = (string) $t->name;
				}
			}
		}
		return $out;
	}

	/**
	 * Status rows: product, category, page, fee check.
	 *
	 * @return array<int,array{label:string,ok:bool,message:string,link:string}>
	 */
	public static function status(): array {
		$rows = array();
		$pid  = Product::id();
		$p    = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		$rows[] = array(
			'label'   => __( 'Product "Naamstroken op maat"', 'borduur-patch-studio' ),
			'ok'      => (bool) $p,
			'message' => $p
				/* translators: %d: product id */
				? sprintf( __( 'Bestaat (ID %d), verborgen in de winkel, prijs komt van de server.', 'borduur-patch-studio' ), $pid )
				: __( 'Nog niet aangemaakt. Het wordt automatisch aangemaakt bij het volgende paginabezoek.', 'borduur-patch-studio' ),
			'link'    => $p ? (string) get_edit_post_link( $pid, 'raw' ) : '',
		);
		$slug    = (string) Config::path( 'woo.categorySlug', 'naamstroken' );
		$foreign = array();
		if ( $p ) {
			foreach ( (array) $p->get_category_ids() as $cid ) {
				$term = get_term( (int) $cid, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) && $term->slug !== $slug ) {
					$foreign[] = $term->name;
				}
			}
		}
		$rows[] = array(
			'label'   => __( 'Productcategorie', 'borduur-patch-studio' ),
			'ok'      => ! $foreign,
			'message' => $foreign
				/* translators: %s: category names */
				? sprintf( __( 'Het product staat ook in: %s. Haal het daaruit, anders kan Conditional Extra Fees de borduurkaart van € 45 aanrekenen.', 'borduur-patch-studio' ), implode( ', ', $foreign ) )
				/* translators: %s: category slug */
				: sprintf( __( 'Enkel in "%s". Controleer in Conditional Extra Fees dat deze categorie niet onder "Borduurkaart en Instelkosten" valt.', 'borduur-patch-studio' ), $slug ),
			'link'    => '',
		);
		$url    = Config::page_url();
		$rows[] = array(
			'label'   => __( 'Naamstrookpagina', 'borduur-patch-studio' ),
			'ok'      => '' !== $url,
			'message' => '' !== $url ? $url : __( 'Nog geen pagina met [patch_studio_naamstroken]. Gebruik "Maak de naamstrookpagina aan" hieronder.', 'borduur-patch-studio' ),
			'link'    => $url,
		);
		$rows[] = array(
			'label'   => __( 'Module', 'borduur-patch-studio' ),
			'ok'      => Config::enabled(),
			'message' => Config::enabled() ? __( 'Aan: klanten kunnen naamstroken bestellen.', 'borduur-patch-studio' ) : __( 'Uit: de pagina toont niets en nieuwe lijsten worden geweigerd.', 'borduur-patch-studio' ),
			'link'    => '',
		);
		return $rows;
	}

	/**
	 * Feed the core status block when it offers the filter (handoff #4).
	 *
	 * @param mixed $checks Checks.
	 */
	public static function status_checks( $checks ): array {
		$checks = is_array( $checks ) ? $checks : array();
		foreach ( self::status() as $r ) {
			$checks[] = array(
				'label'   => 'Naamstroken · ' . $r['label'],
				'ok'      => $r['ok'],
				'message' => $r['message'],
				'detail'  => $r['message'], // Settings_Page::status_checks() / tab-algemeen.php lezen 'detail'.
				'link'    => '' !== $r['link'] ? $r['link'] : self::page_url(),
			);
		}
		return $checks;
	}

	/**
	 * Example quote for the "Test de winkelmand" block (no cart involved, nothing is stored).
	 *
	 * @param int $qty Number of tapes.
	 */
	public static function sample_quote( int $qty = 10 ): array {
		$cfg   = Config::get();
		$first = static function ( string $list ) use ( $cfg ): array {
			$pick = array();
			foreach ( (array) ( $cfg[ $list ] ?? array() ) as $x ) {
				$ok = is_array( $x ) && false !== ( $x['enabled'] ?? true ) && false !== ( $x['inStock'] ?? true );
				if ( $ok && ( ! $pick || ! empty( $x['default'] ) ) ) {
					$pick = $x;
				}
			}
			return $pick;
		};
		$fmt  = $first( 'formats' );
		$font = $first( 'fonts' );
		$back = $first( 'backings' );
		$fab  = $first( 'fabrics' );
		$bord = $first( 'borders' );
		$rows = array();
		for ( $i = 1; $i <= $qty; $i++ ) {
			$rows[] = array(
				'name'  => 'TEST ' . $i,
				'line2' => '',
				'blood' => null,
				'qty'   => 1,
			);
		}
		$batch = array(
			'v'           => 1,
			'locale'      => 'nl-BE',
			'format'      => (string) ( $fmt['id'] ?? '10x2.5' ),
			'custom'      => null,
			'fabric'      => (string) ( $fab['id'] ?? 'black' ),
			'letter'      => 'white',
			'border'      => (string) ( $bord['id'] ?? 'satin_letter' ),
			'borderColor' => null,
			'font'        => (string) ( $font['id'] ?? 'block' ),
			'uppercase'   => true,
			'backing'     => (string) ( $back['id'] ?? 'hook' ),
			'loop'        => false,
			'bloodMode'   => null,
			'rows'        => $rows,
		);
		$ctx = array(
			'patchPoolQty'        => 0,
			'otherNametapePieces' => 0,
			'otherItemsInclVat'   => 0,
			'vatRate'             => Config::vat_rate(),
			'tiers'               => Config::tiers(),
			'shipping'            => Config::shipping(),
			'locale'              => 'nl-BE',
		);
		return Pricing::quote( $batch, $ctx, $cfg );
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		$cfg      = Config::get();
		$status   = self::status();
		$classes  = self::shipping_classes();
		$sample   = self::sample_quote( 10 );
		$msg      = isset( $_GET['bps_nt_msg'] ) ? sanitize_key( wp_unslash( $_GET['bps_nt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$page_url = Config::page_url();
		include __DIR__ . '/views/admin-page.php';
	}

	/**
	 * Common guard for admin-post actions.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * After every change: flush caches, notify, sync the product.
	 */
	private static function after_change(): void {
		Config::flush();
		$cfg = Config::get();
		do_action( 'bps_nametape_settings_updated', $cfg );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( method_exists( Product::class, 'sync' ) && function_exists( 'wc_get_product' ) ) {
			Product::sync();
		}
	}

	/**
	 * admin-post: save.
	 */
	public static function save(): void {
		self::guard( self::SAVE );
		$post = isset( $_POST['bps_nt'] ) && is_array( $_POST['bps_nt'] ) ? wp_unslash( $_POST['bps_nt'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in Settings_Schema.
		$res  = Settings_Schema::sanitize( (array) $post, Config::get(), Config::defaults(), array_keys( self::shipping_classes() ) );
		$keep = Config::overrides();
		$diff = Settings_Schema::diff( $res['config'], Config::defaults() );
		// Values the form does not edit (pageId, …) stay as they were.
		if ( isset( $keep['woo']['pageId'] ) ) {
			$diff['woo']['pageId'] = (int) $keep['woo']['pageId'];
		}
		unset( $diff['_doc'] );
		update_option( Config::OPTION, $diff, false );
		self::after_change();
		wp_safe_redirect( self::page_url( $res['notices'] ? 'saved_fixed' : 'saved' ) );
		exit;
	}

	/**
	 * admin-post: reset to defaults (keeps the page id).
	 */
	public static function reset(): void {
		self::guard( self::RESET );
		$keep = Config::overrides();
		$new  = array();
		if ( ! empty( $keep['woo']['pageId'] ) ) {
			$new['woo']['pageId'] = (int) $keep['woo']['pageId'];
		}
		update_option( Config::OPTION, $new, false );
		self::after_change();
		wp_safe_redirect( self::page_url( 'reset' ) );
		exit;
	}

	/**
	 * admin-post: create the name-tape page (draft-free: published, with the shortcode).
	 */
	public static function create_page(): void {
		self::guard( self::MAKEPAGE );
		if ( '' !== Config::page_url() ) {
			wp_safe_redirect( self::page_url( 'page_exists' ) );
			exit;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Naamstroken met klittenband', 'borduur-patch-studio' ),
				'post_name'    => 'naamstroken',
				'post_content' => "<!-- wp:shortcode -->\n[" . Shortcode::TAG . "]\n<!-- /wp:shortcode -->",
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_safe_redirect( self::page_url( 'page_failed' ) );
			exit;
		}
		update_option( Config::PAGE_OPTION, (int) $id, false );
		$over                  = Config::overrides();
		$over['woo']['pageId'] = (int) $id;
		update_option( Config::OPTION, $over, false );
		self::after_change();
		wp_safe_redirect( self::page_url( 'page_created' ) );
		exit;
	}
}
