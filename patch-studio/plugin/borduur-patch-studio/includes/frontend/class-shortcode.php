<?php
/**
 * [patch_studio] shortcode and the shared root markup (also used by the Elementor widget).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Frontend;

use BorduurPatchStudio\Plugin;
use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Product;

defined( 'ABSPATH' ) || exit;

/**
 * `[patch_studio techniek="" maat="" aantal="" vorm="auto" compact="no"]` (SPEC §4.4).
 *
 * Output: root div with the public config as JSON (no nonce, no personal data → cache-safe) and a
 * server-rendered skeleton (heading, real file input, "Start met tekst", noscript) so nothing jumps
 * and the upload works before the script has loaded.
 */
final class Shortcode {

	const TAG = 'patch_studio';

	/** @var int Instance counter (unique ids when the tool is placed twice). */
	private static $instance = 0;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'shortcode' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'early_enqueue' ), 20 );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'techniek' => '',
				'maat'     => '',
				'aantal'   => '',
				'vorm'     => 'auto',
				'compact'  => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		return self::render( $atts );
	}

	/**
	 * Register the Elementor widget (only fires when Elementor is active).
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public static function register_widget( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}
		require_once __DIR__ . '/class-elementor-widget.php';
		if ( class_exists( Elementor_Widget::class ) ) {
			$widgets_manager->register( new Elementor_Widget() );
		}
	}

	/**
	 * Enqueue the stylesheet in <head> when the current page contains the shortcode (avoids a flash).
	 */
	public static function early_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue app script + style (registered by core; registered here as a fallback).
	 */
	public static function enqueue(): void {
		if ( ! wp_script_is( 'bps-app', 'registered' ) && class_exists( Plugin::class ) && method_exists( Plugin::class, 'register_assets' ) ) {
			Plugin::register_assets();
		}
		wp_enqueue_script( 'bps-app' );
		wp_enqueue_style( 'bps-style' );
	}

	/**
	 * Normalise attributes into the `page` config block.
	 *
	 * @param array $atts Raw attributes (shortcode or widget).
	 */
	public static function page_config( array $atts ): array {
		$tech = sanitize_key( (string) ( $atts['techniek'] ?? '' ) );
		if ( ! in_array( $tech, Config::enabled_techniques(), true ) ) {
			$tech = '';
		}
		$size = str_replace( ',', '.', trim( (string) ( $atts['maat'] ?? '' ) ) );
		$size = is_numeric( $size ) ? round( (float) $size * 2 ) / 2 : null;
		if ( null !== $size ) {
			$min  = (float) Config::path( 'sizes.minCm', 4 );
			$max  = (float) Config::path( 'sizes.maxQuoteCm', 30 );
			$size = ( $size >= $min && $size <= $max ) ? (float) $size : null;
		}
		$qty = trim( (string) ( $atts['aantal'] ?? '' ) );
		$qty = ctype_digit( $qty ) && (int) $qty >= 1 ? min( (int) $qty, (int) Config::path( 'quantity.max', 10000 ) ) : null;

		$shape  = sanitize_key( (string) ( $atts['vorm'] ?? 'auto' ) );
		$shapes = array_keys( (array) Config::path( 'shapes', array() ) );
		if ( ! in_array( $shape, $shapes, true ) ) {
			$shape = 'auto';
		}
		$compact = in_array( strtolower( (string) ( $atts['compact'] ?? 'no' ) ), array( 'yes', 'ja', 'oui', 'true', '1', 'on' ), true );

		return array(
			'technique' => $tech,
			'sizeCm'    => $size,
			'qty'       => $qty,
			'shape'     => $shape,
			'compact'   => $compact,
		);
	}

	/**
	 * Public config for the browser.
	 *
	 * @param array $page Page block.
	 */
	public static function public_config( array $page ): array {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'public_config' ) ) {
			return (array) Settings::public_config( null, $page );
		}
		// Fallback (core Settings missing): same rules as shared/config.js buildPublicConfig().
		$c = Config::get();
		foreach ( array( 'woo', 'retention', 'security', 'admin' ) as $k ) {
			unset( $c[ $k ] );
		}
		foreach ( (array) ( $c['techniques'] ?? array() ) as $id => $t ) {
			unset( $c['techniques'][ $id ]['legacy'] );
		}
		$off          = (array) ( $c['threadsDisabled'] ?? array() );
		$c['threads'] = array_values(
			array_filter(
				(array) ( $c['threads'] ?? array() ),
				static fn( $t ) => ! in_array( $t['code'] ?? '', $off, true ) && ( $t['stock'] ?? true ) !== false
			)
		);
		$c['locale']  = Config::public_locale();
		$c['rest']    = array(
			'root'     => esc_url_raw( rest_url( 'patch-studio/v1/' ) ),
			'storeApi' => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'cartUrl'  => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
		);
		$c['assets']  = array(
			'workerUrl' => BPS_URL . 'assets/dist/patch-studio-worker.js',
			'fontsUrl'  => BPS_URL . 'assets/fonts/',
			'version'   => defined( 'BPS_VERSION' ) ? BPS_VERSION : '2.0.0',
		);
		$c['strings'] = array();
		$c['page']    = $page;
		return (array) apply_filters( 'bps_public_config', $c, $c['locale'] );
	}

	/**
	 * Render the tool. Shared by the shortcode and the Elementor widget. Returns HTML (never echoes).
	 *
	 * @param array $atts Attributes (techniek, maat, aantal, vorm, compact).
	 */
	public static function render( array $atts ): string {
		self::enqueue();
		self::remember_studio_page();
		++self::$instance;

		$page   = self::page_config( $atts );
		$config = self::public_config( $page );
		$locale = isset( $config['locale'] ) && is_string( $config['locale'] ) ? $config['locale'] : Config::public_locale();
		$json   = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}

		$uid       = 'bps-' . self::$instance;
		$accept    = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.ai,.eps,.psd';
		$max_mb    = Config::num( (float) Config::path( 'woo.maxUploadMb', 25 ), 0 );
		$privacy   = (string) Config::path( 'contact.privacyUrl', '' );
		$quote_url = (string) Config::path( 'contact.quoteUrl', '' );
		$classes   = 'bps-studio' . ( $page['compact'] ? ' bps-studio--compact' : '' );

		ob_start();
		?>
