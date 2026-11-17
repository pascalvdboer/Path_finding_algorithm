<?php
/**
 * Uninstall Patch Studio.
 *
 * Always removes settings, transients and scheduled work. Designs, quotes, their files and the
 * "Patch op maat" product are only removed when the owner ticked "Alles verwijderen bij deïnstalleren"
 * (Algemeen tab), because orders keep referring to them (SPEC §11).
 *
 * @package BorduurPatchStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove our data from the current site.
 */
function bps_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'bps_settings', array() );
	$wipe_all = is_array( $settings ) && ! empty( $settings['admin']['deleteDataOnUninstall'] );

	wp_clear_scheduled_hook( 'bps_cleanup' );

	// Transients (rate limits and our own), including their timeouts.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bps_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bps_' ) . '%',
			$wpdb->esc_like( '_site_transient_bps_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_bps_' ) . '%'
		)
	);

	delete_option( 'bps_settings' );
	delete_option( 'bps_db_version' );

	if ( ! $wipe_all ) {
		// Keep product link and reference counters so a reinstall continues where it left off.
		return;
	}

	// Designs and quotes (with their meta).
	foreach ( array( 'bps_design', 'bps_quote' ) as $post_type ) {
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 200", $post_type ) );
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( ! empty( $ids ) );
	}

	// The hidden "Patch op maat" product.
	$product_id = (int) get_option( 'bps_product_id', 0 );
	if ( $product_id && get_post_meta( $product_id, '_bps_patch_product', true ) ) {
		wp_delete_post( $product_id, true );
	}

	// Uploaded originals and previews.
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		bps_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'patch-studio' );
	}

	foreach ( array( 'bps_product_id', 'bps_ref_seq', 'bps_quote_seq' ) as $option ) {
		delete_option( $option );
	}
}

/**
 * Recursively delete a directory inside the uploads folder.
 *
 * @param string $dir Absolute path.
 */
function bps_uninstall_rmdir( string $dir ): void {
	$real    = realpath( $dir );
	$uploads = wp_upload_dir( null, false );
	$base    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
	// Safety: only ever delete .../uploads/patch-studio.
	if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || 'patch-studio' !== basename( $real ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bps_site_id ) {
		switch_to_blog( (int) $bps_site_id );
		bps_uninstall_site();
		restore_current_blog();
	}
} else {
	bps_uninstall_site();
}
