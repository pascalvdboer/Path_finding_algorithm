<?php
/**
 * Checkout: the two withdrawal checkboxes for design packs, the service checkbox, order meta and the pack e-mail
 * block (SPEC-AI-DIENSTEN §11.2, 04-juridisch §4.2). Classic checkout and block checkout.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Never blocking, never preticked. Empty boxes are fine: paid rounds then become usable after 14 days (or as soon as
 * the customer ticks both boxes later in the studio), services start after the withdrawal period.
 */
final class Checkout {

	const PACK_META  = '_bps_ai_withdrawal_consent';
	const SVC_META   = '_bps_svc_consent';
	const OWNER_HINT = '_bps_ai_owner_hint';

	/** Classic checkout field names. */
	const F_START = 'bps_ai_consent_start';
	const F_ACK   = 'bps_ai_consent_ack';
	const F_SVC   = 'bps_svc_consent';

	/** Block checkout field ids (namespace/name, as Woo requires). */
	const B_START = 'borduur-patch-studio/ai-consent-start';
	const B_ACK   = 'borduur-patch-studio/ai-consent-ack';
	const B_SVC   = 'borduur-patch-studio/svc-consent';

	/** WC session key with the AI owner of this browser (set by POST /ai/checkout). */
	const SESSION_OWNER = 'bps_ai_owner';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_classic' ), 25 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'from_classic' ), 25, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_fields' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'from_blocks' ), 25, 2 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_block' ), 25, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Pure helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Consent record for the pack checkboxes (stored as order meta, proof for 04 §4.2).
	 *
	 * @param bool   $start  Box 1 ticked.
	 * @param bool   $ack    Box 2 ticked.
	 * @param string $locale Locale/lang.
	 * @param string $now    UTC 'Y-m-d H:i:s'.
	 * @return array{v:string, start:bool, ack:bool, at:string, locale:string, text_sha1:string}
	 */
	public static function pack_record( bool $start, bool $ack, string $locale, string $now ): array {
		$lang = Text::lang( $locale );
		$text = Text::legal( 'legal.ai.pack_consent_start', $lang ) . "\n" . Text::legal( 'legal.ai.pack_consent_ack', $lang );
		return array(
			'v'         => Text::legal_version(),
			'start'     => $start,
			'ack'       => $ack,
			'at'        => $now,
			'locale'    => $lang,
			'text_sha1' => sha1( $text ),
		);
	}

	/**
	 * Consent record for services.
	 *
	 * @param bool   $given  Ticked.
	 * @param string $locale Locale/lang.
	 * @param string $now    UTC.
	 * @return array{v:string, given:bool, at:string, locale:string, text_sha1:string}
	 */
	public static function svc_record( bool $given, string $locale, string $now ): array {
		$lang = Text::lang( $locale );
		return array(
			'v'         => Text::legal_version(),
			'given'     => $given,
			'at'        => $now,
			'locale'    => $lang,
			'text_sha1' => sha1( Text::legal( 'legal.svc.consent', $lang ) ),
		);
	}

	/**
	 * Both pack boxes ticked = consent to start (the only state that unlocks rounds immediately).
	 *
	 * @param mixed $record Stored record.
	 */
	public static function pack_consented( $record ): bool {
		return is_array( $record ) && ! empty( $record['start'] ) && ! empty( $record['ack'] );
	}

	/**
	 * What does a list of product ids contain? {pack:bool, svcConsent:bool}.
	 *
	 * @param int[] $product_ids Product ids.
	 */
	public static function needs( array $product_ids ): array {
		$out = array(
			'pack'       => false,
			'svcConsent' => false,
		);
		foreach ( $product_ids as $pid ) {
			$what = Products::lookup( (int) $pid );
			if ( ! $what ) {
				continue;
			}
			if ( 'pack' === $what['type'] ) {
				$out['pack'] = true;
			} elseif ( 'service' === $what['type'] ) {
				$svc = Config::service( $what['id'] );
				if ( $svc && ! empty( $svc['needsConsent'] ) ) {
					$out['svcConsent'] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Needs of the current cart.
	 */
	public static function cart_needs(): array {
		$ids = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (int) ( $item['product_id'] ?? 0 );
			}
		}
		return self::needs( $ids );
	}

	/**
	 * Needs of an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_needs( $order ): array {
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( method_exists( $item, 'get_product_id' ) ) {
				$ids[] = (int) $item->get_product_id();
			}
		}
		return self::needs( $ids );
	}

	/* ---------------------------------------------------------------------
	 * Classic checkout
	 * ------------------------------------------------------------------- */

	/**
	 * Render the checkboxes above "Place order".
	 */
	public static function render_classic(): void {
		$needs = self::cart_needs();
		if ( ! $needs['pack'] && ! $needs['svcConsent'] ) {
			return;
		}
		$lang = Text::lang( null );
		echo '<div class="bps-consents" style="margin:0 0 1em">';
		if ( $needs['pack'] ) {
			echo '<fieldset class="bps-consent bps-consent--pack"><legend><strong>' . esc_html( Text::t( 'checkout.pack_title', $lang ) ) . '</strong></legend>';
			self::checkbox( self::F_START, Text::legal( 'legal.ai.pack_consent_start', $lang ) );
			self::checkbox( self::F_ACK, Text::legal( 'legal.ai.pack_consent_ack', $lang ) );
			echo '<p class="bps-consent__info" style="font-size:.9em;margin:.25em 0">' . esc_html( Text::legal( 'legal.ai.pack_unused_refund', $lang ) ) . '</p>';
			echo '<p class="bps-consent__info" style="font-size:.9em;margin:.25em 0">' . esc_html( Text::legal( 'legal.ai.pack_no_consent', $lang ) ) . '</p>';
			echo '</fieldset>';
		}
		if ( $needs['svcConsent'] ) {
			echo '<fieldset class="bps-consent bps-consent--svc"><legend><strong>' . esc_html( Text::t( 'checkout.svc_title', $lang ) ) . '</strong></legend>';
			self::checkbox( self::F_SVC, Text::legal( 'legal.svc.consent', $lang ) );
			echo '<p class="bps-consent__info" style="font-size:.9em;margin:.25em 0">' . esc_html( Text::legal( 'legal.svc.no_consent', $lang ) ) . '</p>';
			echo '</fieldset>';
		}
		echo '</div>';
	}

	/**
	 * One empty checkbox (never preticked).
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 */
	private static function checkbox( string $name, string $label ): void {
		echo '<p class="form-row bps-consent__row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
		echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="' . esc_attr( $name ) . '" value="1"> ';
		echo '<span>' . esc_html( $label ) . '</span></label></p>';
	}

	/**
	 * Classic checkout: store the consent meta. Woo verified its checkout nonce before this hook.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted data.
	 */
	public static function from_classic( $order, $data = array() ): void {
		unset( $data );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WC_Checkout::process_checkout().
		$start = ! empty( $_POST[ self::F_START ] );
		$ack   = ! empty( $_POST[ self::F_ACK ] );
		$svc   = ! empty( $_POST[ self::F_SVC ] );
		// phpcs:enable
		self::apply( $order, $start, $ack, $svc );
	}

	/* ---------------------------------------------------------------------
	 * Block checkout
	 * ------------------------------------------------------------------- */

	/**
	 * Register the additional checkout fields (Woo ≥ 8.9). Optional checkboxes in the order section.
	 */
	public static function register_block_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$lang = Text::lang( null );
		$hint = ' (' . Text::t( 'checkout.blocks_hint', $lang ) . ')';
		$defs = array(
			self::B_START => Text::legal( 'legal.ai.pack_consent_start', $lang ) . $hint,
			self::B_ACK   => Text::legal( 'legal.ai.pack_consent_ack', $lang ) . $hint,
			self::B_SVC   => Text::legal( 'legal.svc.consent', $lang ) . $hint,
		);
		foreach ( $defs as $id => $label ) {
			try {
				woocommerce_register_additional_checkout_field(
					array(
						'id'       => $id,
						'label'    => $label,
						'location' => 'order',
						'type'     => 'checkbox',
						'required' => false,
					)
				);
			} catch ( \Throwable $e ) {
				// Field already registered or Woo version without support: classic checkout still works.
				unset( $e );
			}
		}
	}

	/**
	 * Block checkout: read the additional fields and store the same meta as the classic checkout.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 */
	public static function from_blocks( $order, $request ): void {
		$fields = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'additional_fields' ) : array();
		$fields = is_array( $fields ) ? $fields : array();
		self::apply(
			$order,
			! empty( $fields[ self::B_START ] ),
			! empty( $fields[ self::B_ACK ] ),
			! empty( $fields[ self::B_SVC ] )
		);
	}

	/* ---------------------------------------------------------------------
	 * Shared
	 * ------------------------------------------------------------------- */

	/**
	 * Write consent meta, per-item service start dates and the owner hint on a new order (no save: Woo saves).
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $start Pack box 1.
	 * @param bool      $ack   Pack box 2.
	 * @param bool      $svc   Service box.
	 */
	public static function apply( $order, bool $start, bool $ack, bool $svc ): void {
		$needs  = self::order_needs( $order );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : 'nl_BE';
		if ( $needs['pack'] ) {
			$order->update_meta_data( self::PACK_META, self::pack_record( $start, $ack, $locale, $now ) );
			$hint = self::owner_hint();
			if ( '' !== $hint ) {
				$order->update_meta_data( self::OWNER_HINT, $hint );
			}
		}
		if ( $needs['svcConsent'] ) {
			$order->update_meta_data( self::SVC_META, self::svc_record( $svc, $locale, $now ) );
			if ( ! $svc ) {
				$wait  = (int) ( Text::legal_config()['defaults']['withdrawalDays'] ?? 14 );
				$after = gmdate( 'Y-m-d', strtotime( $now . ' UTC' ) + $wait * DAY_IN_SECONDS );
				foreach ( $order->get_items() as $item ) {
					$info = Service_Status::of_item( $item );
					if ( $info && $info['svc'] && ! empty( $info['svc']['needsConsent'] ) ) {
						$item->update_meta_data( Services::START_AFTER_META, $after );
					}
				}
			}
		}
	}

	/**
	 * Anonymous AI owner of this browser (to merge its free rounds into the buyer's key after payment).
	 * Only an 'a:' key is accepted: a stronger key is derived from the order itself.
	 */
	public static function owner_hint(): string {
		$hint = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$hint = (string) WC()->session->get( self::SESSION_OWNER, '' );
		}
		if ( '' === $hint ) {
			$hint = Owner::current_anon();
		}
		return 'a' === Owner::kind( $hint ) ? $hint : '';
	}

	/* ---------------------------------------------------------------------
	 * E-mail
	 * ------------------------------------------------------------------- */

	/**
	 * Pack text in customer e-mails (consent text, version, date and time, withdrawal link, credit conditions).
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin copy.
	 * @param bool      $plain_text    Plain text e-mail.
	 * @param mixed     $email         WC_Email.
	 */
	public static function email_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );
		if ( $sent_to_admin || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$text = self::pack_email_text( $order );
		if ( '' === $text ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( wp_strip_all_tags( $text ) ) . "\n";
			return;
		}
		echo '<div class="bps-pack-email" style="margin:16px 0;padding:12px;border:1px solid #e5e5e5"><p style="margin:0">' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Build the pack e-mail text ('' when the order has no pack line).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function pack_email_text( $order ): string {
		$rounds = 0;
		$expiry = '';
		$credit = 0.0;
		foreach ( $order->get_items() as $item ) {
			$what = method_exists( $item, 'get_product_id' ) ? Products::lookup( (int) $item->get_product_id() ) : null;
			if ( ! $what || 'pack' !== $what['type'] ) {
				continue;
			}
			$pack    = Config::pack( $what['id'] );
			$rounds += $pack ? $pack['rounds'] * max( 1, (int) $item->get_quantity() ) : 0;
			$credit += (float) $item->get_total() + (float) $item->get_total_tax();
			$meta    = $item->get_meta( '_bps_ai_pack' );
			if ( is_array( $meta ) && ! empty( $meta['expiresAt'] ) ) {
				$expiry = (string) $meta['expiresAt'];
			}
		}
		if ( $rounds <= 0 ) {
			return '';
		}
		$lang    = Service_Status::order_lang( $order );
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		if ( '' === $expiry ) {
			$months = max( (int) Config::get( 'validityMinMonths', 12 ), (int) Config::get( 'validityMonths', 24 ) );
			$expiry = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $months . ' months', $created ) );
		}
		$record  = $order->get_meta( self::PACK_META );
		$percent = (float) Config::get( 'orderCredit.percent', 100 );
		$back    = Config::round_half_up( $credit * $percent / 100, 2 );
		$link    = Withdrawal::link_for_order( $order );
		$days    = (int) Config::get( 'refundUnusedDays', 14 );
		$terms   = function_exists( 'wc_terms_and_conditions_page_id' ) && wc_terms_and_conditions_page_id() ? (string) get_permalink( wc_terms_and_conditions_page_id() ) : home_url( '/' );
		if ( ! self::pack_consented( $record ) ) {
			return Text::t( 'account.rounds', $lang ) . ': ' . $rounds . '. ' . Text::legal( 'legal.ai.pack_no_consent', $lang ) . ' ' . Text::legal( 'legal.ai.withdraw_button', $lang ) . ': ' . $link;
		}
		$at = strtotime( (string) $record['at'] . ' UTC' );
		return Text::legal(
			'legal.ai.pack_email',
			$lang,
			array(
				'n'                 => $rounds,
				'datum'             => Text::date( $expiry, $lang ),
				'datum_toestemming' => Text::date( (string) $record['at'], $lang ),
				'uur'               => function_exists( 'wp_date' ) ? wp_date( 'H:i', $at ) : gmdate( 'H:i', $at ),
				'v'                 => (string) ( $record['v'] ?? '' ),
				'datum_14'          => Text::date( gmdate( 'Y-m-d H:i:s', $created + $days * DAY_IN_SECONDS ), $lang ),
				'link'              => $link,
				'k'                 => (int) Config::get( 'orderCredit.validMonths', 12 ),
				'min'               => number_format( (float) Config::get( 'orderCredit.minArticleIncl', 50 ), 2, ',', '.' ),
				'bedrag'            => number_format( $back, 2, ',', '.' ),
				'av_link'           => $terms,
			)
		);
	}
}
