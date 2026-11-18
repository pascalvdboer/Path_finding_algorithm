<?php
/**
 * AI marker in the design payload, on the production sheet and the "nagekeken" checkbox on the order screen.
 *
 * Closes three integration gaps without changing existing files (all through existing hooks):
 * 1. `bps_payload_clean` (Woo\Payload::sanitize): keeps a validated `source.ai = {jobId, index}`, so
 *    Design_Link can verify the claim on `bps_design_created` and write `_bps_ai`.
 * 2. `bps_fiche_sections` (Admin\Order_Production): a section "AI-ontwerp" on the production sheet.
 * 3. A small order metabox "AI-ontwerp nakijken" with one checkbox per AI line → `_bps_ai.review = {user_id, at}`
 *    (04-juridisch §1, layer 3 of the moderation: a human checks for brands, emblems and real people).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Order-side handling of AI designs.
 */
final class Order_Review {

	const NONCE = 'bps_ai_review';

	/** Crockford base32 ULID, like Jobs::valid_id(). */
	const JOB_ID_RE = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

	/**
	 * Register hooks (from Module::register()).
	 */
	public static function register(): void {
		add_filter( 'bps_payload_clean', array( __CLASS__, 'payload_clean' ), 20, 2 );
		add_filter( 'bps_fiche_sections', array( __CLASS__, 'fiche_sections' ), 20, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 31, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_box' ), 31, 1 );
	}

	/**
	 * Filter bps_payload_clean: keep only a well-formed `source.ai` claim. Ownership and "this proposal was chosen"
	 * are checked later by Design_Link::verify(); anything else from the browser is dropped.
	 *
	 * @param mixed $out Clean payload.
	 * @param mixed $raw Untrusted decoded input.
	 * @return mixed
	 */
	public static function payload_clean( $out, $raw ) {
		if ( ! is_array( $out ) ) {
			return $out;
		}
		$claim = self::claim( is_array( $raw ) ? ( $raw['source']['ai'] ?? null ) : null );
		if ( ! isset( $out['source'] ) || ! is_array( $out['source'] ) ) {
			$out['source'] = array();
		}
		if ( null === $claim ) {
			unset( $out['source']['ai'] );
		} else {
			$out['source']['ai'] = $claim;
		}
		return $out;
	}

	/**
	 * Validate a raw `source.ai` value.
	 *
	 * @param mixed $ai Raw value.
	 * @return array{jobId:string,index:int}|null
	 */
	public static function claim( $ai ): ?array {
		if ( ! is_array( $ai ) || ! isset( $ai['jobId'], $ai['index'] ) || ! is_string( $ai['jobId'] ) || ! is_numeric( $ai['index'] ) ) {
			return null;
		}
		$id = strtoupper( trim( $ai['jobId'] ) );
		if ( ! preg_match( self::JOB_ID_RE, $id ) ) {
			return null;
		}
		$index = (int) $ai['index'];
		if ( $index < 0 || $index > 3 ) {
			return null;
		}
		return array(
			'jobId' => $id,
			'index' => $index,
		);
	}

	/**
	 * `_bps_ai` meta of an order item (or null).
	 *
	 * @param mixed $item Order item.
	 */
	public static function meta_of( $item ): ?array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return null;
		}
		$meta = $item->get_meta( Design_Link::META );
		return is_array( $meta ) && ! empty( $meta['jobId'] ) ? $meta : null;
	}

	/**
	 * Filter bps_fiche_sections: add "AI-ontwerp" when the line comes from an AI proposal.
	 *
	 * @param mixed $sections Sections.
	 * @param mixed $design   DesignRecord.
	 * @param mixed $item     Order item.
	 * @return mixed
	 */
	public static function fiche_sections( $sections, $design = null, $item = null ) {
		unset( $design );
		$meta = self::meta_of( $item );
		if ( ! is_array( $sections ) || null === $meta ) {
			return $sections;
		}
		$review = is_array( $meta['review'] ?? null ) ? $meta['review'] : null;
		$rows   = array(
			array(
				'label' => __( 'Gemaakt met AI', 'borduur-patch-studio' ),
				'value' => trim( sprintf( '%s · %s', (string) ( $meta['provider'] ?? '' ), (string) ( $meta['model'] ?? '' ) ), ' ·' ),
			),
			array(
				'label' => __( 'Voorstel', 'borduur-patch-studio' ),
				'value' => sprintf( '%s #%d', (string) $meta['jobId'], 1 + (int) ( $meta['index'] ?? 0 ) ),
				'mono'  => true,
			),
			array(
				'label' => __( 'Nagekeken (merken, emblemen, echte personen)', 'borduur-patch-studio' ),
				'value' => $review ? self::review_text( $review ) : __( 'Nog niet: kijk na vóór je de proef verstuurt', 'borduur-patch-studio' ),
				'level' => $review ? 'ok' : 'warn',
			),
			array(
				'label' => __( 'Afgewerkt door ontwerper', 'borduur-patch-studio' ),
				'value' => ! empty( $meta['humanFinish'] ) ? __( 'Ja', 'borduur-patch-studio' ) : __( 'Nee (AI-voorstel)', 'borduur-patch-studio' ),
			),
		);
		$sections['ai'] = array(
			'title' => __( 'AI-ontwerp', 'borduur-patch-studio' ),
			'rows'  => $rows,
		);
		return $sections;
	}

	/**
	 * "Ja, door X op datum".
	 *
	 * @param array $review {user_id, at}.
	 */
	private static function review_text( array $review ): string {
		$user = function_exists( 'get_userdata' ) ? get_userdata( (int) ( $review['user_id'] ?? 0 ) ) : null;
		$name = $user && isset( $user->display_name ) ? (string) $user->display_name : '#' . (int) ( $review['user_id'] ?? 0 );
		$at   = (string) ( $review['at'] ?? '' );
		$date = '' !== $at && function_exists( 'wp_date' ) ? wp_date( 'j-n-Y H:i', (int) strtotime( $at . ' UTC' ) ) : $at;
		/* translators: 1: user name, 2: date */
		return sprintf( __( 'Ja, door %1$s op %2$s', 'borduur-patch-studio' ), $name, $date );
	}

	/**
	 * Apply the checkbox state to one item. Returns true when the meta changed.
	 *
	 * @param mixed $item    Order item.
	 * @param bool  $checked Checkbox state.
	 * @param int   $user_id Reviewer.
	 * @param int   $now     Timestamp.
	 */
	public static function set_review( $item, bool $checked, int $user_id, ?int $now = null ): bool {
		$meta = self::meta_of( $item );
		if ( null === $meta ) {
			return false;
		}
		$has = is_array( $meta['review'] ?? null );
		if ( $checked === $has ) {
			return false;
		}
		$meta['review'] = $checked ? array(
			'user_id' => $user_id,
			'at'      => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
		) : null;
		$item->update_meta_data( Design_Link::META, $meta );
		$item->save();
		return true;
	}

	/**
	 * Add the box on the order screen (classic and HPOS) when the order has AI lines.
	 *
	 * @param string $post_type Screen/post type.
	 * @param mixed  $post      Post or order.
	 */
	public static function add_box( $post_type, $post = null ): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( ! in_array( $post_type, array( 'shop_order', $screen ), true ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = $post instanceof \WC_Order ? $post : ( is_object( $post ) && isset( $post->ID ) ? wc_get_order( $post->ID ) : null );
		if ( ! $order || ! self::ai_items( $order ) ) {
			return;
		}
		add_meta_box( 'bps-ai-review', __( 'AI-ontwerp nakijken', 'borduur-patch-studio' ), array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
	}

	/**
	 * AI lines of an order: item_id => meta.
	 *
	 * @param mixed $order WC_Order.
	 * @return array<int, array>
	 */
	public static function ai_items( $order ): array {
		$out = array();
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			$meta = self::meta_of( $item );
			if ( null !== $meta ) {
				$out[ (int) $item_id ] = $meta;
			}
		}
		return $out;
	}

	/**
	 * Render the box.
	 *
	 * @param mixed $post_or_order Post or order.
	 */
	public static function render_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		wp_nonce_field( self::NONCE, 'bps_ai_review_nonce' );
		echo '<p class="description">' . esc_html__( 'Dit ontwerp komt van de AI-studio. Kijk na dat er geen merk, officieel embleem of echte persoon in staat vóór je de proef verstuurt.', 'borduur-patch-studio' ) . '</p>';
		foreach ( $order->get_items() as $item_id => $item ) {
			$meta = self::meta_of( $item );
			if ( null === $meta ) {
				continue;
			}
			$done = is_array( $meta['review'] ?? null );
			echo '<input type="hidden" name="bps_ai_review[' . (int) $item_id . ']" value="0">';
			echo '<p><label><input type="checkbox" name="bps_ai_review[' . (int) $item_id . ']" value="1"' . ( $done ? ' checked' : '' ) . '> ';
			echo esc_html( $item->get_name() ) . ': ' . esc_html__( 'nagekeken', 'borduur-patch-studio' ) . '</label>';
			if ( $done ) {
				echo '<br><small>' . esc_html( self::review_text( $meta['review'] ) ) . '</small>';
			}
			echo '</p>';
		}
	}

	/**
	 * Save the box.
	 *
	 * @param int $order_id Order id.
	 */
	public static function save_box( $order_id ): void {
		if ( ! isset( $_POST['bps_ai_review_nonce'], $_POST['bps_ai_review'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bps_ai_review_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$rows = (array) wp_unslash( $_POST['bps_ai_review'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast per field below.
		foreach ( $rows as $item_id => $val ) {
			$item = $order->get_item( (int) $item_id );
			if ( $item ) {
				self::set_review( $item, '1' === (string) $val, get_current_user_id() );
			}
		}
	}
}
