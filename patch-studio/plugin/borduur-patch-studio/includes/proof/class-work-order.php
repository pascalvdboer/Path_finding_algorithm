<?php
/**
 * Work order (werkbon) data that depends on the proof and the order: the main image with its approval label
 * (atelier-flow-2), the facts row with shipping, payment, phone and rush (atelier-flow-14 / -11) and the work steps
 * that follow the production status (atelier-flow-4) and the proof status.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

use BorduurPatchStudio\Admin\Order_Production;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers used by Order_Production::fiche() and the sheet templates.
 */
final class Work_Order {

	/** Production status → progress rank for the work steps on the sheet (statuses of atelier-flow-4). */
	const PRODUCTION_RANK = array(
		'new'           => 0,
		'proof'         => 1,
		'proof_sent'    => 1,
		'approved'      => 2,
		'digitized'     => 3,
		'in_production' => 4,
		'produced'      => 5,
		'ready'         => 5,
		'done'          => 6,
		'packed'        => 6,
		'shipped'       => 6,
		'completed'     => 6,
	);

	/**
	 * Main image of the work order (atelier-flow-2): the approved proof, else the last proof sent (not approved),
	 * else the studio preview. The banner "NOG NIET GOEDGEKEURD" shows when a proof was sent but not approved, or
	 * when the technique requires a proof (techniques.{id}.proofRequired) and none was approved.
	 *
	 * @param array $rec   DesignRecord.
	 * @param array $proof Proof.
	 * @param array $t     Technique config.
	 */
	public static function visual( array $rec, array $proof, array $t ): array {
		$approved = null;
		$last     = null;
		foreach ( $proof['history'] as $h ) {
			if ( ! is_array( $h ) ) {
				continue;
			}
			if ( 'sent' === ( $h['action'] ?? '' ) ) {
				$last = $h;
			}
			if ( in_array( (string) ( $h['action'] ?? '' ), array( 'approved', 'manual_approved' ), true ) ) {
				$approved = array(
					'round' => (int) ( $h['round'] ?? 0 ),
					'at'    => (string) ( $h['at'] ?? '' ),
				);
			}
		}
		$preview = (string) $rec['preview_url'];
		if ( 'approved' === $proof['status'] ) {
			$round = $approved ? $approved['round'] : $proof['round'];
			$img   = self::round_image( $proof, $round );
			$when  = strtotime( $approved ? $approved['at'] : $proof['responded_at'] );
			return array(
				'visual_url'      => '' !== $img ? $img : $preview,
				'visual_kind'     => 'approved',
				/* translators: 1: round, 2: date */
				'visual_label'    => sprintf( __( 'Goedgekeurde proef · ronde %1$d · %2$s', 'borduur-patch-studio' ), $round, wp_date( 'd-m-Y', $when ? $when : time() ) ),
				'approval_banner' => false,
			);
		}
		$last_img = (string) ( $last['image_url'] ?? $proof['image_url'] );
		if ( $proof['round'] > 0 && '' !== $last_img ) {
			return array(
				'visual_url'      => $last_img,
				'visual_kind'     => 'pending',
				'visual_label'    => __( 'Laatst verstuurde proef – NOG NIET GOEDGEKEURD', 'borduur-patch-studio' ),
				'approval_banner' => true,
			);
		}
		return array(
			'visual_url'      => $preview,
			'visual_kind'     => 'preview',
			'visual_label'    => __( 'Voorbeeld uit de ontwerptool', 'borduur-patch-studio' ),
			'approval_banner' => ! empty( $t['proofRequired'] ),
		);
	}

	/**
	 * Image of a given round (from the 'sent' history entry), '' when unknown.
	 *
	 * @param array $proof Proof.
	 * @param int   $round Round.
	 */
	public static function round_image( array $proof, int $round ): string {
		$img = '';
		foreach ( $proof['history'] as $h ) {
			if ( is_array( $h ) && 'sent' === ( $h['action'] ?? '' ) && (int) ( $h['round'] ?? 0 ) === $round && '' !== (string) ( $h['image_url'] ?? '' ) ) {
				$img = (string) $h['image_url'];
			}
		}
		return '' !== $img ? $img : ( $round === $proof['round'] ? $proof['image_url'] : '' );
	}

	/**
	 * Facts row of the work order (atelier-flow-14 / -11): shipping, payment, phone, rush.
	 *
	 * @param \WC_Order|false $order Order.
	 * @param string          $ref   Design ref.
	 */
	public static function facts( $order, string $ref ): array {
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}
		$rush = false;
		$more = count( Order_Production::design_items( $order ) ) > 1;
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$svc = $line instanceof \WC_Order_Item_Product ? $line->get_meta( '_bps_service' ) : null;
			if ( is_array( $svc ) && 'd4_rush' === ( $svc['id'] ?? '' ) ) {
				$linked = (string) ( $svc['linkedRef'] ?? '' ) . ' ' . (string) ( $svc['designRef'] ?? '' );
				// A rush line linked to this ref, or an unlinked rush line in an order with only this patch.
				$rush = $rush || ( '' === trim( $linked ) && ! $more ) || ( '' !== $ref && false !== strpos( $linked, $ref ) );
			}
		}
		return array(
			'shipping' => trim( (string) $order->get_shipping_method() ),
			'paid'     => (bool) $order->is_paid(),
			'payment'  => trim( (string) $order->get_payment_method_title() ),
			'phone'    => trim( (string) $order->get_billing_phone() ),
			'rush'     => $rush,
		);
	}

	/**
	 * Work steps for the sheet with their done state (production status of atelier-flow-4 + proof status).
	 *
	 * @param array $fiche Fiche.
	 * @return array<int,array{label:string,done:bool}>
	 */
	public static function work_steps( array $fiche ): array {
		$status = (string) ( $fiche['proof']['status'] ?? 'none' );
		$rank   = self::PRODUCTION_RANK[ (string) ( $fiche['production']['status'] ?? '' ) ] ?? 0;
		$sent   = in_array( $status, array( 'sent', 'approved', 'changes_requested' ), true );
		return array(
			array(
				'label' => __( 'Bestand nagekeken', 'borduur-patch-studio' ),
				'done'  => $rank >= 1 || $sent,
			),
			array(
				'label' => ! empty( $fiche['thread_based'] ) ? __( 'Gedigitaliseerd', 'borduur-patch-studio' ) : __( 'Drukbestand klaar', 'borduur-patch-studio' ),
				'done'  => $rank >= 3,
			),
			array(
				'label' => __( 'Proef verstuurd', 'borduur-patch-studio' ),
				'done'  => $rank >= 1 || $sent,
			),
			array(
				'label' => __( 'Proef goedgekeurd', 'borduur-patch-studio' ),
				'done'  => $rank >= 2 || 'approved' === $status,
			),
			array(
				'label' => __( 'Geproduceerd', 'borduur-patch-studio' ),
				'done'  => $rank >= 5,
			),
			array(
				'label' => __( 'Gecontroleerd en verpakt', 'borduur-patch-studio' ),
				'done'  => $rank >= 6,
			),
		);
	}
}
