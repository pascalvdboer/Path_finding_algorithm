<?php
/**
 * Name tapes and the GDPR (premium P03, gdpr-juridisch-1): names of third parties and optional blood groups (a health
 * datum, art. 9 GDPR) on order lines. Exporter, eraser, retention after completion, blood-group consent record and
 * protection of the preview folder.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

/**
 * Data lives on WooCommerce order items: _bps_nt_batch (JSON rows {n, l2, b, q}), bps_nt_blood (visible summary),
 * _bps_nt_preview (PNG file name in uploads/patch-studio/nametape) and _bps_rights_consent. Orders are found by the
 * billing e-mail (HPOS-safe through wc_get_orders).
 *
 * Anonymising a line: names → "[gewist]", line 2 → "[gewist]" when filled, blood group → "", organisation note and
 * review words → empty, preview PNG deleted, bps_nt_blood and _bps_nt_preview removed, _bps_nt_anonymised set.
 * Quantities, format and style stay (bookkeeping, reorders of the style).
 */
final class Privacy {

	const ERASED          = '[gewist]';
	const ANON_META       = '_bps_nt_anonymised';
	const DEFAULT_DAYS    = 180;
	const EXPORT_PAGE     = 20;
	const CRON_MAX_ORDERS = 200;

	/** Version of the blood-group consent text; raise it when the text changes. */
	const BLOOD_CONSENT_VERSION = 'nt-blood-1';

