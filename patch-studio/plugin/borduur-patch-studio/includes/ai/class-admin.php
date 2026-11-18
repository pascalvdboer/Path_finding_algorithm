<?php
/**
 * Admin page WooCommerce → Patch Studio AI (mode, AI service, API keys, limits & costs, retention).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use BorduurPatchStudio\AI\Providers\Openai_Provider;
use BorduurPatchStudio\AI\Providers\Provider_Error;
use BorduurPatchStudio\AI\Providers\Provider_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §12. Own submenu page registered with `admin_menu` (no change to the existing settings page);
 * other builders add tabs through the filter `bps_ai_admin_sections`:
 *
 *   add_filter( 'bps_ai_admin_sections', function ( $sections ) {
 *       $sections['packs'] = array(
 *           'label'  => 'Rondes & pakketten',
 *           'intro'  => '…',
 *           'order'  => 35,                       // ai-backend tabs: 10 mode, 20 service, 30 keys, 60 limits, 70 retention
 *           'render' => callable(): void,          // echoes fields inside our <form> (escape everything)
 *           'save'   => callable( array $post ): ?string,  // $post = wp_unslash( $_POST ); return a notice or null
 *           'reset'  => callable(): void,          // optional "Standaard terugzetten"
 *       );
 *       return $sections;
 *   } );
 *
 * Plain language, units and bounds, never JSON; keys are write-only (only "ingesteld · eindigt op …abcd").
 */
final class Admin {

	const SLUG   = 'bps-ai';
	const ACTION = 'bps_ai_save';
	const NONCE  = 'bps_ai_admin';

	/** Numeric fields per tab: path => [label, unit, help]. */
	const LIMIT_FIELDS = array(
		'limits.dailyBudgetEur'    => array( 'Dagbudget', '€', 'Bereikt? Dan pauzeren de gratis rondes tot morgen. Betaalde rondes werken door.' ),
		'limits.monthlyBudgetEur'  => array( 'Maandbudget', '€', 'Je krijgt een mail bij het alarmpercentage en bij 100 %. Boven 100 % pauzeren de gratis rondes.' ),
		'limits.monthlyHardCapEur' => array( 'Hard maandplafond', '€', 'Daarboven pauzeert de AI-studio volledig ("even niet beschikbaar, je rondes blijven bewaard").' ),
		'limits.alertAtPercent'    => array( 'Alarm bij', '% van het maandbudget', '' ),
		'limits.perOwnerPerDay'    => array( 'Rondes per klant per dag', 'rondes', 'Ook voor betaalde rondes: beschermt tegen scripts.' ),
		'limits.minSecondsBetween' => array( 'Minimale tijd tussen twee rondes', 'seconden', 'Per klant en per internetaansluiting.' ),
		'limits.anonPerIpPerDay'   => array( 'Gratis anonieme rondes per internetaansluiting per dag', 'rondes', 'Tegen het steeds wissen van cookies.' ),
		'limits.globalConcurrent'  => array( 'Opdrachten tegelijk', 'opdrachten', 'Meer klanten tegelijk wachten even in de rij.' ),
		'limits.jobTimeoutSec'     => array( 'Maximale wachttijd per opdracht', 'seconden', 'Daarna stopt de opdracht en krijgt de klant zijn ronde terug.' ),
		'limits.writePerHour'      => array( 'Aanvragen per internetaansluiting per uur', 'aanvragen', '' ),
		'usdToEur'                 => array( 'Wisselkoers dollar → euro', '€ per $', 'AI-diensten rekenen in dollar. Pas aan als de koers sterk verandert.' ),
	);

