<?php
/**
 * Uninstall Patch Studio.
 *
 * Works from one list, Plugin::owned_data() (contract C2), so nothing is forgotten when a module adds data.
 * The main plugin file is not loaded in the uninstall context, so this file registers the same autoloader and
 * never boots modules; tables are dropped through $wpdb and Action Scheduler calls are guarded.
 *
 * Always (also without "Alles verwijderen"):
 * - every cron event whose hook starts with bps_ and every Action Scheduler action of our groups;
 * - secrets (AI provider key, licence key), the AI log, locks, caches and transients;
 * - the WooCommerce e-mail settings of our e-mails (woocommerce_bps_*_settings);
 * - designs that never became an order or a quote (draft, carted, saved) with their files: without the plugin
 *   the retention cleanup no longer runs, so they would otherwise stay forever (security-9).
 *   Ordered and quoted designs, orders, the settings and the products stay, so a reinstall continues.
 *
 * Only with "Alles verwijderen bij deïnstalleren" (Algemeen tab):
 * - all designs and quotes, the AI and credit tables (including bought design rounds and the ledger),
 *   every bps_* option, the products and the studio page the plugin created (only when they carry the plugin's
 *   own meta flag) and wp-content/uploads/patch-studio.
 *
 * @package BorduurPatchStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'BPS_DIR' ) ) {
	define( 'BPS_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BPS_FILE' ) ) {
	define( 'BPS_FILE', __DIR__ . '/borduur-patch-studio.php' );
}
if ( ! defined( 'BPS_VERSION' ) ) {
	define( 'BPS_VERSION', '1.0.0' );
}
if ( ! defined( 'BPS_DB_VERSION' ) ) {
	define( 'BPS_DB_VERSION', 1 );
}
if ( ! defined( 'BPS_URL' ) && function_exists( 'plugin_dir_url' ) ) {
	define( 'BPS_URL', plugin_dir_url( BPS_FILE ) );
}

require_once __DIR__ . '/includes/class-plugin.php';
spl_autoload_register( array( \BorduurPatchStudio\Plugin::class, 'autoload' ) );

/**
 * Remove our data from the current site.
 */
function bps_uninstall_site(): void {
	$owned    = \BorduurPatchStudio\Plugin::owned_data();
	$settings = get_option( 'bps_settings', array() );
	$wipe_all = is_array( $settings ) && ! empty( $settings['admin']['deleteDataOnUninstall'] );

	bps_uninstall_always( $owned );
	if ( $wipe_all ) {
		bps_uninstall_wipe( $owned );
	}
	wp_cache_flush();
}

/**
 * Everything that goes on every uninstall.
 *
 * @param array $owned Plugin::owned_data().
 */
function bps_uninstall_always( array $owned ): void {
	global $wpdb;

	// Scheduled work: listed hooks plus any event whose hook starts with bps_ (all argument sets).
	\BorduurPatchStudio\Plugin::clear_cron();
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( $owned['as_groups'] as $group ) {
			try {
				as_unschedule_all_actions( '', array(), $group );
			} catch ( \Throwable $e ) {
				unset( $e ); // Action Scheduler not initialised (WooCommerce inactive): nothing is queued then.
			}
		}
	}

	foreach ( $owned['always_options'] as $option ) {
		delete_option( $option );
	}
	foreach ( $owned['always_option_patterns'] as $pattern ) {
		bps_uninstall_delete_options_like( $pattern );
	}
	if ( is_multisite() && ! empty( $wpdb->sitemeta ) ) {
		// Network-wide transients of ours.
		foreach ( array( '_site_transient_bps_%', '_site_transient_timeout_bps_%' ) as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", bps_uninstall_like( $pattern ) ) );
		}
	}

	// Designs that never became an order or a quote, with their files.
	bps_uninstall_delete_designs( $owned['transient_designs'] );
}

/**
 * "Alles verwijderen": everything else the plugin owns.
 *
 * @param array $owned Plugin::owned_data().
 */
