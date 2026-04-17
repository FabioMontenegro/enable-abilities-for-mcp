<?php
/**
 * Plugin Name:       Enable Abilities for MCP
 * Description:       Manage which WordPress Abilities are exposed to MCP servers. Enable or disable each ability individually from the dashboard.
 * Version:           1.9.3
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Fabio Montenegro
 * Author URI:        https://fabiomontenegro.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       enable-abilities-for-mcp
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EWPA_VERSION', '1.9.3' );
define( 'EWPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EWPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EWPA_OPTION_KEY', 'ewpa_enabled_abilities' );
define( 'EWPA_API_KEY_OPTION', 'ewpa_api_key' );

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

// Includes.
require_once EWPA_PLUGIN_DIR . 'includes/admin.php';
require_once EWPA_PLUGIN_DIR . 'includes/abilities.php';
require_once EWPA_PLUGIN_DIR . 'includes/auth.php';

// Activation: set all abilities enabled by default.
register_activation_hook( __FILE__, 'ewpa_activate' );

/**
 * Plugin activation callback.
 *
 * Sets all abilities as enabled on first install.
 *
 * @return void
 */
function ewpa_activate() {
	if ( false === get_option( EWPA_OPTION_KEY ) ) {
		update_option( EWPA_OPTION_KEY, ewpa_get_all_ability_keys() );
	}
}

// Hooks.
add_filter( 'wp_register_ability_args', 'ewpa_filter_core_abilities', 10, 2 );
add_action( 'wp_abilities_api_init', 'ewpa_register_custom_abilities' );
add_action( 'wp_abilities_api_categories_init', 'ewpa_register_ability_categories' );

// Migration: rename Spanish keys to English on upgrade.
add_action( 'plugins_loaded', 'ewpa_maybe_migrate_keys' );


/*
 * ==========================================================================
 * KEY MIGRATION (v1.7 → v1.9)
 * ==========================================================================
 * Renames Spanish ability keys to English while preserving enabled/disabled
 * state. Runs once on upgrade.
 * ==========================================================================
 */

/**
 * Migrates ability keys from Spanish to English.
 *
 * @return void
 */
function ewpa_maybe_migrate_keys() {
	if ( get_option( 'ewpa_keys_migrated_v19' ) ) {
		return;
	}

	$enabled = get_option( EWPA_OPTION_KEY );
	if ( ! is_array( $enabled ) ) {
		update_option( 'ewpa_keys_migrated_v19', true );
		return;
	}

	$key_map = ewpa_get_legacy_key_map();

	// Check if any old key exists.
	$has_old_keys = false;
	foreach ( $enabled as $key ) {
		if ( isset( $key_map[ $key ] ) ) {
			$has_old_keys = true;
			break;
		}
	}

	if ( ! $has_old_keys ) {
		update_option( 'ewpa_keys_migrated_v19', true );
		return;
	}

	// Map old keys to new keys.
	$migrated = array();
	foreach ( $enabled as $key ) {
		$migrated[] = isset( $key_map[ $key ] ) ? $key_map[ $key ] : $key;
	}

	// Add new abilities (enabled by default on upgrade).
	$new_abilities = array(
		'ewpa/list-post-types',
		'ewpa/get-cpt-items',
		'ewpa/get-cpt-item',
		'ewpa/create-cpt-item',
		'ewpa/update-cpt-item',
		'ewpa/delete-cpt-item',
		'ewpa/get-cpt-taxonomies',
		'ewpa/assign-cpt-terms',
		// WooCommerce abilities (v1.9+).
		'ewpa/wc-get-products',
		'ewpa/wc-get-product',
		'ewpa/wc-update-product',
		'ewpa/wc-get-orders',
		'ewpa/wc-get-order',
		'ewpa/wc-update-order-status',
		'ewpa/wc-get-customers',
		// The Events Calendar abilities (v1.9+).
		'ewpa/tec-get-events',
		'ewpa/tec-get-event',
		'ewpa/tec-create-event',
		'ewpa/tec-update-event',
		// v1.9.2+.
		'ewpa/get-page',
		'ewpa/update-comment',
	);
	foreach ( $new_abilities as $key ) {
		if ( ! in_array( $key, $migrated, true ) ) {
			$migrated[] = $key;
		}
	}

	update_option( EWPA_OPTION_KEY, $migrated );
	update_option( 'ewpa_keys_migrated_v19', true );

	// Schedule a one-time admin notice.
	set_transient( 'ewpa_migration_notice', true, 60 );
}

/**
 * Returns the mapping from old Spanish keys to new English keys.
 *
 * @return array
 */
