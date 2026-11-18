<?php
/**
 * Links a chosen AI proposal to the saved studio design and the order item (`_bps_ai` meta).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §3.6 and §10.3: the studio keeps `source.ai = {jobId, index}` in the design payload. On
 * `bps_design_created` we check that the job belongs to the same owner and that this proposal was chosen; only then
 * `_bps_ai` is written (otherwise the claim is ignored). `bps_design_ordered` copies it to the order item, where the
 * premium team shows "Gemaakt met AI" and the review checkbox (04 §1, layer 3 of the moderation).
 */
final class Design_Link {

	const META = '_bps_ai';

	/**
	 * Hook bps_design_created.
	 *
	 * @param int   $design_id Design post id.
	 * @param mixed $payload   Sanitized design payload.
	 */
	public static function on_design_created( $design_id, $payload ): void {
		$meta = self::verify( is_array( $payload ) ? $payload : array(), Identity::current( false ) );
		if ( null !== $meta && (int) $design_id > 0 ) {
			update_post_meta( (int) $design_id, self::META, $meta );
		}
	}

	/**
	 * Hook bps_design_ordered: copy the AI marker to the order item (HPOS-safe through the item API).
	 *
	 * @param int   $design_id Design id.
	 * @param mixed $order     WC_Order.
	 * @param mixed $item      WC_Order_Item_Product.
	 */
	public static function on_design_ordered( $design_id, $order, $item ): void {
		unset( $order );
		$meta = get_post_meta( (int) $design_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['jobId'] ) || ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
			return;
		}
		$item->update_meta_data( self::META, $meta );
		$item->save();
		$job = Module::store()->job_get( (string) $meta['jobId'] );
		if ( $job ) {
			// The ordered design keeps its own copy of the file; keep the job (and its C2PA original) as long as orders.
			$days = AI_Settings::retention( 'orderDesignsAfterLastOrder', 1095 );
			Module::store()->job_update( (string) $job['id'], array( 'keep_until' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) ) );
		}
	}

	/**
	 * Validate `source.ai` of a payload against the jobs table.
	 *
	 * @param array  $payload Payload.
	 * @param string $owner   Current owner key.
	 * @return array|null `_bps_ai` meta or null.
	 */
	public static function verify( array $payload, string $owner ): ?array {
		$ai = $payload['source']['ai'] ?? ( $payload['ai'] ?? null );
		if ( ! is_array( $ai ) || '' === $owner ) {
			return null;
		}
		$job_id = strtoupper( (string) ( $ai['jobId'] ?? '' ) );
		$index  = isset( $ai['index'] ) && is_numeric( $ai['index'] ) ? (int) $ai['index'] : -1;
		if ( ! Jobs::valid_id( $job_id ) || $index < 0 ) {
			return null;
		}
		$job = Module::store()->job_get( $job_id );
		if ( ! $job || Identity::resolve( (string) $job['owner_key'] ) !== Identity::resolve( $owner ) ) {
			return null;
		}
		if ( null === $job['chosen_index'] || (int) $job['chosen_index'] !== $index ) {
			return null;
		}
		$legal = Module::legal();
		return array(
			'jobId'        => $job_id,
			'index'        => $index,
			'provider'     => (string) $job['provider'],
			'model'        => (string) $job['model'],
			'legalVersion' => (string) ( $legal['version'] ?? '' ),
			'humanFinish'  => false,
			'review'       => null,
		);
	}
}
