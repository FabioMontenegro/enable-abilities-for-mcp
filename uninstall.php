<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all data stored by the plugin.
 *
 * @package EnableAbilitiesForMCP
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Single site.
delete_option('ewpa_enabled_abilities');

// Multisite: clean each site.
if (is_multisite()) {
    $sites = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        delete_option('ewpa_enabled_abilities');
        restore_current_blog();
    }
}