function ewpa_get_legacy_key_map() {
	return array(
		'ewpa/obtener-posts'        => 'ewpa/get-posts',
		'ewpa/obtener-post'         => 'ewpa/get-post',
		'ewpa/obtener-categorias'   => 'ewpa/get-categories',
		'ewpa/obtener-tags'         => 'ewpa/get-tags',
		'ewpa/obtener-paginas'      => 'ewpa/get-pages',
		'ewpa/obtener-comentarios'  => 'ewpa/get-comments',
		'ewpa/obtener-medios'       => 'ewpa/get-media',
		'ewpa/obtener-usuarios'     => 'ewpa/get-users',
		'ewpa/crear-post'           => 'ewpa/create-post',
		'ewpa/actualizar-post'      => 'ewpa/update-post',
		'ewpa/eliminar-post'        => 'ewpa/delete-post',
		'ewpa/crear-categoria'      => 'ewpa/create-category',
		'ewpa/crear-tag'            => 'ewpa/create-tag',
		'ewpa/crear-pagina'         => 'ewpa/create-page',
		'ewpa/moderar-comentario'   => 'ewpa/moderate-comment',
		'ewpa/responder-comentario' => 'ewpa/reply-comment',
		'ewpa/subir-imagen'         => 'ewpa/upload-image',
		'ewpa/obtener-rankmath'     => 'ewpa/get-rankmath',
		'ewpa/actualizar-rankmath'  => 'ewpa/update-rankmath',
		'ewpa/buscar-reemplazar'    => 'ewpa/search-replace',
		'ewpa/estadisticas-sitio'   => 'ewpa/site-stats',
	);
}


/*
 * ==========================================================================
 * ABILITIES REGISTRY
 * ==========================================================================
 * Central data structure defining all available abilities with metadata.
 * Used by both the admin UI and the registration functions.
 * ==========================================================================
 */

/**
 * Returns the registry of all abilities organized by section.
 *
 * @return array
 */
