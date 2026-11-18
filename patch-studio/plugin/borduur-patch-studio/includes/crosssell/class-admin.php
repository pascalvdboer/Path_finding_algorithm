<?php
/**
 * Beheerpagina "Patch Studio · Cross-sell" (WooCommerce-submenu, cap manage_woocommerce), SPEC-EXTRA §2.8.
 * Instellingen worden als verschil op config/crosssell.json bewaard (optie bps_crosssell_settings).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

defined( 'ABSPATH' ) || exit;

/**
 * Beheer.
 */
final class Admin {

	const SLUG = 'bps-crosssell';
	const CAP  = 'manage_woocommerce';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		foreach ( array( 'save', 'create', 'reset_stats', 'reset' ) as $a ) {
			add_action( 'admin_post_bps_cs_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Submenu onder WooCommerce (of Instellingen zonder WooCommerce).
	 */
	public static function menu(): void {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page( $parent, __( 'Patch Studio · Cross-sell', 'borduur-patch-studio' ), __( 'Patch Studio · Cross-sell', 'borduur-patch-studio' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * URL van de pagina.
	 *
	 * @param array $args Extra query.
	 */
	private static function page_url( array $args = array() ): string {
		$parent = class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php';
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( $parent ) );
	}

	/**
	 * Rechten + nonce, anders stoppen.
	 *
	 * @param string $action Actie.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), 403 );
		}
		check_admin_referer( 'bps_cs_' . $action );
	}

	/**
	 * Lijst product-ID's uit tekst ("36262, 36278").
	 *
	 * @param mixed $raw Invoer.
	 * @return int[]
	 */
	public static function ids( $raw ): array {
		$parts = preg_split( '/[\s,;]+/', (string) $raw ) ?: array();
		return array_values( array_unique( array_filter( array_map( 'absint', $parts ) ) ) );
	}

	/**
	 * Geldbedrag uit tekst ("4,00" of "4.00"), begrensd.
	 *
	 * @param mixed $raw Invoer.
	 * @param float $max Maximum.
	 */
	public static function money( $raw, float $max = 1000.0 ): float {
		$v = (float) str_replace( ',', '.', preg_replace( '/[^0-9.,]/', '', (string) $raw ) ?? '' );
		return round( max( 0.0, min( $max, $v ) ), 2 );
	}

	/**
	 * Opslaan.
	 */
	public static function handle_save(): void {
		self::guard( 'save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce in guard().
		$in  = wp_unslash( $_POST );
		$cfg = Config::get();

		$cfg['enabled']    = ! empty( $in['enabled'] );
		$cfg['showInCart'] = ! empty( $in['showInCart'] );
		$cfg['maxCards']   = max( 1, min( 3, absint( $in['maxCards'] ?? 3 ) ) );
		foreach ( array( 'cap', 'beanie', 'garment_front', 'garment_back' ) as $zone ) {
			if ( isset( $in['application'][ $zone ] ) ) {
				$cfg['application'][ $zone ] = self::money( $in['application'][ $zone ], 100 );
			}
		}
		$cfg['application']['tiers'] = 'none' === ( $in['application']['tiers'] ?? '' ) ? 'none' : 'patch';
		$cfg['stats']['enabled']     = ! empty( $in['stats']['enabled'] );
		$cfg['stats']['autoDisable'] = ! empty( $in['stats']['autoDisable'] );

		$posted = isset( $in['items'] ) && is_array( $in['items'] ) ? $in['items'] : array();
		foreach ( $cfg['items'] as &$it ) {
			$p = isset( $posted[ $it['id'] ] ) && is_array( $posted[ $it['id'] ] ) ? $posted[ $it['id'] ] : null;
			if ( null === $p ) {
				continue;
			}
			$it['enabled'] = ! empty( $p['enabled'] );
			if ( 'frame' === ( $it['type'] ?? '' ) ) {
				foreach ( $it['sizes'] as &$s ) {
					$s['productId'] = absint( $p['sizes'][ $s['id'] ] ?? $s['productId'] ?? 0 );
				}
				unset( $s );
			} else {
				$ids                    = self::ids( $p['products'] ?? '' );
				$it['wcProductIds']     = $ids;
				$def                    = absint( $p['default'] ?? 0 );
				$it['defaultProductId'] = in_array( $def, $ids, true ) ? $def : (int) ( $ids[0] ?? 0 );
			}
			if ( 'pvc' === ( $it['type'] ?? '' ) ) {
				$it['sharedMold']  = ! empty( $p['sharedMold'] );
				$it['moldFeeExcl'] = self::money( $p['moldFeeExcl'] ?? $it['moldFeeExcl'] ?? 0, 1000 );
			}
			foreach ( array( 'label', 'why' ) as $f ) {
				foreach ( array( 'nl', 'fr' ) as $l ) {
					if ( isset( $p[ $f ][ $l ] ) ) {
						$v = sanitize_text_field( (string) $p[ $f ][ $l ] );
						if ( '' !== $v ) {
							$it[ $f ][ $l ] = mb_substr( $v, 0, 160 );
						}
					}
				}
			}
		}
		unset( $it );
		// phpcs:enable
		Config::save( $cfg );
		wp_safe_redirect( self::page_url( array( 'bps_cs_msg' => 'saved' ) ) );
		exit;
	}

	/**
	 * "Maak ontbrekende producten aan".
	 */
	public static function handle_create(): void {
		self::guard( 'create' );
		$made = Products::create_missing();
		wp_safe_redirect( self::page_url( array( 'bps_cs_msg' => 'created', 'n' => count( $made ) ) ) );
		exit;
	}

	/**
	 * "Teller op nul".
	 */
	public static function handle_reset_stats(): void {
		self::guard( 'reset_stats' );
		Stats::reset();
		wp_safe_redirect( self::page_url( array( 'bps_cs_msg' => 'stats' ) ) );
		exit;
	}

	/**
	 * Standaard herstellen.
	 */
	public static function handle_reset(): void {
		self::guard( 'reset' );
		Config::reset();
		wp_safe_redirect( self::page_url( array( 'bps_cs_msg' => 'reset' ) ) );
		exit;
	}

	/**
	 * Formulier-knop naar admin-post met nonce.
	 *
	 * @param string $action Actie.
	 * @param string $label  Tekst.
	 * @param string $class  Knopklasse.
	 * @param string $confirm Bevestigingsvraag (leeg = geen).
	 */
	private static function action_button( string $action, string $label, string $class = 'button', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0"'
			. ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'bps_cs_' . $action ) . '">';
		wp_nonce_field( 'bps_cs_' . $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Pagina.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$cfg    = Config::get();
		$status = class_exists( 'WooCommerce' ) ? Products::status( $cfg ) : array();
		$stats  = Stats::all();
		$labels = array(
			'ok'              => array( __( 'In orde', 'borduur-patch-studio' ), '#00a32a' ),
			'product_missing' => array( __( 'Product ontbreekt', 'borduur-patch-studio' ), '#d63638' ),
			'no_purchasable'  => array( __( 'Geen koopbare variatie', 'borduur-patch-studio' ), '#dba617' ),
		);
		$msgs   = array(
			'saved'   => __( 'Instellingen bewaard.', 'borduur-patch-studio' ),
			'created' => __( 'Ontbrekende producten aangemaakt en gekoppeld. Kijk de prijzen na in WooCommerce.', 'borduur-patch-studio' ),
			'stats'   => __( 'Tellers op nul gezet.', 'borduur-patch-studio' ),
			'reset'   => __( 'Standaardinstellingen hersteld.', 'borduur-patch-studio' ),
		);
		$msg    = isset( $_GET['bps_cs_msg'] ) ? sanitize_key( wp_unslash( $_GET['bps_cs_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap bps-cs-admin"><h1>' . esc_html__( 'Patch Studio · Cross-sell', 'borduur-patch-studio' ) . '</h1>';
		if ( isset( $msgs[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce is niet actief: cross-sell doet niets tot WooCommerce draait.', 'borduur-patch-studio' ) . '</p></div>';
		}
		if ( ! Module::bundle_exists() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Het script patch-studio-crosssell.js is nog niet gebouwd (npm run build). Tot dan opent het paneel niet.', 'borduur-patch-studio' ) . '</p></div>';
		}
		echo '<p style="max-width:760px">' . esc_html__( 'Na "In winkelmand" in de ontwerptool toont de klant één paneel met maximaal 3 producten waarop hetzelfde ontwerp past (pet, muts, hoodie, leder, kader, PVC). Alleen wat technisch past, met de volledige prijs incl. btw en een even grote knop "Nee, bedankt". Elke drager is een gewoon WooCommerce-product: de prijs van het blanco product komt uit WooCommerce.', 'borduur-patch-studio' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="bps_cs_save">';
		wp_nonce_field( 'bps_cs_save' );

		echo '<h2>' . esc_html__( 'Algemeen', 'borduur-patch-studio' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Cross-sell', 'borduur-patch-studio' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $cfg['enabled'] ), true, false ) . '> ' . esc_html__( 'Aan: paneel na "In winkelmand"', 'borduur-patch-studio' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'In de winkelmand', 'borduur-patch-studio' ) . '</th><td><label><input type="checkbox" name="showInCart" value="1"' . checked( ! empty( $cfg['showInCart'] ), true, false ) . '> ' . esc_html__( 'Compacte regel "Ook op pet, muts of in een kader?" onder de mandtabel (nooit in de kassa)', 'borduur-patch-studio' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="bps-cs-max">' . esc_html__( 'Maximaal aantal kaarten', 'borduur-patch-studio' ) . '</label></th><td><input id="bps-cs-max" type="number" min="1" max="3" name="maxCards" value="' . esc_attr( (string) (int) ( $cfg['maxCards'] ?? 3 ) ) . '" class="small-text"></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Aanbrengen (excl. btw, per stuk)', 'borduur-patch-studio' ) . '</h2><table class="form-table" role="presentation">';
		$zones = array(
			'cap'           => __( 'Op een pet', 'borduur-patch-studio' ),
			'beanie'        => __( 'Op een muts', 'borduur-patch-studio' ),
			'garment_front' => __( 'Op de borst (hoodie)', 'borduur-patch-studio' ),
			'garment_back'  => __( 'Op de rug (hoodie)', 'borduur-patch-studio' ),
		);
		foreach ( $zones as $z => $lbl ) {
			echo '<tr><th scope="row"><label for="bps-cs-app-' . esc_attr( $z ) . '">' . esc_html( $lbl ) . '</label></th><td>€ <input id="bps-cs-app-' . esc_attr( $z ) . '" type="text" inputmode="decimal" class="small-text" name="application[' . esc_attr( $z ) . ']" value="' . esc_attr( number_format( (float) ( $cfg['application'][ $z ] ?? 0 ), 2, ',', '' ) ) . '"></td></tr>';
		}
		$tiers = (string) ( $cfg['application']['tiers'] ?? 'patch' );
		echo '<tr><th scope="row">' . esc_html__( 'Staffel op aanbrengen', 'borduur-patch-studio' ) . '</th><td><select name="application[tiers]"><option value="patch"' . selected( $tiers, 'patch', false ) . '>' . esc_html__( 'Zelfde staffel als de patch', 'borduur-patch-studio' ) . '</option><option value="none"' . selected( $tiers, 'none', false ) . '>' . esc_html__( 'Geen staffel', 'borduur-patch-studio' ) . '</option></select></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Producten', 'borduur-patch-studio' ) . '</h2>';
		echo '<p>' . esc_html__( 'Product-ID\'s komma-gescheiden. De eerste (of het standaardproduct) wordt getoond; de klant kan via "Wijzig" een ander model kiezen. Een item zonder koopbaar product wordt nooit getoond.', 'borduur-patch-studio' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Item', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Aan', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Status', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Gekoppelde producten', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Teksten (NL / FR)', 'borduur-patch-studio' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $cfg['items'] as $it ) {
			$id   = (string) $it['id'];
			$n    = 'items[' . $id . ']';
			$st   = $labels[ $status[ $id ] ?? 'product_missing' ] ?? $labels['product_missing'];
			$name = Pricing::lbl( $it['label'] ?? $id, 'nl' );
			echo '<tr><td><strong>' . esc_html( $name ) . '</strong><br><code>' . esc_html( $id ) . '</code></td>';
			echo '<td><input type="checkbox" aria-label="' . esc_attr( $name ) . '" name="' . esc_attr( $n ) . '[enabled]" value="1"' . checked( false !== ( $it['enabled'] ?? true ), true, false ) . '></td>';
			echo '<td><span style="color:' . esc_attr( $st[1] ) . ';font-weight:600">' . esc_html( $st[0] ) . '</span></td><td>';
			if ( 'frame' === ( $it['type'] ?? '' ) ) {
				foreach ( (array) $it['sizes'] as $s ) {
					echo '<label style="display:block;margin-bottom:4px">' . esc_html( Pricing::lbl( $s['label'] ?? $s['id'], 'nl' ) ) . ': <input type="number" min="0" class="small-text" style="width:90px" name="' . esc_attr( $n . '[sizes][' . $s['id'] . ']' ) . '" value="' . esc_attr( (string) (int) ( $s['productId'] ?? 0 ) ) . '"></label>';
				}
			} else {
				echo '<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[products]" value="' . esc_attr( implode( ', ', array_map( 'intval', (array) ( $it['wcProductIds'] ?? array() ) ) ) ) . '" aria-label="' . esc_attr__( 'Product-ID\'s', 'borduur-patch-studio' ) . '">';
				echo '<br><label>' . esc_html__( 'Standaard', 'borduur-patch-studio' ) . ' <input type="number" min="0" style="width:90px" name="' . esc_attr( $n ) . '[default]" value="' . esc_attr( (string) (int) ( $it['defaultProductId'] ?? 0 ) ) . '"></label>';
			}
			if ( 'pvc' === ( $it['type'] ?? '' ) ) {
				echo '<p><label>' . esc_html__( 'Mal (excl. btw) €', 'borduur-patch-studio' ) . ' <input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $n ) . '[moldFeeExcl]" value="' . esc_attr( number_format( (float) ( $it['moldFeeExcl'] ?? 0 ), 2, ',', '' ) ) . '"></label></p>';
				echo '<p><label><input type="checkbox" name="' . esc_attr( $n ) . '[sharedMold]" value="1"' . checked( ! empty( $it['sharedMold'] ), true, false ) . '> ' . esc_html__( 'Mal gedeeld met een PVC-patch van hetzelfde ontwerp', 'borduur-patch-studio' ) . '</label><br><span class="description">' . esc_html__( 'Zet pas aan als je PVC-leverancier schriftelijk bevestigt dat de mal herbruikbaar is.', 'borduur-patch-studio' ) . '</span></p>';
			}
			echo '</td><td>';
			foreach ( array( 'label', 'why' ) as $f ) {
				foreach ( array( 'nl', 'fr' ) as $l ) {
					echo '<input type="text" style="width:100%;margin-bottom:3px" name="' . esc_attr( $n . '[' . $f . '][' . $l . ']' ) . '" value="' . esc_attr( Pricing::lbl( $it[ $f ] ?? '', $l ) ) . '" aria-label="' . esc_attr( $f . ' ' . strtoupper( $l ) ) . '" placeholder="' . esc_attr( strtoupper( $l ) ) . '">';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Meten', 'borduur-patch-studio' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="stats[enabled]" value="1"' . checked( ! empty( $cfg['stats']['enabled'] ), true, false ) . '> ' . esc_html__( 'Tellers bijhouden (zonder persoonsgegevens)', 'borduur-patch-studio' ) . '</label><br>';
		echo '<label><input type="checkbox" name="stats[autoDisable]" value="1"' . checked( ! empty( $cfg['stats']['autoDisable'] ), true, false ) . '> ' . esc_html(
			sprintf(
				/* translators: 1: minimum shown, 2: percentage */
				__( 'Item automatisch verbergen na %1$d vertoningen met minder dan %2$s %% toevoegingen', 'borduur-patch-studio' ),
				(int) ( $cfg['stats']['minShown'] ?? 200 ),
				number_format_i18n( 100 * (float) ( $cfg['stats']['minAddRate'] ?? 0.01 ), 1 )
			)
		) . '</label></p>';
		submit_button( __( 'Instellingen bewaren', 'borduur-patch-studio' ) );
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>' . esc_html__( 'Item', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Getoond', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Toegevoegd', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Verwijderd', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Besteld', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Nee, bedankt', 'borduur-patch-studio' ) . '</th><th>%</th></tr></thead><tbody>';
		foreach ( (array) $cfg['items'] as $it ) {
			$r     = isset( $stats[ $it['id'] ] ) && is_array( $stats[ $it['id'] ] ) ? $stats[ $it['id'] ] : array();
			$shown = (int) ( $r['shown'] ?? 0 );
			$rate  = $shown > 0 ? number_format_i18n( 100 * (int) ( $r['added'] ?? 0 ) / $shown, 1 ) . ' %' : '–';
			$auto  = ! empty( $cfg['stats']['autoDisable'] ) && Stats::auto_disabled( $r, (array) $cfg['stats'] ) ? ' <em>(' . esc_html__( 'automatisch verborgen', 'borduur-patch-studio' ) . ')</em>' : '';
			echo '<tr><td>' . esc_html( Pricing::lbl( $it['label'] ?? $it['id'], 'nl' ) ) . $auto . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $auto is geëscaped.
			foreach ( array( 'shown', 'added', 'removed', 'ordered', 'declined' ) as $k ) {
				echo '<td>' . esc_html( number_format_i18n( (int) ( $r[ $k ] ?? 0 ) ) ) . '</td>';
			}
			echo '<td>' . esc_html( $rate ) . '</td></tr>';
		}
		echo '</tbody></table><p></p>';

		self::action_button( 'create', __( 'Maak ontbrekende producten aan', 'borduur-patch-studio' ), 'button button-secondary' );
		self::action_button( 'reset_stats', __( 'Teller op nul', 'borduur-patch-studio' ), 'button', __( 'Alle tellers op nul zetten?', 'borduur-patch-studio' ) );
		self::action_button( 'reset', __( 'Standaard herstellen', 'borduur-patch-studio' ), 'button button-link-delete', __( 'Alle cross-sell-instellingen terugzetten naar de standaard?', 'borduur-patch-studio' ) );
		echo '<p class="description">' . esc_html__( '"Maak ontbrekende producten aan" maakt verborgen producten voor lederen sleutelhanger, tassenlabel, schaduwdozen en PVC-sleutelhanger met voorgestelde prijzen. Pas de prijzen gewoon aan in WooCommerce. Controleer in Conditional Extra Fees dat deze producten geen borduurkaart van € 45 activeren.', 'borduur-patch-studio' ) . '</p>';
		echo '</div>';
	}
}