function bps_uninstall_wipe( array $owned ): void {
	global $wpdb;

	// Designs and quotes (with meta and files).
	foreach ( $owned['post_types'] as $post_type ) {
		$last = array();
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID LIMIT 200", $post_type ) ) );
			if ( $ids === $last ) {
				break; // Nothing could be deleted: stop instead of looping forever.
			}
			foreach ( $ids as $id ) {
				bps_uninstall_delete_post( $id );
			}
			$last = $ids;
		} while ( ! empty( $ids ) );
	}

	// Products the plugin created (each carries the plugin's own meta flag).
	foreach ( $owned['product_meta'] as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND p.post_type IN ('product','product_variation')", $meta_key ) );
		foreach ( $ids as $id ) {
			bps_uninstall_delete_product( (int) $id );
		}
	}

	// Pages the plugin created (only when marked, never a page the owner made himself).
	foreach ( $owned['page_meta'] as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND p.post_type = 'page'", $meta_key ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	// Tables: the module helpers first (they also drop their version options), then everything listed.
	foreach ( array( array( 'BorduurPatchStudio\AI\Schema', 'drop' ), array( 'BorduurPatchStudio\Services\Credits_Store_Wpdb', 'uninstall' ) ) as $callback ) {
		if ( class_exists( $callback[0] ) && is_callable( $callback ) ) {
			try {
				call_user_func( $callback );
			} catch ( \Throwable $e ) {
				unset( $e ); // The direct DROP below still removes the tables.
			}
		}
	}
	foreach ( $owned['tables'] as $table ) {
		if ( preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	// Uploaded originals, previews, AI images and name-tape previews.
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		foreach ( $owned['upload_dirs'] as $dir ) {
			bps_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . $dir );
		}
	}

	// Every option: the explicit list, then everything with our prefix.
	foreach ( $owned['options'] as $option ) {
		delete_option( $option );
	}
	foreach ( $owned['option_prefixes'] as $prefix ) {
		bps_uninstall_delete_options_like( $prefix . '%' );
	}
}

/**
 * Turn a pattern with `%` wildcards into a LIKE value where `_` is literal.
 *
 * @param string $pattern Pattern.
 */
function bps_uninstall_like( string $pattern ): string {
	global $wpdb;
	return implode( '%', array_map( array( $wpdb, 'esc_like' ), explode( '%', $pattern ) ) );
}

/**
 * Delete options matching a pattern (`%` wildcard) and drop them from the options cache.
 *
 * @param string $pattern Pattern.
 */
function bps_uninstall_delete_options_like( string $pattern ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", bps_uninstall_like( $pattern ) ) );
	foreach ( $names as $name ) {
		delete_option( (string) $name );
	}
}

/**
 * Delete the designs whose status is in $statuses (a design without status counts as draft), batch by batch.
 *
 * @param string[] $statuses Statuses.
 */
function bps_uninstall_delete_designs( array $statuses ): void {
	global $wpdb;
	if ( ! $statuses ) {
		return;
	}
	$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$no_meta  = in_array( 'draft', $statuses, true ) ? ' OR m.meta_value IS NULL' : '';
	$last_ids = array();
	do {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bps_status' WHERE p.post_type = 'bps_design' AND ( m.meta_value IN ({$in}){$no_meta} ) ORDER BY p.ID LIMIT 200",
					...array_values( $statuses )
				)
			)
		);
		// phpcs:enable
		if ( $ids === $last_ids ) {
			break;
		}
		foreach ( $ids as $id ) {
			bps_uninstall_delete_post( $id );
		}
		$last_ids = $ids;
	} while ( ! empty( $ids ) );
}

/**
 * Delete one design/quote post with its files (the Designs hooks that normally remove files are not loaded here).
 *
 * @param int $id Post ID.
 */
function bps_uninstall_delete_post( int $id ): void {
	if ( 'bps_design' === get_post_type( $id ) && class_exists( 'BorduurPatchStudio\Woo\Designs' ) ) {
		try {
			\BorduurPatchStudio\Woo\Designs::delete_files( $id );
		} catch ( \Throwable $e ) {
			unset( $e ); // Files outside our folder or already gone: the post itself is still removed.
		}
	}
	if ( ! wp_delete_post( $id, true ) ) {
		// Make sure a broken post does not stop the loop: remove the row directly.
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->posts, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Delete a product the plugin created (WooCommerce CRUD when available, so lookup tables are cleaned too).
 *
 * @param int $id Product ID.
 */
function bps_uninstall_delete_product( int $id ): void {
	if ( function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			$product->delete( true );
			return;
		}
	}
	wp_delete_post( $id, true );
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
	// Safety: only ever delete a direct child folder of uploads named patch-studio.
	if ( ! $real || ! $base || dirname( $real ) !== $base || 'patch-studio' !== basename( $real ) ) {
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
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $bps_site_id ) {
		switch_to_blog( (int) $bps_site_id );
		bps_uninstall_site();
		restore_current_blog();
	}
} else {
	bps_uninstall_site();
}
