<?php
/**
 * Admin page WooCommerce > Patch Studio with five simple tabs.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Admin;

use BorduurPatchStudio\Plugin;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owner-facing settings (SPEC §8). Design rules (lesson from Fancy Product Designer):
 * nothing is required to start, every tab explains itself in plain words, numbers have units and limits,
 * no JSON editors, and every tab can be restored to the defaults.
 */
final class Settings_Page {

	const SLUG   = 'bps-settings';
	const ACTION = 'bps_save_settings';
	const NONCE  = 'bps_settings';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bps_create_page', array( __CLASS__, 'handle_create_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'bps_settings_updated', array( __CLASS__, 'sync_product_visibility' ), 20 );
	}

	/**
	 * Tabs: key => [label, reset paths].
	 *
	 * @return array<string, array{label:string, intro:string, reset:string[]}>
	 */
	public static function tabs(): array {
		return array(
			'technieken' => array(
				'label' => __( 'Technieken', 'borduur-patch-studio' ),
				'intro' => __( 'Kies welke soorten patches klanten kunnen kiezen en of ze die meteen online bestellen of eerst een offerte vragen. De technische grenzen gebruikt het advies om eerlijk te zeggen wat haalbaar is.', 'borduur-patch-studio' ),
				'reset' => array( 'techniques.*.enabled', 'techniques.*.mode', 'techniques.*.minQty', 'techniques.*.qtyStep', 'techniques.*.maxOrderCm', 'techniques.*.rules', 'specials' ),
			),
			'prijzen'    => array(
				'label' => __( 'Prijzen', 'borduur-patch-studio' ),
				'intro' => __( 'Alle bedragen vul je in zonder btw, net zoals in WooCommerce. De klant ziet altijd de prijs met btw. De tabel bovenaan toont meteen wat een klant betaalt.', 'borduur-patch-studio' ),
				'reset' => array( 'techniques.*.basePrice', 'techniques.*.tiers', 'tiers', 'fees', 'options', 'shipping', 'vat' ),
			),
			'kleuren'    => array(
				'label' => __( 'Kleuren', 'borduur-patch-studio' ),
				'intro' => __( 'Vink de garenkleuren uit die je niet op voorraad hebt: de tool kiest dan automatisch de dichtstbijzijnde kleur die je wel hebt. Stofkleuren zijn de achtergronden waarop geborduurd of gedrukt wordt.', 'borduur-patch-studio' ),
				'reset' => array( 'threadsDisabled', 'threadSpecial', 'fabrics', 'leathers' ),
			),
			'teksten'    => array(
				'label' => __( 'Teksten & beloftes', 'borduur-patch-studio' ),
				'intro' => __( 'Wat je klanten belooft, moet kloppen. Laat een veld leeg als je iets (nog) niet wilt beloven: de tool gebruikt dan een neutrale zin.', 'borduur-patch-studio' ),
				'reset' => array( 'promises', 'contact', 'techniques.*.leadTime' ),
			),
			'algemeen'   => array(
				'label' => __( 'Algemeen', 'borduur-patch-studio' ),
				'intro' => __( 'Hoe de tool samenwerkt met je winkel, welke onderdelen zichtbaar zijn en hoe lang ontwerpen bewaard blijven.', 'borduur-patch-studio' ),
				'reset' => array( 'woo', 'legal', 'features', 'retention', 'admin' ),
			),
		);
	}

	/**
	 * Add the submenu (WooCommerce > Patch Studio; Settings > Patch Studio without WooCommerce).
	 */
	public static function menu(): void {
		$parent = Plugin::woocommerce_active() ? 'woocommerce' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Patch Studio', 'borduur-patch-studio' ),
			__( 'Patch Studio', 'borduur-patch-studio' ),
			Plugin::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Whether the current admin screen is ours.
	 *
	 * @param string $hook Hook suffix.
	 */
	private static function is_our_screen( $hook ): bool {
		return is_string( $hook ) && false !== strpos( $hook, self::SLUG );
	}

	/**
	 * Enqueue admin CSS/JS only on our page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ): void {
		if ( ! self::is_our_screen( $hook ) ) {
			return;
		}
		$base = 'includes/admin/assets/';
		wp_enqueue_style( 'bps-settings', Plugin::asset_url( $base . 'settings.css' ), array(), Plugin::asset_version( $base . 'settings.css' ) );
		wp_enqueue_script(
			'bps-settings',
			Plugin::asset_url( $base . 'settings.js' ),
			array(),
			Plugin::asset_version( $base . 'settings.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Current tab from the request.
	 */
	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'technieken';
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ) );
		}
		$tab      = self::current_tab();
		$tabs     = self::tabs();
		$config   = Settings::get();
		$defaults = Settings::defaults();
		$locale   = 'nl';
		$vat      = (float) ( $config['vat']['rate'] ?? 0.21 );

		$i18n = array(
			/* translators: %s: amount including VAT */
			'incl'   => __( 'klant betaalt %s incl. btw', 'borduur-patch-studio' ),
			'copied' => __( 'Gekopieerd', 'borduur-patch-studio' ),
			/* translators: 1: active colors, 2: total colors */
			'count'  => __( '%1$d van %2$d beschikbaar', 'borduur-patch-studio' ),
		);
		echo '<div class="wrap bps-admin" data-bps-vat="' . esc_attr( (string) $vat ) . '" data-bps-i18n="' . esc_attr( (string) wp_json_encode( $i18n ) ) . '">';
		echo '<header class="bps-admin__head"><div><h1 class="bps-admin__title">' . esc_html__( 'Patch Studio', 'borduur-patch-studio' ) . '</h1>';
		echo '<p class="bps-admin__sub">' . esc_html__( 'Alles staat al goed ingesteld met de prijzen en beloftes van je webshop. Pas enkel aan wat voor jou anders moet.', 'borduur-patch-studio' ) . '</p></div>';
		$pages = self::studio_pages();
		if ( $pages ) {
			echo '<a class="button" href="' . esc_url( get_permalink( $pages[0] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Bekijk je ontwerppagina', 'borduur-patch-studio' ) . ' &#8599;</a>';
		}
		echo '</header>';

		// WordPress moves notices to just below the first h1; keep ours in our own region.
		echo '<hr class="wp-header-end">';
		self::render_notices();

		if ( ! $pages ) {
			self::render_quickstart();
		}

		echo '<nav class="nav-tab-wrapper bps-admin__tabs" aria-label="' . esc_attr__( 'Onderdelen van Patch Studio', 'borduur-patch-studio' ) . '">';
		foreach ( $tabs as $key => $info ) {
			$active = $key === $tab;
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( Plugin::settings_url( $key ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $info['label'] )
			);
		}
		echo '</nav>';

		echo '<p class="bps-admin__intro">' . esc_html( $tabs[ $tab ]['intro'] ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bps-form" data-bps-form novalidate>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( self::NONCE );

		$view = __DIR__ . '/views/tab-' . $tab . '.php';
		if ( is_readable( $view ) ) {
			include $view;
		}

		echo '<div class="bps-savebar">';
		echo '<button type="submit" class="button button-primary button-hero" name="bps_do" value="save">' . esc_html__( 'Wijzigingen bewaren', 'borduur-patch-studio' ) . '</button>';
		echo '<button type="submit" class="button-link bps-reset" name="bps_do" value="reset" data-bps-confirm="' . esc_attr__( 'Alle instellingen van dit tabblad terugzetten naar de standaardwaarden?', 'borduur-patch-studio' ) . '">' . esc_html__( 'Herstel standaard voor dit tabblad', 'borduur-patch-studio' ) . '</button>';
		echo '<span class="bps-savebar__dirty" data-bps-dirty hidden>' . esc_html__( 'Niet-bewaarde wijzigingen', 'borduur-patch-studio' ) . '</span>';
		echo '</div>';
		echo '</form></div>';
	}

	/**
	 * Notices stored by the last save (per user, one-shot).
	 */
	private static function render_notices(): void {
		$key     = 'bps_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $n ) {
			$type = in_array( $n['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible bps-notice"><p>' . wp_kses( (string) ( $n['message'] ?? '' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array() ) ) . '</p></div>';
		}
	}

	/**
	 * "Get started" card, shown until the tool is on a page.
	 */
	private static function render_quickstart(): void {
		echo '<section class="bps-quickstart" aria-labelledby="bps-qs-title">';
		echo '<h2 id="bps-qs-title">' . esc_html__( 'Snel aan de slag', 'borduur-patch-studio' ) . '</h2>';
		echo '<ol class="bps-quickstart__steps">';
		echo '<li><strong>' . esc_html__( 'Maak de ontwerppagina.', 'borduur-patch-studio' ) . '</strong> ';
		echo esc_html__( 'We maken de pagina "Ontwerp je patch" met de tool erop. Je kunt ze daarna gewoon in Elementor verder opmaken.', 'borduur-patch-studio' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bps-inline-form">';
		echo '<input type="hidden" name="action" value="bps_create_page">';
		wp_nonce_field( 'bps_create_page' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Maak de ontwerppagina aan', 'borduur-patch-studio' ) . '</button></form></li>';
		echo '<li><strong>' . esc_html__( 'Kijk je prijzen even na.', 'borduur-patch-studio' ) . '</strong> ';
		echo esc_html__( 'Ze komen uit je huidige webshop. ', 'borduur-patch-studio' );
		echo '<a href="' . esc_url( Plugin::settings_url( 'prijzen' ) ) . '">' . esc_html__( 'Naar Prijzen', 'borduur-patch-studio' ) . '</a></li>';
		echo '<li><strong>' . esc_html__( 'Link ernaar vanuit je menu of de knop "Ontwerp het zelf".', 'borduur-patch-studio' ) . '</strong> ';
		echo esc_html__( 'Liever een bestaande pagina? Zet er de shortcode op of sleep de Elementor-widget "Patch Studio" erin.', 'borduur-patch-studio' ) . ' ';
		self::shortcode_chip();
		echo '</li></ol></section>';
	}

	/**
	 * Copyable shortcode.
	 */
	public static function shortcode_chip(): void {
		echo '<span class="bps-copy"><code data-bps-copy-src>[patch_studio]</code> <button type="button" class="button button-small" data-bps-copy>' . esc_html__( 'Kopieer', 'borduur-patch-studio' ) . '</button></span>';
	}

	/* ---------------------------------------------------------------------
	 * Field helpers (used by the views)
	 * ------------------------------------------------------------------- */

	/**
	 * Build a field name from a path: ['techniques','pvc','minQty'] => bps[techniques][pvc][minQty].
	 *
	 * @param array $path Segments.
	 */
	public static function name( array $path ): string {
		$name = 'bps';
		foreach ( $path as $seg ) {
			$name .= '[' . $seg . ']';
		}
		return $name;
	}

	/**
	 * Stable HTML id from a path.
	 *
	 * @param array $path Segments.
	 */
	public static function id( array $path ): string {
		return 'bps-' . sanitize_html_class( implode( '-', $path ) );
	}

	/**
	 * Format a number for an input with a decimal comma.
	 *
	 * @param mixed $value    Number.
	 * @param int   $decimals Max decimals.
	 */
	public static function num( $value, int $decimals = 2 ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$s = number_format( (float) $value, $decimals, ',', '' );
		if ( false !== strpos( $s, ',' ) ) {
			$s = rtrim( rtrim( $s, '0' ), ',' );
			// Money keeps two decimals when it has any.
			if ( 2 === $decimals && false !== strpos( $s, ',' ) && strlen( substr( $s, strpos( $s, ',' ) + 1 ) ) === 1 ) {
				$s .= '0';
			}
		}
		return $s;
	}

	/**
	 * Money for display: "€ 5,98".
	 *
	 * @param float $amount Amount.
	 */
	public static function money( float $amount ): string {
		return '€ ' . number_format( $amount, 2, ',', '.' );
	}

	/**
	 * Toggle (checkbox with hidden 0).
	 *
	 * @param array  $path  Path.
	 * @param bool   $on    Checked.
	 * @param string $label Label.
	 * @param string $help  Optional help.
	 */
	public static function toggle( array $path, bool $on, string $label, string $help = '' ): void {
		$id = self::id( $path );
		echo '<div class="bps-toggle">';
		echo '<input type="hidden" name="' . esc_attr( self::name( $path ) ) . '" value="0">';
		echo '<input type="checkbox" class="bps-toggle__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="1"' . checked( $on, true, false ) . ( $help ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : '' ) . '>';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( $help ) {
			echo '<p class="bps-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Number input with unit.
	 *
	 * @param array  $path  Path.
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @param array  $args  unit, min, max, step, help, money (bool: show incl. VAT hint), placeholder, decimals, default.
	 */
	public static function number( array $path, $value, string $label, array $args = array() ): void {
		$id    = self::id( $path );
		$money = ! empty( $args['money'] );
		$dec   = $args['decimals'] ?? ( $money ? 2 : 2 );
		echo '<div class="bps-field' . ( $money ? ' bps-field--money' : '' ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<span class="bps-input">';
		if ( $money ) {
			echo '<span class="bps-input__pre" aria-hidden="true">€</span>';
		}
		printf(
			'<input type="text" inputmode="decimal" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s%7$s%8$s>',
			esc_attr( $id ),
			esc_attr( self::name( $path ) ),
			esc_attr( $money && is_numeric( $value ) ? number_format( (float) $value, 2, ',', '' ) : self::num( $value, (int) $dec ) ),
			isset( $args['min'] ) ? ' data-min="' . esc_attr( (string) $args['min'] ) . '"' : '',
			isset( $args['max'] ) ? ' data-max="' . esc_attr( (string) $args['max'] ) . '"' : '',
			isset( $args['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $args['placeholder'] ) . '"' : '',
			$money ? ' data-bps-money' : '',
			isset( $args['default'] ) && is_numeric( $args['default'] ) && ! Settings::same( (float) $args['default'], is_numeric( $value ) ? (float) $value : null ) ? ' data-bps-default="' . esc_attr( self::num( $args['default'], (int) $dec ) ) . '"' : ''
		);
		if ( ! empty( $args['unit'] ) ) {
			echo '<span class="bps-input__unit">' . esc_html( $args['unit'] ) . '</span>';
		}
		echo '</span>';
		if ( $money ) {
			echo '<span class="bps-incl" data-bps-incl aria-live="polite"></span>';
		}
		if ( isset( $args['default'] ) && is_numeric( $args['default'] ) && ! Settings::same( (float) $args['default'], is_numeric( $value ) ? (float) $value : null ) ) {
			echo '<span class="bps-default">' . esc_html(
				sprintf(
					/* translators: %s: default value */
					__( 'standaard: %s', 'borduur-patch-studio' ),
					( $money ? '€ ' : '' ) . self::num( $args['default'], (int) $dec ) . ( ! empty( $args['unit'] ) && ! $money ? ' ' . $args['unit'] : '' )
				)
			) . '</span>';
		}
		if ( ! empty( $args['help'] ) ) {
			echo '<p class="bps-help">' . esc_html( $args['help'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Text input.
	 *
	 * @param array  $path  Path.
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @param array  $args  type, help, placeholder, wide, textarea, lang.
	 */
	public static function text( array $path, $value, string $label, array $args = array() ): void {
		$id   = self::id( $path );
		$type = $args['type'] ?? 'text';
		echo '<div class="bps-field' . ( ! empty( $args['wide'] ) ? ' bps-field--wide' : '' ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$attrs = ( isset( $args['placeholder'] ) ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : '' ) . ( isset( $args['lang'] ) ? ' lang="' . esc_attr( $args['lang'] ) . '"' : '' );
		if ( ! empty( $args['textarea'] ) ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '" rows="3"' . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! empty( $args['help'] ) ) {
			echo '<p class="bps-help">' . esc_html( $args['help'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Pair of NL + FR text inputs for a {nl,fr} label.
	 *
	 * @param array  $path  Path to the label object.
	 * @param mixed  $value {nl,fr}.
	 * @param string $label Label.
	 * @param array  $args  help, textarea, placeholder_nl, placeholder_fr.
	 */
	public static function text_pair( array $path, $value, string $label, array $args = array() ): void {
		$value = is_array( $value ) ? $value : array( 'nl' => (string) $value, 'fr' => '' );
		echo '<fieldset class="bps-pair"><legend>' . esc_html( $label ) . '</legend>';
		self::text(
			array_merge( $path, array( 'nl' ) ),
			$value['nl'] ?? '',
			__( 'Nederlands', 'borduur-patch-studio' ),
			array(
				'textarea'    => ! empty( $args['textarea'] ),
				'lang'        => 'nl-BE',
				'placeholder' => $args['placeholder_nl'] ?? null,
			)
		);
		self::text(
			array_merge( $path, array( 'fr' ) ),
			$value['fr'] ?? '',
			__( 'Frans', 'borduur-patch-studio' ),
			array(
				'textarea'    => ! empty( $args['textarea'] ),
				'lang'        => 'fr-BE',
				'placeholder' => $args['placeholder_fr'] ?? null,
			)
		);
		if ( ! empty( $args['help'] ) ) {
			echo '<p class="bps-help">' . esc_html( $args['help'] ) . '</p>';
		}
		echo '</fieldset>';
	}

	/**
	 * Select.
	 *
	 * @param array  $path    Path.
	 * @param string $value   Current.
	 * @param string $label   Label.
	 * @param array  $choices value => label (label prefixed with "!" = disabled).
	 * @param string $help    Help.
	 */
	public static function select( array $path, string $value, string $label, array $choices, string $help = '' ): void {
		$id = self::id( $path );
		echo '<div class="bps-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '">';
		foreach ( $choices as $k => $text ) {
			$disabled = 0 === strpos( (string) $text, '!' );
			printf(
				'<option value="%1$s"%2$s%3$s>%4$s</option>',
				esc_attr( (string) $k ),
				selected( $value, (string) $k, false ),
				$disabled ? ' disabled' : '',
				esc_html( ltrim( (string) $text, '!' ) )
			);
		}
		echo '</select>';
		if ( $help ) {
			echo '<p class="bps-help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Data helpers for the views
	 * ------------------------------------------------------------------- */

	/**
	 * Techniques in display order.
	 *
	 * @param array $config Config.
	 * @return array<string, array>
	 */
	public static function techniques( array $config ): array {
		$order = (array) ( $config['techniqueOrder'] ?? array_keys( (array) ( $config['techniques'] ?? array() ) ) );
		$out   = array();
		foreach ( $order as $id ) {
			if ( isset( $config['techniques'][ $id ] ) && is_array( $config['techniques'][ $id ] ) ) {
				$out[ $id ] = $config['techniques'][ $id ];
			}
		}
		foreach ( (array) ( $config['techniques'] ?? array() ) as $id => $t ) {
			if ( ! isset( $out[ $id ] ) && is_array( $t ) ) {
				$out[ $id ] = $t;
			}
		}
		return $out;
	}

	/**
	 * Example prices (incl. VAT) for the owner, computed with the real engine.
	 *
	 * @param array $config Config.
	 * @return array{available:bool, qtys:int[], size:float, rows:array}
	 */
	public static function price_examples( array $config ): array {
		$qtys = array( 1, 10, 25, 50, 100 );
		$size = (float) ( $config['sizes']['defaultCm'] ?? 8 );
		$out  = array(
			'available' => class_exists( '\BorduurPatchStudio\Pricing' ) && method_exists( '\BorduurPatchStudio\Pricing', 'quote' ),
			'qtys'      => $qtys,
			'size'      => $size,
			'rows'      => array(),
		);
		if ( ! $out['available'] ) {
			return $out;
		}
		foreach ( self::techniques( $config ) as $id => $t ) {
			if ( empty( $t['enabled'] ) || 'order' !== ( $t['mode'] ?? '' ) ) {
				continue;
			}
			$row = array(
				'label' => Settings::label( $t['label'] ?? $id ),
				'cells' => array(),
			);
			foreach ( $qtys as $qty ) {
				$min  = max( 1, (int) ( $t['minQty'] ?? 1 ) );
				$step = max( 1, (int) ( $t['qtyStep'] ?? 1 ) );
				if ( $qty < $min || 0 !== $qty % $step ) {
					$row['cells'][] = array(
						'note' => sprintf(
							/* translators: %d: minimum quantity */
							__( 'vanaf %d st.', 'borduur-patch-studio' ),
							$min
						),
					);
					continue;
				}
				try {
					$q = \BorduurPatchStudio\Pricing::quote(
						array(
							'technique'          => $id,
							'sizeCm'             => $size,
							'qty'                => $qty,
							'options'            => Settings::default_options( $config, $id ),
							'poolQty'            => 0,
							'feesAlreadyCharged' => array(),
							'otherItemsInclVat'  => 0,
							'withLadder'         => false,
							'locale'             => 'nl-BE',
						),
						$config
					);
				} catch ( \Throwable $e ) {
					$q = array( 'ok' => false );
				}
				if ( empty( $q['ok'] ) ) {
					$row['cells'][] = array( 'note' => '—' );
					continue;
				}
				$row['cells'][] = array(
					'total'    => (float) ( $q['totalIncl'] ?? 0 ),
					'perPiece' => (float) ( $q['perPieceAllInIncl'] ?? 0 ),
					'unit'     => (float) ( $q['unitIncl'] ?? 0 ),
				);
			}
			$out['rows'][] = $row;
		}
		return $out;
	}

	/**
	 * Compare the config base prices with the existing WooCommerce products ("Kassa-check", 06 §15).
	 *
	 * @param array $config Config.
	 * @return array<int, array{label:string, size:string, config:float, shop:?float, ok:bool}>
	 */
	public static function shop_check( array $config ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$rows = array();
		foreach ( self::techniques( $config ) as $id => $t ) {
			$vars = $t['legacy']['variations'] ?? null;
			if ( empty( $t['enabled'] ) || ! is_array( $vars ) || ! is_array( $t['basePrice'] ?? null ) ) {
				continue;
			}
			foreach ( $vars as $cm => $variation_id ) {
				$product = wc_get_product( (int) $variation_id );
				$shop    = null;
				if ( $product ) {
					$price = $product->get_regular_price();
					$shop  = is_numeric( $price ) ? (float) $price : null;
				}
				$conf   = isset( $t['basePrice'][ $cm ] ) ? (float) $t['basePrice'][ $cm ] : 0.0;
				$rows[] = array(
					'label'  => Settings::label( $t['label'] ?? $id ),
					'size'   => (string) $cm,
					'config' => $conf,
					'shop'   => $shop,
					'ok'     => null !== $shop && abs( $shop - $conf ) < 0.005,
				);
			}
		}
		// No legacy products on this site at all: nothing to compare.
		$found = array_filter( $rows, static fn( $r ) => null !== $r['shop'] );
		return $found ? $rows : array();
	}

	/**
	 * Published/draft pages that contain the tool (shortcode or Elementor widget).
	 *
	 * @return int[]
	 */
	public static function studio_pages(): array {
		global $wpdb;
		$like_sc  = '%' . $wpdb->esc_like( '[patch_studio' ) . '%';
		$like_el  = '%' . $wpdb->esc_like( 'bps-patch-studio' ) . '%';
		$like_el2 = '%' . $wpdb->esc_like( 'patch_studio' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				WHERE p.post_type IN ('page','post') AND p.post_status IN ('publish','private','draft','future')
				AND ( p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s )
				ORDER BY (p.post_status = 'publish') DESC, p.ID ASC LIMIT 5",
				$like_sc,
				$like_el,
				$like_el2
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Health checks for the Algemeen tab.
	 *
	 * @return array<int, array{ok:bool|null, label:string, detail:string, link?:string, link_label?:string}>
	 */
	public static function status_checks(): array {
		$checks = array();

		$woo      = Plugin::woocommerce_active();
		$checks[] = array(
			'ok'     => $woo,
			'label'  => __( 'WooCommerce', 'borduur-patch-studio' ),
			'detail' => $woo ? __( 'Actief', 'borduur-patch-studio' ) : __( 'Niet actief: klanten kunnen niet bestellen.', 'borduur-patch-studio' ),
		);

		$pid     = Settings::product_id();
		$product = ( $woo && $pid && function_exists( 'wc_get_product' ) ) ? wc_get_product( $pid ) : null;
		$row     = array(
			'ok'     => (bool) $product,
			'label'  => __( 'Product "Patch op maat"', 'borduur-patch-studio' ),
			'detail' => $product ? sprintf(
				/* translators: %d: product ID */
				__( 'Gevonden (ID %d), verborgen in de winkel. Prijzen zet Patch Studio zelf.', 'borduur-patch-studio' ),
				$pid
			) : __( 'Nog niet aangemaakt. Het wordt automatisch gemaakt bij het activeren van de plugin.', 'borduur-patch-studio' ),
		);
		if ( $product ) {
			$row['link']       = (string) get_edit_post_link( $pid, 'raw' );
			$row['link_label'] = __( 'Bekijken', 'borduur-patch-studio' );
		}
		$checks[] = $row;

		$pages = self::studio_pages();
		$row   = array(
			'ok'     => (bool) $pages,
			'label'  => __( 'Ontwerppagina', 'borduur-patch-studio' ),
			'detail' => $pages ? sprintf(
				/* translators: %s: page title */
				__( 'De tool staat op "%s".', 'borduur-patch-studio' ),
				get_the_title( $pages[0] )
			) : __( 'Nog op geen enkele pagina. Gebruik de knop bovenaan of de shortcode.', 'borduur-patch-studio' ),
		);
		if ( $pages ) {
			$row['link']       = (string) get_permalink( $pages[0] );
			$row['link_label'] = __( 'Bekijken', 'borduur-patch-studio' );
		}
		$checks[] = $row;

		$uploads = null;
		foreach ( array( '\BorduurPatchStudio\Uploads', '\BorduurPatchStudio\Woo\Uploads' ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'self_test' ) ) {
				try {
					$uploads = $class::self_test();
				} catch ( \Throwable $e ) {
					$uploads = array(
						'ok'      => false,
						'message' => $e->getMessage(),
					);
				}
				break;
			}
		}
		$checks[] = array(
			'ok'     => null === $uploads ? null : ! empty( $uploads['ok'] ),
			'label'  => __( 'Veilige opslag van bestanden', 'borduur-patch-studio' ),
			'detail' => null === $uploads ? __( 'Nog niet te testen (uploadmodule niet geladen).', 'borduur-patch-studio' ) : (string) ( $uploads['message'] ?? '' ),
		);

		$built    = is_readable( BPS_DIR . 'assets/dist/patch-studio.js' );
		$checks[] = array(
			'ok'     => $built,
			'label'  => __( 'Ontwerptool (JavaScript)', 'borduur-patch-studio' ),
			'detail' => $built ? __( 'Aanwezig.', 'borduur-patch-studio' ) : __( 'Het bestand assets/dist/patch-studio.js ontbreekt. Installeer de volledige plugin-zip.', 'borduur-patch-studio' ),
		);

		$pricing  = class_exists( '\BorduurPatchStudio\Pricing' );
		$checks[] = array(
			'ok'     => $pricing,
			'label'  => __( 'Prijsberekening op de server', 'borduur-patch-studio' ),
			'detail' => $pricing ? __( 'Actief: de winkelmand rekent met exact dezelfde prijzen als de tool.', 'borduur-patch-studio' ) : __( 'Prijsmodule ontbreekt.', 'borduur-patch-studio' ),
		);

		if ( defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' ) ) {
			$checks[] = array(
				'ok'     => true,
				'label'  => __( 'WP Rocket', 'borduur-patch-studio' ),
				'detail' => __( 'Gevonden. De tool is automatisch uitgesloten van "JavaScript uitstellen" en de cache wordt geleegd als je hier iets bewaart.', 'borduur-patch-studio' ),
			);
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$checks[] = array(
				'ok'     => true,
				'label'  => __( 'Elementor', 'borduur-patch-studio' ),
				'detail' => __( 'Gevonden. Sleep de widget "Patch Studio" op een pagina, of gebruik de shortcode.', 'borduur-patch-studio' ),
			);
		}
		return $checks;
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------- */

	/**
	 * admin-post handler.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'technieken';
		}
		$do = isset( $_POST['bps_do'] ) ? sanitize_key( wp_unslash( $_POST['bps_do'] ) ) : 'save';

		$notices = array();
		if ( 'reset' === $do ) {
			Settings::reset_paths( $tabs[ $tab ]['reset'] );
			$notices[] = array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: tab name */
					__( 'De standaardwaarden voor "%s" staan terug.', 'borduur-patch-studio' ),
					$tabs[ $tab ]['label']
				),
			);
		} else {
			$raw     = isset( $_POST['bps'] ) && is_array( $_POST['bps'] ) ? wp_unslash( $_POST['bps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Settings::update().
			$method  = 'collect_' . $tab;
			$partial = method_exists( __CLASS__, $method ) ? self::$method( $raw, Settings::get() ) : array();
			Settings::update( $partial );
			$notices[] = array(
				'type'    => 'success',
				'message' => __( 'Bewaard. De ontwerptool gebruikt meteen de nieuwe instellingen.', 'borduur-patch-studio' ),
			);
			$notices = array_merge( $notices, Settings::last_notices() );
		}

		set_transient( 'bps_admin_notices_' . get_current_user_id(), $notices, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Plugin::settings_url( $tab ) );
		exit;
	}

	/**
	 * Read a nested POST value.
	 *
	 * @param array $raw  POST data.
	 * @param array $path Segments.
	 * @return mixed|null
	 */
	private static function in( array $raw, array $path ) {
		$node = $raw;
		foreach ( $path as $seg ) {
			if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
				return null;
			}
			$node = $node[ $seg ];
		}
		return $node;
	}

	/**
	 * Set $out[path] = value when value is not null.
	 *
	 * @param array $out   Target.
	 * @param array $path  Segments.
	 * @param mixed $value Value.
	 */
	private static function put( array &$out, array $path, $value ): void {
		if ( null === $value ) {
			return;
		}
		$ref = &$out;
		foreach ( $path as $seg ) {
			if ( ! isset( $ref[ $seg ] ) || ! is_array( $ref[ $seg ] ) ) {
				$ref[ $seg ] = array();
			}
			$ref = &$ref[ $seg ];
		}
		$ref = $value;
	}

	/**
	 * Copy the listed POST paths (relative to a base) into $out.
	 *
	 * @param array $out   Target.
	 * @param array $raw   POST.
	 * @param array $base  Base path.
	 * @param array $leafs Leaf keys.
	 */
	private static function copy( array &$out, array $raw, array $base, array $leafs ): void {
		foreach ( $leafs as $leaf ) {
			$path = array_merge( $base, (array) $leaf );
			self::put( $out, $path, self::in( $raw, $path ) );
		}
	}

	/**
	 * Percent field ("5" or "5,5") to a fraction (0.05 / 0.055). Null when empty/invalid.
	 *
	 * @param mixed $value Value.
	 */
	private static function pct( $value ): ?float {
		if ( null === $value || ! is_scalar( $value ) ) {
			return null;
		}
		$s = str_replace( array( '%', ' ', ',' ), array( '', '', '.' ), trim( (string) $value ) );
		return is_numeric( $s ) ? round( (float) $s / 100, 6 ) : null;
	}

	/**
	 * Tab Technieken.
	 *
	 * @param array $raw    POST.
	 * @param array $config Current config.
	 */
	private static function collect_technieken( array $raw, array $config ): array {
		$out = array();
		foreach ( array_keys( self::techniques( $config ) ) as $id ) {
			self::copy( $out, $raw, array( 'techniques', $id ), array( 'enabled', 'mode', 'minQty', 'qtyStep' ) );
			$rules = self::in( $raw, array( 'techniques', $id, 'rules' ) );
			if ( is_array( $rules ) ) {
				self::put( $out, array( 'techniques', $id, 'rules' ), $rules );
			}
		}
		foreach ( array_keys( (array) ( $config['specials'] ?? array() ) ) as $sid ) {
			self::copy( $out, $raw, array( 'specials', $sid ), array( 'enabled' ) );
		}
		return $out;
	}

	/**
	 * Tab Prijzen.
	 *
	 * @param array $raw    POST.
	 * @param array $config Current config.
	 */
	private static function collect_prijzen( array $raw, array $config ): array {
		$out = array();
		foreach ( array_keys( self::techniques( $config ) ) as $id ) {
			$prices = self::in( $raw, array( 'techniques', $id, 'basePrice' ) );
			if ( is_array( $prices ) ) {
				$clean = array();
				foreach ( $prices as $cm => $p ) {
					if ( is_scalar( $p ) && '' !== trim( (string) $p ) ) {
						$clean[ (string) $cm ] = $p;
					}
				}
				// Quote-only techniques without prices keep basePrice null.
				if ( $clean || is_array( $config['techniques'][ $id ]['basePrice'] ?? null ) ) {
					self::put( $out, array( 'techniques', $id, 'basePrice' ), $clean ? $clean : null );
				}
			}
			self::copy( $out, $raw, array( 'techniques', $id ), array( 'tiers' ) );
		}

		self::copy( $out, $raw, array( 'tiers' ), array( 'pooled' ) );
		$steps = self::in( $raw, array( 'tiers', 'steps' ) );
		if ( is_array( $steps ) ) {
			$list = array();
			foreach ( $steps as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['min'] ) || '' === trim( (string) $row['min'] ) || ! is_numeric( trim( (string) $row['min'] ) ) ) {
					continue;
				}
				$list[] = array(
					'min'      => (int) $row['min'],
					'discount' => (float) ( self::pct( $row['discount'] ?? 0 ) ?? 0 ),
				);
			}
			$out['tiers']['steps'] = $list;
		}

		foreach ( array_keys( (array) ( $config['fees']['groups'] ?? array() ) ) as $gid ) {
			self::copy( $out, $raw, array( 'fees', 'groups', $gid ), array( 'amount' ) );
		}
		self::copy( $out, $raw, array( 'fees' ), array( 'scope' ) );

		// Options are a list: rebuild the full list from the current one with the edits applied.
		$posted = self::in( $raw, array( 'options' ) );
		if ( is_array( $posted ) ) {
			$options = array();
			foreach ( (array) ( $config['options'] ?? array() ) as $opt ) {
				if ( ! is_array( $opt ) || ! isset( $opt['id'] ) ) {
					continue;
				}
				$edit = $posted[ $opt['id'] ] ?? null;
				if ( is_array( $edit ) ) {
					if ( isset( $edit['enabled'] ) ) {
						$opt['enabled'] = '1' === (string) $edit['enabled'];
					}
					if ( isset( $edit['amount'] ) && '' !== trim( (string) $edit['amount'] ) ) {
						$opt['price']['amount'] = 'percent' === ( $opt['price']['type'] ?? '' ) ? self::pct( $edit['amount'] ) : $edit['amount'];
					}
					foreach ( (array) ( $edit['byTech'] ?? array() ) as $tech => $amount ) {
						if ( isset( $opt['priceByTechnique'][ $tech ] ) && '' !== trim( (string) $amount ) ) {
							$opt['priceByTechnique'][ $tech ]['amount'] = 'percent' === ( $opt['priceByTechnique'][ $tech ]['type'] ?? '' ) ? self::pct( $amount ) : $amount;
						}
					}
				}
				$options[] = $opt;
			}
			$out['options'] = $options;
		}

		self::copy( $out, $raw, array( 'shipping' ), array( 'flat', 'freeFromInclVat', 'hintFromInclVat' ) );
		self::copy( $out, $raw, array( 'vat' ), array( 'showExclToggle' ) );
		return $out;
	}

	/**
	 * Tab Kleuren.
	 *
	 * @param array $raw    POST.
	 * @param array $config Current config.
	 */
	private static function collect_kleuren( array $raw, array $config ): array {
		$out = array();

		if ( isset( $raw['threads_present'] ) ) {
			$on       = array_map( 'strval', (array) ( $raw['threads_on'] ?? array() ) );
			$disabled = array();
			foreach ( (array) ( $config['threads'] ?? array() ) as $thread ) {
				if ( isset( $thread['code'] ) && ! in_array( (string) $thread['code'], $on, true ) && ( $thread['stock'] ?? true ) !== false ) {
					$disabled[] = (string) $thread['code'];
				}
			}
			$out['threadsDisabled'] = $disabled;
		}
		self::copy( $out, $raw, array( 'threadSpecial' ), array( 'black', 'white' ) );

		foreach ( array( 'fabrics', 'leathers' ) as $list_key ) {
			$rows = self::in( $raw, array( $list_key ) );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			$list = array();
			$seen = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
					continue;
				}
				$nl = trim( (string) ( $row['label']['nl'] ?? '' ) );
				$hex = sanitize_hex_color( trim( (string) ( $row['hex'] ?? '' ) ) );
				if ( '' === $nl || ! $hex ) {
					continue;
				}
				$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
				if ( '' === $id ) {
					$id = ( 'leathers' === $list_key ? 'leather_' : '' ) . sanitize_key( remove_accents( str_replace( ' ', '_', strtolower( $nl ) ) ) );
				}
				$base = $id;
				$n    = 2;
				while ( isset( $seen[ $id ] ) ) {
					$id = $base . '_' . $n++;
				}
				$seen[ $id ] = true;
				$item        = array(
					'id'    => $id,
					'hex'   => $hex,
					'label' => array(
						'nl' => $nl,
						'fr' => trim( (string) ( $row['label']['fr'] ?? '' ) ),
					),
				);
				if ( 'leathers' === $list_key ) {
					$engrave         = sanitize_hex_color( trim( (string) ( $row['engrave'] ?? '' ) ) );
					$item['engrave'] = $engrave ? $engrave : self::darken( $hex, 0.5 );
				}
				$list[] = $item;
			}
			if ( $list ) {
				$out[ $list_key ] = $list;
			}
		}
		return $out;
	}

	/**
	 * Tab Teksten & beloftes.
	 *
	 * @param array $raw    POST.
	 * @param array $config Current config.
	 */
	private static function collect_teksten( array $raw, array $config ): array {
		$out = array();
		self::copy( $out, $raw, array( 'promises' ), array( 'proofDays', 'fromOne', 'cancelUntilProof', 'payAfterProof', 'reorderKeepsSetup' ) );
		foreach ( array( 'proof', 'fromOneText', 'responseTime', 'leadTimeFallback' ) as $key ) {
			self::copy( $out, $raw, array( 'promises', $key ), array( 'nl', 'fr' ) );
		}
		self::copy( $out, $raw, array( 'promises', 'testimonial' ), array( 'enabled', 'name', array( 'role', 'nl' ), array( 'role', 'fr' ), array( 'text', 'nl' ), array( 'text', 'fr' ) ) );
		self::copy( $out, $raw, array( 'promises', 'deadline' ), array( 'enabled', array( 'text', 'nl' ), array( 'text', 'fr' ) ) );
		self::copy( $out, $raw, array( 'contact' ), array( 'phone', 'whatsapp', 'email', 'city', 'quoteUrl', 'privacyUrl', 'termsUrl' ) );
		foreach ( array_keys( self::techniques( $config ) ) as $id ) {
			self::copy( $out, $raw, array( 'techniques', $id, 'leadTime' ), array( 'nl', 'fr' ) );
		}
		// An empty proof-days field means "don't promise a number of days".
		if ( isset( $out['promises'] ) && array_key_exists( 'proofDays', $out['promises'] ) && '' === trim( (string) $out['promises']['proofDays'] ) ) {
			$out['promises']['proofDays'] = null;
		}
		return $out;
	}

	/**
	 * Tab Algemeen.
	 *
	 * @param array $raw    POST.
	 * @param array $config Current config.
	 */
	private static function collect_algemeen( array $raw, array $config ): array {
		$out = array();
		self::copy( $out, $raw, array( 'woo' ), array( 'productVisibility', 'redirectToCart', 'skipFeeIfLegacyInCart', 'quoteRecipient', 'maxUploadMb' ) );
		self::copy( $out, $raw, array( 'legal' ), array( 'fixOrderButton', 'showWithdrawalNotice' ) );
		foreach ( array_keys( (array) ( $config['features'] ?? array() ) ) as $f ) {
			self::copy( $out, $raw, array( 'features' ), array( $f ) );
		}
		self::copy( $out, $raw, array( 'retention' ), array( 'orphanDays', 'savedDays', 'quoteDays', 'orderYears' ) );
		self::copy( $out, $raw, array( 'admin' ), array( 'deleteDataOnUninstall' ) );
		return $out;
	}

	/**
	 * Darken a hex color (for a default engraving tint of a new leather color).
	 *
	 * @param string $hex    #rrggbb.
	 * @param float  $factor 0..1.
	 */
	private static function darken( string $hex, float $factor ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );
		return sprintf( '#%02x%02x%02x', ...array_map( static fn( $c ) => (int) round( $c * $factor ), $rgb ) );
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	/**
	 * Create the "Ontwerp je patch" page with the shortcode.
	 */
	public static function handle_create_page(): void {
		if ( ! current_user_can( Plugin::capability() ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), 403 );
		}
		check_admin_referer( 'bps_create_page' );

		$existing = self::studio_pages();
		$notices  = array();
		if ( $existing ) {
			$page_id = $existing[0];
		} else {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Ontwerp je patch', 'borduur-patch-studio' ),
					'post_name'    => sanitize_title( __( 'patch-ontwerpen', 'borduur-patch-studio' ) ),
					'post_content' => "<!-- wp:shortcode -->\n[patch_studio]\n<!-- /wp:shortcode -->",
					'comment_status' => 'closed',
				),
				true
			);
		}
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			$notices[] = array(
				'type'    => 'error',
				'message' => __( 'De pagina kon niet aangemaakt worden. Maak zelf een pagina en zet er de shortcode [patch_studio] op.', 'borduur-patch-studio' ),
			);
		} else {
			$notices[] = array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: view URL, 2: edit URL */
					__( 'Je ontwerppagina staat online. <a href="%1$s" target="_blank" rel="noopener">Bekijk ze</a> of <a href="%2$s">bewerk ze</a>. Zet er nog een link naar in je menu of op de knop "Ontwerp het zelf".', 'borduur-patch-studio' ),
					esc_url( get_permalink( (int) $page_id ) ),
					esc_url( (string) get_edit_post_link( (int) $page_id, 'raw' ) )
				),
			);
			Plugin::purge_page_cache();
		}
		set_transient( 'bps_admin_notices_' . get_current_user_id(), $notices, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Plugin::settings_url( self::current_tab_from_referer() ) );
		exit;
	}

	/**
	 * Tab the user came from (for redirects).
	 */
	private static function current_tab_from_referer(): string {
		$ref = wp_get_referer();
		if ( $ref ) {
			$query = wp_parse_url( $ref, PHP_URL_QUERY );
			parse_str( (string) $query, $args );
			if ( isset( $args['tab'] ) && array_key_exists( (string) $args['tab'], self::tabs() ) ) {
				return (string) $args['tab'];
			}
		}
		return 'technieken';
	}

	/**
	 * Keep the product's catalog visibility in line with the setting.
	 *
	 * @param mixed $config New config.
	 */
	public static function sync_product_visibility( $config ): void {
		if ( ! is_array( $config ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$visibility = (string) ( $config['woo']['productVisibility'] ?? 'hidden' );
		$pid        = Settings::product_id();
		$product    = $pid ? wc_get_product( $pid ) : null;
		if ( $product && method_exists( $product, 'get_catalog_visibility' ) && $product->get_catalog_visibility() !== $visibility ) {
			try {
				$product->set_catalog_visibility( $visibility );
				$product->save();
			} catch ( \Throwable $e ) {
				// Invalid visibility for this product type: leave it as it is.
				unset( $e );
			}
		}
	}
}
