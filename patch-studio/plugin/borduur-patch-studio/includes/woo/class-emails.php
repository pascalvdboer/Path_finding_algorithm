<?php
/**
 * E-mails: our WC_Email classes, the patch preview in order mails and the "what happens now" block.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers BPS_Email_Quote_Admin, BPS_Email_Quote_Customer and BPS_Email_Saved_Design
 * (manageable under WooCommerce › Settings › Emails) and enriches the standard order e-mails.
 */
final class Emails {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ) );
		add_filter( 'woocommerce_email_order_items_args', array( __CLASS__, 'order_items_args' ) );
		add_filter( 'woocommerce_order_item_thumbnail', array( __CLASS__, 'order_item_thumbnail' ), 10, 2 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'after_order_table' ), 10, 4 );
	}

	/**
	 * Register our e-mail classes (loaded lazily: WC_Email exists at this point).
	 *
	 * @param array $emails Emails.
	 */
	public static function email_classes( $emails ) {
		$emails                             = is_array( $emails ) ? $emails : array();
		$emails['BPS_Email_Quote_Admin']    = new Emails\Quote_Admin();
		$emails['BPS_Email_Quote_Customer'] = new Emails\Quote_Customer();
		$emails['BPS_Email_Saved_Design']   = new Emails\Saved_Design();
		return $emails;
	}

	/**
	 * Get one of our e-mail objects (initialises the mailer).
	 *
	 * @param string $key Class key.
	 * @return \WC_Email|null
	 */
	public static function get( string $key ) {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$mailer = WC()->mailer();
		$all    = $mailer ? $mailer->get_emails() : array();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Show product images in order e-mails when a Patch Studio line is present.
	 *
	 * @param array $args Template args.
	 */
	public static function order_items_args( $args ) {
		if ( is_array( $args ) && isset( $args['order'] ) && Order::has_design( $args['order'] ) ) {
			$args['show_image'] = true;
			$args['image_size'] = array( 96, 96 );
		}
		return $args;
	}

	/**
	 * Replace the product image by the patch preview (public PNG; e-mail clients need absolute https URLs).
	 *
	 * @param string $html Thumbnail.
	 * @param mixed  $item Order item.
	 */
	public static function order_item_thumbnail( $html, $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $html;
		}
		$url = (string) $item->get_meta( '_bps_preview_url' );
		if ( '' === $url ) {
			return $html;
		}
		return sprintf(
			'<div style="margin-bottom:5px"><img src="%1$s" alt="%2$s" width="96" height="96" style="display:block;border:0;border-radius:6px;max-width:96px;height:auto;vertical-align:middle;margin-right:10px;" /></div>',
			esc_url( $url ),
			esc_attr__( 'Voorbeeld van je patch', 'borduur-patch-studio' )
		);
	}

	/**
	 * Block F (08 §1.4) under the order table, only for orders with a Patch Studio line.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin mail.
	 * @param bool      $plain_text    Plain text.
	 * @param mixed     $email         Email object.
	 */
	public static function after_order_table( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! Order::has_design( $order ) ) {
			return;
		}
		$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		if ( in_array( $id, array( 'cancelled_order', 'failed_order', 'customer_refunded_order', 'customer_failed_order' ), true ) ) {
			return;
		}
		$title = __( 'Wat gebeurt er nu?', 'borduur-patch-studio' );
		$text  = self::next_steps_text();
		if ( $plain_text ) {
			echo "\n" . esc_html( wp_strip_all_tags( $title ) ) . "\n" . esc_html( wp_strip_all_tags( $text ) ) . "\n\n";
			return;
		}
		echo '<div class="bps-email-next" style="margin:0 0 24px;padding:16px 18px;border:1px solid #E3EBF2;border-left:4px solid #21A9E2;border-radius:6px;background:#F7FBFE;">';
		echo '<p style="margin:0 0 6px;font-weight:700;color:#0A1F44;">' . esc_html( $title ) . '</p>';
		echo '<p style="margin:0;color:#323232;">' . esc_html( $text ) . '</p>';
		echo '</div>';
	}

	/**
	 * "Within X days you receive your digital proof…" (proof term only when configured).
	 */
	public static function next_steps_text(): string {
		$days = Config::path( 'promises.proofDays' );
		if ( is_numeric( $days ) && (int) $days > 0 ) {
			$first = sprintf(
				/* translators: %d: number of working days */
				_n( 'Binnen %d werkdag krijg je je digitale proef per e-mail.', 'Binnen %d werkdagen krijg je je digitale proef per e-mail.', (int) $days, 'borduur-patch-studio' ),
				(int) $days
			);
		} else {
			$first = __( 'Je krijgt eerst je digitale proef per e-mail.', 'borduur-patch-studio' );
		}
		$text = $first . ' ' . __( 'Pas na jouw akkoord starten we de productie. Je patch wordt op maat gemaakt; daarom geldt het herroepingsrecht niet.', 'borduur-patch-studio' );
		if ( Config::path( 'promises.cancelUntilProof', false ) ) {
			$text .= ' ' . __( 'Tot je de proef goedkeurt, kan je je bestelling kosteloos annuleren.', 'borduur-patch-studio' );
		}
		return $text;
	}

	/**
	 * Base dir of our templates (theme override: yourtheme/woocommerce/emails/<name>).
	 */
	public static function template_base(): string {
		return BPS_DIR . 'templates/';
	}
}