<div class="<?php echo esc_attr( $classes ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-bps-root data-bps-version="<?php echo esc_attr( defined( 'BPS_VERSION' ) ? BPS_VERSION : '2.0.0' ); ?>" lang="<?php echo esc_attr( $locale ); ?>">
	<script type="application/json" class="bps-config"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with JSON_HEX_TAG|JSON_HEX_AMP cannot break out of the script element. ?></script>
	<div class="bps-skeleton" data-bps-skeleton>
		<h2 class="bps-skeleton__title"><?php esc_html_e( 'Ontwerp je patch', 'borduur-patch-studio' ); ?></h2>
		<p class="bps-skeleton__subtitle"><?php esc_html_e( 'Upload je logo, tekening of zelfs een foto van een bestaande patch. Wij kijken meteen of het te borduren is.', 'borduur-patch-studio' ); ?></p>
		<label class="bps-skeleton__dropzone" for="<?php echo esc_attr( $uid ); ?>-file">
			<span class="bps-skeleton__drop-main"><strong><?php esc_html_e( 'Sleep je bestand hierheen', 'borduur-patch-studio' ); ?></strong> <?php esc_html_e( 'of', 'borduur-patch-studio' ); ?> <em><?php esc_html_e( 'kies een bestand', 'borduur-patch-studio' ); ?></em></span>
			<span class="bps-skeleton__drop-hint">
				<?php
				/* translators: %s: maximum file size in MB */
				echo esc_html( sprintf( __( 'PNG, JPG, SVG of PDF · max. %s MB', 'borduur-patch-studio' ), $max_mb ) );
				?>
			</span>
			<input class="bps-skeleton__input" id="<?php echo esc_attr( $uid ); ?>-file" type="file" accept="<?php echo esc_attr( $accept ); ?>" data-bps-skeleton-input>
		</label>
		<p class="bps-skeleton__reassure"><?php esc_html_e( 'Je hoeft geen perfect bestand te hebben. Een schets of foto is genoeg om te beginnen.', 'borduur-patch-studio' ); ?></p>
		<p class="bps-skeleton__alt"><button type="button" class="bps-skeleton__text-only" data-bps-action="text-only"><?php esc_html_e( 'Nog geen ontwerp? Start met tekst', 'borduur-patch-studio' ); ?></button></p>
		<p class="bps-skeleton__privacy">
			<?php esc_html_e( 'Je bestand wordt eerst alleen op je eigen toestel geanalyseerd. Pas als je bestelt, een offerte vraagt of je ontwerp bewaart, sturen we het naar ons om je patch te maken. We bewaren het niet langer dan nodig.', 'borduur-patch-studio' ); ?>
			<?php if ( '' !== $privacy ) : ?>
				<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Privacybeleid', 'borduur-patch-studio' ); ?></a>
			<?php endif; ?>
		</p>
		<noscript>
			<p class="bps-skeleton__noscript">
				<?php esc_html_e( 'Je browser ondersteunt de ontwerptool niet. Vraag je patch eenvoudig aan via ons offerteformulier.', 'borduur-patch-studio' ); ?>
				<?php if ( '' !== $quote_url ) : ?>
					<a href="<?php echo esc_url( $quote_url ); ?>"><?php esc_html_e( 'Naar het offerteformulier', 'borduur-patch-studio' ); ?></a>
				<?php endif; ?>
			</p>
		</noscript>
	</div>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Remember which published page hosts the studio (links from the cart, e-mails and the hidden product).
	 */
	private static function remember_studio_page(): void {
		if ( is_admin() || wp_doing_ajax() || ! is_singular() || is_preview() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return;
		}
		$product = Config::product_id();
		if ( $id === $product ) {
			return;
		}
		if ( (int) get_option( Product::STUDIO_OPTION, 0 ) !== $id ) {
			update_option( Product::STUDIO_OPTION, $id, true );
		}
	}
}