function ewpa_get_abilities_registry() {
	return array(
		'core'        => array(
			'section_label' => __( 'WordPress Core', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Native WordPress core abilities. Exposed to MCP with the public flag.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-wordpress',
			'abilities'     => array(
				'core/get-site-info'        => array(
					'label' => __( 'Site Information', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'General site data: name, URL, description, language, timezone, WP version.', 'enable-abilities-for-mcp' ),
				),
				'core/get-user-info'        => array(
					'label' => __( 'User Information', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Current user data: name, email, role, avatar.', 'enable-abilities-for-mcp' ),
				),
				'core/get-environment-info' => array(
					'label' => __( 'Environment Information', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Technical details: PHP version, DB server, environment type.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'read'        => array(
			'section_label' => __( 'Read (Query Only)', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Only query data, do not modify anything. Safest to expose via MCP.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-visibility',
			'abilities'     => array(
				'ewpa/get-posts'      => array(
					'label' => __( 'Get Posts', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List posts with filters by status, category, count, and order.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-post'       => array(
					'label' => __( 'Get Single Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Full post detail by ID, including content, meta data, and featured image.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-categories' => array(
					'label' => __( 'Get Categories', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List all categories with ID, name, slug, and post count.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-tags'       => array(
					'label' => __( 'Get Tags', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List all tags with ID, name, slug, and post count.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-pages'      => array(
					'label' => __( 'Get Pages', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List site pages with title, status, and hierarchy.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-page'       => array(
					'label' => __( 'Get Single Page', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Full page detail by ID, including content, template, hierarchy, and SEO metadata.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-comments'   => array(
					'label' => __( 'Get Comments', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List comments with filters by status, post, and count.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-media'      => array(
					'label' => __( 'Get Media', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List media library files with filters by type.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-users'      => array(
					'label' => __( 'Get Users', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List site users with ID, name, email, and role.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'write'       => array(
			'section_label' => __( 'Write (Create & Modify)', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Create or modify content. Require appropriate MCP user permissions.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-edit',
			'section_badge' => 'warning',
			'abilities'     => array(
				'ewpa/create-post'      => array(
					'label' => __( 'Create Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Create a new post with title, content, categories, tags, featured image, and more.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/update-post'      => array(
					'label' => __( 'Update Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Modify an existing post. Only updates the fields provided.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/delete-post'      => array(
					'label' => __( 'Delete Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Send a post to trash or permanently delete it.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/create-category'  => array(
					'label' => __( 'Create Category', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Create a new category with name, slug, description, and parent.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/create-tag'       => array(
					'label' => __( 'Create Tag', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Create a new tag with name, slug, and description.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/create-page'      => array(
					'label' => __( 'Create Page', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Create a new page with title, content, status, and parent page.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/moderate-comment' => array(
					'label' => __( 'Moderate Comment', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Change comment status: approve, hold, spam, or trash.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/reply-comment'    => array(
					'label' => __( 'Reply to Comment', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Reply to an existing comment as the authenticated user.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/update-comment'   => array(
					'label' => __( 'Update Comment', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Update content, author name, email, or WordPress user of an existing comment.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/upload-image'     => array(
					'label' => __( 'Upload Image from URL', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Download an image from an external URL and register it in the media library. Returns the attachment ID.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'seo'         => array(
			'section_label'  => __( 'SEO — Rank Math', 'enable-abilities-for-mcp' ),
			'section_desc'   => __( 'Query and update Rank Math SEO metadata on posts and pages.', 'enable-abilities-for-mcp' ),
			'section_icon'   => 'dashicons-search',
			'section_notice' => 'ewpa_section_notice_rankmath',
			'abilities'      => array(
				'ewpa/get-rankmath'    => array(
					'label' => __( 'Get Rank Math Metadata', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Get Rank Math SEO metadata for a post or page: title, description, keywords, robots, Open Graph, and more.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/update-rankmath' => array(
					'label' => __( 'Update Rank Math SEO / Focus Keyword', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Update focus keyword, SEO title, description, canonical URL, robots, and Open Graph via Rank Math.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'utility'     => array(
			'section_label' => __( 'Utility', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Auxiliary tools that complement the workflow.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-admin-tools',
			'abilities'     => array(
				'ewpa/search-replace' => array(
					'label' => __( 'Search and Replace', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Search for text in a post content and replace it with another.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/site-stats'     => array(
					'label' => __( 'Site Statistics', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Site summary: total posts, pages, categories, tags, comments, and users.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'woocommerce' => array(
			'section_label'  => __( 'WooCommerce', 'enable-abilities-for-mcp' ),
			'section_desc'   => __( 'Query and manage WooCommerce products, orders, and customers using the native WooCommerce API (HPOS-compatible).', 'enable-abilities-for-mcp' ),
			'section_icon'   => 'dashicons-cart',
			'section_badge'  => 'warning',
			'section_notice' => 'ewpa_section_notice_woocommerce',
			'abilities'      => array(
				'ewpa/wc-get-products'        => array(
					'label' => __( 'Get Products', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List products with price, SKU, stock status, categories, and type. Supports search and category filter.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/wc-get-product'         => array(
					'label' => __( 'Get Single Product', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Full product detail: price, SKU, stock, description, gallery, attributes, and variations for variable products.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/wc-update-product'      => array(
					'label' => __( 'Update Product', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Update product price, sale price, stock quantity, status, or description using the WooCommerce API.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/wc-get-orders'          => array(
					'label' => __( 'Get Orders', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List orders with customer, total, status, and date. HPOS-compatible. Supports filter by status.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/wc-get-order'           => array(
					'label' => __( 'Get Single Order', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Full order detail: line items, customer billing/shipping, totals, status history, and notes.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/wc-update-order-status' => array(
					'label' => __( 'Update Order Status', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Change the status of an order (e.g., pending → processing → completed) with an optional note.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/wc-get-customers'       => array(
					'label' => __( 'Get Customers', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List customers with email, name, total spent, and order count. Supports search by email or name.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'tec'         => array(
			'section_label'  => __( 'The Events Calendar', 'enable-abilities-for-mcp' ),
			'section_desc'   => __( 'Query and manage events from The Events Calendar plugin, including dates, venues, and organizers.', 'enable-abilities-for-mcp' ),
			'section_icon'   => 'dashicons-calendar-alt',
			'section_notice' => 'ewpa_section_notice_tec',
			'abilities'      => array(
				'ewpa/tec-get-events'   => array(
					'label' => __( 'Get Events', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List events with start/end date, venue, and organizer. Supports upcoming/past filter and date range.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/tec-get-event'    => array(
					'label' => __( 'Get Single Event', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Full event detail with resolved venue address and organizer contact info in a single call.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/tec-create-event' => array(
					'label' => __( 'Create Event', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Create a new event with title, description, start/end dates, timezone, and venue (by ID or name).', 'enable-abilities-for-mcp' ),
				),
				'ewpa/tec-update-event' => array(
					'label' => __( 'Update Event', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Update an existing event: title, description, start/end dates, timezone, or venue.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'cpt'         => array(
			'section_label'  => __( 'Custom Post Types', 'enable-abilities-for-mcp' ),
			'section_desc'   => __( 'Discover and manage Custom Post Types registered by plugins or themes. Excludes posts, pages, and attachments which have dedicated abilities.', 'enable-abilities-for-mcp' ),
			'section_icon'   => 'dashicons-archive',
			'section_badge'  => 'warning',
			'section_notice' => 'ewpa_section_notice_cpt',
			'abilities'      => array(
				'ewpa/list-post-types'    => array(
					'label' => __( 'List Post Types', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List all public Custom Post Types with their labels, taxonomies, supported features, and capabilities.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-cpt-items'      => array(
					'label' => __( 'Get CPT Items', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List items from any CPT with filters by status, count, order, and search.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-cpt-item'       => array(
					'label' => __( 'Get Single CPT Item', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Get full detail of a CPT item by ID, including content, meta data, taxonomies, and featured image.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/create-cpt-item'    => array(
					'label' => __( 'Create CPT Item', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Create a new item in any CPT with title, content, status, taxonomies, and featured image.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/update-cpt-item'    => array(
					'label' => __( 'Update CPT Item', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Update an existing CPT item. Only modifies the fields provided.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/delete-cpt-item'    => array(
					'label' => __( 'Delete CPT Item', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Send a CPT item to trash or permanently delete it.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/get-cpt-taxonomies' => array(
					'label' => __( 'Get CPT Taxonomies', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'List taxonomies and their terms for a given CPT.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/assign-cpt-terms'   => array(
					'label' => __( 'Assign Terms to CPT Item', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Assign taxonomy terms to a CPT item. Can add to or replace existing terms.', 'enable-abilities-for-mcp' ),
				),
			),
		),
	);
}

/**
 * Returns a flat array of all ability keys.
 *
 * @return array
 */
function ewpa_get_all_ability_keys() {
	$keys = array();
	foreach ( ewpa_get_abilities_registry() as $section ) {
		$keys = array_merge( $keys, array_keys( $section['abilities'] ) );
	}
	return $keys;
}

/**
 * Checks if a specific ability is enabled.
 *
 * @param string $ability_key The ability key to check.
 * @return bool
 */
function ewpa_is_ability_enabled( $ability_key ) {
	$enabled = get_option( EWPA_OPTION_KEY, null );

	// First install: all enabled by default.
	if ( null === $enabled ) {
		return true;
	}

	return in_array( $ability_key, (array) $enabled, true );
}


/*
 * ==========================================================================
 * SECTION NOTICE CALLBACKS
 * ==========================================================================
 */

/**
 * Section notice for CPT: shows info when no CPTs are detected.
 *
 * @return string
 */
function ewpa_section_notice_cpt() {
	$cpt_types = get_post_types(
		array(
			'public'   => true,
			'_builtin' => false,
		),
		'names'
	);

	// Also check show_in_rest CPTs.
	$rest_types = get_post_types(
		array(
			'show_in_rest' => true,
			'_builtin'     => false,
		),
		'names'
	);

	$all_cpts = array_unique( array_merge( $cpt_types, $rest_types ) );

	// Remove WordPress internal non-content types.
	$internal = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
	$all_cpts = array_diff( $all_cpts, $internal );

	if ( ! empty( $all_cpts ) ) {
		return '';
	}

	return '<div class="ewpa-section-notice ewpa-section-notice-info">'
		. '<span class="dashicons dashicons-info"></span> '
		. esc_html__( 'No Custom Post Types detected on this site. These abilities will become available when a plugin or theme registers custom post types (e.g., WooCommerce, ACF, JetEngine).', 'enable-abilities-for-mcp' )
		. '</div>';
}

/**
 * Section notice for SEO: shows info when Rank Math is not active.
 *
 * @return string
 */
function ewpa_section_notice_rankmath() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
		return '';
	}

	return '<div class="ewpa-section-notice ewpa-section-notice-info">'
		. '<span class="dashicons dashicons-info"></span> '
		. esc_html__( 'Rank Math SEO plugin is not active. These abilities require Rank Math to function.', 'enable-abilities-for-mcp' )
		. '</div>';
}

/**
 * Section notice for WooCommerce: shows info when WooCommerce is not active.
 *
 * @return string
 */
function ewpa_section_notice_woocommerce() {
	if ( class_exists( 'WooCommerce' ) ) {
		return '';
	}

	return '<div class="ewpa-section-notice ewpa-section-notice-info">'
		. '<span class="dashicons dashicons-info"></span> '
		. esc_html__( 'WooCommerce is not active. These abilities require WooCommerce to function.', 'enable-abilities-for-mcp' )
		. '</div>';
}

/**
 * Section notice for The Events Calendar: shows info when the plugin is not active.
 *
 * @return string
 */
function ewpa_section_notice_tec() {
	if ( class_exists( 'Tribe__Events__Main' ) ) {
		return '';
	}

	return '<div class="ewpa-section-notice ewpa-section-notice-info">'
		. '<span class="dashicons dashicons-info"></span> '
		. esc_html__( 'The Events Calendar plugin is not active. These abilities require The Events Calendar to function.', 'enable-abilities-for-mcp' )
		. '</div>';
}