	/** Retention fields: key => [label, min, max]. */
	const RETENTION_FIELDS = array(
		'guestPromptsAndProposals'    => array( 'Beschrijvingen en voorstellen van bezoekers zonder bestelling', 7, 90 ),
		'accountDesignsAfterLastOpen' => array( 'Gekozen ontwerpen in een account (na laatste bezoek)', 30, 1095 ),
		'refusedPromptLog'            => array( 'Geweigerde beschrijvingen', 1, 90 ),
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 61 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bps_ai_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_bps_ai_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_post_bps_ai_purge', array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notice' ) );
	}

	/**
	 * Capability for the page.
	 */
	public static function capability(): string {
		return class_exists( '\BorduurPatchStudio\Plugin' ) && method_exists( '\BorduurPatchStudio\Plugin', 'capability' ) ? (string) \BorduurPatchStudio\Plugin::capability() : 'manage_woocommerce';
	}

	/**
	 * Submenu under WooCommerce (Settings without WooCommerce).
	 */
	public static function menu(): void {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page( $parent, __( 'Patch Studio AI', 'borduur-patch-studio' ), __( 'Patch Studio AI', 'borduur-patch-studio' ), self::capability(), self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param string $tab Tab.
	 * @param array  $args Extra args.
	 */
	public static function url( string $tab = 'mode', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * All tabs (own + filtered), ordered.
	 *
	 * @return array<string, array>
	 */
	public static function tabs(): array {
		$own = array(
			'mode'      => array(
				'label' => __( 'Aan/uit', 'borduur-patch-studio' ),
				'intro' => __( 'Zet de AI-ontwerpstudio aan voor je klanten, of probeer ze eerst zelf uit in testmodus (met voorbeeldontwerpen, zonder kosten, enkel zichtbaar voor beheerders).', 'borduur-patch-studio' ),
				'order' => 10,
				'reset' => array( 'mode' ),
			),
			'service'   => array(
				'label' => __( 'AI-dienst', 'borduur-patch-studio' ),
				'intro' => __( 'Welke beeld-AI de voorstellen maakt. De standaard (OpenAI, kwaliteit medium, 3 voorstellen) is een goede verhouding tussen kwaliteit en kost (± € 0,15 per ronde).', 'borduur-patch-studio' ),
				'order' => 20,
				'reset' => array( 'default', 'fallback', 'vectorizer', 'n', 'refineN', 'providers.openai.model', 'providers.openai.quality', 'moderation.enabled' ),
			),
			'keys'      => array(
				'label' => __( 'API-sleutels', 'borduur-patch-studio' ),
				'intro' => __( 'De sleutels blijven op je server: ze gaan nooit naar de browser van je klanten en worden versleuteld bewaard. Je ziet enkel de laatste 4 tekens.', 'borduur-patch-studio' ),
				'order' => 30,
				'reset' => array(),
			),
			'limits'    => array(
				'label' => __( 'Limieten & kosten', 'borduur-patch-studio' ),
				'intro' => __( 'Hiermee houd je de kosten in de hand. Alle bedragen zijn wat de AI-dienst jou kost, niet wat de klant betaalt.', 'borduur-patch-studio' ),
				'order' => 60,
				'reset' => array_merge( array_keys( self::LIMIT_FIELDS ), array( 'alerts.email' ) ),
			),
			'retention' => array(
				'label' => __( 'Bewaren', 'borduur-patch-studio' ),
				'intro' => __( 'Hoe lang beschrijvingen en voorstellen bewaard blijven. Korter is privacyvriendelijker; ontwerpen bij bestellingen volgen altijd de bestelling.', 'borduur-patch-studio' ),
				'order' => 70,
				'reset' => array( 'retentionDays' ),
			),
		);
		$extra = apply_filters( 'bps_ai_admin_sections', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $id => $sec ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || isset( $own[ $id ] ) || ! is_array( $sec ) || empty( $sec['label'] ) || ! isset( $sec['render'] ) || ! is_callable( $sec['render'] ) ) {
				continue;
			}
			$own[ $id ] = array_merge( array( 'order' => 50, 'intro' => '' ), $sec, array( 'external' => true ) );
		}
		uasort(
			$own,
			static function ( $a, $b ) {
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);
		return $own;
	}

	/**
	 * Current tab.
	 */
	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'mode'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'mode';
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ) );
		}
		$tab  = self::current_tab();
		$tabs = self::tabs();
		echo '<div class="wrap bps-admin bps-ai-admin">';
		echo '<h1>' . esc_html__( 'Patch Studio AI', 'borduur-patch-studio' ) . '</h1>';
		echo '<p>' . esc_html__( 'Laat klanten zonder logo met AI een ontwerp maken, meteen op hun patch. Alles staat veilig ingesteld; de studio staat uit tot jij ze aanzet.', 'borduur-patch-studio' ) . '</p>';
		echo '<hr class="wp-header-end">';
		self::render_notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Onderdelen van Patch Studio AI', 'borduur-patch-studio' ) . '">';
		foreach ( $tabs as $key => $info ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( (string) $info['label'] )
			);
		}
		echo '</nav>';
		if ( '' !== (string) $tabs[ $tab ]['intro'] ) {
			echo '<p class="description" style="max-width:760px;font-size:14px">' . esc_html( (string) $tabs[ $tab ]['intro'] ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( self::NONCE );
		if ( ! empty( $tabs[ $tab ]['external'] ) ) {
			call_user_func( $tabs[ $tab ]['render'] );
		} else {
			call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		}
		echo '<p class="submit"><button type="submit" class="button button-primary" name="bps_do" value="save">' . esc_html__( 'Wijzigingen bewaren', 'borduur-patch-studio' ) . '</button> ';
		if ( ! empty( $tabs[ $tab ]['reset'] ) ) {
			echo '<button type="submit" class="button-link" name="bps_do" value="reset" onclick="return confirm(\'' . esc_js( __( 'Dit tabblad terugzetten naar de standaardwaarden?', 'borduur-patch-studio' ) ) . '\')">' . esc_html__( 'Standaard terugzetten', 'borduur-patch-studio' ) . '</button>';
		}
		echo '</p></form></div>';
	}

	/**
	 * Tab "Aan/uit" + status block.
	 */
	private static function tab_mode(): void {
		$mode   = AI_Settings::mode();
		$labels = array(
			'off'  => __( 'Uit — klanten zien geen AI-knop', 'borduur-patch-studio' ),
			'test' => __( 'Test — voorbeeldontwerpen, geen kosten, enkel zichtbaar voor beheerders', 'borduur-patch-studio' ),
			'live' => __( 'Live — klanten maken echte AI-ontwerpen', 'borduur-patch-studio' ),
		);
		echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Modus', 'borduur-patch-studio' ) . '</th><td><fieldset>';
		foreach ( $labels as $k => $label ) {
			echo '<label style="display:block;margin:6px 0"><input type="radio" name="mode" value="' . esc_attr( $k ) . '"' . checked( $mode, $k, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset>';
		if ( 'test' === $mode ) {
			echo '<p class="description">' . esc_html__( 'Open je ontwerppagina terwijl je ingelogd bent en tik op "Nog geen logo?" om het zelf te proberen.', 'borduur-patch-studio' ) . '</p>';
		}
		echo '</td></tr></table>';

		$primary = Provider_Registry::primary();
		$totals  = Cost_Log::totals();
		$budget  = Limits::budget();
		$rows    = array(
			__( 'Beeld-AI', 'borduur-patch-studio' )            => $primary->label() . ( $primary->is_configured() ? ' · ' . __( 'klaar', 'borduur-patch-studio' ) : ' · ' . __( 'sleutel ontbreekt', 'borduur-patch-studio' ) ),
			__( 'Kosten vandaag', 'borduur-patch-studio' )       => self::eur( $totals['day'] ) . ' / ' . self::eur( AI_Settings::limit( 'dailyBudgetEur' ) ),
			__( 'Kosten deze maand', 'borduur-patch-studio' )    => self::eur( $totals['month'] ) . ' / ' . self::eur( AI_Settings::limit( 'monthlyBudgetEur' ) ) . ' (' . (int) $budget['monthPct'] . ' %)',
			__( 'Gratis rondes', 'borduur-patch-studio' )        => $budget['freePaused'] ? __( 'gepauzeerd (budget bereikt)', 'borduur-patch-studio' ) : __( 'actief', 'borduur-patch-studio' ),
			__( 'Achtergrondtaken', 'borduur-patch-studio' )     => function_exists( 'as_enqueue_async_action' ) ? __( 'Action Scheduler (WooCommerce)', 'borduur-patch-studio' ) : __( 'geen; opdrachten lopen tijdens het wachten van de klant (werkt ook)', 'borduur-patch-studio' ),
			__( 'Rondes en pakketten', 'borduur-patch-studio' )  => 'commerce' === Credits_Gateway::instance()->kind() ? __( 'actief', 'borduur-patch-studio' ) : __( 'module niet geladen (in test: onbeperkte testrondes)', 'borduur-patch-studio' ),
			__( 'Sleutelversleuteling', 'borduur-patch-studio' ) => Secrets::can_encrypt() ? __( 'actief (sodium)', 'borduur-patch-studio' ) : __( 'niet beschikbaar op deze server: sleutels staan onversleuteld zoals andere WordPress-sleutels', 'borduur-patch-studio' ),
		);
		echo '<h2>' . esc_html__( 'Status', 'borduur-patch-studio' ) . '</h2><table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:240px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description" style="max-width:760px">' . esc_html__( 'Beelden staan in wp-content/uploads/patch-studio/ai en zijn afgeschermd met .htaccess. Gebruikt je server nginx? Voeg dan toe: location ^~ /wp-content/uploads/patch-studio/ai/ { deny all; }', 'borduur-patch-studio' ) . '</p>';
		$log = Module::recent_log( 5 );
		if ( $log ) {
			echo '<h2>' . esc_html__( 'Laatste meldingen', 'borduur-patch-studio' ) . '</h2><ul style="max-width:760px">';
			foreach ( $log as $l ) {
				echo '<li><code>' . esc_html( (string) $l['at'] ) . '</code> ' . esc_html( (string) $l['topic'] ) . ': ' . esc_html( (string) $l['msg'] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Tab "AI-dienst".
	 */
	private static function tab_service(): void {
		$s       = AI_Settings::get();
		$models  = (array) AI_Settings::path( 'providers.openai.models', array( 'gpt-image-2' ) );
		$names   = array(
			'openai'  => 'OpenAI',
			'recraft' => 'Recraft',
		);
		echo '<table class="form-table" role="presentation">';
		self::select_row( 'default', __( 'Beeld-AI', 'borduur-patch-studio' ), $names, (string) ( $s['default'] ?? 'openai' ), __( 'OpenAI maakt de beste borduurbare ontwerpen met tekst. Recraft levert meteen vectorbestanden.', 'borduur-patch-studio' ) );
		self::select_row( 'providers.openai.model', __( 'OpenAI-model', 'borduur-patch-studio' ), array_combine( $models, $models ), (string) AI_Settings::path( 'providers.openai.model', 'gpt-image-2' ), '' );
		self::select_row(
			'providers.openai.quality',
			__( 'Kwaliteit', 'borduur-patch-studio' ),
			array(
				'low'    => __( 'Laag (± € 0,03 per ronde, ruwer)', 'borduur-patch-studio' ),
				'medium' => __( 'Medium (± € 0,15 per ronde, aanbevolen)', 'borduur-patch-studio' ),
			),
			Openai_Provider::quality(),
			''
		);
		self::number_row( 'n', __( 'Voorstellen per ronde', 'borduur-patch-studio' ), __( 'voorstellen', 'borduur-patch-studio' ), (int) AI_Settings::path( 'n', 3 ), __( 'Tussen 2 en 4. Drie past mooi op een gsm-scherm.', 'borduur-patch-studio' ) );
		self::number_row( 'refineN', __( 'Versies per aanpassing', 'borduur-patch-studio' ), __( 'versies', 'borduur-patch-studio' ), (int) AI_Settings::path( 'refineN', 2 ), __( '1 of 2.', 'borduur-patch-studio' ) );
		self::select_row( 'fallback', __( 'Terugval bij storing', 'borduur-patch-studio' ), array( '' => __( 'Geen', 'borduur-patch-studio' ) ) + $names, (string) ( $s['fallback'] ?? '' ), __( 'Enkel bij een storing of overbelasting, nooit als de AI een ontwerp weigert. Werkt alleen met een sleutel voor die dienst.', 'borduur-patch-studio' ) );
		self::select_row(
			'vectorizer',
			__( 'Vectorbestand maken na kiezen', 'borduur-patch-studio' ),
			array(
				''        => __( 'Nee', 'borduur-patch-studio' ),
				'recraft' => __( 'Ja, met Recraft (± € 0,01, gratis voor de klant)', 'borduur-patch-studio' ),
			),
			(string) ( $s['vectorizer'] ?? '' ),
			''
		);
		echo '<tr><th scope="row">' . esc_html__( 'Extra controle op ongepaste beschrijvingen', 'borduur-patch-studio' ) . '</th><td><label><input type="checkbox" name="moderation_enabled" value="1"' . checked( ! empty( $s['moderation']['enabled'] ), true, false ) . '> ' . esc_html__( 'Gratis moderatiecontrole van OpenAI gebruiken (aanbevolen)', 'borduur-patch-studio' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Naam van de AI-dienst tonen', 'borduur-patch-studio' ) . '</th><td><label><input type="checkbox" checked disabled> ' . esc_html__( 'Altijd aan: de klant moet weten dat een AI de voorstellen maakt (AI-verordening).', 'borduur-patch-studio' ) . '</label></td></tr>';
		echo '</table>';
		echo '<p><button type="submit" class="button" name="bps_do" value="test">' . esc_html__( 'Verbinding testen', 'borduur-patch-studio' ) . '</button> <span class="description">' . esc_html__( 'Controleert de sleutel zonder een beeld te maken (gratis).', 'borduur-patch-studio' ) . '</span></p>';
		$unverified = array();
		foreach ( array( 'openai', 'recraft' ) as $p ) {
			if ( empty( $s['providers'][ $p ]['verified'] ) ) {
				$unverified[] = $names[ $p ];
			}
		}
		if ( $unverified ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %s: provider names */ __( 'De prijzen van %s zijn schattingen. Kijk ze na op de officiële prijspagina vóór je live gaat.', 'borduur-patch-studio' ), implode( ', ', $unverified ) ) ) . '</p></div>';
		}
	}

	/**
	 * Tab "API-sleutels".
	 */
	private static function tab_keys(): void {
		echo '<table class="form-table" role="presentation">';
		foreach ( array( 'openai' => 'OpenAI', 'recraft' => 'Recraft' ) as $id => $label ) {
			$src = Secrets::source( $id );
			echo '<tr><th scope="row"><label for="bps-key-' . esc_attr( $id ) . '">' . esc_html( sprintf( /* translators: %s: provider */ __( 'Sleutel %s', 'borduur-patch-studio' ), $label ) ) . '</label></th><td>';
			if ( 'constant' === $src ) {
				echo '<p>' . esc_html( sprintf( /* translators: 1: last 4 chars, 2: constant */ __( 'Ingesteld in wp-config.php · eindigt op …%1$s (%2$s). Pas hem daar aan.', 'borduur-patch-studio' ), Secrets::last4( $id ), Secrets::CONSTANTS[ $id ] ) ) . '</p>';
			} else {
				echo '<input type="password" id="bps-key-' . esc_attr( $id ) . '" name="key_' . esc_attr( $id ) . '" value="" autocomplete="new-password" spellcheck="false" class="regular-text" placeholder="' . esc_attr( 'option' === $src ? __( 'Laat leeg om te behouden', 'borduur-patch-studio' ) : __( 'Plak hier je sleutel', 'borduur-patch-studio' ) ) . '">';
				if ( 'option' === $src ) {
					echo '<p class="description">' . esc_html( sprintf( /* translators: %s: last 4 chars */ __( 'Ingesteld · eindigt op …%s', 'borduur-patch-studio' ), Secrets::last4( $id ) ) ) . ' <label><input type="checkbox" name="clear_' . esc_attr( $id ) . '" value="1"> ' . esc_html__( 'Wissen', 'borduur-patch-studio' ) . '</label></p>';
				}
				echo '<p class="description">' . esc_html( sprintf( /* translators: %s: constant */ __( 'Liever in wp-config.php? define( \'%s\', \'…\' );', 'borduur-patch-studio' ), Secrets::CONSTANTS[ $id ] ) ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		if ( ! Secrets::can_encrypt() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Deze server kan sleutels niet versleutelen (sodium ontbreekt). Zet ze liever in wp-config.php.', 'borduur-patch-studio' ) . '</p></div>';
		}
	}

	/**
	 * Tab "Limieten & kosten" + key figures.
	 */
	private static function tab_limits(): void {
		echo '<table class="form-table" role="presentation">';
		foreach ( self::LIMIT_FIELDS as $path => $f ) {
			$value = AI_Settings::path( $path, null );
			self::number_row( $path, __( $f[0], 'borduur-patch-studio' ), __( $f[1], 'borduur-patch-studio' ), $value, '' !== $f[2] ? __( $f[2], 'borduur-patch-studio' ) : '' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		$updated = (string) AI_Settings::path( 'usdToEurUpdated', '' );
		if ( '' !== $updated ) {
			echo '<tr><th></th><td class="description">' . esc_html( sprintf( /* translators: %s: date */ __( 'Wisselkoers laatst aangepast op %s.', 'borduur-patch-studio' ), $updated ) ) . '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="bps-alert">' . esc_html__( 'Budgetmails naar', 'borduur-patch-studio' ) . '</label></th><td><input type="email" id="bps-alert" name="alerts_email" class="regular-text" value="' . esc_attr( (string) AI_Settings::path( 'alerts.email', '' ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"><p class="description">' . esc_html__( 'Leeg = het beheerdersadres. De mail bevat enkel bedragen, nooit klantgegevens.', 'borduur-patch-studio' ) . '</p></td></tr>';
		echo '</table>';

		list( $from ) = Cost_Log::range( 'last30' );
		$stats        = Module::store()->job_stats( $from );
		list( $f, $t ) = Cost_Log::range( 'last30' );
		$cost30       = Module::store()->cost_sum( $f, $t ) / 1000000;
		$rounds       = max( 0, $stats['total'] - $stats['blocked'] );
		$figures      = array(
			__( 'Rondes (30 dagen)', 'borduur-patch-studio' )                 => (string) $rounds,
			__( 'Waarvan aanpassingen', 'borduur-patch-studio' )              => (string) $stats['refine'],
			__( 'Gelukt', 'borduur-patch-studio' )                            => (string) $stats['done'],
			__( 'Mislukt (ronde teruggegeven)', 'borduur-patch-studio' )      => (string) $stats['failed'],
			__( 'Geweigerd (geen ronde gebruikt)', 'borduur-patch-studio' )   => (string) $stats['blocked'],
			__( 'Voorstel gekozen', 'borduur-patch-studio' )                  => $stats['done'] > 0 ? round( 100 * $stats['chosen'] / $stats['done'] ) . ' %' : '–',
			__( 'AI-kosten (30 dagen)', 'borduur-patch-studio' )              => self::eur( $cost30 ),
			__( 'Kost per gelukte ronde', 'borduur-patch-studio' )            => $stats['done'] > 0 ? self::eur( $cost30 / $stats['done'] ) : '–',
		);
		echo '<h2>' . esc_html__( 'Kengetallen', 'borduur-patch-studio' ) . '</h2><table class="widefat striped" style="max-width:600px"><tbody>';
		foreach ( $figures as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$ref = Limits::refusal_totals( 30 );
		if ( $ref ) {
			echo '<h3>' . esc_html__( 'Weigeringen per soort (30 dagen)', 'borduur-patch-studio' ) . '</h3><p>';
			foreach ( $ref as $code => $n ) {
				echo '<code>' . esc_html( (string) $code ) . '</code> ' . (int) $n . ' &nbsp; ';
			}
			echo '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bps_ai_csv' ), 'bps_ai_csv' ) ) . '">' . esc_html__( 'Kostlog downloaden (CSV, zonder persoonsgegevens)', 'borduur-patch-studio' ) . '</a></p>';
	}

	/**
	 * Tab "Bewaren".
	 */
	private static function tab_retention(): void {
		echo '<table class="form-table" role="presentation">';
		foreach ( self::RETENTION_FIELDS as $key => $f ) {
			$v = AI_Settings::retention( $key, $f[1] );
			echo '<tr><th scope="row"><label for="bps-ret-' . esc_attr( $key ) . '">' . esc_html( __( $f[0], 'borduur-patch-studio' ) ) . '</label></th><td>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			echo '<input type="number" id="bps-ret-' . esc_attr( $key ) . '" name="ret_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $v ) . '" min="' . (int) $f[1] . '" max="' . (int) $f[2] . '" step="1" class="small-text"> ' . esc_html__( 'dagen', 'borduur-patch-studio' );
			echo '<p class="description">' . esc_html( sprintf( /* translators: 1: min, 2: max */ __( 'Tussen %1$d en %2$d dagen.', 'borduur-patch-studio' ), $f[1], $f[2] ) ) . '</p></td></tr>';
		}
		echo '</table><p class="description">' . esc_html__( 'Ontwerprondes en facturen blijven 10 jaar bewaard (boekhouding). Het kostlog bevat geen persoonsgegevens.', 'borduur-patch-studio' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bps_ai_purge' ), 'bps_ai_purge' ) ) . '">' . esc_html__( 'Wis nu verlopen gegevens', 'borduur-patch-studio' ) . '</a></p>';
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------- */

	/**
	 * admin-post handler.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized below.
		$tab  = isset( $post['tab'] ) ? sanitize_key( (string) $post['tab'] ) : 'mode';
		$tabs = self::tabs();
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'mode';
		$do   = isset( $post['bps_do'] ) ? sanitize_key( (string) $post['bps_do'] ) : 'save';
		$msg  = 'saved';
		if ( 'reset' === $do ) {
			if ( ! empty( $tabs[ $tab ]['external'] ) ) {
				if ( isset( $tabs[ $tab ]['reset'] ) && is_callable( $tabs[ $tab ]['reset'] ) ) {
					call_user_func( $tabs[ $tab ]['reset'] );
				}
			} else {
				AI_Settings::reset( (array) $tabs[ $tab ]['reset'] );
			}
			$msg = 'reset';
		} elseif ( ! empty( $tabs[ $tab ]['external'] ) ) {
			$res = isset( $tabs[ $tab ]['save'] ) && is_callable( $tabs[ $tab ]['save'] ) ? call_user_func( $tabs[ $tab ]['save'], is_array( $post ) ? $post : array() ) : null;
			$msg = is_string( $res ) && '' !== $res ? 'custom' : 'saved';
			if ( 'custom' === $msg ) {
				set_transient( 'bps_ai_admin_msg_' . get_current_user_id(), sanitize_text_field( $res ), 60 );
			}
		} else {
			$msg = self::save_tab( $tab, is_array( $post ) ? $post : array() );
			if ( 'service' === $tab && 'test' === $do ) {
				$msg = self::connection_test();
			}
		}
		Module::flush();
		wp_safe_redirect( self::url( $tab, array( 'bps_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Save one of our tabs. Returns a notice code.
	 *
	 * @param string $tab  Tab.
	 * @param array  $post Unslashed POST.
	 */
	public static function save_tab( string $tab, array $post ): string {
		$values = array();
		$msg    = 'saved';
		switch ( $tab ) {
			case 'mode':
				$mode = isset( $post['mode'] ) ? sanitize_key( (string) $post['mode'] ) : 'off';
				$mode = in_array( $mode, AI_Settings::MODES, true ) ? $mode : 'off';
				$values['mode'] = $mode;
				if ( 'live' === $mode ) {
					AI_Settings::update( $values );
					return Provider_Registry::primary()->is_configured() && 'mock' !== Provider_Registry::primary()->id() ? 'live' : 'live_nokey';
				}
				break;
			case 'service':
				$providers = array( 'openai', 'recraft' );
				$def       = sanitize_key( (string) ( $post['default'] ?? 'openai' ) );
				$values['default'] = in_array( $def, $providers, true ) ? $def : 'openai';
				$fb                 = sanitize_key( (string) ( $post['fallback'] ?? '' ) );
				$values['fallback'] = in_array( $fb, $providers, true ) && $fb !== $values['default'] ? $fb : '';
				$vec                  = sanitize_key( (string) ( $post['vectorizer'] ?? '' ) );
				$values['vectorizer'] = 'recraft' === $vec ? 'recraft' : '';
				$models = (array) AI_Settings::path( 'providers.openai.models', array( 'gpt-image-2' ) );
				$model  = sanitize_text_field( (string) ( $post['providers_openai_model'] ?? '' ) );
				if ( in_array( $model, $models, true ) ) {
					$values['providers.openai.model'] = $model;
				}
				$q = sanitize_key( (string) ( $post['providers_openai_quality'] ?? 'medium' ) );
				$values['providers.openai.quality'] = in_array( $q, array( 'low', 'medium' ), true ) ? $q : 'medium';
				foreach ( array( 'n', 'refineN' ) as $p ) {
					$v = AI_Settings::clamp( $p, $post[ self::field( $p ) ] ?? null );
					if ( null !== $v ) {
						$values[ $p ] = $v;
					}
				}
				$values['moderation.enabled'] = ! empty( $post['moderation_enabled'] );
				break;
			case 'keys':
				foreach ( array_keys( Secrets::CONSTANTS ) as $id ) {
					if ( ! empty( $post[ 'clear_' . $id ] ) ) {
						Secrets::clear( $id );
						$msg = 'key_cleared';
						continue;
					}
					$key = isset( $post[ 'key_' . $id ] ) ? (string) $post[ 'key_' . $id ] : '';
					if ( '' !== trim( $key ) ) {
						$msg = Secrets::set( $id, $key ) ? 'key_saved' : 'key_refused';
					}
				}
				return $msg;
			case 'limits':
				$old = (float) AI_Settings::path( 'usdToEur', 0.93 );
				foreach ( array_keys( self::LIMIT_FIELDS ) as $p ) {
					$v = AI_Settings::clamp( $p, $post[ self::field( $p ) ] ?? null );
					if ( null !== $v ) {
						$values[ $p ] = $v;
					}
				}
				if ( isset( $values['usdToEur'] ) && abs( (float) $values['usdToEur'] - $old ) > 0.00001 ) {
					$values['usdToEurUpdated'] = wp_date( 'Y-m-d' );
				}
				$hard = (float) ( $values['limits.monthlyHardCapEur'] ?? AI_Settings::limit( 'monthlyHardCapEur' ) );
				$mon  = (float) ( $values['limits.monthlyBudgetEur'] ?? AI_Settings::limit( 'monthlyBudgetEur' ) );
				if ( $hard > 0 && $hard < $mon ) {
					$values['limits.monthlyHardCapEur'] = $mon;
					$msg                                = 'cap_raised';
				}
				$email                  = sanitize_email( (string) ( $post['alerts_email'] ?? '' ) );
				$values['alerts.email'] = is_email( $email ) ? $email : '';
				break;
			case 'retention':
				foreach ( self::RETENTION_FIELDS as $key => $f ) {
					$raw = $post[ 'ret_' . $key ] ?? null;
					if ( is_numeric( $raw ) ) {
						$values[ 'retentionDays.' . $key ] = max( (int) $f[1], min( (int) $f[2], (int) $raw ) );
					}
				}
				break;
		}
		if ( $values ) {
			AI_Settings::update( $values );
		}
		return $msg;
	}

	/**
	 * Form field name for a dot path.
	 *
	 * @param string $path Path.
	 */
	private static function field( string $path ): string {
		return str_replace( '.', '_', $path );
	}

	/**
	 * Cheap connection test (no image).
	 */
	public static function connection_test(): string {
		$p = Provider_Registry::get( (string) AI_Settings::path( 'default', 'openai' ) );
		if ( ! $p || ! $p->is_configured() ) {
			return 'test_nokey';
		}
		if ( ! $p instanceof Openai_Provider ) {
			return 'test_skipped';
		}
		$start = microtime( true );
		try {
			$r = $p->test_connection();
			Cost_Log::add(
				array(
					'provider'    => 'openai',
					'model'       => $r['model'],
					'op'          => 'test',
					'cost_usd'    => 0,
					'duration_ms' => $r['ms'],
					'outcome'     => 'ok',
				)
			);
			return $r['ok'] ? 'test_ok' : 'test_fail';
		} catch ( Provider_Error $e ) {
			Module::log( 'provider', 'connection test ' . $e->error_code() . ': ' . $e->getMessage() );
			Cost_Log::add(
				array(
					'provider'    => 'openai',
					'model'       => '',
					'op'          => 'test',
					'cost_usd'    => 0,
					'duration_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
					'outcome'     => 'error',
				)
			);
			return 'auth' === $e->error_code() ? 'test_auth' : 'test_fail';
		}
	}

	/**
	 * Standalone test action (link).
	 */
	public static function handle_test(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'bps_ai_test' );
		wp_safe_redirect( self::url( 'service', array( 'bps_msg' => self::connection_test() ) ) );
		exit;
	}

	/**
	 * CSV download of the cost log.
	 */
	public static function handle_csv(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'bps_ai_csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="patch-studio-ai-kosten-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo Cost_Log::csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download.
		exit;
	}

	/**
	 * "Wis nu verlopen gegevens".
	 */
	public static function handle_purge(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'bps_ai_purge' );
		$r = Cleanup::run();
		set_transient( 'bps_ai_admin_msg_' . get_current_user_id(), sprintf( /* translators: 1: jobs, 2: files */ __( 'Opgeruimd: %1$d opdrachten en %2$d beelden.', 'borduur-patch-studio' ), $r['jobs'], $r['files'] ), 60 );
		wp_safe_redirect( self::url( 'retention', array( 'bps_msg' => 'custom' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Notices and small widgets
	 * ------------------------------------------------------------------- */

	/**
	 * Notice after a redirect.
	 */
	private static function render_notice(): void {
		$code = isset( $_GET['bps_msg'] ) ? sanitize_key( wp_unslash( $_GET['bps_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return;
		}
		$map = array(
			'saved'        => array( 'success', __( 'Bewaard.', 'borduur-patch-studio' ) ),
			'reset'        => array( 'success', __( 'Standaardwaarden teruggezet.', 'borduur-patch-studio' ) ),
			'live'         => array( 'success', __( 'De AI-studio staat live voor je klanten.', 'borduur-patch-studio' ) ),
			'live_nokey'   => array( 'warning', __( 'Live gezet, maar er is nog geen sleutel voor de beeld-AI. Klanten krijgen dan "even niet beschikbaar" (zonder kosten). Vul de sleutel in bij API-sleutels.', 'borduur-patch-studio' ) ),
			'key_saved'    => array( 'success', __( 'Sleutel versleuteld bewaard.', 'borduur-patch-studio' ) ),
			'key_cleared'  => array( 'success', __( 'Sleutel gewist.', 'borduur-patch-studio' ) ),
			'key_refused'  => array( 'error', __( 'Deze sleutel kon niet bewaard worden.', 'borduur-patch-studio' ) ),
			'cap_raised'   => array( 'warning', __( 'Het harde plafond mag niet lager zijn dan het maandbudget; het is gelijkgezet.', 'borduur-patch-studio' ) ),
			'test_ok'      => array( 'success', __( 'Verbinding gelukt. De sleutel werkt.', 'borduur-patch-studio' ) ),
			'test_auth'    => array( 'error', __( 'De sleutel werd geweigerd. Kijk hem na (of het tegoed bij de AI-dienst).', 'borduur-patch-studio' ) ),
			'test_fail'    => array( 'error', __( 'De AI-dienst antwoordde niet goed. Probeer later opnieuw; details staan bij "Laatste meldingen".', 'borduur-patch-studio' ) ),
			'test_nokey'   => array( 'warning', __( 'Er is nog geen sleutel ingesteld.', 'borduur-patch-studio' ) ),
			'test_skipped' => array( 'info', __( 'Voor deze dienst is er geen gratis test; de sleutel wordt bij de eerste opdracht gecontroleerd.', 'borduur-patch-studio' ) ),
		);
		if ( 'custom' === $code ) {
			$text = (string) get_transient( 'bps_ai_admin_msg_' . get_current_user_id() );
			delete_transient( 'bps_ai_admin_msg_' . get_current_user_id() );
			$map['custom'] = array( 'success', '' !== $text ? $text : __( 'Bewaard.', 'borduur-patch-studio' ) );
		}
		if ( isset( $map[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $code ][1] ) . '</p></div>';
		}
	}

	/**
	 * Site-wide notice for managers when the AI studio is fully paused by the hard cap.
	 */
	public static function global_notice(): void {
		if ( 'off' === AI_Settings::mode() || ! current_user_can( self::capability() ) ) {
			return;
		}
		$b = Limits::budget();
		if ( $b['hardCapped'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Patch Studio AI is gepauzeerd: het harde maandplafond is bereikt.', 'borduur-patch-studio' ) . ' <a href="' . esc_url( self::url( 'limits' ) ) . '">' . esc_html__( 'Limieten bekijken', 'borduur-patch-studio' ) . '</a></p></div>';
		}
	}

	/**
	 * Select row.
	 *
	 * @param string $path    Dot path.
	 * @param string $label   Label.
	 * @param array  $options value => label.
	 * @param string $current Current.
	 * @param string $help    Help.
	 */
	private static function select_row( string $path, string $label, array $options, string $current, string $help ): void {
		$name = self::field( $path );
		echo '<tr><th scope="row"><label for="bps-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="bps-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $v => $l ) {
			echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( $current, (string) $v, false ) . '>' . esc_html( (string) $l ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Number row with bounds from AI_Settings::BOUNDS.
	 *
	 * @param string $path  Dot path.
	 * @param string $label Label.
	 * @param string $unit  Unit.
	 * @param mixed  $value Value.
	 * @param string $help  Help.
	 */
	private static function number_row( string $path, string $label, string $unit, $value, string $help ): void {
		$name               = self::field( $path );
		list( $min, $max, $int ) = AI_Settings::BOUNDS[ $path ] ?? array( 0, 100000, false );
		echo '<tr><th scope="row"><label for="bps-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" id="bps-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="' . ( $int ? '1' : '0.01' ) . '" class="small-text"> ' . esc_html( $unit );
		$range = sprintf( /* translators: 1: min, 2: max */ __( 'Tussen %1$s en %2$s.', 'borduur-patch-studio' ), $min, $max );
		echo '<p class="description">' . esc_html( trim( $help . ' ' . $range ) ) . '</p></td></tr>';
	}

	/**
	 * Euro amount.
	 *
	 * @param float $v Amount.
	 */
	private static function eur( float $v ): string {
		return '€ ' . number_format_i18n( $v, 2 );
	}
}
