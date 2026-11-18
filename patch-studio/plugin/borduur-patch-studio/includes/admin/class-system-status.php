<?php
/**
 * Support data for Site Health and the WooCommerce system status report (productisering-8).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Admin;

use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Plugin;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One source, data(), feeds three places:
 * 1. Tools > Site Health > Info: section "Patch Studio" (debug_information);
 * 2. Site Health tests: uploads protected, cron running, upload limit vs. woo.maxUploadMb;
 * 3. WooCommerce > Status: table "Patch Studio" (and so "### Patch Studio ###" in "Get system report").
 *
 * Never contains secrets or personal data: the AI key and the licence key appear only as "ja/nee".
 */
final class System_Status {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_status_tests' ) );
		add_action( 'woocommerce_system_status_report', array( __CLASS__, 'wc_report' ) );
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------- */

	/**
	 * Support data: key => {label, value (string), raw (scalar for tests)}.
	 *
	 * @return array<string, array{label:string, value:string, raw:mixed}>
	 */
	public static function data(): array {
		$yes  = __( 'ja', 'borduur-patch-studio' );
		$no   = __( 'nee', 'borduur-patch-studio' );
		$rows = array();

		$add = static function ( string $key, string $label, $raw, ?string $value = null ) use ( &$rows ): void {
			$rows[ $key ] = array(
				'label' => $label,
				'value' => null === $value ? (string) $raw : $value,
				'raw'   => $raw,
			);
		};

		$add( 'version', __( 'Pluginversie', 'borduur-patch-studio' ), defined( 'BPS_VERSION' ) ? BPS_VERSION : '' );
		$add( 'stored_version', __( 'Opgeslagen versie (upgrades)', 'borduur-patch-studio' ), (string) get_option( Plugin::VERSION_OPTION, '' ) );
		$add( 'db_version', __( 'Databaseversie', 'borduur-patch-studio' ), (int) get_option( 'bps_db_version', 0 ) );
		$add( 'ai_db_version', __( 'Databaseversie AI', 'borduur-patch-studio' ), (int) get_option( 'bps_ai_db_version', 0 ) );

		$wc = class_exists( 'BorduurPatchStudio\Woo\Integration' ) ? \BorduurPatchStudio\Woo\Integration::wc_version() : '';
		$add(
			'woocommerce',
			__( 'WooCommerce', 'borduur-patch-studio' ),
			$wc,
			'' === $wc ? __( 'niet actief', 'borduur-patch-studio' ) : ( Plugin::woocommerce_supported() ? $wc : sprintf( /* translators: %s: version */ __( '%s (te oud)', 'borduur-patch-studio' ), $wc ) )
		);

		$active = array();
		foreach ( Plugin::module_states() as $id => $on ) {
			if ( $on ) {
				$active[] = $id;
			}
		}
		$add( 'modules', __( 'Actieve modules', 'borduur-patch-studio' ), implode( ',', $active ), $active ? implode( ', ', $active ) : __( 'geen', 'borduur-patch-studio' ) );

		$mode = '';
		$key  = false;
		if ( class_exists( 'BorduurPatchStudio\AI\AI_Settings' ) ) {
			try {
				$mode = (string) \BorduurPatchStudio\AI\AI_Settings::mode();
				$key  = \BorduurPatchStudio\AI\Secrets::has( 'openai' ) || \BorduurPatchStudio\AI\Secrets::has( 'recraft' );
			} catch ( \Throwable $e ) {
				Logger::warning( 'status_ai_unreadable', array( 'exception' => $e ) );
			}
		}
		$add( 'ai_mode', __( 'AI-modus', 'borduur-patch-studio' ), $mode, '' === $mode ? '–' : $mode );
		$add( 'ai_key', __( 'API-sleutel', 'borduur-patch-studio' ), $key, $key ? $yes : $no );

		$licence = class_exists( 'BorduurPatchStudio\Licensing\Client' ) ? \BorduurPatchStudio\Licensing\Client::data() : array(
			'key'    => '',
			'status' => '',
		);
		$add( 'licence', __( 'Licentiesleutel', 'borduur-patch-studio' ), '' !== $licence['key'], '' !== $licence['key'] ? $yes . ( '' !== $licence['status'] ? ' (' . $licence['status'] . ')' : '' ) : $no );

		$next = wp_next_scheduled( 'bps_cleanup' );
		$add( 'cleanup_next', __( 'Volgende opruimbeurt', 'borduur-patch-studio' ), (int) $next, $next ? gmdate( 'Y-m-d H:i', (int) $next ) . ' UTC' : __( 'niet gepland', 'borduur-patch-studio' ) );
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$add( 'wp_cron_disabled', 'DISABLE_WP_CRON', $cron_off, $cron_off ? $yes : $no );

		$as = self::action_scheduler_counts( 'bps-ai' );
		$add( 'as_pending', __( 'Wachtende AI-taken', 'borduur-patch-studio' ), $as['pending'], null === $as['pending'] ? '–' : (string) $as['pending'] );
		$add( 'as_failed', __( 'Mislukte AI-taken', 'borduur-patch-studio' ), $as['failed'], null === $as['failed'] ? '–' : (string) $as['failed'] );

		$limits = self::upload_limits();
		$add( 'upload_max_filesize', 'upload_max_filesize', $limits['upload_max_filesize'], size_format( $limits['upload_max_filesize'] ) );
		$add( 'post_max_size', 'post_max_size', $limits['post_max_size'], size_format( $limits['post_max_size'] ) );
		$add( 'max_upload_setting', __( 'Max. upload (instelling)', 'borduur-patch-studio' ), $limits['setting'], size_format( $limits['setting'] ) );

		$add( 'gd', 'GD', extension_loaded( 'gd' ), extension_loaded( 'gd' ) ? $yes : $no );
		$add( 'imagick', 'Imagick', extension_loaded( 'imagick' ), extension_loaded( 'imagick' ) ? $yes : $no );
		$add( 'sodium', 'Sodium', function_exists( 'sodium_crypto_sign_verify_detached' ), function_exists( 'sodium_crypto_sign_verify_detached' ) ? $yes : $no );

		$self = get_transient( 'bps_uploads_selftest' );
		$prot = is_array( $self ) ? ( ! empty( $self['ok'] ) ? $yes : $no ) : __( 'nog niet getest', 'borduur-patch-studio' );
		$add( 'uploads_protected', __( 'Uploads beschermd', 'borduur-patch-studio' ), is_array( $self ) ? ! empty( $self['ok'] ) : null, $prot );

		$add( 'log_level', __( 'Logniveau', 'borduur-patch-studio' ), Logger::threshold() );
		$missing = Plugin::missing_components();
		$add( 'missing', __( 'Ontbrekende onderdelen', 'borduur-patch-studio' ), implode( ',', $missing ), $missing ? implode( ', ', $missing ) : __( 'geen', 'borduur-patch-studio' ) );

		/**
		 * Extra support rows. Never add secrets or personal data.
		 *
		 * @param array $rows Rows.
		 */
		$rows = apply_filters( 'bps_system_status_data', $rows );
		return self::scrub( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Last line of defence: drop anything that looks like a secret or an e-mail address.
	 *
	 * @param array $rows Rows.
	 */
	private static function scrub( array $rows ): array {
		foreach ( $rows as $k => $row ) {
			if ( ! is_array( $row ) ) {
				unset( $rows[ $k ] );
				continue;
			}
			$value               = Logger::redact( (string) ( $row['value'] ?? '' ) );
			$rows[ $k ]['value'] = $value;
			$rows[ $k ]['label'] = (string) ( $row['label'] ?? $k );
		}
		return $rows;
	}

	/**
	 * Pending/failed Action Scheduler actions of a group (null when Action Scheduler is not available).
	 *
	 * @param string $group Group.
	 * @return array{pending:int|null, failed:int|null}
	 */
	public static function action_scheduler_counts( string $group ): array {
		$out = array(
			'pending' => null,
			'failed'  => null,
		);
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return $out;
		}
		try {
			foreach ( array(
				'pending' => 'pending',
				'failed'  => 'failed',
			) as $key => $status ) {
				$ids         = as_get_scheduled_actions(
					array(
						'group'    => $group,
						'status'   => $status,
						'per_page' => 100,
					),
					'ids'
				);
				$out[ $key ] = is_array( $ids ) ? count( $ids ) : 0;
			}
		} catch ( \Throwable $e ) {
			Logger::warning( 'status_action_scheduler_failed', array( 'exception' => $e ) );
		}
		return $out;
	}

	/**
	 * PHP upload limits and the configured maximum, in bytes.
	 *
	 * @return array{upload_max_filesize:int, post_max_size:int, setting:int}
	 */
	public static function upload_limits(): array {
		$mb = 25.0;
		try {
			$mb = (float) Settings::get_path( 'woo.maxUploadMb', 25 );
		} catch ( \Throwable $e ) {
			Logger::warning( 'status_settings_unreadable', array( 'exception' => $e ) );
		}
		return array(
			'upload_max_filesize' => (int) wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			'post_max_size'       => (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ),
			'setting'             => (int) round( max( 1, $mb ) * MB_IN_BYTES ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Site Health
	 * ------------------------------------------------------------------- */

	/**
	 * Section "Patch Studio" in Site Health > Info.
	 *
	 * @param mixed $info Sections.
	 * @return array
	 */
	public static function debug_information( $info ): array {
		$info   = is_array( $info ) ? $info : array();
		$fields = array();
		foreach ( self::data() as $key => $row ) {
			$fields[ $key ] = array(
				'label' => $row['label'],
				'value' => $row['value'],
				'debug' => is_bool( $row['raw'] ) ? ( $row['raw'] ? 'yes' : 'no' ) : $row['value'],
			);
		}
		$info['borduur-patch-studio'] = array(
			'label'  => 'Patch Studio',
			'fields' => $fields,
		);
		return $info;
	}

	/**
	 * Register the Site Health tests.
	 *
	 * @param mixed $tests Tests.
	 * @return array
	 */
	public static function site_status_tests( $tests ): array {
		$tests           = is_array( $tests ) ? $tests : array();
		$tests['direct'] = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();
		foreach ( array( 'uploads_protected', 'cron_running', 'upload_limit' ) as $id ) {
			$tests['direct'][ 'bps_' . $id ] = array(
				'label' => 'Patch Studio',
				'test'  => array( __CLASS__, 'test_' . $id ),
			);
		}
		return $tests;
	}

	/**
	 * Common shape of a Site Health result.
	 *
	 * @param string $id          Test id.
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Title.
	 * @param string $description Explanation (plain text).
	 */
	private static function result( string $id, string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => 'Patch Studio',
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( Plugin::settings_url() ) . '">' . esc_html__( 'Naar Patch Studio', 'borduur-patch-studio' ) . '</a></p>',
			'test'        => 'bps_' . $id,
		);
	}

	/**
	 * Uploaded originals are not publicly reachable (uses the cached self-test; never an extra request here).
	 */
	public static function test_uploads_protected(): array {
		$self = get_transient( 'bps_uploads_selftest' );
		if ( ! is_array( $self ) ) {
			return self::result( 'uploads_protected', 'good', __( 'Patch Studio: bescherming van uploads nog niet getest', 'borduur-patch-studio' ), __( 'De test draait vanzelf zodra je de instellingen van Patch Studio opent.', 'borduur-patch-studio' ) );
		}
		if ( ! empty( $self['ok'] ) ) {
			return self::result( 'uploads_protected', 'good', __( 'Patch Studio: geüploade bestanden zijn beschermd', 'borduur-patch-studio' ), __( 'Originele bestanden van klanten zijn niet publiek bereikbaar.', 'borduur-patch-studio' ) );
		}
		return self::result( 'uploads_protected', 'critical', __( 'Patch Studio: geüploade bestanden zijn mogelijk publiek bereikbaar', 'borduur-patch-studio' ), (string) ( $self['message'] ?? '' ) );
	}

	/**
	 * The daily cleanup runs (retention periods depend on it).
	 */
	public static function test_cron_running(): array {
		$next = wp_next_scheduled( 'bps_cleanup' );
		if ( ! $next ) {
			if ( ! Plugin::woocommerce_supported() ) {
				return self::result( 'cron_running', 'good', __( 'Patch Studio: opruimen niet nodig zonder WooCommerce', 'borduur-patch-studio' ), __( 'Zonder WooCommerce bewaart Patch Studio geen ontwerpen.', 'borduur-patch-studio' ) );
			}
			return self::result( 'cron_running', 'recommended', __( 'Patch Studio: de dagelijkse opruimbeurt is niet gepland', 'borduur-patch-studio' ), __( 'Oude ontwerpen worden niet automatisch verwijderd. Deactiveer en activeer Patch Studio om de taak opnieuw te plannen.', 'borduur-patch-studio' ) );
		}
		if ( (int) $next < time() - 6 * HOUR_IN_SECONDS ) {
			$hint = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
				? __( 'DISABLE_WP_CRON staat aan: controleer of je hosting wp-cron.php echt regelmatig uitvoert.', 'borduur-patch-studio' )
				: __( 'WP-Cron draait niet: geplande taken lopen meer dan 6 uur achter. Vraag je hostingpartij om een echte cron-taak.', 'borduur-patch-studio' );
			return self::result( 'cron_running', 'recommended', __( 'Patch Studio: geplande taken lopen achter', 'borduur-patch-studio' ), $hint );
		}
		return self::result( 'cron_running', 'good', __( 'Patch Studio: de dagelijkse opruimbeurt is gepland', 'borduur-patch-studio' ), __( 'Bewaartermijnen worden automatisch toegepast.', 'borduur-patch-studio' ) );
	}

	/**
	 * PHP accepts uploads as large as woo.maxUploadMb.
	 */
	public static function test_upload_limit(): array {
		$l   = self::upload_limits();
		$php = min( $l['upload_max_filesize'] > 0 ? $l['upload_max_filesize'] : PHP_INT_MAX, $l['post_max_size'] > 0 ? $l['post_max_size'] : PHP_INT_MAX );
		if ( $php >= $l['setting'] ) {
			return self::result( 'upload_limit', 'good', __( 'Patch Studio: de server aanvaardt grote genoeg bestanden', 'borduur-patch-studio' ), __( 'De uploadlimiet van PHP is minstens zo groot als de maximale bestandsgrootte in Patch Studio.', 'borduur-patch-studio' ) );
		}
		return self::result(
			'upload_limit',
			'recommended',
			__( 'Patch Studio: de server aanvaardt kleinere bestanden dan ingesteld', 'borduur-patch-studio' ),
			sprintf(
				/* translators: 1: PHP limit, 2: configured maximum */
				__( 'PHP aanvaardt maximaal %1$s per upload, maar Patch Studio staat ingesteld op %2$s. Vraag je hostingpartij om upload_max_filesize en post_max_size te verhogen, of verlaag de maximale bestandsgrootte.', 'borduur-patch-studio' ),
				size_format( $php ),
				size_format( $l['setting'] )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce system status report
	 * ------------------------------------------------------------------- */

	/**
	 * Table "Patch Studio" on WooCommerce > Status (the copy text gets "### Patch Studio ###").
	 */
	public static function wc_report(): void {
		echo '<table class="wc_status_table widefat" cellspacing="0" id="status-patch-studio"><thead><tr>';
		echo '<th colspan="3" data-export-label="Patch Studio"><h2>Patch Studio</h2></th>';
		echo '</tr></thead><tbody>';
		foreach ( self::data() as $row ) {
			echo '<tr><td data-export-label="' . esc_attr( $row['label'] ) . '">' . esc_html( $row['label'] ) . ':</td>';
			echo '<td class="help">&nbsp;</td><td>' . esc_html( $row['value'] ) . '</td></tr>';
		}
		$logs = Logger::logs_url();
		if ( '' !== $logs ) {
			echo '<tr><td data-export-label="Logs">' . esc_html__( 'Logbestanden', 'borduur-patch-studio' ) . ':</td><td class="help">&nbsp;</td><td><a href="' . esc_url( $logs ) . '">' . esc_html( Logger::SOURCE ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}
}
