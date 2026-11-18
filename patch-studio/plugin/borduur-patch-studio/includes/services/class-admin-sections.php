<?php
/**
 * Admin sections "Rondes & pakketten" and "Diensten" (SPEC-AI-DIENSTEN §12). Plain words, units and limits, no JSON.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Offered to the AI admin page (ai-backend, `AI\Admin`) through the filter `bps_ai_admin_sections`:
 *   [ id => [ 'title' => string, 'render' => callable(): void, 'save' => callable(array $post): string[] errors,
 *             'reset' => callable(): void ] ]
 * The page shell prints one <form> per section (with its own nonce) and calls render/save/reset.
 * Without AI\Admin (not built or not loaded yet) we add our own page under WooCommerce, so the owner is never stuck.
 *
 * All values pass sanitize_rounds() / sanitize_services() (pure, tested) before they are stored in bps_ai_settings.
 */
final class Admin_Sections {

	const CAP    = 'manage_woocommerce';
	const NONCE  = 'bps_svc_admin';
	const PAGE   = 'bps-ai-commerce';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'bps_ai_admin_sections', array( __CLASS__, 'sections' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'fallback_menu' ), 99 );
		add_action( 'admin_post_bps_svc_save', array( __CLASS__, 'handle_fallback' ) );
	}

	/**
	 * Our sections for the AI admin page.
	 *
	 * @param array $sections Sections.
	 */
	public static function sections( $sections ) {
		$sections             = is_array( $sections ) ? $sections : array();
		$sections['rounds']   = array(
			'title'  => __( 'Rondes & pakketten', 'borduur-patch-studio' ),
			'render' => array( __CLASS__, 'render_rounds' ),
			'save'   => array( __CLASS__, 'save_rounds' ),
			'reset'  => array( __CLASS__, 'reset_rounds' ),
		);
		$sections['services'] = array(
			'title'  => __( 'Diensten', 'borduur-patch-studio' ),
			'render' => array( __CLASS__, 'render_services' ),
			'save'   => array( __CLASS__, 'save_services' ),
			'reset'  => array( __CLASS__, 'reset_services' ),
		);
		return $sections;
	}

	/* ---------------------------------------------------------------------
	 * Sanitizing (pure)
	 * ------------------------------------------------------------------- */

	/**
	 * Clamp a number.
	 *
	 * @param mixed $v   Value.
	 * @param float $min Min.
	 * @param float $max Max.
	 * @param float $def Default when not numeric.
	 */
	public static function num( $v, float $min, float $max, float $def ): float {
		if ( is_string( $v ) ) {
			$v = str_replace( array( ' ', ',' ), array( '', '.' ), $v );
		}
		if ( ! is_numeric( $v ) ) {
			return $def;
		}
		return max( $min, min( $max, (float) $v ) );
	}

	/**
	 * Posted "Rondes & pakketten" → settings patch (top level of bps_ai_settings).
	 *
	 * @param array $in Posted values.
	 * @return array{patch:array, errors:string[]}
	 */
	public static function sanitize_rounds( array $in ): array {
		$errors = array();
		$patch  = array(
			'free'           => array(
				'anonymous' => (int) self::num( $in['free_anonymous'] ?? 1, 0, 5, 1 ),
				'withEmail' => (int) self::num( $in['free_withEmail'] ?? 2, 0, 10, 2 ),
				'perOrder'  => (int) self::num( $in['free_perOrder'] ?? 5, 0, 50, 5 ),
			),
			'emailVerify'    => ! empty( $in['emailVerify'] ),
			'validityMonths' => (int) self::num( $in['validityMonths'] ?? 24, 12, 36, 24 ),
			'refundUnused'   => ! empty( $in['refundUnused'] ),
			'orderCredit'    => array(
				'percent'        => in_array( (int) ( $in['credit_percent'] ?? 100 ), array( 0, 50, 100 ), true ) ? (int) $in['credit_percent'] : 100,
				'minArticleIncl' => Config::round_half_up( self::num( $in['credit_min'] ?? 50, 0, 1000, 50 ), 2 ),
				'validMonths'    => (int) self::num( $in['credit_months'] ?? 12, 1, 36, 12 ),
			),
		);
		$packs = array();
		$ids   = array();
		foreach ( (array) ( $in['packs'] ?? array() ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$id = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $p['id'] ?? '' ) ) );
			if ( '' === $id || isset( $ids[ $id ] ) || strlen( $id ) > 32 ) {
				continue;
			}
			$ids[ $id ] = true;
			$rounds     = (int) self::num( $p['rounds'] ?? 0, 1, 500, 10 );
			$excl       = Config::round_half_up( self::num( $p['priceExcl'] ?? 0, 0, 1000, 0 ), 4 );
			$enabled    = ! empty( $p['enabled'] );
			if ( $enabled && $excl <= 0 ) {
				/* translators: %s: pack id */
				$errors[] = sprintf( __( 'Pakket %s heeft geen prijs en staat daarom uit.', 'borduur-patch-studio' ), $id );
				$enabled  = false;
			}
			$old   = Config::pack( $id );
			$label = array(
				'nl' => sanitize_text_field( (string) ( $p['label_nl'] ?? ( $old['label']['nl'] ?? '' ) ) ),
				'fr' => sanitize_text_field( (string) ( $p['label_fr'] ?? ( $old['label']['fr'] ?? '' ) ) ),
			);
			if ( '' === $label['nl'] ) {
				$label['nl'] = 'Ontwerppakket · ' . $rounds . ' rondes';
			}
			if ( '' === $label['fr'] ) {
				$label['fr'] = 'Pack design · ' . $rounds . ' essais';
			}
			$packs[] = array(
				'id'        => $id,
				'rounds'    => $rounds,
				'priceExcl' => $excl,
				'enabled'   => $enabled,
				'label'     => $label,
			);
		}
		if ( $packs ) {
			$patch['packs'] = $packs;
		}
		return array(
			'patch'  => $patch,
			'errors' => $errors,
		);
	}

	/** Numeric trigger/price fields per service: key => [min, max]. */
	const SVC_NUMBERS = array(
		'priceExcl'         => array( 0, 2000 ),
		'priceExclLarge'    => array( 0, 2000 ),
		'leadDays'          => array( 0, 60 ),
		'percent'           => array( 0, 100 ),
		'minExcl'           => array( 0, 2000 ),
		'freeFromQty'       => array( 0, 100000 ),
		'refundFromQty'     => array( 0, 100000 ),
		'refundValidMonths' => array( 1, 36 ),
		'largeFromStitches' => array( 0, 1000000 ),
	);

	/** Numeric triggers: key => [min, max]. */
	const TRIGGER_NUMBERS = array(
		'minSidePxBelow'     => array( 0, 5000 ),
		'jpegNoiseAbove'     => array( 0, 1 ),
		'offerFromQty'       => array( 0, 100000 ),
		'offerFromTotalIncl' => array( 0, 100000 ),
	);

	/**
	 * Posted "Diensten" → settings patch ('services' key of bps_ai_settings). Unknown services are ignored.
	 *
	 * @param array $in    Posted values: [serviceId => fields].
	 * @param array $known Known services (Config::services()).
	 * @return array{patch:array, errors:string[]}
	 */
	public static function sanitize_services( array $in, array $known ): array {
		$out    = array();
		$errors = array();
		foreach ( $known as $id => $def ) {
			if ( ! isset( $in[ $id ] ) || ! is_array( $in[ $id ] ) ) {
				continue;
			}
			$row = $in[ $id ];
			$s   = array(
				'enabled'    => ! empty( $row['enabled'] ),
				'showInShop' => ! empty( $row['showInShop'] ),
			);
			foreach ( self::SVC_NUMBERS as $k => $range ) {
				if ( array_key_exists( $k, $def ) && isset( $row[ $k ] ) ) {
					$v       = self::num( $row[ $k ], (float) $range[0], (float) $range[1], (float) $def[ $k ] );
					$s[ $k ] = in_array( $k, array( 'priceExcl', 'priceExclLarge', 'minExcl' ), true ) ? Config::round_half_up( $v, 4 ) : ( 'percent' === $k ? $v : (int) $v );
				}
			}
			foreach ( array( 'label', 'short', 'freeAlternative' ) as $k ) {
				if ( isset( $row[ $k . '_nl' ] ) || isset( $row[ $k . '_fr' ] ) ) {
					$s[ $k ] = array(
						'nl' => sanitize_text_field( (string) ( $row[ $k . '_nl' ] ?? '' ) ),
						'fr' => sanitize_text_field( (string) ( $row[ $k . '_fr' ] ?? '' ) ),
					);
					if ( 'label' === $k && ( '' === $s[ $k ]['nl'] || '' === $s[ $k ]['fr'] ) ) {
						$s[ $k ] = $def[ $k ] ?? $s[ $k ]; // A label is required in both languages.
					}
				}
			}
			$t = array();
			foreach ( self::TRIGGER_NUMBERS as $k => $range ) {
				if ( isset( $def['triggers'][ $k ] ) && isset( $row[ 'trigger_' . $k ] ) ) {
					$t[ $k ] = self::num( $row[ 'trigger_' . $k ], (float) $range[0], (float) $range[1], (float) $def['triggers'][ $k ] );
					if ( 'jpegNoiseAbove' !== $k ) {
						$t[ $k ] = (int) $t[ $k ];
					}
				}
			}
			if ( $t ) {
				$s['triggers'] = $t;
			}
			if ( $s['enabled'] && 'product' === ( $def['kind'] ?? 'product' ) && isset( $s['priceExcl'] ) && $s['priceExcl'] <= 0 ) {
				/* translators: %s: service label */
				$errors[] = sprintf( __( 'Dienst "%s" heeft geen prijs en staat daarom uit.', 'borduur-patch-studio' ), Text::label( $def['label'] ?? $id, 'nl' ) );
				$s['enabled'] = false;
			}
			if ( 'line_option' === ( $def['kind'] ?? '' ) ) {
				$s['showInShop'] = false; // Line options always hang on a patch line.
			}
			$out[ $id ] = $s;
		}
		return array(
			'patch'  => array( 'services' => $out ),
			'errors' => $errors,
		);
	}

	/* ---------------------------------------------------------------------
	 * Save / reset (called by the page shell or our fallback page)
	 * ------------------------------------------------------------------- */

	/**
	 * Save "Rondes & pakketten".
	 *
	 * @param array $post Posted values (unslashed).
	 * @return string[] Errors/notes.
	 */
	public static function save_rounds( array $post ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array( __( 'Geen toegang.', 'borduur-patch-studio' ) );
		}
		$res = self::sanitize_rounds( (array) ( $post['bps_rounds'] ?? array() ) );
		Config::save( $res['patch'] );
		$report = Products::ensure();
		return array_merge( $res['errors'], self::report_lines( $report ) );
	}

	/**
	 * Save "Diensten".
	 *
	 * @param array $post Posted values (unslashed).
	 * @return string[] Errors/notes.
	 */
	public static function save_services( array $post ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array( __( 'Geen toegang.', 'borduur-patch-studio' ) );
		}
		$res = self::sanitize_services( (array) ( $post['bps_services'] ?? array() ), Config::services( false ) );
		Config::save( $res['patch'] );
		$report = Products::ensure();
		if ( ! empty( $post['bps_check_products'] ) && ! $report['created'] && ! $report['repaired'] ) {
			$res['errors'][] = __( 'Alle producten zijn in orde.', 'borduur-patch-studio' );
		}
		return array_merge( $res['errors'], self::report_lines( $report ) );
	}

	/**
	 * Lines for the product sync report.
	 *
	 * @param array $report Products::ensure() report.
	 * @return string[]
	 */
	private static function report_lines( array $report ): array {
		$out = array();
		if ( $report['created'] ) {
			/* translators: %s: product names */
			$out[] = sprintf( __( 'Nieuwe producten aangemaakt: %s.', 'borduur-patch-studio' ), implode( ', ', $report['created'] ) );
		}
		if ( $report['repaired'] ) {
			/* translators: %s: product names */
			$out[] = sprintf( __( 'Producten teruggezet volgens de instellingen: %s.', 'borduur-patch-studio' ), implode( ', ', $report['repaired'] ) );
		}
		return $out;
	}

	/**
	 * Defaults for rounds.
	 */
	public static function reset_rounds(): void {
		if ( current_user_can( self::CAP ) ) {
			Config::reset( array( 'free', 'emailVerify', 'packs', 'validityMonths', 'refundUnused', 'orderCredit' ) );
			Products::ensure();
		}
	}

	/**
	 * Defaults for services.
	 */
	public static function reset_services(): void {
		if ( current_user_can( self::CAP ) ) {
			Config::reset( array( 'services', 'offerRules' ) );
			Products::ensure();
		}
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Number input row.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @param string $unit  Unit/help.
	 * @param array  $attr  min/max/step.
	 */
	private static function number_row( string $name, $value, string $label, string $unit, array $attr ): void {
		$id = 'f-' . md5( $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"';
		foreach ( $attr as $k => $v ) {
			echo ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo ' class="small-text"> <span class="description">' . esc_html( $unit ) . '</span></td></tr>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name  Name.
	 * @param bool   $on    Checked.
	 * @param string $label Label.
	 * @param string $help  Help.
	 */
	private static function check_row( string $name, bool $on, string $label, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . '> ';
		echo esc_html( $help ) . '</label></td></tr>';
	}

	/**
	 * Fields of "Rondes & pakketten" (inside the page's form).
	 */
	public static function render_rounds(): void {
		$vat = Config::vat_rate();
		echo '<h2>' . esc_html__( 'Gratis rondes', 'borduur-patch-studio' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '1 ronde = 1× "Maak voorstellen" of 1× aanpassen met AI. Mislukte en geweigerde rondes komen altijd terug.', 'borduur-patch-studio' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::number_row( 'bps_rounds[free_anonymous]', Config::get( 'free.anonymous', 1 ), __( 'Zonder e-mail', 'borduur-patch-studio' ), __( 'rondes per browser (max 3 nieuwe per internetaansluiting per dag)', 'borduur-patch-studio' ), array( 'min' => 0, 'max' => 5 ) );
		self::number_row( 'bps_rounds[free_withEmail]', Config::get( 'free.withEmail', 2 ), __( 'Na e-mailbevestiging', 'borduur-patch-studio' ), __( 'extra rondes, eenmalig per e-mailadres', 'borduur-patch-studio' ), array( 'min' => 0, 'max' => 10 ) );
		self::check_row( 'bps_rounds[emailVerify]', (bool) Config::get( 'emailVerify', true ), __( 'E-mailcode', 'borduur-patch-studio' ), __( 'Klanten bevestigen hun e-mailadres met een code van 6 cijfers', 'borduur-patch-studio' ) );
		self::number_row( 'bps_rounds[free_perOrder]', Config::get( 'free.perOrder', 5 ), __( 'Per betaalde patchbestelling', 'borduur-patch-studio' ), __( 'rondes cadeau na betaling', 'borduur-patch-studio' ), array( 'min' => 0, 'max' => 50 ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Pakketten', 'borduur-patch-studio' ) . '</h2>';
		/* translators: %s: VAT percentage */
		echo '<p class="description">' . esc_html( sprintf( __( 'Prijzen excl. btw, zoals de rest van de beheerpagina. De klant ziet de prijs incl. %s %% btw. Pakketten worden enkel verkocht als de AI-studio op "Live" staat.', 'borduur-patch-studio' ), (string) round( $vat * 100 ) ) ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Aan', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Naam (NL / FR)', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Rondes', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Prijs excl. btw', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Klant betaalt', 'borduur-patch-studio' ) . '</th></tr></thead><tbody>';
		foreach ( Config::packs( false ) as $i => $p ) {
			$n = 'bps_rounds[packs][' . (int) $i . ']';
			echo '<tr><td><input type="hidden" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $p['id'] ) . '"><input type="checkbox" name="' . esc_attr( $n ) . '[enabled]" value="1"' . checked( $p['enabled'], true, false ) . '></td>';
			echo '<td><input type="text" name="' . esc_attr( $n ) . '[label_nl]" value="' . esc_attr( Text::label( $p['label'], 'nl' ) ) . '" class="regular-text"><br><input type="text" name="' . esc_attr( $n ) . '[label_fr]" value="' . esc_attr( Text::label( $p['label'], 'fr' ) ) . '" class="regular-text"></td>';
			echo '<td><input type="number" min="1" max="500" name="' . esc_attr( $n ) . '[rounds]" value="' . (int) $p['rounds'] . '" class="small-text"></td>';
			echo '<td>€ <input type="number" min="0" step="0.0001" name="' . esc_attr( $n ) . '[priceExcl]" value="' . esc_attr( (string) $p['priceExcl'] ) . '" style="width:7em"></td>';
			echo '<td>' . esc_html( Text::money( $p['priceIncl'], 'nl' ) ) . ' · ' . esc_html( Text::money( $p['priceIncl'] / max( 1, $p['rounds'] ), 'nl' ) ) . ' ' . esc_html__( 'per ronde', 'borduur-patch-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<table class="form-table" role="presentation">';
		self::number_row( 'bps_rounds[validityMonths]', Config::get( 'validityMonths', 24 ), __( 'Betaalde rondes geldig', 'borduur-patch-studio' ), __( 'maanden (12–36)', 'borduur-patch-studio' ), array( 'min' => 12, 'max' => 36 ) );
		self::check_row( 'bps_rounds[refundUnused]', (bool) Config::get( 'refundUnused', true ), __( 'Herroeping', 'borduur-patch-studio' ), __( 'Ongebruikte rondes terugbetalen als de klant binnen 14 dagen herroept (knop in Mijn account)', 'borduur-patch-studio' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Terug als korting op patches', 'borduur-patch-studio' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Na een pakketaankoop krijgt de klant een persoonlijke kortingscode. Die wordt vanzelf toegepast in de winkelmand zodra er patches in zitten en het minimum gehaald is. Nooit twee codes op één bestelling.', 'borduur-patch-studio' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Hoeveel komt terug', 'borduur-patch-studio' ) . '</th><td><select name="bps_rounds[credit_percent]">';
		foreach ( array( 0, 50, 100 ) as $pct ) {
			echo '<option value="' . (int) $pct . '"' . selected( (int) Config::get( 'orderCredit.percent', 100 ), $pct, false ) . '>' . (int) $pct . ' %</option>';
		}
		echo '</select> <span class="description">' . esc_html__( 'van de pakketprijs', 'borduur-patch-studio' ) . '</span></td></tr>';
		self::number_row( 'bps_rounds[credit_min]', Config::get( 'orderCredit.minArticleIncl', 50 ), __( 'Minimum patchbestelling', 'borduur-patch-studio' ), __( 'euro incl. btw', 'borduur-patch-studio' ), array( 'min' => 0, 'max' => 1000, 'step' => '0.01' ) );
		self::number_row( 'bps_rounds[credit_months]', Config::get( 'orderCredit.validMonths', 12 ), __( 'Code geldig', 'borduur-patch-studio' ), __( 'maanden', 'borduur-patch-studio' ), array( 'min' => 1, 'max' => 36 ) );
		echo '</table>';
	}

	/**
	 * Fields of "Diensten".
	 */
	public static function render_services(): void {
		echo '<p class="description">' . esc_html__( 'Diensten worden enkel voorgesteld als er echt een reden is (klein of wazig bestand, borduurcheck niet in orde, grote bestelling …), maximaal één tegelijk, nooit vooraf aangevinkt en altijd met "Nee, dank je". Prijzen excl. btw.', 'borduur-patch-studio' ) . '</p>';
		$trigger_labels = array(
			'minSidePxBelow'     => array( __( 'Voorstellen als het bestand kleiner is dan', 'borduur-patch-studio' ), __( 'pixels (kortste zijde)', 'borduur-patch-studio' ) ),
			'jpegNoiseAbove'     => array( __( 'of de JPEG-ruis hoger is dan', 'borduur-patch-studio' ), __( '(0–1)', 'borduur-patch-studio' ) ),
			'offerFromQty'       => array( __( 'Voorstellen vanaf', 'borduur-patch-studio' ), __( 'stuks', 'borduur-patch-studio' ) ),
			'offerFromTotalIncl' => array( __( 'of vanaf een bestelling van', 'borduur-patch-studio' ), __( 'euro incl. btw', 'borduur-patch-studio' ) ),
		);
		$num_labels     = array(
			'priceExcl'         => array( __( 'Prijs excl. btw', 'borduur-patch-studio' ), __( 'euro', 'borduur-patch-studio' ) ),
			'priceExclLarge'    => array( __( 'Prijs groot ontwerp excl. btw', 'borduur-patch-studio' ), __( 'euro', 'borduur-patch-studio' ) ),
			'largeFromStitches' => array( __( 'Groot ontwerp vanaf', 'borduur-patch-studio' ), __( 'steken', 'borduur-patch-studio' ) ),
			'leadDays'          => array( __( 'Levertijd', 'borduur-patch-studio' ), __( 'werkdagen', 'borduur-patch-studio' ) ),
			'percent'           => array( __( 'Toeslag', 'borduur-patch-studio' ), __( '% van de patchprijs', 'borduur-patch-studio' ) ),
			'minExcl'           => array( __( 'Minimum toeslag excl. btw', 'borduur-patch-studio' ), __( 'euro', 'borduur-patch-studio' ) ),
			'freeFromQty'       => array( __( 'Gratis vanaf', 'borduur-patch-studio' ), __( 'stuks', 'borduur-patch-studio' ) ),
			'refundFromQty'     => array( __( 'Terug als korting bij een bestelling vanaf', 'borduur-patch-studio' ), __( 'stuks', 'borduur-patch-studio' ) ),
			'refundValidMonths' => array( __( 'Korting geldig', 'borduur-patch-studio' ), __( 'maanden', 'borduur-patch-studio' ) ),
		);
		$notes          = array(
			'd4_rush'        => __( 'Zet dit pas aan als je spoedlevering echt kunt waarmaken. De klant ziet de nieuwe datum.', 'borduur-patch-studio' ),
			'd5_extra_proof' => __( 'Zet dit pas aan als je beslist hebt hoeveel proefaanpassingen gratis zijn.', 'borduur-patch-studio' ),
		);
		foreach ( Config::services( false ) as $id => $s ) {
			$n = 'bps_services[' . $id . ']';
			echo '<details class="bps-svc-admin" style="margin:0 0 .5em;padding:.5em 1em;background:#fff;border:1px solid #dcdcde"' . ( $s['enabled'] ? ' open' : '' ) . '><summary style="cursor:pointer"><strong>' . esc_html( Text::label( $s['label'] ?? $id, 'nl' ) ) . '</strong> — ' . esc_html( $s['enabled'] ? __( 'aan', 'borduur-patch-studio' ) : __( 'uit', 'borduur-patch-studio' ) ) . ( 'line_option' !== $s['kind'] ? ' · ' . esc_html( Text::money( (float) $s['priceIncl'], 'nl' ) ) . ' ' . esc_html__( 'incl. btw', 'borduur-patch-studio' ) : '' ) . '</summary>';
			if ( isset( $notes[ $id ] ) ) {
				echo '<p class="description">' . esc_html( $notes[ $id ] ) . '</p>';
			}
			echo '<table class="form-table" role="presentation">';
			self::check_row( $n . '[enabled]', $s['enabled'], __( 'Aan', 'borduur-patch-studio' ), __( 'Deze dienst aanbieden', 'borduur-patch-studio' ) );
			foreach ( array( 'label' => __( 'Naam', 'borduur-patch-studio' ), 'short' => __( 'Korte uitleg', 'borduur-patch-studio' ), 'freeAlternative' => __( 'Gratis alternatief', 'borduur-patch-studio' ) ) as $k => $lab ) {
				if ( ! isset( $s[ $k ] ) ) {
					continue;
				}
				echo '<tr><th scope="row">' . esc_html( $lab ) . '</th><td>';
				echo 'NL <input type="text" class="large-text" name="' . esc_attr( $n . '[' . $k . '_nl]' ) . '" value="' . esc_attr( Text::label( $s[ $k ], 'nl' ) ) . '"><br>';
				echo 'FR <input type="text" class="large-text" name="' . esc_attr( $n . '[' . $k . '_fr]' ) . '" value="' . esc_attr( Text::label( $s[ $k ], 'fr' ) ) . '"></td></tr>';
			}
			foreach ( self::SVC_NUMBERS as $k => $range ) {
				if ( ! array_key_exists( $k, $s ) ) {
					continue;
				}
				$step = in_array( $k, array( 'priceExcl', 'priceExclLarge', 'minExcl' ), true ) ? '0.0001' : '1';
				self::number_row( $n . '[' . $k . ']', $s[ $k ], $num_labels[ $k ][0], $num_labels[ $k ][1], array( 'min' => $range[0], 'max' => $range[1], 'step' => $step ) );
			}
			foreach ( self::TRIGGER_NUMBERS as $k => $range ) {
				if ( ! isset( $s['triggers'][ $k ] ) ) {
					continue;
				}
				self::number_row( $n . '[trigger_' . $k . ']', $s['triggers'][ $k ], $trigger_labels[ $k ][0], $trigger_labels[ $k ][1], array( 'min' => $range[0], 'max' => $range[1], 'step' => 'jpegNoiseAbove' === $k ? '0.01' : '1' ) );
			}
			if ( 'product' === $s['kind'] ) {
				self::check_row( $n . '[showInShop]', ! empty( $s['showInShop'] ), __( 'In de winkel', 'borduur-patch-studio' ), __( 'Ook als los product tonen in de shop', 'borduur-patch-studio' ) );
			}
			$pid = Products::product_id( 'service', $id );
			if ( $pid > 0 ) {
				echo '<tr><th scope="row">' . esc_html__( 'WooCommerce-product', 'borduur-patch-studio' ) . '</th><td><a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">#' . (int) $pid . '</a></td></tr>';
			}
			echo '</table></details>';
		}
		echo '<p><label><input type="checkbox" name="bps_check_products" value="1"> ' . esc_html__( 'Producten nakijken (herstelt verwijderde of gewijzigde pakket- en dienstproducten)', 'borduur-patch-studio' ) . '</label></p>';
	}

	/* ---------------------------------------------------------------------
	 * Fallback page (only when AI\Admin does not provide the shell)
	 * ------------------------------------------------------------------- */

	/**
	 * Does the AI admin page exist?
	 */
	private static function has_shell(): bool {
		return class_exists( '\BorduurPatchStudio\AI\Admin' ) && (bool) has_filter( 'bps_ai_admin_sections' ) && did_action( 'bps_ai_admin_ready' );
	}

	/**
	 * Add our own page under WooCommerce when there is no shell.
	 */
	public static function fallback_menu(): void {
		if ( self::has_shell() ) {
			return;
		}
		add_submenu_page( 'woocommerce', __( 'Patch Studio: rondes & diensten', 'borduur-patch-studio' ), __( 'Rondes & diensten', 'borduur-patch-studio' ), self::CAP, self::PAGE, array( __CLASS__, 'render_fallback' ) );
	}

	/**
	 * Fallback page.
	 */
	public static function render_fallback(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$tab   = isset( $_GET['tab'] ) && 'services' === $_GET['tab'] ? 'services' : 'rounds';
		$notes = get_transient( 'bps_svc_admin_notes_' . get_current_user_id() );
		delete_transient( 'bps_svc_admin_notes_' . get_current_user_id() );
		echo '<div class="wrap"><h1>' . esc_html__( 'Patch Studio: rondes & diensten', 'borduur-patch-studio' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::sections( array() ) as $id => $sec ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => $id,
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $sec['title'] ) . '</a>';
		}
		echo '</nav>';
		if ( is_array( $notes ) ) {
			foreach ( $notes as $note ) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( (string) $note ) . '</p></div>';
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="bps_svc_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		if ( 'services' === $tab ) {
			self::render_services();
		} else {
			self::render_rounds();
		}
		submit_button( __( 'Bewaren', 'borduur-patch-studio' ), 'primary', 'save', false );
		echo ' ';
		submit_button( __( 'Standaard terugzetten', 'borduur-patch-studio' ), 'secondary', 'reset', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Alle waarden in deze sectie terugzetten?', 'borduur-patch-studio' ) ) . "');" ) );
		echo '</form></div>';
	}

	/**
	 * Save from the fallback page.
	 */
	public static function handle_fallback(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'borduur-patch-studio' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$tab  = isset( $_POST['tab'] ) && 'services' === $_POST['tab'] ? 'services' : 'rounds';
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in sanitize_*().
		if ( isset( $post['reset'] ) ) {
			'services' === $tab ? self::reset_services() : self::reset_rounds();
			$notes = array( __( 'Standaardwaarden teruggezet.', 'borduur-patch-studio' ) );
		} else {
			$notes   = 'services' === $tab ? self::save_services( $post ) : self::save_rounds( $post );
			$notes[] = __( 'Bewaard.', 'borduur-patch-studio' );
		}
		set_transient( 'bps_svc_admin_notes_' . get_current_user_id(), $notes, MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
