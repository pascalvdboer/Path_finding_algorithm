<?php
/**
 * [patch_studio_naamstroken] shortcode: root markup, public config and assets (SPEC-EXTRA §1.10).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

/**
 * `[patch_studio_naamstroken formaat="" stof="" compact="no"]`.
 * Output is cache-safe (no nonce, no personal data); the edit key is read client-side from ?bps_nt_edit.
 */
final class Shortcode {

	const TAG       = 'patch_studio_naamstroken';
	const SCRIPT    = 'bps-nametape';
	const STYLE     = 'bps-nametape-style';
	const DIST      = 'assets/dist/patch-studio-nametape.js';
	const SRC_ENTRY = 'assets/src/nametape/index.js';
	const CSS       = 'assets/src/nametape/nametape.css';

	/** @var int */
	private static $instance = 0;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 6 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ), 6 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'early_enqueue' ), 20 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
		add_filter( 'rocket_rucss_external_exclusions', array( __CLASS__, 'rocket_css' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_js' ) );
	}

	/**
	 * Use the built bundle when present, else the ES-module sources (works before the build entry exists).
	 */
	public static function uses_dist(): bool {
		return defined( 'BPS_DIR' ) && is_readable( BPS_DIR . self::DIST );
	}

	/**
	 * Version for cache busting.
	 *
	 * @param string $rel Relative path.
	 */
	private static function ver( string $rel ): string {
		$file = defined( 'BPS_DIR' ) ? BPS_DIR . $rel : '';
		$time = $file && is_readable( $file ) ? (int) filemtime( $file ) : 0;
		$v    = defined( 'BPS_VERSION' ) ? BPS_VERSION : '1';
		return $time ? $v . '-' . $time : $v;
	}

	/**
	 * Register handles.
	 */
	public static function register_assets(): void {
		if ( ! defined( 'BPS_URL' ) ) {
			return;
		}
		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			$rel = self::uses_dist() ? self::DIST : self::SRC_ENTRY;
			wp_register_script(
				self::SCRIPT,
				BPS_URL . $rel,
				array(),
				self::ver( $rel ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, BPS_URL . self::CSS, array(), self::ver( self::CSS ) );
		}
	}

	/**
	 * The source entry must load as a module; never let Rocket Loader/Jetpack Boost touch it.
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 */
	public static function script_tag( $tag, $handle ) {
		if ( self::SCRIPT !== $handle || ! is_string( $tag ) ) {
			return $tag;
		}
		if ( ! self::uses_dist() && false === strpos( $tag, 'type="module"' ) ) {
			$tag = preg_replace( '/<script(?![^>]*\btype=)/', '<script type="module"', $tag, 1 );
			$tag = str_replace( array( " type='text/javascript'", ' type="text/javascript"' ), ' type="module"', (string) $tag );
		}
		if ( false === strpos( (string) $tag, 'data-cfasync' ) ) {
			$tag = str_replace( '<script ', '<script data-cfasync="false" data-jetpack-boost="ignore" ', (string) $tag );
		}
		return $tag;
	}

	/**
	 * Enqueue in the head when the current post contains the shortcode (no flash of unstyled content).
	 */
	public static function early_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'formaat' => '',
				'stof'    => '',
				'compact' => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		if ( ! Config::enabled() ) {
			return '';
		}
		self::remember_page();
		return self::render( $atts );
	}

	/**
	 * Remember the page that holds the shortcode (for links from the studio and the cart).
	 */
	private static function remember_page(): void {
		if ( ! is_singular() || is_admin() ) {
			return;
		}
		$id = (int) get_the_ID();
		if ( $id > 0 && 'publish' === get_post_status( $id ) && (int) get_option( Config::PAGE_OPTION, 0 ) !== $id && ! (int) Config::path( 'woo.pageId', 0 ) ) {
			update_option( Config::PAGE_OPTION, $id, false );
		}
	}

	/**
	 * Markup.
	 *
	 * @param array $atts Attributes.
	 */
	public static function render( array $atts ): string {
		++self::$instance;
		$locale = Config::public_locale();
		$page   = array();
		$fmt    = sanitize_text_field( (string) $atts['formaat'] );
		if ( '' !== $fmt && Fit::find( Config::get(), 'formats', $fmt ) ) {
			$page['format'] = $fmt;
		}
		$fab = sanitize_key( (string) $atts['stof'] );
		if ( '' !== $fab && Fit::find( Config::get(), 'fabrics', $fab ) ) {
			$page['fabric'] = $fab;
		}
		$page['compact'] = 'yes' === $atts['compact'];
		$cfg             = Config::public_config( $locale, $page );

		self::register_assets();
		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );

		$json = wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		$lang = 'fr-BE' === $locale ? 'fr' : 'nl';
		$fr   = 'fr' === $lang;
		ob_start();
		?>
<div class="bps-nametape<?php echo $page['compact'] ? ' bps-nametape--compact' : ''; ?>" data-bps-nametape-root id="bps-nametape-<?php echo (int) self::$instance; ?>" lang="<?php echo esc_attr( $lang ); ?>">
	<script type="application/json" class="bps-nt-config"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX_TAG/AMP. ?></script>
	<div class="bps-nt-skeleton">
		<h2><?php echo esc_html( $fr ? 'Bandes nominatives avec scratch' : 'Naamstroken met klittenband' ); ?></h2>
		<p><?php echo esc_html( $fr ? 'Tapez un nom ou collez votre liste. Vous voyez tout de suite chaque bande.' : 'Typ een naam of plak je lijst. Je ziet meteen hoe elke strook eruitziet.' ); ?></p>
		<p><label><?php echo esc_html( $fr ? 'Nom sur votre bande' : 'Naam op je strook' ); ?><br><input type="text" disabled placeholder="PEETERS J."></label></p>
		<noscript><p><?php echo esc_html( $fr ? 'Cet outil a besoin de JavaScript. Appelez-nous ou écrivez-nous sur WhatsApp : nous vous aidons volontiers.' : 'Deze tool heeft JavaScript nodig. Bel of WhatsApp ons gerust: we helpen je graag verder.' ); ?></p></noscript>
	</div>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Keep our plain CSS out of WP Rocket "Remove Unused CSS" (UI is built client-side).
	 *
	 * @param mixed $ex Exclusions.
	 */
	public static function rocket_css( $ex ): array {
		$ex   = is_array( $ex ) ? $ex : array();
		$ex[] = '/borduur-patch-studio/assets/src/nametape/';
		return array_values( array_unique( $ex ) );
	}

	/**
	 * Never delay our script.
	 *
	 * @param mixed $ex Exclusions.
	 */
	public static function rocket_js( $ex ): array {
		$ex   = is_array( $ex ) ? $ex : array();
		$ex[] = '/borduur-patch-studio/assets/';
		$ex[] = self::SCRIPT;
		return array_values( array_unique( $ex ) );
	}
}