	/** The blood-group consent sentence (single source: sent to the UI, hashed into the stored record). */
	const BLOOD_CONSENT_TEXT = array(
		'nl' => 'Ik heb van elke persoon op deze lijst toestemming om zijn of haar bloedgroep op de naamstrook te laten borduren.',
		'fr' => 'J’ai l’accord de chaque personne de cette liste pour faire broder son groupe sanguin sur la bande nominative.',
	);

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks (registered through Module::register_always(), also when the module is off).
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * @param mixed $list Exporters.
	 */
	public static function exporters( $list ): array {
		$list                                  = is_array( $list ) ? $list : array();
		$list['borduur-patch-studio-nametape'] = array(
			'exporter_friendly_name' => __( 'Patch Studio-naamstroken', 'borduur-patch-studio' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	/**
	 * @param mixed $list Erasers.
	 */
	public static function erasers( $list ): array {
		$list                                  = is_array( $list ) ? $list : array();
		$list['borduur-patch-studio-nametape'] = array(
			'eraser_friendly_name' => __( 'Patch Studio-naamstroken', 'borduur-patch-studio' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/* ---------------------------------------------------------------------
	 * Settings and consent
	 * ------------------------------------------------------------------- */

	/**
	 * Days after completion before a name-tape line is anonymised (nametape retention.daysAfterComplete).
	 */
	public static function retention_days(): int {
		$v = Config::path( 'retention.daysAfterComplete', self::DEFAULT_DAYS );
		return is_numeric( $v ) ? max( 1, min( 3650, (int) $v ) ) : self::DEFAULT_DAYS;
	}

	/**
	 * Consent sentence in a language ('nl' | 'fr' | locale).
	 *
	 * @param string $locale Locale.
	 */
	public static function blood_consent_text( string $locale ): string {
		$lang = 0 === stripos( $locale, 'fr' ) ? 'fr' : 'nl';
		return self::BLOOD_CONSENT_TEXT[ $lang ];
	}

	/**
	 * Data for the public config of the name-tape UI.
	 *
	 * @param string $locale Locale.
	 * @return array{v:string,text:string}
	 */
	public static function blood_consent_public( string $locale ): array {
		return array(
			'v'    => self::BLOOD_CONSENT_VERSION,
			'text' => self::blood_consent_text( $locale ),
		);
	}

	/**
	 * Did the client send an accepted blood-group consent?
	 *
	 * @param mixed $raw bloodConsent from the request ({accepted:true} or true).
	 */
	public static function consent_given( $raw ): bool {
		if ( true === $raw || 'true' === $raw ) {
			return true;
		}
		return is_array( $raw ) && ( true === ( $raw['accepted'] ?? false ) || 'true' === ( $raw['accepted'] ?? '' ) );
	}

	/**
	 * Server-side consent record {v, at, text_sha1, locale} (the version and hash never come from the client).
	 *
	 * @param string $locale Batch locale (nl-BE | fr-BE).
	 */
	public static function consent_record( string $locale ): array {
		return array(
			'v'         => self::BLOOD_CONSENT_VERSION,
			'at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'text_sha1' => sha1( self::blood_consent_text( $locale ) ),
			'locale'    => 0 === stripos( $locale, 'fr' ) ? 'fr_BE' : 'nl_BE',
		);
	}

	/**
	 * Does a validated batch contain a blood group?
	 *
	 * @param array $batch Validated batch (rows[].blood) or cart data (rows[].b).
	 */
	public static function has_blood( array $batch ): bool {
		foreach ( (array) ( $batch['rows'] ?? array() ) as $r ) {
			if ( is_array( $r ) && ( ! empty( $r['blood'] ) || '' !== (string) ( $r['b'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Preview folder
	 * ------------------------------------------------------------------- */

	/**
	 * Protection files for uploads/patch-studio/nametape (same idea as Uploads::ensure_dirs for previews/):
	 * no listing, PNG only, noindex.
	 *
	 * @param string $dir Absolute dir without trailing slash.
	 */
	public static function protect_dir( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$htaccess = "# Patch Studio - name-tape previews (PNG only, not indexed).\n"
			. "Options -Indexes -ExecCGI\n"
			. "<FilesMatch \"\\.(?!png$)[^.]+$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n  </IfModule>\n</FilesMatch>\n"
			. "<IfModule mod_headers.c>\n  Header set X-Robots-Tag \"noindex, nofollow\"\n  Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n";
		$files = array(
			$dir . '/.htaccess' => $htaccess,
			$dir . '/index.php' => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $file => $content ) {
			if ( is_readable( $file ) && (string) file_get_contents( $file ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				continue;
			}
			file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Absolute path of a preview file name, or '' when the name is not ours.
	 *
	 * @param string $name File name.
	 */
	public static function preview_path( string $name ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}\.png$/', $name ) || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) ) {
			return '';
		}
		return trailingslashit( $up['basedir'] ) . Rest::PREVIEW_DIR . '/' . $name;
	}

	/* ---------------------------------------------------------------------
	 * Order lines
	 * ------------------------------------------------------------------- */

	/**
	 * Raw name-tape data of an order item, or null.
	 *
	 * @param mixed $item Order item.
	 */
	public static function data( $item ): ?array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return null;
		}
		$raw = (string) $item->get_meta( '_bps_nt_batch' );
		if ( '' === $raw ) {
			return null;
		}
		$nt = json_decode( $raw, true );
		return is_array( $nt ) ? $nt : null;
	}

	/**
	 * Is the order finished (files and names no longer needed to fulfil it)?
	 *
	 * @param mixed $order Order.
	 */
	public static function order_finished( $order ): bool {
		$cls = '\BorduurPatchStudio\Woo\Order_Lifecycle';
		if ( class_exists( $cls ) ) {
			return (bool) call_user_func( array( $cls, 'is_finished' ), $order );
		}
		return is_object( $order ) && method_exists( $order, 'get_status' ) && in_array( (string) $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed' ), true );
	}

	/**
	 * Reference date for the retention period: completion date, else last change, else creation.
	 *
	 * @param mixed $order Order.
	 */
	public static function finished_at( $order ): int {
		foreach ( array( 'get_date_completed', 'get_date_modified', 'get_date_created' ) as $m ) {
			if ( is_object( $order ) && method_exists( $order, $m ) ) {
				$d = $order->$m();
				if ( $d && method_exists( $d, 'getTimestamp' ) ) {
					return (int) $d->getTimestamp();
				}
			}
		}
		return 0;
	}

	/**
	 * Anonymise one name-tape order line. Returns true when something changed.
	 *
	 * @param mixed $item Order item.
	 */
	public static function anonymise_item( $item ): bool {
		$nt = self::data( $item );
		if ( null === $nt || $item->get_meta( self::ANON_META ) ) {
			return false;
		}
		$rows = array();
		foreach ( (array) ( $nt['rows'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$r['n']  = '' !== (string) ( $r['n'] ?? '' ) ? self::ERASED : '';
			$r['l2'] = '' !== (string) ( $r['l2'] ?? '' ) ? self::ERASED : '';
			$r['b']  = '';
			$rows[]  = $r;
		}
		$file               = (string) $item->get_meta( '_bps_nt_preview' );
		$file               = '' !== $file ? $file : (string) ( $nt['preview_file'] ?? '' );
		$nt['rows']         = $rows;
		$nt['org_note']     = '';
		$nt['review_words'] = array();
		$nt['preview_url']  = '';
		$nt['preview_file'] = '';
		$nt['anonymised']   = gmdate( 'Y-m-d\TH:i:s\Z' );

		$path = self::preview_path( $file );
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
		$item->update_meta_data( '_bps_nt_batch', wp_json_encode( $nt ) );
		$item->delete_meta_data( 'bps_nt_blood' );
		$item->delete_meta_data( 'bps_nt_names' );
		$item->delete_meta_data( '_bps_nt_preview' );
		$item->update_meta_data( self::ANON_META, gmdate( 'Y-m-d H:i:s' ) );
		$item->save();
		do_action( 'bps_nametape_anonymised', $item );
		return true;
	}

	/**
	 * Name-tape lines of an order.
	 *
	 * @param mixed $order Order.
	 * @return array<int, object>
	 */
	public static function lines( $order ): array {
		$out = array();
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}
		foreach ( $order->get_items() as $id => $item ) {
			if ( null !== self::data( $item ) ) {
				$out[ (int) $id ] = $item;
			}
		}
		return $out;
	}

	/**
	 * One page of orders of a billing e-mail (stable: sorted by id).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	private static function orders_page( string $email, int $page ): array {
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::EXPORT_PAGE,
				'page'          => max( 1, $page ),
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'return'        => 'objects',
				'type'          => 'shop_order',
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	/* ---------------------------------------------------------------------
	 * Exporter / eraser
	 * ------------------------------------------------------------------- */

	/**
	 * Exporter: one item per name-tape order line (names, line 2, blood group, organisation, consents).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function export( $email, $page = 1 ): array {
		$email  = sanitize_email( (string) $email );
		$orders = self::orders_page( $email, (int) $page );
		$data   = array();
		foreach ( $orders as $order ) {
			foreach ( self::lines( $order ) as $item_id => $item ) {
				$nt    = (array) self::data( $item );
				$names = array();
				foreach ( (array) ( $nt['rows'] ?? array() ) as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$names[] = trim( implode( ' / ', array_filter( array( (string) ( $r['n'] ?? '' ), (string) ( $r['l2'] ?? '' ), (string) ( $r['b'] ?? '' ) ), 'strlen' ) ) . ( (int) ( $r['q'] ?? 1 ) > 1 ? ' (' . (int) $r['q'] . '×)' : '' ) );
				}
				$rights  = json_decode( (string) $item->get_meta( '_bps_rights_consent' ), true );
				$blood   = isset( $nt['bloodConsent'] ) && is_array( $nt['bloodConsent'] ) ? $nt['bloodConsent'] : null;
				$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
				$rows    = array(
					array( 'name' => __( 'Bestelling', 'borduur-patch-studio' ), 'value' => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order->get_id() ),
					array( 'name' => __( 'Datum', 'borduur-patch-studio' ), 'value' => $created ? $created->date( 'Y-m-d H:i' ) : '' ),
					array( 'name' => __( 'Namen (naam / regel 2 / bloedgroep)', 'borduur-patch-studio' ), 'value' => implode( "\n", $names ) ),
					array( 'name' => __( 'Organisatie', 'borduur-patch-studio' ), 'value' => (string) ( $nt['org_note'] ?? '' ) ),
					array( 'name' => __( 'Verklaring over rechten', 'borduur-patch-studio' ), 'value' => is_array( $rights ) ? trim( (string) ( $rights['at'] ?? '' ) . ' · ' . (string) ( $rights['v'] ?? '' ), ' ·' ) : '' ),
					array( 'name' => __( 'Toestemming bloedgroep', 'borduur-patch-studio' ), 'value' => $blood ? trim( (string) ( $blood['at'] ?? '' ) . ' · ' . (string) ( $blood['v'] ?? '' ), ' ·' ) : '' ),
					array( 'name' => __( 'Gewist op', 'borduur-patch-studio' ), 'value' => (string) $item->get_meta( self::ANON_META ) ),
				);
				$data[] = array(
					'group_id'    => 'bps-nametape',
					'group_label' => __( 'Patch Studio-naamstroken', 'borduur-patch-studio' ),
					'item_id'     => 'bps-nt-' . (int) $item_id,
					'data'        => array_values( array_filter( $rows, static fn( $row ) => '' !== (string) $row['value'] ) ),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $orders ) < self::EXPORT_PAGE,
		);
	}

	/**
	 * Eraser: anonymises name-tape lines of finished orders; lines of running orders are retained with a message.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function erase( $email, $page = 1 ): array {
		$email    = sanitize_email( (string) $email );
		$orders   = self::orders_page( $email, (int) $page );
		$removed  = false;
		$retained = false;
		$messages = array();
		foreach ( $orders as $order ) {
			$lines = self::lines( $order );
			if ( ! $lines ) {
				continue;
			}
			$finished = self::order_finished( $order );
			foreach ( $lines as $item ) {
				if ( $item->get_meta( self::ANON_META ) ) {
					continue;
				}
				if ( ! $finished ) {
					$retained   = true;
					$messages[] = sprintf(
						/* translators: %s: order number */
						__( 'De naamstroken van bestelling %s worden nog gemaakt of geleverd. We bewaren de namen tot de bestelling afgerond is en wissen ze daarna.', 'borduur-patch-studio' ),
						method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order->get_id()
					);
					break;
				}
				try {
					$removed = self::anonymise_item( $item ) || $removed;
				} catch ( \Throwable $e ) {
					self::log( 'nametape_erase_failed', array( 'exception' => $e ) );
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::EXPORT_PAGE,
		);
	}

	/* ---------------------------------------------------------------------
	 * Retention (cron bps_nt_cleanup, through Order_Tools::cleanup)
	 * ------------------------------------------------------------------- */

	/**
	 * Order ids with name-tape lines that are not anonymised yet, after an id (ascending).
	 * Filter `bps_nt_retention_candidates` may return the list itself (other storage, tests).
	 *
	 * @param int $after Last id seen.
	 * @param int $limit Max ids.
	 * @return int[]
	 */
	public static function candidates( int $after, int $limit ): array {
		$pre = apply_filters( 'bps_nt_retention_candidates', null, $after, $limit );
		if ( is_array( $pre ) ) {
			return array_map( 'intval', $pre );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$items} oi INNER JOIN {$meta} m ON m.order_item_id = oi.order_item_id AND m.meta_key = '_bps_nt_batch' LEFT JOIN {$meta} a ON a.order_item_id = oi.order_item_id AND a.meta_key = %s WHERE a.meta_id IS NULL AND oi.order_id > %d ORDER BY oi.order_id ASC LIMIT %d", self::ANON_META, $after, $limit ) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Anonymise name-tape lines of orders finished longer than retention_days() ago. Returns the number of lines.
	 */
	public static function run_retention(): int {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$cutoff = time() - self::retention_days() * DAY_IN_SECONDS;
		$done   = 0;
		$after  = 0;
		try {
			for ( $round = 0; $round < 10 && $done < self::CRON_MAX_ORDERS; $round++ ) {
				$ids = self::candidates( $after, self::CRON_MAX_ORDERS );
				if ( ! $ids ) {
					break;
				}
				foreach ( $ids as $oid ) {
					$after = max( $after, $oid );
					$order = wc_get_order( $oid );
					if ( ! $order || ! self::order_finished( $order ) ) {
						continue;
					}
					$at = self::finished_at( $order );
					if ( $at <= 0 || $at > $cutoff ) {
						continue;
					}
					foreach ( self::lines( $order ) as $item ) {
						if ( self::anonymise_item( $item ) ) {
							++$done;
						}
					}
				}
				if ( count( $ids ) < self::CRON_MAX_ORDERS ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'nametape_retention_failed', array( 'exception' => $e ) );
		}
		return $done;
	}

	/**
	 * Log through the plugin Logger (contract C1) when available.
	 *
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	private static function log( string $event, array $context = array() ): void {
		if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
			\BorduurPatchStudio\Logger::error( $event, $context );
		}
	}
}
