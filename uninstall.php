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

// Single site.
delete_option( 'ewpa_enabled_abilities' );
delete_option( 'ewpa_api_key' );
delete_option( 'ewpa_keys_migrated_v18' );
delete_option( 'ewpa_keys_migrated_v19' );

// Multisite: clean each site.
if ( is_multisite() ) {
	$ewpa_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ewpa_sites as $ewpa_site_id ) {
		switch_to_blog( $ewpa_site_id );
		delete_option( 'ewpa_enabled_abilities' );
		delete_option( 'ewpa_api_key' );
		delete_option( 'ewpa_keys_migrated_v18' );
		delete_option( 'ewpa_keys_migrated_v19' );
		restore_current_blog();
	}
}
