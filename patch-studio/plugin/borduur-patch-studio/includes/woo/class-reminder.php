<?php
/**
 * The one reminder the studio promises after "Bewaar & mail mijn ontwerp" (klant-account-1).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Flow:
 * 1. Routes::email_design fires bps_design_emailed (contract C4). When the customer ticked the reminder box
 *    (request field reminder: true) and reminder.enabled is on, we store the consent in _bps_reminder and schedule
 *    exactly one action bps_design_reminder after reminder.delayDays (Action Scheduler, WP-Cron as fallback).
 * 2. The handler sends at most ONE mail per design, ever. Nothing is sent when the design was carted, quoted or
 *    ordered in the meantime, when the customer opened it again, unsubscribed, or when the reminder was cancelled.
 * 3. Every reminder carries an unsubscribe link (GET /reminder/unsubscribe?d=&s=, HMAC) and a List-Unsubscribe
 *    header (one-click POST on the same URL, RFC 8058).
 *
 * Stored consent (_bps_reminder): {email_hash, consent_at, consent_locale, consent_text_version, due_gmt,
 * sent_gmt|null, cancelled|null, failed?}. The e-mail address itself stays in _bps_email (removed by retention).
 */
final class Reminder {

	const HOOK         = 'bps_design_reminder';
	const GROUP        = 'bps-reminder';
	const META         = '_bps_reminder';
	const CLAIM_META   = '_bps_reminder_claim';
	const OPTOUT       = 'bps_reminder_optout';
	const OPTOUT_MAX   = 20000;
	const TEXT_VERSION = 'reminder-2026-11';
	const EMAIL_KEY    = 'BPS_Email_Design_Reminder';

