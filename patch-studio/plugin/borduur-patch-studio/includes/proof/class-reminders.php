<?php
/**
 * Reminders for proofs that wait for the customer (atelier-flow-9). A daily task (Action Scheduler when present,
 * WP-Cron otherwise) sends at most 2 reminders per round, each after proof.reminderDays days (default 3) since
 * the proof or the previous reminder. Cancelled / refunded / failed orders are skipped.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

use BorduurPatchStudio\Admin\Order_Production;

defined( 'ABSPATH' ) || exit;

/**
 * Proof reminders.
 */
final class Reminders {

	/** Cron / Action Scheduler hook. */
	const HOOK = 'bps_proof_followup';

	/** Action Scheduler group. */
	const GROUP = 'bps-proof';

	/** Max reminders per round. */
	const MAX_PER_ROUND = 2;

	/** Orders handled per run. */
	const BATCH = 200;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 0 );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
		add_action( 'bps_proof_sent', array( __CLASS__, 'schedule' ), 10, 0 );
	}

	/**
	 * Make sure the daily task exists.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Days before a reminder (setting proof.reminderDays, default 3, at least 1).
	 */
	public static function delay_days(): int {
		return max( 1, (int) Spec::cfg( 'proof.reminderDays', 3 ) );
	}

	/**
	 * Run: send due reminders. Returns the number of e-mails sent.
	 *
	 * @param int|null $now Timestamp (tests).
	 */
	public static function run( $now = null ): int {
		$now  = null === $now ? time() : (int) $now;
		$mail = Proof_Service::email( 'BPS_Email_Proof_Reminder' );
		if ( ! $mail || ! $mail->is_enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH,
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small flag, daily.
					array(
						'key'   => Proof_Service::OPEN_META,
						'value' => '1',
					),
				),
			)
		);
		$sent = 0;
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order || '' !== Proof_Service::blocked_status( $order ) ) {
				continue;
			}
			$due = self::due_lines( $order, $now );
			if ( ! $due ) {
				continue;
			}
			try {
				$ok = $mail->trigger( $order, $due );
			} catch ( \Throwable $e ) {
				$ok = false;
				if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
					\BorduurPatchStudio\Logger::error( 'proof_reminder_failed', array( 'order' => (int) $order->get_id(), 'exception' => $e ) );
				}
			}
			if ( ! $ok ) {
				continue;
			}
			++$sent;
			$at = gmdate( 'c', $now );
			foreach ( $due as $line ) {
				$p    = $line['proof'];
				$list = $p['reminders_sent'];
				$list[] = array(
					'round' => $p['round'],
					'at'    => $at,
				);
				Proof_Service::save(
					$line['item'],
					array(
						'reminders_sent' => $list,
						'history_add'    => array_merge( Proof_Service::event( 'reminder', 'system', '', $p['round'] ), array( 'at' => $at ) ),
					)
				);
			}
			$order->add_order_note(
				/* translators: %s: design refs */
				sprintf( __( 'Herinnering voor de digitale proef verstuurd (%s).', 'borduur-patch-studio' ), implode( ', ', array_map( static fn( $l ) => (string) $l['item']->get_meta( 'bps_ref' ), $due ) ) ),
				0,
				false
			);
		}
		return $sent;
	}

	/**
	 * Lines of an order that are due for a reminder.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $now   Timestamp.
	 * @return array<int,array{item:\WC_Order_Item_Product,proof:array,url:string,count:int}>
	 */
	public static function due_lines( $order, int $now ): array {
		$wait = self::delay_days() * DAY_IN_SECONDS;
		$due  = array();
		foreach ( Order_Production::design_items( $order ) as $item ) {
			$p = Proof_Service::get( $item );
			if ( 'sent' !== $p['status'] || $p['round'] < 1 ) {
				continue;
			}
			$last  = strtotime( $p['sent_at'] ) ?: 0;
			$count = 0;
			foreach ( $p['reminders_sent'] as $r ) {
				if ( is_array( $r ) && (int) ( $r['round'] ?? 0 ) === $p['round'] ) {
					++$count;
					$last = max( $last, (int) strtotime( (string) ( $r['at'] ?? '' ) ) );
				}
			}
			if ( $last <= 0 || $count >= self::MAX_PER_ROUND || ( $now - $last ) < $wait ) {
				continue;
			}
			$due[] = array(
				'item'  => $item,
				'proof' => $p,
				'url'   => Proof_Service::url( $order, $item, 'approve', $p['round'] ),
				'count' => $count + 1,
			);
		}
		return $due;
	}
}
