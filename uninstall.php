<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all data stored by the plugin.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─── Single site cleanup ─────────────────────────────────────────────────────

/**
 * Deletes all plugin options and drops the activity log table for one site.
 */
function ewpa_uninstall_site(): void {
	global $wpdb;

	delete_option( 'ewpa_enabled_abilities' );
	delete_option( 'ewpa_api_key' );
	delete_option( 'ewpa_bearer_enabled' );
	delete_option( 'ewpa_db_version' );
	delete_option( 'ewpa_keys_migrated_v18' );
	delete_option( 'ewpa_keys_migrated_v19' );

	$table = $wpdb->prefix . 'ewpa_activity_log';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS $table" );
}

ewpa_uninstall_site();

// ─── Multisite: clean each sub-site ──────────────────────────────────────────
if ( is_multisite() ) {
	$ewpa_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ewpa_sites as $ewpa_site_id ) {
		switch_to_blog( $ewpa_site_id );
		ewpa_uninstall_site();
		restore_current_blog();
	}
}