	/**
	 * Hooks (registered by Designs::register()).
	 */
	public static function register(): void {
		add_action( 'bps_design_emailed', array( __CLASS__, 'on_design_emailed' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ), 20 );
		add_filter( 'bps_owned_data', array( __CLASS__, 'owned_data_filter' ) );
		add_action( 'bps_design_ordered', array( __CLASS__, 'on_status_change' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 5, 1 );
	}

	/**
	 * What this feature stores (contract C2; also usable statically by uninstall).
	 *
	 * @return array<string, string[]>
	 */
	public static function owned_data(): array {
		return array(
			'cron_hooks' => array( self::HOOK ),
			'as_groups'  => array( self::GROUP ),
			'options'    => array( self::OPTOUT ),
		);
	}

	/**
	 * Filter bps_owned_data.
	 *
	 * @param mixed $data Owned data.
	 * @return mixed
	 */
	public static function owned_data_filter( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( self::owned_data() as $key => $list ) {
			$data[ $key ] = array_values( array_unique( array_merge( (array) ( $data[ $key ] ?? array() ), $list ) ) );
		}
		return $data;
	}

	/**
	 * Register the e-mail class.
	 *
	 * @param mixed $emails Emails.
	 * @return mixed
	 */
	public static function email_classes( $emails ) {
		$emails = is_array( $emails ) ? $emails : array();
		if ( class_exists( '\WC_Email' ) ) {
			$emails[ self::EMAIL_KEY ] = new Emails\Design_Reminder();
		}
		return $emails;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * reminder.enabled (default on: the studio already offers the checkbox).
	 */
	public static function enabled(): bool {
		$v = Config::path( 'reminder.enabled', true );
		return ! ( false === $v || 0 === $v || '0' === $v || 'no' === $v );
	}

	/**
	 * reminder.delayDays (default 3, 1–30).
	 */
	public static function delay_days(): int {
		return max( 1, min( 30, (int) Config::path( 'reminder.delayDays', 3 ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * Listener for bps_design_emailed (contract C4).
	 *
	 * @param mixed $design_id Design id.
	 * @param mixed $email     Recipient.
	 * @param mixed $request   WP_REST_Request.
	 */
	public static function on_design_emailed( $design_id, $email = '', $request = null ): void {
		$id = (int) $design_id;
		if ( $id <= 0 ) {
			return;
		}
		$wanted = $request instanceof \WP_REST_Request && self::requested( $request );
		if ( ! $wanted ) {
			// The latest save is the customer's current choice: without the tick, an open reminder is withdrawn.
			self::cancel( $id, 'withdrawn' );
			return;
		}
		if ( ! self::enabled() ) {
			return;
		}
		$locale = $request instanceof \WP_REST_Request ? (string) ( $request->get_param( 'locale' ) ?? '' ) : '';
		self::schedule( $id, (string) $email, $locale );
	}

	/**
	 * Did the request explicitly ask for the reminder (reminder: true)?
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function requested( \WP_REST_Request $r ): bool {
		$json = $r->get_json_params();
		$v    = is_array( $json ) && array_key_exists( 'reminder', $json ) ? $json['reminder'] : $r->get_param( 'reminder' );
		return true === $v || 1 === $v || ( is_string( $v ) && in_array( strtolower( $v ), array( 'true', '1', 'yes', 'on' ), true ) );
	}

	/**
	 * Store the consent and schedule exactly one reminder. A design that already got its reminder never gets another.
	 *
	 * @param int    $id     Design id.
	 * @param string $email  Recipient (the address the design was mailed to).
	 * @param string $locale Customer locale.
	 */
	public static function schedule( int $id, string $email, string $locale = '' ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}
		$cur = self::get( $id );
		if ( $cur && ! empty( $cur['sent_gmt'] ) ) {
			return false;
		}
		self::unschedule( $id );
		$now  = Designs::now_ts();
		$due  = $now + self::delay_days() * DAY_IN_SECONDS;
		$data = array(
			'email_hash'           => self::email_hash( $email ),
			'consent_at'           => gmdate( 'Y-m-d H:i:s', $now ),
			'consent_locale'       => Config::public_locale( $locale ),
			'consent_text_version' => (string) apply_filters( 'bps_reminder_text_version', self::TEXT_VERSION ),
			'due_gmt'              => gmdate( 'Y-m-d H:i:s', $due ),
			'sent_gmt'             => null,
			'cancelled'            => null,
		);
		update_post_meta( $id, self::META, $data );
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $due, self::HOOK, array( $id ), self::GROUP );
			} else {
				wp_schedule_single_event( $due, self::HOOK, array( $id ) );
			}
		} catch ( \Throwable $e ) {
			Logger::error(
				'reminder_schedule_failed',
				array(
					'design' => $id,
					'error'  => $e->getMessage(),
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Remove any pending action of a design.
	 *
	 * @param int $id Design id.
	 */
	public static function unschedule( int $id ): void {
		try {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, array( $id ), self::GROUP );
			}
		} catch ( \Throwable $e ) {
			Logger::warning(
				'reminder_unschedule_failed',
				array(
					'design' => $id,
					'error'  => $e->getMessage(),
				)
			);
		}
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}

	/**
	 * Cancel an open reminder (nothing happens when it was already sent or cancelled).
	 *
	 * @param int    $id     Design id.
	 * @param string $reason Reason (status, opened, unsubscribed, withdrawn, disabled, hidden, …).
	 */
	public static function cancel( int $id, string $reason ): bool {
		$cur = self::get( $id );
		if ( ! $cur || ! empty( $cur['sent_gmt'] ) || ! empty( $cur['cancelled'] ) ) {
			return false;
		}
		$cur['cancelled'] = array(
			'at'     => Designs::now(),
			'reason' => sanitize_key( $reason ),
		);
		update_post_meta( $id, self::META, $cur );
		self::unschedule( $id );
		return true;
	}

	/**
	 * Stored reminder data or null.
	 *
	 * @param int $id Design id.
	 */
	public static function get( int $id ): ?array {
		$m = $id > 0 ? get_post_meta( $id, self::META, true ) : '';
		return is_array( $m ) && ! empty( $m['email_hash'] ) ? $m : null;
	}

	/**
	 * A carted/ordered design does not need a reminder any more.
	 *
	 * @param mixed $design_id Design id.
	 */
	public static function on_status_change( $design_id ): void {
		self::cancel( (int) $design_id, 'status' );
	}

	/**
	 * Clean up the pending action when a design post is deleted.
	 *
	 * @param mixed $post_id Post id.
	 */
	public static function on_delete_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( Designs::CPT === get_post_type( $post_id ) && self::get( $post_id ) ) {
			self::unschedule( $post_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Action handler. Sends at most one mail per design; returns what happened (for tests and logs).
	 *
	 * @param mixed $design_id Design id.
	 * @return string sent|failed|none|already_sent|cancelled|early|gone|<cancel reason>
	 */
	public static function run( $design_id ): string {
		$id  = (int) $design_id;
		$cur = self::get( $id );
		if ( ! $cur ) {
			return 'none';
		}
		if ( ! empty( $cur['sent_gmt'] ) ) {
			return 'already_sent';
		}
		if ( ! empty( $cur['cancelled'] ) ) {
			return 'cancelled';
		}
		$d = Designs::get( $id );
		if ( ! $d ) {
			return 'gone';
		}
		$reason = self::stop_reason( $id, $d, $cur );
		if ( '' !== $reason ) {
			self::cancel( $id, $reason );
			return $reason;
		}
		$now = Designs::now_ts();
		$due = strtotime( (string) $cur['due_gmt'] . ' UTC' );
		if ( $due && $now < $due - 5 * MINUTE_IN_SECONDS ) {
			// Fired too early (clock change, manual run): try again at the planned time.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $due, self::HOOK, array( $id ), self::GROUP );
			} else {
				wp_schedule_single_event( $due, self::HOOK, array( $id ) );
			}
			return 'early';
		}
		// At most once, also with two runners at the same time: the unique meta row is the lock.
		if ( ! add_post_meta( $id, self::CLAIM_META, (string) $now, true ) ) {
			return 'already_sent';
		}
		$cur['sent_gmt'] = gmdate( 'Y-m-d H:i:s', $now );
		update_post_meta( $id, self::META, $cur );

		$mail = Emails::get( self::EMAIL_KEY );
		$sent = false;
		try {
			$sent = $mail ? (bool) $mail->trigger( $id, (string) $d['email'], (string) $cur['consent_locale'], self::unsubscribe_url( $id ) ) : false;
		} catch ( \Throwable $e ) {
			Logger::error(
				'reminder_send_exception',
				array(
					'design' => $id,
					'error'  => $e->getMessage(),
				)
			);
		}
		if ( ! $sent ) {
			// No retry: one attempt is all the customer agreed to.
			$cur['failed'] = true;
			update_post_meta( $id, self::META, $cur );
			Logger::error( 'reminder_send_failed', array( 'design' => $id ) );
			return 'failed';
		}
		do_action( 'bps_design_reminder_sent', $id );
		return 'sent';
	}

	/**
	 * Why the reminder must not go out ('' = send).
	 *
	 * @param int   $id  Design id.
	 * @param array $d   DesignRecord.
	 * @param array $cur Reminder data.
	 */
	private static function stop_reason( int $id, array $d, array $cur ): string {
		if ( ! self::enabled() ) {
			return 'disabled';
		}
		if ( 'saved' !== $d['status'] || ! empty( $d['order_id'] ) ) {
			return 'status';
		}
		if ( ! empty( $d['hidden'] ) ) {
			return 'hidden';
		}
		$opened  = strtotime( (string) get_post_meta( $id, '_bps_last_opened_gmt', true ) . ' UTC' );
		$consent = strtotime( (string) $cur['consent_at'] . ' UTC' );
		if ( $opened && $consent && $opened > $consent ) {
			return 'opened';
		}
		$email = (string) ( $d['email'] ?? '' );
		if ( '' === $email || ! hash_equals( (string) $cur['email_hash'], self::email_hash( $email ) ) ) {
			return 'email_changed';
		}
		if ( self::opted_out( (string) $cur['email_hash'] ) ) {
			return 'unsubscribed';
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Unsubscribe
	 * ------------------------------------------------------------------- */

	/**
	 * Keyed hash of an e-mail address (never the address itself in the opt-out list).
	 *
	 * @param string $email Address.
	 */
	public static function email_hash( string $email ): string {
		return substr( hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Signature of the unsubscribe link of a design.
	 *
	 * @param int    $id   Design id.
	 * @param string $hash E-mail hash.
	 */
	public static function sig( int $id, string $hash ): string {
		return hash_hmac( 'sha256', 'bps-unsub|' . $id . '|' . $hash, wp_salt( 'auth' ) );
	}

	/**
	 * Unsubscribe link (empty when the design has no reminder consent).
	 *
	 * @param int $id Design id.
	 */
	public static function unsubscribe_url( int $id ): string {
		$cur = self::get( $id );
		if ( ! $cur ) {
			return '';
		}
		return add_query_arg(
			array(
				'd' => $id,
				's' => self::sig( $id, (string) $cur['email_hash'] ),
			),
			rest_url( 'patch-studio/v1/reminder/unsubscribe' )
		);
	}

	/**
	 * Handle an unsubscribe link: put the address on the opt-out list and cancel this design's reminder.
	 *
	 * @param int    $id  Design id.
	 * @param string $sig Signature.
	 */
	public static function unsubscribe( int $id, string $sig ): bool {
		$cur = self::get( $id );
		if ( ! $cur || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) || ! hash_equals( self::sig( $id, (string) $cur['email_hash'] ), $sig ) ) {
			return false;
		}
		self::opt_out( (string) $cur['email_hash'] );
		self::cancel( $id, 'unsubscribed' );
		return true;
	}

	/**
	 * Add an e-mail hash to the opt-out list (non-autoloaded option, newest kept when full).
	 *
	 * @param string $hash E-mail hash.
	 */
	public static function opt_out( string $hash ): void {
		$list = get_option( self::OPTOUT, array() );
		$list = is_array( $list ) ? $list : array();
		if ( isset( $list[ $hash ] ) ) {
			return;
		}
		$list[ $hash ] = gmdate( 'Y-m-d' );
		if ( count( $list ) > self::OPTOUT_MAX ) {
			$list = array_slice( $list, -1 * self::OPTOUT_MAX, null, true );
		}
		if ( false === get_option( self::OPTOUT, false ) ) {
			add_option( self::OPTOUT, $list, '', false );
		} else {
			update_option( self::OPTOUT, $list, false );
		}
	}

	/**
	 * Is this e-mail hash on the opt-out list?
	 *
	 * @param string $hash E-mail hash.
	 */
	public static function opted_out( string $hash ): bool {
		$list = get_option( self::OPTOUT, array() );
		return is_array( $list ) && isset( $list[ $hash ] );
	}
}
