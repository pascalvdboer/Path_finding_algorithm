<?php
/**
 * E-mail confirmation for +2 free rounds (SPEC-AI-DIENSTEN §3.5). No table: codes live in transients.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * 6 digits, 15 min, max 5 tries, max 3 codes per address per hour and limits.emailCodesPerIpPerHour per IP hash.
 * The address is stored only as an owner row (bps_ai_owners) and never sent to the AI provider.
 */
final class Email_Gate {

	/** @var callable|null Test hook: receives (to, subject, body) instead of wp_mail. */
	public static $mailer = null;

	/** @var string|null Test hook: fixed code. */
	public static $fixed_code = null;

	/**
	 * Transient name for an address.
	 *
	 * @param string $email E-mail (normalised).
	 */
	private static function slot( string $email ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'bps';
		return 'bps_ai_code_' . substr( hash_hmac( 'sha256', $email, $salt ), 0, 40 );
	}

	/**
	 * Is the e-mail gate enabled?
	 */
	public static function enabled(): bool {
		return (bool) Config::get( 'emailVerify', true ) && (int) Config::get( 'free.withEmail', 2 ) > 0;
	}

	/**
	 * Send a code. Always answers {sent:true} for a valid address (no account enumeration).
	 *
	 * @param string  $email   Address.
	 * @param string  $ip_hash HMAC of the client IP.
	 * @param string  $lang    nl|fr.
	 * @param Credits $credits Credits (for the counters).
	 * @return array|\WP_Error
	 */
	public static function request( string $email, string $ip_hash, string $lang, Credits $credits ) {
		$lang  = Text::lang( $lang );
		$email = Owner::normalize_email( $email );
		if ( ! self::enabled() ) {
			return new \WP_Error( 'bps_ai_email_off', Text::t( 'email.off', $lang ), array( 'status' => 403 ) );
		}
		if ( strlen( $email ) > 190 || ! is_email( $email ) ) {
			return new \WP_Error( 'bps_ai_invalid', Text::t( 'email.invalid', $lang ), array( 'status' => 400 ) );
		}
		$store   = $credits->store();
		$per_ip  = max( 1, (int) Config::get( 'limits.emailCodesPerIpPerHour', 5 ) );
		$per_adr = max( 1, (int) Config::get( 'limits.emailCodesPerEmailPerHour', 3 ) );
		$adr_key = 'email_adr|' . self::slot( $email );
		if ( ( '' !== $ip_hash && $store->counter_get( 'email_ip|' . $ip_hash ) >= $per_ip ) || $store->counter_get( $adr_key ) >= $per_adr ) {
			return new \WP_Error( 'bps_ai_slow_down', Text::t( 'email.too_many', $lang ), array( 'status' => 429, 'retryAfter' => HOUR_IN_SECONDS ) );
		}
		if ( '' !== $ip_hash ) {
			$store->counter_incr( 'email_ip|' . $ip_hash, HOUR_IN_SECONDS );
		}
		$store->counter_incr( $adr_key, HOUR_IN_SECONDS );

		$minutes = max( 5, (int) Config::get( 'limits.emailCodeMinutes', 15 ) );
		$code    = null !== self::$fixed_code ? self::$fixed_code : str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		set_transient(
			self::slot( $email ),
			array(
				'hash'  => wp_hash_password( $code ),
				'tries' => 0,
				'exp'   => time() + $minutes * MINUTE_IN_SECONDS,
			),
			$minutes * MINUTE_IN_SECONDS
		);
		$subject = Text::t( 'email.subject', $lang );
		$body    = Text::t( 'email.body', $lang, array( 'code' => $code, 'minutes' => $minutes ) );
		$sent    = self::$mailer ? (bool) call_user_func( self::$mailer, $email, $subject, $body ) : (bool) wp_mail( $email, $subject, $body );
		if ( ! $sent ) {
			delete_transient( self::slot( $email ) );
			return new \WP_Error( 'bps_ai_mail', Text::t( 'email.send_failed', $lang ), array( 'status' => 503 ) );
		}
		return array( 'sent' => true );
	}

	/**
	 * Check a code; on success grant the free rounds (once per address) and merge the browser's rounds.
	 *
	 * @param string  $email   Address.
	 * @param string  $code    6 digits.
	 * @param string  $current Current owner key ('' allowed).
	 * @param string  $lang    nl|fr.
	 * @param Credits $credits Credits.
	 * @return array|\WP_Error {balance, granted, owner}
	 */
	public static function verify( string $email, string $code, string $current, string $lang, Credits $credits ) {
		$lang  = Text::lang( $lang );
		$email = Owner::normalize_email( $email );
		$code  = preg_replace( '/\D/', '', $code );
		if ( ! is_email( $email ) || 6 !== strlen( (string) $code ) ) {
			return new \WP_Error( 'bps_ai_invalid', Text::t( 'email.wrong_code', $lang ), array( 'status' => 400 ) );
		}
		$slot = self::slot( $email );
		$data = get_transient( $slot );
		if ( ! is_array( $data ) || (int) $data['exp'] < time() ) {
			return new \WP_Error( 'bps_ai_code_expired', Text::t( 'email.expired', $lang ), array( 'status' => 400 ) );
		}
		$max = max( 1, (int) Config::get( 'limits.emailCodeTries', 5 ) );
		if ( (int) $data['tries'] >= $max ) {
			delete_transient( $slot );
			return new \WP_Error( 'bps_ai_code_expired', Text::t( 'email.expired', $lang ), array( 'status' => 400 ) );
		}
		if ( ! wp_check_password( (string) $code, (string) $data['hash'] ) ) {
			++$data['tries'];
			set_transient( $slot, $data, max( 1, (int) $data['exp'] - time() ) );
			return new \WP_Error( 'bps_ai_code_wrong', Text::t( 'email.wrong_code', $lang ), array( 'status' => 400, 'triesLeft' => max( 0, $max - (int) $data['tries'] ) ) );
		}
		delete_transient( $slot );

		$email_owner = Owner::for_email( $email );
		Owner::remember( $email_owner, null, $email, true );
		$lot     = $credits->grant_free_email( $email_owner );
		$granted = $lot ? (int) Config::get( 'free.withEmail', 2 ) : 0;

		// Weaker keys flow into the strongest one: anonymous browser → e-mail → account.
		$target = 'u' === Owner::kind( $current ) ? $current : $email_owner;
		if ( $target !== $email_owner ) {
			Owner::merge_into( $email_owner, $target, $credits );
		}
		foreach ( array_unique( array_filter( array( $current, Owner::current_anon() ) ) ) as $weak ) {
			if ( Owner::valid( $weak ) && $weak !== $target && Owner::strength( $weak ) <= Owner::strength( $target ) ) {
				Owner::merge_into( $weak, $target, $credits );
			}
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bps_ai_email_verified', $email_owner, $target );
		}
		return array(
			'balance' => $credits->balance( $target ),
			'granted' => $granted,
			'owner'   => $target,
		);
	}
}
