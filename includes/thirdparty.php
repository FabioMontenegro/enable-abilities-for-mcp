<?php
/**
 * Third-party ability management.
 *
 * Other plugins (Fluent Forms, etc.) register their own abilities via
 * wp_register_ability() with mcp.public metadata, which makes them reachable
 * through every MCP server on the site — including this plugin's OAuth
 * connector (the adapter's discover/execute meta-tools enumerate the whole
 * registry). This module lets the site owner toggle those abilities from the
 * same dashboard: a disabled third-party ability is unregistered late in
 * wp_abilities_api_init, so it is simply never exposed anywhere.
 *
 * Model: denylist (option ewpa_thirdparty_disabled). New abilities from other
 * plugins start enabled, matching this plugin's own activation behavior. A
 * snapshot of every third-party ability seen (option ewpa_thirdparty_seen)
 * backs the admin UI, so disabled abilities remain listed and re-enablable.
 *
 * @package EnableAbilitiesForMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the third-party ability names the site owner disabled.
 *
 * @return string[]
 */
function ewpa_tp_get_disabled(): array {
	$disabled = get_option( 'ewpa_thirdparty_disabled', array() );

	return is_array( $disabled ) ? $disabled : array();
}

/**
 * Returns the snapshot of third-party abilities seen on the last registry pass.
 *
 * Keyed by ability name; each entry has label, desc, and category.
 *
 * @return array<string, array{label: string, desc: string, category: string}>
 */
function ewpa_tp_get_seen(): array {
	$seen = get_option( 'ewpa_thirdparty_seen', array() );

	return is_array( $seen ) ? $seen : array();
}

/**
 * Whether an ability name belongs to a third-party plugin.
 *
 * Everything not registered by this plugin (ewpa/*), not an adapter
 * meta-tool (mcp-adapter/*), and not one of the core abilities this
 * plugin already manages through its own registry.
 *
 * @param string $name Fully qualified ability name (namespace/ability).
 * @return bool
 */
function ewpa_tp_is_thirdparty( string $name ): bool {
	if ( str_starts_with( $name, 'ewpa/' ) || str_starts_with( $name, 'mcp-adapter/' ) ) {
		return false;
	}

	return ! in_array( $name, ewpa_get_all_ability_keys(), true );
}

// Runs after every other plugin registered its abilities (Fluent Forms & co.
// use this same hook at default priority).
add_action( 'wp_abilities_api_init', 'ewpa_tp_enforce', PHP_INT_MAX );

/**
 * Snapshots third-party abilities and unregisters the disabled ones.
 *
 * @return void
 */
function ewpa_tp_enforce(): void {
	if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_unregister_ability' ) ) {
		return;
	}

	$disabled = ewpa_tp_get_disabled();
	$seen     = array();

	foreach ( wp_get_abilities() as $ability ) {
		$name = $ability->get_name();

		if ( ! ewpa_tp_is_thirdparty( $name ) ) {
			continue;
		}

		$seen[ $name ] = array(
			'label'    => $ability->get_label(),
			'desc'     => $ability->get_description(),
			'category' => $ability->get_category(),
		);

		if ( in_array( $name, $disabled, true ) ) {
			wp_unregister_ability( $name );
		}
	}

	ksort( $seen );

	if ( ewpa_tp_get_seen() !== $seen ) {
		update_option( 'ewpa_thirdparty_seen', $seen, false );
	}
}

/**
 * Groups the seen third-party abilities by namespace for the admin UI.
 *
 * @return array<string, array<string, array{label: string, desc: string, category: string}>>
 */
function ewpa_tp_get_sections(): array {
	$sections = array();

	foreach ( ewpa_tp_get_seen() as $name => $info ) {
		$namespace = explode( '/', $name )[0];

		$sections[ $namespace ][ $name ] = $info;
	}

	ksort( $sections );

	return $sections;
}
