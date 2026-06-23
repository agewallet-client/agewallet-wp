<?php
/**
 * AgeWallet OIDC Client — Uninstall Handler
 *
 * Fires when the user deletes the plugin from the WordPress admin (NOT on
 * deactivate). Cleans up all plugin state so no orphans remain in the DB
 * or filesystem.
 *
 * @package AgeWallet
 */

// Exit if not called by WordPress' uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Loop-local file-system variables ($site_ids, $site_id, $entries, $entry, $path) are introduced
// inside the multisite iteration / rrmdir helper. They do not leak to caller scope and they only
// live for the lifetime of the uninstall request. The phpcs prefix rule would force renaming them
// to $agewallet_* without functional benefit.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

/**
 * Delete every option whose name starts with the agewallet_ prefix.
 *
 * Uses a single SQL DELETE rather than enumerating each option key, because
 * the plugin writes ~30 options across credentials, guarding, appearance,
 * scripts, and WooCommerce groups, and the set grows with each feature.
 *
 * @param wpdb $wpdb WordPress database handle.
 */
function agewallet_uninstall_delete_options( $wpdb ) {
	// One-shot uninstall pass: prefix-pattern DELETE is the canonical shape for purging plugin
	// options. delete_option() per key would mean enumerating ~30 names and would still hit the
	// same writes plus extra read overhead. No caching concern at uninstall time.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'agewallet\\_%'"
	);

	// Transients live as options named '_transient_<key>' and
	// '_transient_timeout_<key>'. Catch both shapes.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_agewallet\\_%'
			OR option_name LIKE '\\_transient\\_timeout\\_agewallet\\_%'
			OR option_name LIKE '\\_site\\_transient\\_agewallet\\_%'
			OR option_name LIKE '\\_site\\_transient\\_timeout\\_agewallet\\_%'"
	);
}

/**
 * Clear the recurring cache-purge cron event and any other agewallet_ hooks.
 *
 * @return void
 */
function agewallet_uninstall_clear_cron() {
	wp_clear_scheduled_hook( 'agewallet_scheduled_purge_event' );
}

/**
 * Recursively remove the strict-mode HTML cache directory at
 * /wp-content/uploads/agewallet-cache/ if it exists. Honors the same
 * `agewallet_cache_directory` filter the runtime uses, in case an integrator
 * relocated the cache to a non-standard path.
 *
 * @return void
 */
function agewallet_uninstall_remove_cache_dir() {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$base_dir = trailingslashit( $upload_dir['basedir'] ) . 'agewallet-cache/';
	$cache_dir = trailingslashit(
		apply_filters( 'agewallet_cache_directory', $base_dir )
	);

	if ( ! is_dir( $cache_dir ) ) {
		return;
	}

	// Refuse to recursively delete anything outside wp-content/uploads/.
	// Belt-and-suspenders against a misbehaving cache_directory filter.
	$allowed_root = trailingslashit( $upload_dir['basedir'] );
	if ( 0 !== strpos( $cache_dir, $allowed_root ) ) {
		return;
	}

	agewallet_uninstall_rrmdir( $cache_dir );
}

/**
 * Recursive directory removal helper. Skips '.' and '..'.
 *
 * @param string $dir Absolute path to remove.
 * @return void
 */
function agewallet_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );
	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			agewallet_uninstall_rrmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	// WP_Filesystem is not guaranteed to be initialised during uninstall (it normally requires
	// admin context + filesystem credentials), so a direct rmdir on our own scoped cache dir
	// is the pragmatic choice. The $dir argument is constrained by remove_cache_dir() to live
	// inside the uploads root.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

global $wpdb;

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		agewallet_uninstall_delete_options( $wpdb );
		agewallet_uninstall_clear_cron();
		agewallet_uninstall_remove_cache_dir();
		restore_current_blog();
	}
} else {
	agewallet_uninstall_delete_options( $wpdb );
	agewallet_uninstall_clear_cron();
	agewallet_uninstall_remove_cache_dir();
}
