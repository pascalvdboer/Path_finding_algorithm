<?php
/**
 * Daily retention cleanup (SPEC §6.1, research 08 §2.3, premium P03).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Buckets (all periods come from the `retention` settings, with safe fallbacks):
 *
 * draft/carted    → deleted after retention.orphanDays since creation (a cancelled/failed order resets the
 *                   creation time when it releases its designs, see Order_Lifecycle).
 * saved (guest)   → deleted after retention.savedDays since the last opening of the link (post_author = 0).
 * saved (account) → deleted after retention.accountSavedDays since the last opening (post_author > 0).
 * quoted          → design and files deleted after retention.quoteDays since creation.
 * quotes          → contact data of quote requests older than retention.quoteDays without a linked order is
 *                   anonymised (Quotes::anonymise); the quote post stays as an anonymous lead.
 * ordered         → original removed after retention.orderYears since the last order. With
 *                   retention.keepPreviewOnExpiry (default on) a small preview (≤ 300 px, ≤ 30 kB) is kept so the
 *                   order line and "My account" still show what was made. Personal data on the design post
 *                   (e-mail, page URL, notes, proof notes) goes too; the order line gets a visible note.
 */
final class Cleanup {

	const HOOK  = 'bps_cleanup';
	const BATCH = 50;

	/** Kept preview after expiry: longest side and byte budget. */
	const THUMB_MAX_PX    = 300;
	const THUMB_MAX_BYTES = 30720;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/**
	 * Activation.
	 */
	public static function activate(): void {
		self::schedule();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK, array( 1 ) );
	}

	/**
	 * Schedule daily at ~03:17 site time if missing.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$tz    = wp_timezone();
		$first = new \DateTimeImmutable( 'tomorrow 03:17', $tz );
		wp_schedule_event( $first->getTimestamp(), 'daily', self::HOOK );
	}

	/**
	 * Retention settings with fallbacks (P08 adds accountSavedDays/keepPreviewOnExpiry to defaults.json).
	 *
	 * @return array{orphanDays:int,savedDays:int,accountSavedDays:int,quoteDays:int,orderYears:int,keepPreviewOnExpiry:bool}
	 */
	public static function settings(): array {
		$r = (array) Config::path( 'retention', array() );
		return array(
			'orphanDays'          => max( 1, (int) ( $r['orphanDays'] ?? 30 ) ),
			'savedDays'           => max( 1, (int) ( $r['savedDays'] ?? 90 ) ),
			'accountSavedDays'    => max( 1, (int) ( $r['accountSavedDays'] ?? 730 ) ),
			'quoteDays'           => max( 1, (int) ( $r['quoteDays'] ?? 365 ) ),
			'orderYears'          => max( 1, (int) ( $r['orderYears'] ?? 3 ) ),
			'keepPreviewOnExpiry' => ! array_key_exists( 'keepPreviewOnExpiry', $r ) || ! empty( $r['keepPreviewOnExpiry'] ),
		);
	}

	/**
	 * Design ids by meta query and optional author filter, oldest id first.
	 *
	 * @param array      $meta_query Meta query.
	 * @param int        $limit      Max ids (-1 = all).
	 * @param array|null $author     ['in' => int[]] or ['not_in' => int[]].
	 * @return int[]
	 */
	private static function design_ids( array $meta_query, int $limit, ?array $author = null ): array {
		$args = array(
			'post_type'              => Designs::CPT,
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'posts_per_page'         => $limit,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		);
		if ( isset( $author['in'] ) ) {
			$args['author__in'] = array_map( 'intval', (array) $author['in'] );
		}
		if ( isset( $author['not_in'] ) ) {
			$args['author__not_in'] = array_map( 'intval', (array) $author['not_in'] );
		}
		$q = new \WP_Query( $args );
		return array_map( 'intval', (array) $q->posts );
	}

	/**
	 * Run (also callable manually). Returns counts per bucket.
	 *
	 * @param int $continuation 1 when this is a follow-up batch.
	 * @return array<string,int>
	 */
	public static function run( $continuation = 0 ): array {
		unset( $continuation );
		$s      = self::settings();
		$now    = time();
		$counts = array(
			'orphan'  => 0,
			'saved'   => 0,
			'quoted'  => 0,
			'quotes'  => 0,
			'ordered' => 0,
		);
		$before = static fn( int $days ): string => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );

		try {
			// Orphans: never ordered, quoted or saved.
			$ids = self::design_ids(
				array(
					'relation' => 'AND',
					array(
						'key'     => '_bps_status',
						'value'   => array( 'draft', 'carted' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_bps_created_gmt',
						'value'   => $before( $s['orphanDays'] ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
				self::BATCH
			);
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true ); // Files go via Designs::on_delete_post.
				++$counts['orphan'];
			}

			// Saved designs, counted from the last opening: guests (no account) and account designs separately.
			foreach ( array(
				array( 'in' => array( 0 ), 'days' => $s['savedDays'] ),
				array( 'not_in' => array( 0 ), 'days' => $s['accountSavedDays'] ),
			) as $bucket ) {
				$days = (int) $bucket['days'];
				unset( $bucket['days'] );
				$ids = self::design_ids(
					array(
						'relation' => 'AND',
						array(
							'key'   => '_bps_status',
							'value' => 'saved',
						),
						array(
							'key'     => '_bps_last_opened_gmt',
							'value'   => $before( $days ),
							'compare' => '<',
							'type'    => 'DATETIME',
						),
					),
					self::BATCH,
					$bucket
				);
				foreach ( $ids as $id ) {
					wp_delete_post( $id, true );
					++$counts['saved'];
				}
			}

			// Quoted designs: design and files go after quoteDays.
			$ids = self::design_ids(
				array(
					'relation' => 'AND',
					array(
						'key'   => '_bps_status',
						'value' => 'quoted',
					),
					array(
						'key'     => '_bps_created_gmt',
						'value'   => $before( $s['quoteDays'] ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
				self::BATCH
			);
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
				++$counts['quoted'];
			}

			// Quote requests: contact data goes after quoteDays unless the quote became an order.
			foreach ( self::expired_quote_ids( $before( $s['quoteDays'] ), self::BATCH ) as $qid ) {
				if ( self::anonymise_quote( $qid ) ) {
					++$counts['quotes'];
				}
			}

			// Ordered designs: original (and personal data) after N years since the (last) order.
			$ids = self::design_ids(
				array(
					'relation' => 'AND',
					array(
						'key'   => '_bps_status',
						'value' => 'ordered',
					),
					array(
						'key'     => '_bps_ordered_gmt',
						'value'   => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $s['orderYears'] . ' years', $now ) ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => '_bps_files_removed_gmt',
						'compare' => 'NOT EXISTS',
					),
				),
				self::BATCH
			);
			foreach ( $ids as $id ) {
				self::expire_ordered( $id );
				++$counts['ordered'];
			}
		} catch ( \Throwable $e ) {
			self::log( 'cleanup_failed', array( 'exception' => $e ) );
		}

		do_action( 'bps_cleanup_done', $counts );
		// More left? Continue in a few minutes instead of one long request.
		if ( max( $counts ) >= self::BATCH && ! wp_next_scheduled( self::HOOK, array( 1 ) ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, array( 1 ) );
		}
		return $counts;
	}

	/**
	 * Quote posts older than $before (post date, GMT) that still hold contact data and have no linked order.
	 *
	 * @param string $before Datetime UTC.
	 * @param int    $limit  Max ids.
	 * @return int[]
	 */
	public static function expired_quote_ids( string $before, int $limit ): array {
		$q = new \WP_Query(
			array(
				'post_type'              => Quotes::CPT,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'date_query'             => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $before,
					),
				),
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => '_bps_anonymised_gmt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_bps_order_id',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => '_bps_order_id',
							'value' => array( '', '0' ),
							'compare' => 'IN',
						),
					),
				),
			)
		);
		return array_map( 'intval', (array) $q->posts );
	}

	/**
	 * Anonymise one quote request: Quotes::anonymise() (P07) when available, otherwise the same steps inline.
	 * Shared by the cron and the privacy eraser so both do exactly the same.
	 *
	 * @param int $id Quote id.
	 */
	public static function anonymise_quote( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		if ( is_callable( array( Quotes::class, 'anonymise' ) ) ) {
			$res = Quotes::anonymise( $id );
			if ( ! get_post_meta( $id, '_bps_anonymised_gmt', true ) ) {
				update_post_meta( $id, '_bps_anonymised_gmt', gmdate( 'Y-m-d H:i:s' ) );
			}
			return false !== $res;
		}
		// Fallback until Quotes::anonymise() exists (same behaviour as the former inline eraser code).
		foreach ( Quotes::CONTACT_META as $key ) {
			delete_post_meta( $id, $key );
		}
		update_post_meta( $id, '_bps_anonymised_gmt', gmdate( 'Y-m-d H:i:s' ) );
		return true;
	}

	/**
	 * Expire an ordered design: remove the original (and the full preview, keeping a small one when the setting is
	 * on), wipe the personal data on the design post and leave a note on the order line.
	 *
	 * @param int       $id           Design id.
	 * @param bool|null $keep_preview Keep a small preview; null = retention.keepPreviewOnExpiry.
	 */
	public static function expire_ordered( int $id, ?bool $keep_preview = null ): void {
		$keep  = null === $keep_preview ? self::settings()['keepPreviewOnExpiry'] : $keep_preview;
		$thumb = null;
		if ( $keep ) {
			$preview = get_post_meta( $id, '_bps_preview', true );
			$src     = is_array( $preview ) ? (string) ( $preview['path'] ?? '' ) : '';
			$thumb   = '' !== $src ? self::small_preview( $src ) : null;
		}
		Designs::delete_files( $id, false ); // Original + full preview.
		if ( $thumb ) {
			update_post_meta( $id, '_bps_preview', $thumb );
		}
		self::scrub_personal_data( $id );

		$order_id = (int) get_post_meta( $id, '_bps_order_id', true );
		$item_id  = (int) get_post_meta( $id, '_bps_order_item_id', true );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$item = $order->get_item( $item_id );
		if ( $item instanceof \WC_Order_Item_Product ) {
			/* translators: %s: date */
			$item->update_meta_data( 'bps_file_removed', sprintf( __( 'Ontwerpbestand verwijderd op %s (bewaartermijn)', 'borduur-patch-studio' ), wp_date( 'd-m-Y' ) ) );
			$item->update_meta_data( '_bps_preview_url', $thumb ? (string) $thumb['url'] : '' );
			$item->save();
		}
	}

	/**
	 * Remove personal data from a design post that must stay (ordered): e-mail, sent-to address, page URL, notes in
	 * the payload and the notes in the proof history. The proof status/rounds stay (production record).
	 *
	 * @param int $id Design id.
	 */
	public static function scrub_personal_data( int $id ): void {
		delete_post_meta( $id, '_bps_email' );
		delete_post_meta( $id, '_bps_email_sent_to' );
		delete_post_meta( $id, '_bps_page_url' );

		$payload = json_decode( (string) get_post_meta( $id, '_bps_payload', true ), true );
		if ( is_array( $payload ) && isset( $payload['notes'] ) && '' !== (string) $payload['notes'] ) {
			$payload['notes'] = '';
			update_post_meta( $id, '_bps_payload', wp_slash( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		}

		$proof = get_post_meta( $id, '_bps_proof', true );
		if ( is_array( $proof ) && ! empty( $proof['history'] ) && is_array( $proof['history'] ) ) {
			foreach ( $proof['history'] as $i => $entry ) {
				if ( is_array( $entry ) && array_key_exists( 'note', $entry ) ) {
					$proof['history'][ $i ]['note'] = '';
				}
			}
			update_post_meta( $id, '_bps_proof', $proof );
		}
	}

	/**
	 * Create a small preview (longest side ≤ 300 px, ≤ 30 kB PNG) next to the previews. Returns {path,url} or null.
	 * PNG because the previews folder only serves PNG (.htaccess); a palette is used when truecolor is too big.
	 *
	 * @param string $src Source preview path.
	 * @return array{path:string,url:string,small:bool}|null
	 */
	public static function small_preview( string $src ): ?array {
		if ( ! function_exists( 'imagecreatefrompng' ) || ! is_readable( $src ) || ! \BorduurPatchStudio\Uploads::is_ours( $src ) ) {
			return null;
		}
		$im = @imagecreatefrompng( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $im ) {
			return null;
		}
		$w    = imagesx( $im );
		$h    = imagesy( $im );
		$dir  = \BorduurPatchStudio\Uploads::preview_dir();
		$name = '';
		for ( $i = 0; $i < 32; $i++ ) {
			$name .= 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'[ random_int( 0, 61 ) ];
		}
		$dest = $dir . $name . '.png';
		$ok   = false;
		foreach ( array( self::THUMB_MAX_PX, 220, 160, 120 ) as $max ) {
			$scale = min( 1, $max / max( 1, $w, $h ) );
			$nw    = max( 1, (int) round( $w * $scale ) );
			$nh    = max( 1, (int) round( $h * $scale ) );
			foreach ( array( 0, 128, 48 ) as $colors ) {
				$out = imagecreatetruecolor( $nw, $nh );
				imagealphablending( $out, false );
				imagesavealpha( $out, true );
				imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
				imagecopyresampled( $out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
				if ( $colors > 0 ) {
					imagetruecolortopalette( $out, true, $colors );
				}
				$written = imagepng( $out, $dest, 9 );
				imagedestroy( $out );
				clearstatcache( true, $dest );
				if ( $written && is_file( $dest ) && filesize( $dest ) <= self::THUMB_MAX_BYTES ) {
					$ok = true;
					break 2;
				}
			}
		}
		imagedestroy( $im );
		if ( ! $ok ) {
			if ( is_file( $dest ) ) {
				wp_delete_file( $dest );
			}
			return null;
		}
		@chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return array(
			'path'  => $dest,
			'url'   => \BorduurPatchStudio\Uploads::preview_url_for( $dest ),
			'small' => true,
		);
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
