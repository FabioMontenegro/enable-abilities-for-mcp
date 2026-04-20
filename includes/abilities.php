<?php
/**
 * Ability registration for Enable Abilities for MCP.
 *
 * Each ability is only registered if enabled in the admin settings.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a post meta value as string, with a fallback default.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @param string $fallback Fallback value.
 * @return string
 */
function ewpa_get_meta_string( $post_id, $key, $fallback = '' ) {
	$value = get_post_meta( $post_id, $key, true );
	return $value ? $value : $fallback;
}

/**
 * Validates a post type as a valid custom post type (not built-in).
 *
 * @param string $post_type The post type slug to validate.
 * @return WP_Post_Type|WP_Error The post type object on success, WP_Error on failure.
 */
function ewpa_validate_cpt( $post_type ) {
	$post_type = sanitize_key( $post_type );

	$builtin_excluded = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	if ( in_array( $post_type, $builtin_excluded, true ) ) {
		return new WP_Error(
			'builtin_type',
			__( 'This content type has dedicated abilities. Use the specific abilities for posts, pages, or media instead.', 'enable-abilities-for-mcp' )
		);
	}

	if ( ! post_type_exists( $post_type ) ) {
		return new WP_Error(
			'invalid_post_type',
			__( 'The specified content type does not exist.', 'enable-abilities-for-mcp' )
		);
	}

	$cpt_obj = get_post_type_object( $post_type );

	if ( ! $cpt_obj->public && ! $cpt_obj->show_in_rest ) {
		return new WP_Error(
			'private_post_type',
			__( 'This content type is not publicly accessible.', 'enable-abilities-for-mcp' )
		);
	}

	return $cpt_obj;
}

/**
 * Returns list of WordPress core internal meta keys that should not be written to.
 *
 * @return array
 */
function ewpa_get_wp_internal_meta_keys() {
	return array(
		'_edit_lock',
		'_edit_last',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_old_slug',
		'_encloseme',
		'_pingme',
		'_wp_attached_file',
		'_wp_attachment_metadata',
	);
}

/*
 * ==========================================================================
 * CORE ABILITIES FILTER
 * ==========================================================================
 * WordPress 6.9 core abilities exist but aren't exposed to MCP by default.
 * This filter adds the meta.mcp.public flag for enabled core abilities.
 * ==========================================================================
 */

/**
 * Exposes enabled core abilities to MCP.
 *
 * @param array  $args         The ability arguments.
 * @param string $ability_name The ability name.
 * @return array
 */
function ewpa_filter_core_abilities( array $args, string $ability_name ): array {
	$core_abilities = array(
		'core/get-site-info',
		'core/get-user-info',
		'core/get-environment-info',
	);

	if ( ! in_array( $ability_name, $core_abilities, true ) || ! ewpa_is_ability_enabled( $ability_name ) ) {
		return $args;
	}

	$args['meta']['mcp']['public'] = true;

	if ( ! empty( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input ) use ( $ability_name, $original ) {
			$result = $original( $input );
			ewpa_log_activity( get_current_user_id(), $ability_name );
			return $result;
		};
	}

	return $args;
}

/*
 * ==========================================================================
 * ABILITY CATEGORIES
 * ==========================================================================
 */

/**
 * Registers ability categories for the Abilities Explorer.
 */
function ewpa_register_ability_categories(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'content-management',
		array(
			'label'       => __( 'Content Management', 'enable-abilities-for-mcp' ),
			'description' => __( 'Abilities to create, read, update, and delete blog content.', 'enable-abilities-for-mcp' ),
		)
	);

	wp_register_ability_category(
		'user-management',
		array(
			'label'       => __( 'User Management', 'enable-abilities-for-mcp' ),
			'description' => __( 'Abilities to query site user information.', 'enable-abilities-for-mcp' ),
		)
	);

	wp_register_ability_category(
		'site-information',
		array(
			'label'       => __( 'Site Information', 'enable-abilities-for-mcp' ),
			'description' => __( 'Abilities to get general information and site statistics.', 'enable-abilities-for-mcp' ),
		)
	);

	wp_register_ability_category(
		'cpt-management',
		array(
			'label'       => __( 'Custom Post Types', 'enable-abilities-for-mcp' ),
			'description' => __( 'Abilities to discover and manage custom post types registered by plugins or themes.', 'enable-abilities-for-mcp' ),
		)
	);
}

/*
 * ==========================================================================
 * CUSTOM ABILITIES REGISTRATION
 * ==========================================================================
 * Each ability checks ewpa_is_ability_enabled() before registering.
 * ==========================================================================
 */

/**
 * Registers all enabled custom abilities.
 */
function ewpa_register_custom_abilities(): void {
	/*
	 * ======================================================================
	 * SECTION A: READ ABILITIES
	 * ======================================================================
	 */

	// ── A1: Get Posts ───────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-posts' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-posts',
			array(
				'label'               => __( 'Get Posts', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves a list of blog posts with optional filters by status, category, count, and order.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'numberposts'   => array(
							'type'        => 'integer',
							'description' => 'Number of posts to retrieve (max. 100)',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'status'        => array(
							'type'        => 'string',
							'description' => 'Post status: publish, draft, pending, private, trash',
							'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
							'default'     => 'publish',
						),
						'category_name' => array(
							'type'        => 'string',
							'description' => 'Category slug to filter by (optional)',
						),
						'tag'           => array(
							'type'        => 'string',
							'description' => 'Tag slug to filter by (optional)',
						),
						'orderby'       => array(
							'type'        => 'string',
							'description' => 'Order by: date, title, modified, rand',
							'enum'        => array( 'date', 'title', 'modified', 'rand' ),
							'default'     => 'date',
						),
						'order'         => array(
							'type'        => 'string',
							'description' => 'Order direction: ASC or DESC',
							'enum'        => array( 'ASC', 'DESC' ),
							'default'     => 'DESC',
						),
						's'             => array(
							'type'        => 'string',
							'description' => 'Search term to filter posts (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'ID'           => array( 'type' => 'integer' ),
							'post_title'   => array( 'type' => 'string' ),
							'post_status'  => array( 'type' => 'string' ),
							'post_date'    => array( 'type' => 'string' ),
							'post_excerpt' => array( 'type' => 'string' ),
							'post_author'  => array( 'type' => 'string' ),
							'permalink'    => array( 'type' => 'string' ),
							'categories'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'tags'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$allowed_status  = array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' );
					$allowed_orderby = array( 'date', 'title', 'modified', 'rand' );
					$allowed_order   = array( 'ASC', 'DESC' );

					$numberposts = min( 100, max( 1, absint( $input['numberposts'] ?? 10 ) ) );
					$post_status = in_array( $input['status'] ?? 'publish', $allowed_status, true )
						? $input['status'] : 'publish';
					$orderby = in_array( $input['orderby'] ?? 'date', $allowed_orderby, true )
						? $input['orderby'] : 'date';
					$order = in_array( $input['order'] ?? 'DESC', $allowed_order, true )
						? $input['order'] : 'DESC';

					$args = array(
						'numberposts' => $numberposts,
						'post_status' => $post_status,
						'orderby'     => $orderby,
						'order'       => $order,
					);
					if ( ! empty( $input['category_name'] ) ) {
						$args['category_name'] = sanitize_text_field( $input['category_name'] );
					}
					if ( ! empty( $input['tag'] ) ) {
						$args['tag'] = sanitize_text_field( $input['tag'] );
					}
					if ( ! empty( $input['s'] ) ) {
						$args['s'] = sanitize_text_field( $input['s'] );
					}

					$posts  = get_posts( $args );
					$result = array();

					foreach ( $posts as $post ) {
						$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
						$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
						$result[] = array(
							'ID'           => $post->ID,
							'post_title'   => $post->post_title,
							'post_status'  => $post->post_status,
							'post_date'    => $post->post_date,
							'post_excerpt' => $post->post_excerpt,
							'post_author'  => get_the_author_meta( 'display_name', $post->post_author ),
							'permalink'    => get_permalink( $post->ID ),
							'categories'   => $cats,
							'tags'         => $tags,
						);
					}

					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A2: Get Single Post ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-post' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-post',
			array(
				'label'               => __( 'Get Single Post', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves all details of a specific post by ID, including full content, metadata, and featured image.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID to retrieve',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ID'               => array( 'type' => 'integer' ),
						'post_title'       => array( 'type' => 'string' ),
						'post_content'     => array( 'type' => 'string' ),
						'post_excerpt'     => array( 'type' => 'string' ),
						'post_status'      => array( 'type' => 'string' ),
						'post_date'        => array( 'type' => 'string' ),
						'post_modified'    => array( 'type' => 'string' ),
						'post_author'      => array( 'type' => 'string' ),
						'permalink'        => array( 'type' => 'string' ),
						'featured_image'   => array( 'type' => 'string' ),
						'categories'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'tags'             => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'meta_title'       => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post = get_post( $post_id );
					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error( 'not_found', 'Post not found.' );
					}

					$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'full' );
					$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
					$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

					$meta_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
					if ( ! $meta_title ) {
						$meta_title = get_post_meta( $post->ID, 'rank_math_title', true );
					}
					$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
					if ( ! $meta_desc ) {
						$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
					}

					return array(
						'ID'               => $post->ID,
						'post_title'       => $post->post_title,
						'post_content'     => $post->post_content,
						'post_excerpt'     => $post->post_excerpt,
						'post_status'      => $post->post_status,
						'post_date'        => $post->post_date,
						'post_modified'    => $post->post_modified,
						'post_author'      => get_the_author_meta( 'display_name', $post->post_author ),
						'permalink'        => get_permalink( $post->ID ),
						'featured_image'   => $thumbnail_url ? $thumbnail_url : '',
						'categories'       => $cats,
						'tags'             => $tags,
						'meta_title'       => $meta_title,
						'meta_description' => $meta_desc,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A2b: Get Single Page ────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-page' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-page',
			array(
				'label'               => __( 'Get Single Page', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves all details of a specific page by ID, including full content, template, hierarchy, and SEO metadata.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'page_id' ),
					'properties' => array(
						'page_id' => array(
							'type'        => 'integer',
							'description' => 'Page ID to retrieve',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ID'               => array( 'type' => 'integer' ),
						'post_title'       => array( 'type' => 'string' ),
						'post_content'     => array( 'type' => 'string' ),
						'post_excerpt'     => array( 'type' => 'string' ),
						'post_status'      => array( 'type' => 'string' ),
						'post_date'        => array( 'type' => 'string' ),
						'post_modified'    => array( 'type' => 'string' ),
						'post_author'      => array( 'type' => 'string' ),
						'permalink'        => array( 'type' => 'string' ),
						'featured_image'   => array( 'type' => 'string' ),
						'post_parent'      => array( 'type' => 'integer' ),
						'menu_order'       => array( 'type' => 'integer' ),
						'page_template'    => array( 'type' => 'string' ),
						'meta_title'       => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$page_id = absint( $input['page_id'] );
					$page    = get_post( $page_id );

					if ( ! $page || 'page' !== $page->post_type ) {
						return new WP_Error( 'not_found', 'Page not found.' );
					}

					$thumbnail_url = get_the_post_thumbnail_url( $page->ID, 'full' );

					$meta_title = get_post_meta( $page->ID, '_yoast_wpseo_title', true );
					if ( ! $meta_title ) {
						$meta_title = get_post_meta( $page->ID, 'rank_math_title', true );
					}
					$meta_desc = get_post_meta( $page->ID, '_yoast_wpseo_metadesc', true );
					if ( ! $meta_desc ) {
						$meta_desc = get_post_meta( $page->ID, 'rank_math_description', true );
					}

					return array(
						'ID'               => $page->ID,
						'post_title'       => $page->post_title,
						'post_content'     => $page->post_content,
						'post_excerpt'     => $page->post_excerpt,
						'post_status'      => $page->post_status,
						'post_date'        => $page->post_date,
						'post_modified'    => $page->post_modified,
						'post_author'      => get_the_author_meta( 'display_name', $page->post_author ),
						'permalink'        => get_permalink( $page->ID ),
						'featured_image'   => $thumbnail_url ? $thumbnail_url : '',
						'post_parent'      => (int) $page->post_parent,
						'menu_order'       => (int) $page->menu_order,
						'page_template'    => get_page_template_slug( $page->ID ),
						'meta_title'       => $meta_title ? $meta_title : '',
						'meta_description' => $meta_desc ? $meta_desc : '',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A3: Get Categories ──────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-categories' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-categories',
			array(
				'label'               => __( 'Get Categories', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves all blog categories with their ID, name, slug, description, and post count.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => 'Hide categories with no posts (true/false)',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'term_id'     => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'count'       => array( 'type' => 'integer' ),
							'parent'      => array( 'type' => 'integer' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$categories = get_categories(
						array(
							'hide_empty' => $input['hide_empty'] ?? false,
						)
					);
					$result = array();
					foreach ( $categories as $cat ) {
						$result[] = array(
							'term_id'     => $cat->term_id,
							'name'        => $cat->name,
							'slug'        => $cat->slug,
							'description' => $cat->description,
							'count'       => $cat->count,
							'parent'      => $cat->parent,
						);
					}
					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A4: Get Tags ────────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-tags' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-tags',
			array(
				'label'               => __( 'Get Tags', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves all blog tags with their ID, name, slug, and post count.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => 'Hide tags with no posts (true/false)',
							'default'     => false,
						),
						'number'     => array(
							'type'        => 'integer',
							'description' => 'Maximum number of tags to retrieve',
							'default'     => 100,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'term_id'     => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'count'       => array( 'type' => 'integer' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$tags = get_tags(
						array(
							'hide_empty' => ! empty( $input['hide_empty'] ),
							'number'     => min( 500, max( 1, absint( $input['number'] ?? 100 ) ) ),
						)
					);
					$result = array();
					foreach ( $tags as $tag ) {
						$result[] = array(
							'term_id'     => $tag->term_id,
							'name'        => $tag->name,
							'slug'        => $tag->slug,
							'description' => $tag->description,
							'count'       => $tag->count,
						);
					}
					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A5: Get Pages ───────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-pages' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-pages',
			array(
				'label'               => __( 'Get Pages', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves site pages with their title, status, content, and hierarchy (parent/child).', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'numberposts' => array(
							'type'        => 'integer',
							'description' => 'Number of pages to retrieve',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'status'      => array(
							'type'        => 'string',
							'description' => 'Page status: publish, draft, private',
							'enum'        => array( 'publish', 'draft', 'private', 'any' ),
							'default'     => 'publish',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'ID'          => array( 'type' => 'integer' ),
							'post_title'  => array( 'type' => 'string' ),
							'post_status' => array( 'type' => 'string' ),
							'post_parent' => array( 'type' => 'integer' ),
							'menu_order'  => array( 'type' => 'integer' ),
							'permalink'   => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$allowed_status = array( 'publish', 'draft', 'private', 'any' );
					$numberposts = min( 100, max( 1, absint( $input['numberposts'] ?? 20 ) ) );
					$post_status = in_array( $input['status'] ?? 'publish', $allowed_status, true )
						? $input['status'] : 'publish';

					$pages = get_posts(
						array(
							'post_type'   => 'page',
							'numberposts' => $numberposts,
							'post_status' => $post_status,
							'orderby'     => 'menu_order',
							'order'       => 'ASC',
						)
					);
					$result = array();
					foreach ( $pages as $page ) {
						$result[] = array(
							'ID'          => $page->ID,
							'post_title'  => $page->post_title,
							'post_status' => $page->post_status,
							'post_parent' => $page->post_parent,
							'menu_order'  => $page->menu_order,
							'permalink'   => get_permalink( $page->ID ),
						);
					}
					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A6: Get Comments ────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-comments' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-comments',
			array(
				'label'               => __( 'Get Comments', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves blog comments with optional filters by status, post, and count. Useful for moderation and analysis.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'number'  => array(
							'type'        => 'integer',
							'description' => 'Number of comments to retrieve',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'status'  => array(
							'type'        => 'string',
							'description' => 'Comment status: approve, hold, spam, trash, all',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
							'default'     => 'approve',
						),
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Filter comments by post ID (optional, 0 = all)',
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'comment_ID'       => array( 'type' => 'integer' ),
							'comment_author'   => array( 'type' => 'string' ),
							'comment_content'  => array( 'type' => 'string' ),
							'comment_date'     => array( 'type' => 'string' ),
							'comment_post_ID'  => array( 'type' => 'integer' ),
							'post_title'       => array( 'type' => 'string' ),
							'comment_approved' => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$allowed_status = array( 'approve', 'hold', 'spam', 'trash', 'all' );
					$number = min( 100, max( 1, absint( $input['number'] ?? 20 ) ) );
					$status = in_array( $input['status'] ?? 'approve', $allowed_status, true )
						? $input['status'] : 'approve';

					$args = array(
						'number' => $number,
						'status' => $status,
					);
					if ( ! empty( $input['post_id'] ) ) {
						$args['post_id'] = absint( $input['post_id'] );
					}
					$comments = get_comments( $args );
					$result   = array();
					foreach ( $comments as $comment ) {
						$result[] = array(
							'comment_ID'       => (int) $comment->comment_ID,
							'comment_author'   => $comment->comment_author,
							'comment_content'  => $comment->comment_content,
							'comment_date'     => $comment->comment_date,
							'comment_post_ID'  => (int) $comment->comment_post_ID,
							'post_title'       => get_the_title( $comment->comment_post_ID ),
							'comment_approved' => $comment->comment_approved,
						);
					}
					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A7: Get Media ───────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-media' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-media',
			array(
				'label'               => __( 'Get Media', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves media library files (images, videos, documents) with filters by MIME type and search.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'numberposts'    => array(
							'type'        => 'integer',
							'description' => 'Number of media items to retrieve',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'post_mime_type' => array(
							'type'        => 'string',
							'description' => 'MIME type filter: image, video, audio, application (optional)',
							'enum'        => array( 'image', 'video', 'audio', 'application', '' ),
							'default'     => '',
						),
						's'              => array(
							'type'        => 'string',
							'description' => 'Search term (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'ID'        => array( 'type' => 'integer' ),
							'title'     => array( 'type' => 'string' ),
							'url'       => array( 'type' => 'string' ),
							'mime_type' => array( 'type' => 'string' ),
							'alt_text'  => array( 'type' => 'string' ),
							'date'      => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'execute_callback'    => function ( $input ) {
					$numberposts = min( 100, max( 1, absint( $input['numberposts'] ?? 20 ) ) );
					$args = array(
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
						'numberposts' => $numberposts,
						'orderby'     => 'date',
						'order'       => 'DESC',
					);
					if ( ! empty( $input['post_mime_type'] ) ) {
						$args['post_mime_type'] = sanitize_text_field( $input['post_mime_type'] );
					}
					if ( ! empty( $input['s'] ) ) {
						$args['s'] = sanitize_text_field( $input['s'] );
					}

					$medios  = get_posts( $args );
					$result  = array();
					foreach ( $medios as $medio ) {
						$result[] = array(
							'ID'        => $medio->ID,
							'title'     => $medio->post_title,
							'url'       => wp_get_attachment_url( $medio->ID ),
							'mime_type' => $medio->post_mime_type,
							'alt_text'  => ewpa_get_meta_string( $medio->ID, '_wp_attachment_image_alt' ),
							'date'      => $medio->post_date,
						);
					}
					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── A8: Get Users ───────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-users' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-users',
			array(
				'label'               => __( 'Get Users', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves the list of site users with their ID, name, email, and role. Useful for assigning post authors.', 'enable-abilities-for-mcp' ),
				'category'            => 'user-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'role' => array(
							'type'        => 'string',
							'description' => 'Filter by role: administrator, editor, author, contributor, subscriber (optional)',
							'enum'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', '' ),
							'default'     => '',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'ID'           => array( 'type' => 'integer' ),
							'display_name' => array( 'type' => 'string' ),
							'user_login'   => array( 'type' => 'string' ),
							'roles'        => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'list_users' );
				},
				'execute_callback'    => function ( $input ) {
					$args = array();
					if ( ! empty( $input['role'] ) ) {
						$args['role'] = sanitize_text_field( $input['role'] );
					}
					$users  = get_users( $args );
					$result = array();
					foreach ( $users as $user ) {
						$result[] = array(
							'ID'           => $user->ID,
							'display_name' => $user->display_name,
							'user_login'   => $user->user_login,
							'roles'        => $user->roles,
						);
					}
					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	/*
	 * ======================================================================
	 * SECTION B: WRITE ABILITIES
	 * ======================================================================
	 */

	// ── B1: Create Post ─────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/create-post' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/create-post',
			array(
				'label'               => __( 'Create Post', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a new blog post. Accepts title, HTML content, excerpt, categories, tags, featured image, and status. Defaults to draft.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title', 'content' ),
					'properties' => array(
						'title'             => array(
							'type'        => 'string',
							'description' => 'Post title (required)',
						),
						'content'           => array(
							'type'        => 'string',
							'description' => 'Post content in HTML or Gutenberg blocks (required)',
						),
						'excerpt'           => array(
							'type'        => 'string',
							'description' => 'Post excerpt/summary (optional)',
						),
						'status'            => array(
							'type'        => 'string',
							'description' => 'Status: draft, publish, pending, private, future',
							'enum'        => array( 'draft', 'publish', 'pending', 'private', 'future' ),
							'default'     => 'draft',
						),
						'categories'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Array of category IDs to assign (optional)',
						),
						'tags'              => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Array of tag names to assign (optional)',
						),
						'featured_image_id' => array(
							'type'        => 'integer',
							'description' => 'Featured image ID (optional)',
						),
						'post_date'         => array(
							'type'        => 'string',
							'description' => 'Publication date YYYY-MM-DD HH:MM:SS (optional)',
						),
						'author_id'         => array(
							'type'        => 'integer',
							'description' => 'Post author ID (optional)',
						),
						'slug'              => array(
							'type'        => 'string',
							'description' => 'Custom slug/permalink (optional)',
						),
						'meta_title'        => array(
							'type'        => 'string',
							'description' => 'SEO meta title for Yoast/RankMath (optional)',
						),
						'meta_description'  => array(
							'type'        => 'string',
							'description' => 'SEO meta description for Yoast/RankMath (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'permalink' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'publish_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$allowed_status = array( 'draft', 'publish', 'pending', 'private', 'future' );
					$status = in_array( $input['status'] ?? 'draft', $allowed_status, true )
						? $input['status'] : 'draft';

					$post_data = array(
						'post_title'   => sanitize_text_field( $input['title'] ),
						'post_content' => wp_kses_post( $input['content'] ),
						'post_status'  => $status,
						'post_type'    => 'post',
					);

					if ( ! empty( $input['excerpt'] ) ) {
						$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
					}
					if ( ! empty( $input['categories'] ) ) {
						$post_data['post_category'] = array_map( 'absint', (array) $input['categories'] );
					}
					if ( ! empty( $input['post_date'] ) ) {
						$date = sanitize_text_field( $input['post_date'] );
						if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $date ) ) {
							$post_data['post_date'] = $date;
						}
					}
					if ( ! empty( $input['author_id'] ) ) {
						$author_id = absint( $input['author_id'] );
						if ( get_userdata( $author_id ) ) {
							$post_data['post_author'] = $author_id;
						}
					}
					if ( ! empty( $input['slug'] ) ) {
						$post_data['post_name'] = sanitize_title( $input['slug'] );
					}

					$post_id = wp_insert_post( $post_data, true );

					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					if ( ! empty( $input['tags'] ) ) {
						$tags = array_map( 'sanitize_text_field', (array) $input['tags'] );
						wp_set_post_tags( $post_id, $tags );
					}
					if ( ! empty( $input['featured_image_id'] ) ) {
						$img_id = absint( $input['featured_image_id'] );
						if ( wp_attachment_is_image( $img_id ) ) {
							set_post_thumbnail( $post_id, $img_id );
						}
					}
					if ( ! empty( $input['meta_title'] ) ) {
						update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $input['meta_title'] ) );
						update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $input['meta_title'] ) );
					}
					if ( ! empty( $input['meta_description'] ) ) {
						update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $input['meta_description'] ) );
						update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $input['meta_description'] ) );
					}

					return array(
						'post_id'   => $post_id,
						'permalink' => get_permalink( $post_id ),
						'status'    => $status,
						'message'   => 'Post created successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B2: Update Post ─────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/update-post' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/update-post',
			array(
				'label'               => __( 'Update Post', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Updates an existing post. Only the provided fields are modified, others remain unchanged.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => 'Post ID to update (required)',
						),
						'title'             => array(
							'type'        => 'string',
							'description' => 'New title (optional)',
						),
						'content'           => array(
							'type'        => 'string',
							'description' => 'New content in HTML (optional)',
						),
						'excerpt'           => array(
							'type'        => 'string',
							'description' => 'New excerpt (optional)',
						),
						'status'            => array(
							'type'        => 'string',
							'description' => 'New status: draft, publish, pending, private',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
						),
						'categories'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'New category IDs (replaces existing ones)',
						),
						'tags'              => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'New tags (replaces existing ones)',
						),
						'featured_image_id' => array(
							'type'        => 'integer',
							'description' => 'New featured image ID (0 to remove)',
						),
						'meta_title'        => array(
							'type'        => 'string',
							'description' => 'SEO meta title (optional)',
						),
						'meta_description'  => array(
							'type'        => 'string',
							'description' => 'SEO meta description (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'permalink' => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post = get_post( $post_id );
					if ( ! $post ) {
						return new WP_Error( 'not_found', 'Post not found.' );
					}
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new WP_Error( 'forbidden', 'You do not have permission to edit this post.' );
					}

					$post_data = array( 'ID' => $post_id );

					if ( isset( $input['title'] ) ) {
						$post_data['post_title'] = sanitize_text_field( $input['title'] );
					}
					if ( isset( $input['content'] ) ) {
						$post_data['post_content'] = wp_kses_post( $input['content'] );
					}
					if ( isset( $input['excerpt'] ) ) {
						$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
					}
					if ( isset( $input['status'] ) ) {
						$allowed_status = array( 'draft', 'publish', 'pending', 'private' );
						if ( in_array( $input['status'], $allowed_status, true ) ) {
							$post_data['post_status'] = $input['status'];
						}
					}
					if ( isset( $input['categories'] ) ) {
						$post_data['post_category'] = array_map( 'absint', (array) $input['categories'] );
					}

					$result = wp_update_post( $post_data, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					if ( isset( $input['tags'] ) ) {
						$tags = array_map( 'sanitize_text_field', (array) $input['tags'] );
						wp_set_post_tags( $post_id, $tags );
					}
					if ( isset( $input['featured_image_id'] ) ) {
						$img_id = absint( $input['featured_image_id'] );
						if ( 0 === $img_id ) {
							delete_post_thumbnail( $post_id );
						} elseif ( wp_attachment_is_image( $img_id ) ) {
							set_post_thumbnail( $post_id, $img_id );
						}
					}
					if ( ! empty( $input['meta_title'] ) ) {
						update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $input['meta_title'] ) );
						update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $input['meta_title'] ) );
					}
					if ( ! empty( $input['meta_description'] ) ) {
						update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $input['meta_description'] ) );
						update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $input['meta_description'] ) );
					}

					return array(
						'post_id'   => $post_id,
						'permalink' => get_permalink( $post_id ),
						'message'   => 'Post updated successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B3: Delete Post ─────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/delete-post' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/delete-post',
			array(
				'label'               => __( 'Delete Post', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Sends a post to trash or permanently deletes it. Defaults to trash.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'Post ID to delete (required)',
						),
						'force_delete' => array(
							'type'        => 'boolean',
							'description' => 'true = permanently delete, false = trash',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'delete_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post = get_post( $post_id );
					if ( ! $post ) {
						return new WP_Error( 'not_found', 'Post not found.' );
					}
					if ( ! current_user_can( 'delete_post', $post_id ) ) {
						return new WP_Error( 'forbidden', 'You do not have permission to delete this post.' );
					}

					$force  = ! empty( $input['force_delete'] );
					$result = wp_delete_post( $post_id, $force );

					if ( ! $result ) {
						return new WP_Error( 'delete_failed', 'Could not delete the post.' );
					}

					$action = $force ? 'permanently deleted' : 'sent to trash';
					return array(
						'post_id' => $post_id,
						'deleted' => true,
						'message' => "Post {$action} successfully.",
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B4: Create Category ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/create-category' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/create-category',
			array(
				'label'               => __( 'Create Category', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a new blog category with name, slug, description, and parent category (optional).', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'        => array(
							'type'        => 'string',
							'description' => 'Category name (required)',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Category slug (optional)',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Category description (optional)',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Parent category ID (0 = no parent)',
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'name'    => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'execute_callback'    => function ( $input ) {
					$args = array();
					if ( ! empty( $input['slug'] ) ) {
						$args['slug'] = sanitize_title( $input['slug'] );
					}
					if ( ! empty( $input['description'] ) ) {
						$args['description'] = sanitize_textarea_field( $input['description'] );
					}
					if ( isset( $input['parent'] ) ) {
						$args['parent'] = absint( $input['parent'] );
					}

					$result = wp_insert_term(
						sanitize_text_field( $input['name'] ),
						'category',
						$args
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$term = get_term( $result['term_id'], 'category' );
					return array(
						'term_id' => $result['term_id'],
						'name'    => $term->name,
						'slug'    => $term->slug,
						'message' => 'Category created successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B5: Create Tag ──────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/create-tag' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/create-tag',
			array(
				'label'               => __( 'Create Tag', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a new blog tag with name, slug, and description.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'        => array(
							'type'        => 'string',
							'description' => 'Tag name (required)',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Tag slug (optional)',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Tag description (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'name'    => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'execute_callback'    => function ( $input ) {
					$args = array();
					if ( ! empty( $input['slug'] ) ) {
						$args['slug'] = sanitize_title( $input['slug'] );
					}
					if ( ! empty( $input['description'] ) ) {
						$args['description'] = sanitize_textarea_field( $input['description'] );
					}

					$result = wp_insert_term(
						sanitize_text_field( $input['name'] ),
						'post_tag',
						$args
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$term = get_term( $result['term_id'], 'post_tag' );
					return array(
						'term_id' => $result['term_id'],
						'name'    => $term->name,
						'slug'    => $term->slug,
						'message' => 'Tag created successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B6: Create Page ─────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/create-page' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/create-page',
			array(
				'label'               => __( 'Create Page', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a new WordPress page with title, content, status, and parent page (for hierarchy).', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title', 'content' ),
					'properties' => array(
						'title'      => array(
							'type'        => 'string',
							'description' => 'Page title (required)',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Page content in HTML (required)',
						),
						'status'     => array(
							'type'        => 'string',
							'description' => 'Status: draft, publish, pending, private',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
							'default'     => 'draft',
						),
						'parent_id'  => array(
							'type'        => 'integer',
							'description' => 'Parent page ID (0 = no parent)',
							'default'     => 0,
						),
						'menu_order' => array(
							'type'        => 'integer',
							'description' => 'Menu order',
							'default'     => 0,
						),
						'template'   => array(
							'type'        => 'string',
							'description' => 'Page template to use (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page_id'   => array( 'type' => 'integer' ),
						'permalink' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'publish_pages' );
				},
				'execute_callback'    => function ( $input ) {
					$allowed_status = array( 'draft', 'publish', 'pending', 'private' );
					$status = in_array( $input['status'] ?? 'draft', $allowed_status, true )
						? $input['status'] : 'draft';

					$post_data = array(
						'post_title'   => sanitize_text_field( $input['title'] ),
						'post_content' => wp_kses_post( $input['content'] ),
						'post_status'  => $status,
						'post_type'    => 'page',
						'post_parent'  => absint( $input['parent_id'] ?? 0 ),
						'menu_order'   => absint( $input['menu_order'] ?? 0 ),
					);

					$page_id = wp_insert_post( $post_data, true );

					if ( is_wp_error( $page_id ) ) {
						return $page_id;
					}

					if ( ! empty( $input['template'] ) ) {
						update_post_meta( $page_id, '_wp_page_template', sanitize_file_name( $input['template'] ) );
					}

					return array(
						'page_id'   => $page_id,
						'permalink' => get_permalink( $page_id ),
						'status'    => $status,
						'message'   => 'Page created successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B7: Moderate Comment ────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/moderate-comment' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/moderate-comment',
			array(
				'label'               => __( 'Moderate Comment', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Changes a comment status: approve, hold, mark as spam, or send to trash.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'comment_id', 'action' ),
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'Comment ID to moderate (required)',
						),
						'action'     => array(
							'type'        => 'string',
							'description' => 'Action: approve, hold, spam, trash',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'new_status' => array( 'type' => 'string' ),
						'message'    => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$comment_id = absint( $input['comment_id'] );
					$comment = get_comment( $comment_id );
					if ( ! $comment ) {
						return new WP_Error( 'not_found', 'Comment not found.' );
					}

					$status_map = array(
						'approve' => '1',
						'hold'    => '0',
						'spam'    => 'spam',
						'trash'   => 'trash',
					);

					if ( ! isset( $status_map[ $input['action'] ] ) ) {
						return new WP_Error( 'invalid_action', 'Invalid action. Use: approve, hold, spam, or trash.' );
					}

					$new_status = $status_map[ $input['action'] ];
					$result = wp_set_comment_status( $comment_id, $new_status );

					if ( ! $result ) {
						return new WP_Error( 'update_failed', 'Could not moderate the comment.' );
					}

					$action_labels = array(
						'approve' => 'approved',
						'hold'    => 'put on hold',
						'spam'    => 'marked as spam',
						'trash'   => 'sent to trash',
					);

					return array(
						'comment_id' => $comment_id,
						'new_status' => $input['action'],
						'message'    => 'Comment ' . ( $action_labels[ $input['action'] ] ?? 'moderated' ) . ' successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B8: Reply to Comment ────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/reply-comment' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/reply-comment',
			array(
				'label'               => __( 'Reply to Comment', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Replies to an existing comment on a post or page. The reply is published as the current authenticated user.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'comment_id', 'content' ),
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'Comment ID to reply to (required)',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Reply content (required)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'parent_id'  => array( 'type' => 'integer' ),
						'post_id'    => array( 'type' => 'integer' ),
						'author'     => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'date'       => array( 'type' => 'string' ),
						'message'    => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$parent_comment = get_comment( absint( $input['comment_id'] ) );
					if ( ! $parent_comment ) {
						return new WP_Error( 'not_found', 'Parent comment not found.' );
					}

					$current_user = wp_get_current_user();
					$comment_data = array(
						'comment_post_ID'      => (int) $parent_comment->comment_post_ID,
						'comment_parent'       => absint( $input['comment_id'] ),
						'comment_content'      => wp_kses_post( $input['content'] ),
						'user_id'              => $current_user->ID,
						'comment_author'       => $current_user->display_name,
						'comment_author_email' => $current_user->user_email,
						'comment_approved'     => 1,
					);

					$new_comment_id = wp_insert_comment( $comment_data );
					if ( ! $new_comment_id ) {
						return new WP_Error( 'insert_failed', 'Could not create the comment reply.' );
					}

					return array(
						'comment_id' => $new_comment_id,
						'parent_id'  => absint( $input['comment_id'] ),
						'post_id'    => (int) $parent_comment->comment_post_ID,
						'author'     => $current_user->display_name,
						'content'    => wp_kses_post( $input['content'] ),
						'date'       => current_time( 'mysql' ),
						'message'    => 'Reply to comment #' . absint( $input['comment_id'] ) . ' published successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B9: Update Comment ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/update-comment' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/update-comment',
			array(
				'label'               => __( 'Update Comment', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Updates an existing comment. Allows changing the content, author name, author email, and the WordPress user associated with the comment.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array(
						'comment_id'           => array(
							'type'        => 'integer',
							'description' => 'ID of the comment to update (required)',
						),
						'content'              => array(
							'type'        => 'string',
							'description' => 'New comment content',
						),
						'comment_author'       => array(
							'type'        => 'string',
							'description' => 'Display name of the comment author',
						),
						'comment_author_email' => array(
							'type'        => 'string',
							'description' => 'Email address of the comment author',
						),
						'user_id'              => array(
							'type'        => 'integer',
							'description' => 'WordPress user ID to associate with the comment (0 = guest)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id'           => array( 'type' => 'integer' ),
						'content'              => array( 'type' => 'string' ),
						'comment_author'       => array( 'type' => 'string' ),
						'comment_author_email' => array( 'type' => 'string' ),
						'user_id'              => array( 'type' => 'integer' ),
						'message'              => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$comment_id = absint( $input['comment_id'] );
					$comment    = get_comment( $comment_id );

					if ( ! $comment ) {
						return new WP_Error( 'not_found', 'Comment not found.' );
					}

					$update_data = array(
						'comment_ID' => $comment_id,
					);

					if ( isset( $input['content'] ) ) {
						$update_data['comment_content'] = wp_kses_post( $input['content'] );
					}

					if ( isset( $input['comment_author'] ) ) {
						$update_data['comment_author'] = sanitize_text_field( $input['comment_author'] );
					}

					if ( isset( $input['comment_author_email'] ) ) {
						$update_data['comment_author_email'] = sanitize_email( $input['comment_author_email'] );
					}

					if ( isset( $input['user_id'] ) ) {
						$user_id = absint( $input['user_id'] );
						if ( 0 < $user_id ) {
							$user = get_userdata( $user_id );
							if ( ! $user ) {
								return new WP_Error( 'invalid_user', 'User ID ' . $user_id . ' does not exist.' );
							}
							$update_data['user_id'] = $user_id;
							// Sync author fields from user if not explicitly overridden.
							if ( ! isset( $input['comment_author'] ) ) {
								$update_data['comment_author'] = $user->display_name;
							}
							if ( ! isset( $input['comment_author_email'] ) ) {
								$update_data['comment_author_email'] = $user->user_email;
							}
						} else {
							$update_data['user_id'] = 0;
						}
					}

					$result = wp_update_comment( $update_data );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( ! $result ) {
						return new WP_Error( 'update_failed', 'Comment could not be updated.' );
					}

					$updated = get_comment( $comment_id );

					return array(
						'comment_id'           => $comment_id,
						'content'              => $updated->comment_content,
						'comment_author'       => $updated->comment_author,
						'comment_author_email' => $updated->comment_author_email,
						'user_id'              => (int) $updated->user_id,
						'message'              => 'Comment #' . $comment_id . ' updated successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── B10: Upload Image from URL ──────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/upload-image' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/upload-image',
			array(
				'label'               => __( 'Upload Image from URL', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Downloads an image from an external URL and registers it in the WordPress media library. Optionally assigns it as featured image for a post. Returns the attachment ID and local URL.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'         => array(
							'type'        => 'string',
							'description' => 'Image URL to download (required)',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'Title for the image in the media library (optional)',
						),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => 'Image alt text (optional)',
						),
						'caption'     => array(
							'type'        => 'string',
							'description' => 'Image caption (optional)',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Image description (optional)',
						),
						'post_id'     => array(
							'type'        => 'integer',
							'description' => 'Post ID to attach the image to. If provided, also sets it as featured image (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id'    => array( 'type' => 'integer' ),
						'url'              => array( 'type' => 'string' ),
						'title'            => array( 'type' => 'string' ),
						'file'             => array( 'type' => 'string' ),
						'mime_type'        => array( 'type' => 'string' ),
						'set_as_thumbnail' => array( 'type' => 'boolean' ),
						'message'          => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'execute_callback'    => function ( $input ) {
					// Require media functions.
					if ( ! function_exists( 'media_sideload_image' ) ) {
						require_once ABSPATH . 'wp-admin/includes/media.php';
						require_once ABSPATH . 'wp-admin/includes/file.php';
						require_once ABSPATH . 'wp-admin/includes/image.php';
					}

					$url = esc_url_raw( $input['url'] );
					if ( empty( $url ) ) {
						return new WP_Error( 'invalid_url', 'The image URL is not valid.' );
					}

					// Validate that URL points to an image by extension.
					$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'avif' );
					$path = wp_parse_url( $url, PHP_URL_PATH );
					$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
					if ( ! in_array( $ext, $allowed_extensions, true ) ) {
						return new WP_Error( 'invalid_type', 'The URL does not point to a valid image format. Allowed extensions: ' . implode( ', ', $allowed_extensions ) );
					}

					$parent_post_id = ! empty( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

					// Validate parent post exists if provided.
					if ( $parent_post_id > 0 && ! get_post( $parent_post_id ) ) {
						return new WP_Error( 'post_not_found', 'The specified post does not exist.' );
					}

					// Download and sideload the image.
					$tmp_file = download_url( $url, 30 );
					if ( is_wp_error( $tmp_file ) ) {
						return new WP_Error( 'download_failed', 'Could not download the image: ' . $tmp_file->get_error_message() );
					}

					// Build the file array for media_handle_sideload.
					$filename   = ! empty( $input['title'] ) ? sanitize_file_name( $input['title'] ) . '.' . $ext : basename( $path );
					$file_array = array(
						'name'     => sanitize_file_name( $filename ),
						'tmp_name' => $tmp_file,
					);

					$attachment_id = media_handle_sideload( $file_array, $parent_post_id );

					// Clean up temp file on failure.
					if ( is_wp_error( $attachment_id ) ) {
						wp_delete_file( $tmp_file );
						return new WP_Error( 'sideload_failed', 'Could not register the image: ' . $attachment_id->get_error_message() );
					}

					// Set title if provided.
					if ( ! empty( $input['title'] ) ) {
						wp_update_post(
							array(
								'ID'         => $attachment_id,
								'post_title' => sanitize_text_field( $input['title'] ),
							)
						);
					}

					// Set alt text.
					if ( ! empty( $input['alt_text'] ) ) {
						update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
					}

					// Set caption.
					if ( ! empty( $input['caption'] ) ) {
						wp_update_post(
							array(
								'ID'           => $attachment_id,
								'post_excerpt' => sanitize_text_field( $input['caption'] ),
							)
						);
					}

					// Set description.
					if ( ! empty( $input['description'] ) ) {
						wp_update_post(
							array(
								'ID'           => $attachment_id,
								'post_content' => sanitize_textarea_field( $input['description'] ),
							)
						);
					}

					// Set as featured image if post_id was provided.
					$set_thumbnail = false;
					if ( $parent_post_id > 0 ) {
						set_post_thumbnail( $parent_post_id, $attachment_id );
						$set_thumbnail = true;
					}

					$attachment = get_post( $attachment_id );

					return array(
						'attachment_id'    => $attachment_id,
						'url'              => wp_get_attachment_url( $attachment_id ),
						'title'            => $attachment->post_title,
						'mime_type'        => $attachment->post_mime_type,
						'set_as_thumbnail' => $set_thumbnail,
						'message'          => $set_thumbnail
							? 'Image uploaded and set as featured image successfully.'
							: 'Image uploaded to the media library successfully.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	/*
	 * ======================================================================
	 * SECTION S: SEO — RANK MATH ABILITIES
	 * ======================================================================
	 */

	// ── S1: Get Rank Math Metadata ──────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-rankmath' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-rankmath',
			array(
				'label'               => __( 'Get Rank Math Metadata', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves all Rank Math SEO metadata for a post or page: title, description, keywords, robots, advanced robots, Open Graph, Twitter Card, schema, breadcrumb, cornerstone, and SEO score.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post or page ID to query',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'              => array( 'type' => 'integer' ),
						'post_title'           => array( 'type' => 'string' ),
						'titulo_seo'           => array( 'type' => 'string' ),
						'descripcion_seo'      => array( 'type' => 'string' ),
						'keywords'             => array( 'type' => 'string' ),
						'canonical_url'        => array( 'type' => 'string' ),
						'robots'               => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'advanced_robots'      => array( 'type' => 'object' ),
						'og_title'             => array( 'type' => 'string' ),
						'og_description'       => array( 'type' => 'string' ),
						'og_image'             => array( 'type' => 'string' ),
						'twitter_title'        => array( 'type' => 'string' ),
						'twitter_description'  => array( 'type' => 'string' ),
						'twitter_image'        => array( 'type' => 'string' ),
						'twitter_use_facebook' => array( 'type' => 'boolean' ),
						'primary_category'     => array( 'type' => 'integer' ),
						'pillar_content'       => array( 'type' => 'boolean' ),
						'cornerstone'          => array( 'type' => 'boolean' ),
						'breadcrumb_title'     => array( 'type' => 'string' ),
						'snippet_type'         => array( 'type' => 'string' ),
						'snippet_data'         => array( 'type' => 'object' ),
						'schema'               => array( 'type' => 'object' ),
						'seo_score'            => array( 'type' => 'integer' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post = get_post( $post_id );
					if ( ! $post ) {
						return new WP_Error( 'not_found', 'Post or page not found.' );
					}

					$robots_raw = get_post_meta( $post_id, 'rank_math_robots', true );
					$robots = is_array( $robots_raw ) ? $robots_raw : array();

					$advanced_robots_raw = get_post_meta( $post_id, 'rank_math_advanced_robots', true );
					$advanced_robots = is_array( $advanced_robots_raw ) ? $advanced_robots_raw : array();

					$snippet_data_raw = get_post_meta( $post_id, 'rank_math_rich_snippet', true );
					$snippet_data = is_array( $snippet_data_raw ) ? $snippet_data_raw : array();

					// Collect all rank_math_schema_* meta keys.
					$schema = array();
					$all_meta = get_post_meta( $post_id );
					foreach ( $all_meta as $key => $values ) {
						if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
							$schema_key = substr( $key, strlen( 'rank_math_schema_' ) );
							$decoded = maybe_unserialize( $values[0] );
							if ( is_string( $decoded ) ) {
								$json = json_decode( $decoded, true );
								$schema[ $schema_key ] = $json ? $json : $decoded;
							} else {
								$schema[ $schema_key ] = $decoded;
							}
						}
					}

					return array(
						'post_id'              => $post->ID,
						'post_title'           => $post->post_title,
						'titulo_seo'           => ewpa_get_meta_string( $post_id, 'rank_math_title' ),
						'descripcion_seo'      => ewpa_get_meta_string( $post_id, 'rank_math_description' ),
						'keywords'             => ewpa_get_meta_string( $post_id, 'rank_math_focus_keyword' ),
						'canonical_url'        => ewpa_get_meta_string( $post_id, 'rank_math_canonical_url' ),
						'robots'               => $robots,
						'advanced_robots'      => $advanced_robots,
						'og_title'             => ewpa_get_meta_string( $post_id, 'rank_math_facebook_title' ),
						'og_description'       => ewpa_get_meta_string( $post_id, 'rank_math_facebook_description' ),
						'og_image'             => ewpa_get_meta_string( $post_id, 'rank_math_facebook_image' ),
						'twitter_title'        => ewpa_get_meta_string( $post_id, 'rank_math_twitter_title' ),
						'twitter_description'  => ewpa_get_meta_string( $post_id, 'rank_math_twitter_description' ),
						'twitter_image'        => ewpa_get_meta_string( $post_id, 'rank_math_twitter_image' ),
						'twitter_use_facebook' => (bool) get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true ),
						'primary_category'     => (int) get_post_meta( $post_id, 'rank_math_primary_category', true ),
						'pillar_content'       => (bool) get_post_meta( $post_id, 'rank_math_pillar_content', true ),
						'cornerstone'          => (bool) get_post_meta( $post_id, 'rank_math_cornerstone', true ),
						'breadcrumb_title'     => ewpa_get_meta_string( $post_id, 'rank_math_breadcrumb_title' ),
						'snippet_type'         => ewpa_get_meta_string( $post_id, 'rank_math_snippet_type' ),
						'snippet_data'         => $snippet_data,
						'schema'               => $schema,
						'seo_score'            => (int) get_post_meta( $post_id, 'rank_math_seo_score', true ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── S2: Update Rank Math Metadata ───────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/update-rankmath' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/update-rankmath',
			array(
				'label'               => __( 'Update Rank Math SEO / Focus Keyword', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Updates Rank Math SEO metadata on a post or page (rank_math_focus_keyword, rank_math_title, rank_math_description, etc). Use this ability to set or change the focus keyword, SEO title, meta description, canonical URL, robots, Open Graph, Twitter Card, breadcrumb, schema snippet, cornerstone, and pillar content. Only the provided fields are modified.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'              => array(
							'type'        => 'integer',
							'description' => 'Post or page ID to update (required)',
						),
						'titulo_seo'           => array(
							'type'        => 'string',
							'description' => 'SEO title for Rank Math (optional)',
						),
						'descripcion_seo'      => array(
							'type'        => 'string',
							'description' => 'SEO meta description for Rank Math (optional)',
						),
						'keyword'              => array(
							'type'        => 'string',
							'description' => 'Focus keyword for Rank Math. Stored in rank_math_focus_keyword. E.g.: "healthy recipes" (optional)',
						),
						'canonical_url'        => array(
							'type'        => 'string',
							'description' => 'Custom canonical URL (optional)',
						),
						'robots'               => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ),
							),
							'description' => 'Robots directives, e.g.: ["index", "follow"] or ["noindex", "nofollow"] (optional)',
						),
						'advanced_robots'      => array(
							'type'        => 'object',
							'description' => 'Advanced robots, e.g.: {"max-snippet": -1, "max-image-preview": "large", "max-video-preview": -1} (optional)',
							'properties'  => array(
								'max-snippet'       => array(
									'type'        => 'integer',
									'description' => 'Max snippet characters (-1 = no limit)',
								),
								'max-image-preview' => array(
									'type'        => 'string',
									'description' => 'Max image size: none, standard, large',
									'enum'        => array( 'none', 'standard', 'large' ),
								),
								'max-video-preview' => array(
									'type'        => 'integer',
									'description' => 'Max video preview seconds (-1 = no limit)',
								),
							),
						),
						'og_title'             => array(
							'type'        => 'string',
							'description' => 'Open Graph / Facebook title (optional)',
						),
						'og_description'       => array(
							'type'        => 'string',
							'description' => 'Open Graph / Facebook description (optional)',
						),
						'og_image'             => array(
							'type'        => 'string',
							'description' => 'Open Graph / Facebook image URL (optional)',
						),
						'twitter_title'        => array(
							'type'        => 'string',
							'description' => 'Twitter Card title (optional)',
						),
						'twitter_description'  => array(
							'type'        => 'string',
							'description' => 'Twitter Card description (optional)',
						),
						'twitter_image'        => array(
							'type'        => 'string',
							'description' => 'Twitter Card image URL (optional)',
						),
						'twitter_use_facebook' => array(
							'type'        => 'boolean',
							'description' => 'Reuse Facebook data for Twitter (true/false) (optional)',
						),
						'primary_category'     => array(
							'type'        => 'integer',
							'description' => 'Primary category ID for Rank Math (optional)',
						),
						'pillar_content'       => array(
							'type'        => 'boolean',
							'description' => 'Mark as pillar content (true/false) (optional)',
						),
						'cornerstone'          => array(
							'type'        => 'boolean',
							'description' => 'Mark as cornerstone content (true/false) (optional)',
						),
						'breadcrumb_title'     => array(
							'type'        => 'string',
							'description' => 'Custom breadcrumb title (optional)',
						),
						'snippet_type'         => array(
							'type'        => 'string',
							'description' => 'Rich snippet type: off, article, book, course, event, faq, howto, job_posting, local_business, music, product, recipe, restaurant, review, software, video (optional)',
							'enum'        => array( 'off', 'article', 'book', 'course', 'event', 'faq', 'howto', 'job_posting', 'local_business', 'music', 'product', 'recipe', 'restaurant', 'review', 'software', 'video' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer' ),
						'updated_fields' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'message'        => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post = get_post( $post_id );
					if ( ! $post ) {
						return new WP_Error( 'not_found', 'Post or page not found.' );
					}
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new WP_Error( 'forbidden', 'You do not have permission to edit this post.' );
					}

					$updated = array();

					// ── General SEO ─────────────────────────────────────
					if ( isset( $input['titulo_seo'] ) ) {
						update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $input['titulo_seo'] ) );
						$updated[] = 'titulo_seo';
					}

					if ( isset( $input['descripcion_seo'] ) ) {
						update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $input['descripcion_seo'] ) );
						$updated[] = 'descripcion_seo';
					}

					if ( isset( $input['keyword'] ) ) {
						$keyword = sanitize_text_field( $input['keyword'] );
						update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
						$updated[] = 'keyword';
					}

					if ( isset( $input['canonical_url'] ) ) {
						update_post_meta( $post_id, 'rank_math_canonical_url', esc_url_raw( $input['canonical_url'] ) );
						$updated[] = 'canonical_url';
					}

					// ── Robots ────────────────────────────────────────────
					if ( isset( $input['robots'] ) ) {
						$allowed_robots = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
						$robots = array_filter(
							(array) $input['robots'],
							function ( $val ) use ( $allowed_robots ) {
								return in_array( $val, $allowed_robots, true );
							}
						);
						update_post_meta( $post_id, 'rank_math_robots', array_values( $robots ) );
						$updated[] = 'robots';
					}

					if ( isset( $input['advanced_robots'] ) ) {
						$adv = (array) $input['advanced_robots'];
						$sanitized_adv = array();
						if ( isset( $adv['max-snippet'] ) ) {
							$sanitized_adv['max-snippet'] = (int) $adv['max-snippet'];
						}
						if ( isset( $adv['max-image-preview'] ) ) {
							$allowed_img = array( 'none', 'standard', 'large' );
							if ( in_array( $adv['max-image-preview'], $allowed_img, true ) ) {
								$sanitized_adv['max-image-preview'] = $adv['max-image-preview'];
							}
						}
						if ( isset( $adv['max-video-preview'] ) ) {
							$sanitized_adv['max-video-preview'] = (int) $adv['max-video-preview'];
						}
						if ( ! empty( $sanitized_adv ) ) {
							update_post_meta( $post_id, 'rank_math_advanced_robots', $sanitized_adv );
							$updated[] = 'advanced_robots';
						}
					}

					// ── Open Graph / Facebook ────────────────────────────
					if ( isset( $input['og_title'] ) ) {
						update_post_meta( $post_id, 'rank_math_facebook_title', sanitize_text_field( $input['og_title'] ) );
						$updated[] = 'og_title';
					}

					if ( isset( $input['og_description'] ) ) {
						update_post_meta( $post_id, 'rank_math_facebook_description', sanitize_text_field( $input['og_description'] ) );
						$updated[] = 'og_description';
					}

					if ( isset( $input['og_image'] ) ) {
						update_post_meta( $post_id, 'rank_math_facebook_image', esc_url_raw( $input['og_image'] ) );
						$updated[] = 'og_image';
					}

					// ── Twitter Card ─────────────────────────────────────
					if ( isset( $input['twitter_title'] ) ) {
						update_post_meta( $post_id, 'rank_math_twitter_title', sanitize_text_field( $input['twitter_title'] ) );
						$updated[] = 'twitter_title';
					}

					if ( isset( $input['twitter_description'] ) ) {
						update_post_meta( $post_id, 'rank_math_twitter_description', sanitize_text_field( $input['twitter_description'] ) );
						$updated[] = 'twitter_description';
					}

					if ( isset( $input['twitter_image'] ) ) {
						update_post_meta( $post_id, 'rank_math_twitter_image', esc_url_raw( $input['twitter_image'] ) );
						$updated[] = 'twitter_image';
					}

					if ( isset( $input['twitter_use_facebook'] ) ) {
						update_post_meta( $post_id, 'rank_math_twitter_use_facebook', $input['twitter_use_facebook'] ? 'on' : 'off' );
						$updated[] = 'twitter_use_facebook';
					}

					// ── Taxonomy and Content ─────────────────────────────
					if ( isset( $input['primary_category'] ) ) {
						$cat_id = absint( $input['primary_category'] );
						if ( $cat_id > 0 && term_exists( $cat_id, 'category' ) ) {
							update_post_meta( $post_id, 'rank_math_primary_category', $cat_id );
							$updated[] = 'primary_category';
						}
					}

					if ( isset( $input['pillar_content'] ) ) {
						update_post_meta( $post_id, 'rank_math_pillar_content', $input['pillar_content'] ? 'on' : '' );
						$updated[] = 'pillar_content';
					}

					if ( isset( $input['cornerstone'] ) ) {
						update_post_meta( $post_id, 'rank_math_cornerstone', $input['cornerstone'] ? 'on' : '' );
						$updated[] = 'cornerstone';
					}

					if ( isset( $input['breadcrumb_title'] ) ) {
						update_post_meta( $post_id, 'rank_math_breadcrumb_title', sanitize_text_field( $input['breadcrumb_title'] ) );
						$updated[] = 'breadcrumb_title';
					}

					// ── Schema / Rich Snippet ────────────────────────────
					if ( isset( $input['snippet_type'] ) ) {
						$allowed_snippets = array( 'off', 'article', 'book', 'course', 'event', 'faq', 'howto', 'job_posting', 'local_business', 'music', 'product', 'recipe', 'restaurant', 'review', 'software', 'video' );
						if ( in_array( $input['snippet_type'], $allowed_snippets, true ) ) {
							update_post_meta( $post_id, 'rank_math_snippet_type', $input['snippet_type'] );
							$updated[] = 'snippet_type';
						}
					}

					if ( empty( $updated ) ) {
						return new WP_Error( 'no_fields', 'No fields were provided for update.' );
					}

					$count = count( $updated );
					return array(
						'post_id'        => $post_id,
						'updated_fields' => $updated,
						'message'        => "{$count} Rank Math field(s) updated successfully.",
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	/*
	 * ======================================================================
	 * SECTION C: UTILITY ABILITIES
	 * ======================================================================
	 */

	// ── C1: Search and Replace ──────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/search-replace' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/search-replace',
			array(
				'label'               => __( 'Search and Replace', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Searches for text in a specific post content and replaces it with another. Useful for corrections and updates.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'search', 'replace' ),
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID to search and replace in',
						),
						'search'  => array(
							'type'        => 'string',
							'description' => 'Text to search for',
						),
						'replace' => array(
							'type'        => 'string',
							'description' => 'Replacement text',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer' ),
						'replacements' => array( 'type' => 'integer' ),
						'message'      => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post = get_post( $post_id );
					if ( ! $post ) {
						return new WP_Error( 'not_found', 'Post not found.' );
					}
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new WP_Error( 'forbidden', 'You do not have permission to edit this post.' );
					}

					$search  = sanitize_text_field( $input['search'] );
					$replace = wp_kses_post( $input['replace'] );

					if ( empty( $search ) ) {
						return new WP_Error( 'invalid_input', 'The search text cannot be empty.' );
					}

					$count       = 0;
					$new_content = str_replace(
						$search,
						$replace,
						$post->post_content,
						$count
					);

					if ( $count > 0 ) {
						wp_update_post(
							array(
								'ID'           => $post_id,
								'post_content' => $new_content,
							)
						);
					}

					return array(
						'post_id'      => $post_id,
						'replacements' => $count,
						'message'      => $count > 0
							? "{$count} replacement(s) made successfully."
							: 'No matches found.',
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── C2: Site Statistics ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/site-stats' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/site-stats',
			array(
				'label'               => __( 'Site Statistics', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns a summary with the total posts, pages, categories, tags, comments, and users of the site.', 'enable-abilities-for-mcp' ),
				'category'            => 'site-information',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts_published'   => array( 'type' => 'integer' ),
						'posts_draft'       => array( 'type' => 'integer' ),
						'posts_pending'     => array( 'type' => 'integer' ),
						'pages_published'   => array( 'type' => 'integer' ),
						'categories_total'  => array( 'type' => 'integer' ),
						'tags_total'        => array( 'type' => 'integer' ),
						'comments_approved' => array( 'type' => 'integer' ),
						'comments_pending'  => array( 'type' => 'integer' ),
						'comments_spam'     => array( 'type' => 'integer' ),
						'users_total'       => array( 'type' => 'integer' ),
						'media_total'       => array( 'type' => 'integer' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function () {
					$post_counts    = wp_count_posts( 'post' );
					$page_counts    = wp_count_posts( 'page' );
					$comment_counts = wp_count_comments();
					$media_counts   = wp_count_posts( 'attachment' );

					// CPT counts.
					$custom_post_types = array();
					$cpt_list = get_post_types(
						array(
							'public'   => true,
							'_builtin' => false,
						),
						'objects'
					);
					foreach ( $cpt_list as $cpt_slug => $cpt_obj ) {
						$counts = wp_count_posts( $cpt_slug );
						$custom_post_types[ $cpt_slug ] = array(
							'label'     => $cpt_obj->label,
							'published' => (int) $counts->publish,
						);
					}

					return array(
						'posts_published'   => (int) $post_counts->publish,
						'posts_draft'       => (int) $post_counts->draft,
						'posts_pending'     => (int) $post_counts->pending,
						'pages_published'   => (int) $page_counts->publish,
						'categories_total'  => (int) wp_count_terms( 'category' ),
						'tags_total'        => (int) wp_count_terms( 'post_tag' ),
						'comments_approved' => (int) $comment_counts->approved,
						'comments_pending'  => (int) $comment_counts->moderated,
						'comments_spam'     => (int) $comment_counts->spam,
						'users_total'       => (int) count_users()['total_users'],
						'media_total'       => (int) $media_counts->inherit,
						'custom_post_types' => $custom_post_types,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	/*
	 * ======================================================================
	 * SECTION D: CUSTOM POST TYPE ABILITIES
	 * ======================================================================
	 */

	// ── D1: List Post Types ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/list-post-types' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/list-post-types',
			array(
				'label'               => __( 'List Post Types', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Lists all custom post types registered on the site, with their configuration, supported features, and associated taxonomies.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'         => array( 'type' => 'string' ),
							'label'        => array( 'type' => 'string' ),
							'description'  => array( 'type' => 'string' ),
							'hierarchical' => array( 'type' => 'boolean' ),
							'supports'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'taxonomies'   => array( 'type' => 'array' ),
							'count'        => array( 'type' => 'integer' ),
							'rest_base'    => array( 'type' => 'string' ),
							'menu_icon'    => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function () {
					$public_cpts = get_post_types(
						array(
							'public'   => true,
							'_builtin' => false,
						),
						'objects'
					);
					$rest_cpts   = get_post_types(
						array(
							'show_in_rest' => true,
							'_builtin'     => false,
						),
						'objects'
					);
					$all_cpts    = array_merge( $public_cpts, $rest_cpts );
					$result      = array();

					foreach ( $all_cpts as $slug => $cpt_obj ) {
						$taxonomies = array();
						foreach ( get_object_taxonomies( $slug, 'objects' ) as $tax_slug => $tax_obj ) {
							$taxonomies[] = array(
								'slug'         => $tax_slug,
								'label'        => $tax_obj->label,
								'hierarchical' => $tax_obj->hierarchical,
							);
						}

						$counts = wp_count_posts( $slug );

						$result[] = array(
							'name'         => $slug,
							'label'        => $cpt_obj->label,
							'description'  => $cpt_obj->description,
							'hierarchical' => $cpt_obj->hierarchical,
							'supports'     => array_keys( get_all_post_type_supports( $slug ) ),
							'taxonomies'   => $taxonomies,
							'count'        => isset( $counts->publish ) ? (int) $counts->publish : 0,
							'rest_base'    => $cpt_obj->rest_base ? $cpt_obj->rest_base : $slug,
							'menu_icon'    => $cpt_obj->menu_icon ? $cpt_obj->menu_icon : '',
						);
					}

					if ( empty( $result ) ) {
						return array(
							'message'    => __( 'No custom post types detected on this site.', 'enable-abilities-for-mcp' ),
							'post_types' => array(),
						);
					}

					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D2: Get CPT Items ───────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-cpt-items' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-cpt-items',
			array(
				'label'               => __( 'Get CPT Items', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves a list of items from a specific custom post type with filtering, search, and taxonomy query support.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_type' ),
					'properties' => array(
						'post_type'   => array(
							'type'        => 'string',
							'description' => __( 'The custom post type slug (e.g. product, portfolio).', 'enable-abilities-for-mcp' ),
						),
						'numberposts' => array(
							'type'        => 'integer',
							'description' => __( 'Number of items to return (default 20, max 100).', 'enable-abilities-for-mcp' ),
						),
						'status'      => array(
							'type'        => 'string',
							'description' => __( 'Filter by status: publish, draft, pending, private, any (default publish).', 'enable-abilities-for-mcp' ),
						),
						'orderby'     => array(
							'type'        => 'string',
							'description' => __( 'Order by: date, title, modified, menu_order, ID, rand (default date).', 'enable-abilities-for-mcp' ),
						),
						'order'       => array(
							'type'        => 'string',
							'description' => __( 'Sort direction: ASC or DESC (default DESC).', 'enable-abilities-for-mcp' ),
						),
						's'           => array(
							'type'        => 'string',
							'description' => __( 'Search keyword to filter items.', 'enable-abilities-for-mcp' ),
						),
						'tax_query'   => array(
							'type'        => 'array',
							'description' => __( 'Taxonomy query array. Each item: {taxonomy, terms, field (slug|id), operator (IN|NOT IN|AND)}.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'ID'           => array( 'type' => 'integer' ),
							'post_title'   => array( 'type' => 'string' ),
							'post_status'  => array( 'type' => 'string' ),
							'post_date'    => array( 'type' => 'string' ),
							'post_excerpt' => array( 'type' => 'string' ),
							'post_author'  => array( 'type' => 'integer' ),
							'permalink'    => array( 'type' => 'string' ),
							'post_type'    => array( 'type' => 'string' ),
							'taxonomies'   => array( 'type' => 'object' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$cpt_obj = ewpa_validate_cpt( $input['post_type'] );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					$numberposts = min( absint( $input['numberposts'] ?? 20 ), 100 );
					$post_status = sanitize_text_field( $input['status'] ?? 'publish' );
					$orderby     = sanitize_text_field( $input['orderby'] ?? 'date' );
					$order       = in_array( strtoupper( $input['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true )
						? strtoupper( $input['order'] )
						: 'DESC';

					$allowed_orderby = array( 'date', 'title', 'modified', 'menu_order', 'ID', 'rand' );
					if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
						$orderby = 'date';
					}

					$args = array(
						'post_type'        => $cpt_obj->name,
						'numberposts'      => $numberposts,
						'post_status'      => $post_status,
						'orderby'          => $orderby,
						'order'            => $order,
						'suppress_filters' => false,
					);

					if ( ! empty( $input['s'] ) ) {
						$args['s'] = sanitize_text_field( $input['s'] );
					}

					if ( ! empty( $input['tax_query'] ) && is_array( $input['tax_query'] ) ) {
						$tax_query = array();
						foreach ( $input['tax_query'] as $tq ) {
							if ( empty( $tq['taxonomy'] ) || empty( $tq['terms'] ) ) {
								continue;
							}
							$tax_query[] = array(
								'taxonomy' => sanitize_key( $tq['taxonomy'] ),
								'field'    => in_array( $tq['field'] ?? 'slug', array( 'slug', 'term_id', 'id' ), true )
									? ( 'id' === $tq['field'] ? 'term_id' : $tq['field'] )
									: 'slug',
								'terms'    => is_array( $tq['terms'] ) ? array_map( 'sanitize_text_field', $tq['terms'] ) : array( sanitize_text_field( $tq['terms'] ) ),
								'operator' => in_array( strtoupper( $tq['operator'] ?? 'IN' ), array( 'IN', 'NOT IN', 'AND' ), true )
									? strtoupper( $tq['operator'] )
									: 'IN',
							);
						}
						if ( ! empty( $tax_query ) ) {
							$args['tax_query'] = $tax_query;
						}
					}

					$posts  = get_posts( $args );
					$result = array();

					foreach ( $posts as $p ) {
						$taxonomies = array();
						foreach ( get_object_taxonomies( $cpt_obj->name, 'objects' ) as $tax_slug => $tax_obj ) {
							$terms = wp_get_object_terms( $p->ID, $tax_slug, array( 'fields' => 'all' ) );
							if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
								$taxonomies[ $tax_slug ] = array_map(
									function ( $term ) {
										return array(
											'term_id' => $term->term_id,
											'name'    => $term->name,
											'slug'    => $term->slug,
										);
									},
									$terms
								);
							}
						}

						$result[] = array(
							'ID'           => $p->ID,
							'post_title'   => $p->post_title,
							'post_status'  => $p->post_status,
							'post_date'    => $p->post_date,
							'post_excerpt' => $p->post_excerpt,
							'post_author'  => (int) $p->post_author,
							'permalink'    => get_permalink( $p->ID ),
							'post_type'    => $p->post_type,
							'taxonomies'   => $taxonomies,
						);
					}

					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D3: Get CPT Item ────────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-cpt-item' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-cpt-item',
			array(
				'label'               => __( 'Get CPT Item', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves full details of a single custom post type item, including all meta fields, taxonomies, and content.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the item to retrieve.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ID'              => array( 'type' => 'integer' ),
						'post_title'      => array( 'type' => 'string' ),
						'post_content'    => array( 'type' => 'string' ),
						'post_excerpt'    => array( 'type' => 'string' ),
						'post_status'     => array( 'type' => 'string' ),
						'post_date'       => array( 'type' => 'string' ),
						'post_type'       => array( 'type' => 'string' ),
						'post_type_label' => array( 'type' => 'string' ),
						'post_parent'     => array( 'type' => 'integer' ),
						'menu_order'      => array( 'type' => 'integer' ),
						'post_author'     => array( 'type' => 'integer' ),
						'permalink'       => array( 'type' => 'string' ),
						'featured_image'  => array( 'type' => 'string' ),
						'taxonomies'      => array( 'type' => 'object' ),
						'meta'            => array( 'type' => 'object' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Item not found.', 'enable-abilities-for-mcp' ) );
					}

					$cpt_obj = ewpa_validate_cpt( $post->post_type );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					// Taxonomies.
					$taxonomies = array();
					foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax_slug => $tax_obj ) {
						$terms = wp_get_object_terms( $post_id, $tax_slug, array( 'fields' => 'all' ) );
						if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
							$taxonomies[ $tax_slug ] = array_map(
								function ( $term ) {
									return array(
										'term_id' => $term->term_id,
										'name'    => $term->name,
										'slug'    => $term->slug,
									);
								},
								$terms
							);
						}
					}

					// All meta fields.
					$raw_meta = get_post_meta( $post_id );
					$meta     = array();
					if ( is_array( $raw_meta ) ) {
						foreach ( $raw_meta as $key => $values ) {
							$meta[ $key ] = count( $values ) === 1
								? maybe_unserialize( $values[0] )
								: array_map( 'maybe_unserialize', $values );
						}
					}

					$featured = '';
					$thumb_id = get_post_thumbnail_id( $post_id );
					if ( $thumb_id ) {
						$featured = wp_get_attachment_url( $thumb_id );
					}

					return array(
						'ID'              => $post->ID,
						'post_title'      => $post->post_title,
						'post_content'    => $post->post_content,
						'post_excerpt'    => $post->post_excerpt,
						'post_status'     => $post->post_status,
						'post_date'       => $post->post_date,
						'post_type'       => $post->post_type,
						'post_type_label' => $cpt_obj->labels->singular_name,
						'post_parent'     => (int) $post->post_parent,
						'menu_order'      => (int) $post->menu_order,
						'post_author'     => (int) $post->post_author,
						'permalink'       => get_permalink( $post_id ),
						'featured_image'  => $featured,
						'taxonomies'      => $taxonomies,
						'meta'            => $meta,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D4: Create CPT Item ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/create-cpt-item' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/create-cpt-item',
			array(
				'label'               => __( 'Create CPT Item', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a new item in a custom post type. Content is optional as some CPTs store data in custom fields instead.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_type', 'title' ),
					'properties' => array(
						'post_type'         => array(
							'type'        => 'string',
							'description' => __( 'The custom post type slug.', 'enable-abilities-for-mcp' ),
						),
						'title'             => array(
							'type'        => 'string',
							'description' => __( 'The item title.', 'enable-abilities-for-mcp' ),
						),
						'content'           => array(
							'type'        => 'string',
							'description' => __( 'The item content (optional — some CPTs store data in meta fields instead).', 'enable-abilities-for-mcp' ),
						),
						'excerpt'           => array(
							'type'        => 'string',
							'description' => __( 'The item excerpt.', 'enable-abilities-for-mcp' ),
						),
						'status'            => array(
							'type'        => 'string',
							'description' => __( 'Post status: draft, publish, pending, private (default draft).', 'enable-abilities-for-mcp' ),
						),
						'featured_image_id' => array(
							'type'        => 'integer',
							'description' => __( 'Attachment ID for the featured image.', 'enable-abilities-for-mcp' ),
						),
						'post_parent'       => array(
							'type'        => 'integer',
							'description' => __( 'Parent item ID (for hierarchical CPTs).', 'enable-abilities-for-mcp' ),
						),
						'menu_order'        => array(
							'type'        => 'integer',
							'description' => __( 'Menu order value.', 'enable-abilities-for-mcp' ),
						),
						'author_id'         => array(
							'type'        => 'integer',
							'description' => __( 'Author user ID.', 'enable-abilities-for-mcp' ),
						),
						'slug'              => array(
							'type'        => 'string',
							'description' => __( 'The URL slug for the item.', 'enable-abilities-for-mcp' ),
						),
						'taxonomies'        => array(
							'type'        => 'object',
							'description' => __( 'Object mapping taxonomy slugs to arrays of term slugs or IDs. Example: {"product_cat": ["clothing"], "product_tag": ["sale"]}.', 'enable-abilities-for-mcp' ),
						),
						'meta'              => array(
							'type'        => 'object',
							'description' => __( 'Object mapping meta keys to values. Supports plugin meta like _price, _sku, ACF fields, etc.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'post_type' => array( 'type' => 'string' ),
						'permalink' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$cpt_obj = ewpa_validate_cpt( $input['post_type'] );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					if ( ! current_user_can( $cpt_obj->cap->create_posts ) ) {
						return new WP_Error( 'forbidden', __( 'You do not have permission to create items of this type.', 'enable-abilities-for-mcp' ) );
					}

					$allowed_statuses = array( 'draft', 'publish', 'pending', 'private' );
					$status           = in_array( $input['status'] ?? 'draft', $allowed_statuses, true )
						? $input['status']
						: 'draft';

					$post_data = array(
						'post_type'   => $cpt_obj->name,
						'post_title'  => sanitize_text_field( $input['title'] ),
						'post_status' => $status,
					);

					if ( isset( $input['content'] ) ) {
						$post_data['post_content'] = wp_kses_post( $input['content'] );
					}
					if ( isset( $input['excerpt'] ) ) {
						$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
					}
					if ( isset( $input['post_parent'] ) ) {
						$post_data['post_parent'] = absint( $input['post_parent'] );
					}
					if ( isset( $input['menu_order'] ) ) {
						$post_data['menu_order'] = intval( $input['menu_order'] );
					}
					if ( isset( $input['author_id'] ) ) {
						$post_data['post_author'] = absint( $input['author_id'] );
					}
					if ( isset( $input['slug'] ) ) {
						$post_data['post_name'] = sanitize_title( $input['slug'] );
					}

					$post_id = wp_insert_post( $post_data, true );
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					// Featured image.
					if ( ! empty( $input['featured_image_id'] ) ) {
						set_post_thumbnail( $post_id, absint( $input['featured_image_id'] ) );
					}

					// Taxonomies.
					if ( ! empty( $input['taxonomies'] ) && is_array( $input['taxonomies'] ) ) {
						foreach ( $input['taxonomies'] as $tax_slug => $terms ) {
							$tax_slug = sanitize_key( $tax_slug );
							if ( taxonomy_exists( $tax_slug ) && in_array( $tax_slug, get_object_taxonomies( $cpt_obj->name ), true ) ) {
								$term_values = is_array( $terms ) ? $terms : array( $terms );
								wp_set_object_terms( $post_id, $term_values, $tax_slug );
							}
						}
					}

					// Meta fields.
					if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
						$blocked_keys = ewpa_get_wp_internal_meta_keys();
						foreach ( $input['meta'] as $key => $value ) {
							$key = sanitize_text_field( $key );
							if ( ! in_array( $key, $blocked_keys, true ) ) {
								update_post_meta( $post_id, $key, $value );
							}
						}
					}

					return array(
						'post_id'   => $post_id,
						'post_type' => $cpt_obj->name,
						'permalink' => get_permalink( $post_id ),
						'status'    => $status,
						'message'   => sprintf(
							/* translators: %s: post type label */
							__( '%s created successfully.', 'enable-abilities-for-mcp' ),
							$cpt_obj->labels->singular_name
						),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D5: Update CPT Item ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/update-cpt-item' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/update-cpt-item',
			array(
				'label'               => __( 'Update CPT Item', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Updates an existing custom post type item. Only provided fields are modified (partial update).', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the item to update.', 'enable-abilities-for-mcp' ),
						),
						'title'             => array(
							'type'        => 'string',
							'description' => __( 'New title.', 'enable-abilities-for-mcp' ),
						),
						'content'           => array(
							'type'        => 'string',
							'description' => __( 'New content (optional — some CPTs use meta fields instead).', 'enable-abilities-for-mcp' ),
						),
						'excerpt'           => array(
							'type'        => 'string',
							'description' => __( 'New excerpt.', 'enable-abilities-for-mcp' ),
						),
						'status'            => array(
							'type'        => 'string',
							'description' => __( 'New status: draft, publish, pending, private.', 'enable-abilities-for-mcp' ),
						),
						'featured_image_id' => array(
							'type'        => 'integer',
							'description' => __( 'Attachment ID for the featured image. Pass 0 to remove.', 'enable-abilities-for-mcp' ),
						),
						'slug'              => array(
							'type'        => 'string',
							'description' => __( 'New URL slug.', 'enable-abilities-for-mcp' ),
						),
						'taxonomies'        => array(
							'type'        => 'object',
							'description' => __( 'Object mapping taxonomy slugs to arrays of term slugs or IDs. Replaces existing terms.', 'enable-abilities-for-mcp' ),
						),
						'meta'              => array(
							'type'        => 'object',
							'description' => __( 'Object mapping meta keys to values. Supports plugin meta like _price, _sku, ACF fields, etc.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'post_type' => array( 'type' => 'string' ),
						'permalink' => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Item not found.', 'enable-abilities-for-mcp' ) );
					}

					$cpt_obj = ewpa_validate_cpt( $post->post_type );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					if ( ! current_user_can( $cpt_obj->cap->edit_posts ) || ! current_user_can( 'edit_post', $post_id ) ) {
						return new WP_Error( 'forbidden', __( 'You do not have permission to edit this item.', 'enable-abilities-for-mcp' ) );
					}

					$post_data = array( 'ID' => $post_id );

					if ( isset( $input['title'] ) ) {
						$post_data['post_title'] = sanitize_text_field( $input['title'] );
					}
					if ( isset( $input['content'] ) ) {
						$post_data['post_content'] = wp_kses_post( $input['content'] );
					}
					if ( isset( $input['excerpt'] ) ) {
						$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
					}
					if ( isset( $input['status'] ) ) {
						$allowed_statuses = array( 'draft', 'publish', 'pending', 'private' );
						if ( in_array( $input['status'], $allowed_statuses, true ) ) {
							$post_data['post_status'] = $input['status'];
						}
					}
					if ( isset( $input['slug'] ) ) {
						$post_data['post_name'] = sanitize_title( $input['slug'] );
					}

					$result = wp_update_post( $post_data, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					// Featured image.
					if ( isset( $input['featured_image_id'] ) ) {
						$img_id = absint( $input['featured_image_id'] );
						if ( 0 === $img_id ) {
							delete_post_thumbnail( $post_id );
						} else {
							set_post_thumbnail( $post_id, $img_id );
						}
					}

					// Taxonomies.
					if ( ! empty( $input['taxonomies'] ) && is_array( $input['taxonomies'] ) ) {
						foreach ( $input['taxonomies'] as $tax_slug => $terms ) {
							$tax_slug = sanitize_key( $tax_slug );
							if ( taxonomy_exists( $tax_slug ) && in_array( $tax_slug, get_object_taxonomies( $cpt_obj->name ), true ) ) {
								$term_values = is_array( $terms ) ? $terms : array( $terms );
								wp_set_object_terms( $post_id, $term_values, $tax_slug );
							}
						}
					}

					// Meta fields.
					if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
						$blocked_keys = ewpa_get_wp_internal_meta_keys();
						foreach ( $input['meta'] as $key => $value ) {
							$key = sanitize_text_field( $key );
							if ( ! in_array( $key, $blocked_keys, true ) ) {
								update_post_meta( $post_id, $key, $value );
							}
						}
					}

					return array(
						'post_id'   => $post_id,
						'post_type' => $cpt_obj->name,
						'permalink' => get_permalink( $post_id ),
						'message'   => sprintf(
							/* translators: %s: post type label */
							__( '%s updated successfully.', 'enable-abilities-for-mcp' ),
							$cpt_obj->labels->singular_name
						),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D6: Delete CPT Item ─────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/delete-cpt-item' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/delete-cpt-item',
			array(
				'label'               => __( 'Delete CPT Item', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Deletes a custom post type item. By default moves to trash; use force_delete to permanently remove.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the item to delete.', 'enable-abilities-for-mcp' ),
						),
						'force_delete' => array(
							'type'        => 'boolean',
							'description' => __( 'If true, permanently deletes instead of trashing (default false).', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'post_type' => array( 'type' => 'string' ),
						'deleted'   => array( 'type' => 'boolean' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Item not found.', 'enable-abilities-for-mcp' ) );
					}

					$cpt_obj = ewpa_validate_cpt( $post->post_type );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					if ( ! current_user_can( 'delete_post', $post_id ) ) {
						return new WP_Error( 'forbidden', __( 'You do not have permission to delete this item.', 'enable-abilities-for-mcp' ) );
					}

					$force  = ! empty( $input['force_delete'] );
					$result = wp_delete_post( $post_id, $force );

					if ( ! $result ) {
						return new WP_Error( 'delete_failed', __( 'Failed to delete the item.', 'enable-abilities-for-mcp' ) );
					}

					return array(
						'post_id'   => $post_id,
						'post_type' => $cpt_obj->name,
						'deleted'   => true,
						'message'   => $force
							? sprintf(
								/* translators: %s: post type label */
								__( '%s permanently deleted.', 'enable-abilities-for-mcp' ),
								$cpt_obj->labels->singular_name
							)
							: sprintf(
								/* translators: %s: post type label */
								__( '%s moved to trash.', 'enable-abilities-for-mcp' ),
								$cpt_obj->labels->singular_name
							),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D7: Get CPT Taxonomies ──────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/get-cpt-taxonomies' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-cpt-taxonomies',
			array(
				'label'               => __( 'Get CPT Taxonomies', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Retrieves all taxonomies associated with a custom post type, including their terms.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_type' ),
					'properties' => array(
						'post_type'  => array(
							'type'        => 'string',
							'description' => __( 'The custom post type slug.', 'enable-abilities-for-mcp' ),
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => __( 'If true, only show terms with posts (default false).', 'enable-abilities-for-mcp' ),
						),
						'number'     => array(
							'type'        => 'integer',
							'description' => __( 'Max number of terms per taxonomy (default 100, max 500).', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy'     => array( 'type' => 'string' ),
							'label'        => array( 'type' => 'string' ),
							'hierarchical' => array( 'type' => 'boolean' ),
							'terms'        => array( 'type' => 'array' ),
						),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$cpt_obj = ewpa_validate_cpt( $input['post_type'] );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					$hide_empty = ! empty( $input['hide_empty'] );
					$number     = min( absint( $input['number'] ?? 100 ), 500 );
					$result     = array();

					foreach ( get_object_taxonomies( $cpt_obj->name, 'objects' ) as $tax_slug => $tax_obj ) {
						$terms = get_terms(
							array(
								'taxonomy'   => $tax_slug,
								'hide_empty' => $hide_empty,
								'number'     => $number,
							)
						);

						$term_data = array();
						if ( ! is_wp_error( $terms ) ) {
							foreach ( $terms as $term ) {
								$term_data[] = array(
									'term_id'     => $term->term_id,
									'name'        => $term->name,
									'slug'        => $term->slug,
									'description' => $term->description,
									'parent'      => $term->parent,
									'count'       => $term->count,
								);
							}
						}

						$result[] = array(
							'taxonomy'     => $tax_slug,
							'label'        => $tax_obj->label,
							'hierarchical' => $tax_obj->hierarchical,
							'terms'        => $term_data,
						);
					}

					return $result;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// ── D8: Assign CPT Terms ────────────────────────────────────────────
	if ( ewpa_is_ability_enabled( 'ewpa/assign-cpt-terms' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/assign-cpt-terms',
			array(
				'label'               => __( 'Assign CPT Terms', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Assigns taxonomy terms to a custom post type item. Can replace or append terms.', 'enable-abilities-for-mcp' ),
				'category'            => 'cpt-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'taxonomy', 'terms' ),
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the item to assign terms to.', 'enable-abilities-for-mcp' ),
						),
						'taxonomy' => array(
							'type'        => 'string',
							'description' => __( 'The taxonomy slug.', 'enable-abilities-for-mcp' ),
						),
						'terms'    => array(
							'type'        => 'array',
							'description' => __( 'Array of term slugs or IDs to assign.', 'enable-abilities-for-mcp' ),
						),
						'append'   => array(
							'type'        => 'boolean',
							'description' => __( 'If true, appends terms instead of replacing (default false).', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'taxonomy'  => array( 'type' => 'string' ),
						'terms_set' => array( 'type' => 'array' ),
						'message'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'execute_callback'    => function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Item not found.', 'enable-abilities-for-mcp' ) );
					}

					$cpt_obj = ewpa_validate_cpt( $post->post_type );
					if ( is_wp_error( $cpt_obj ) ) {
						return $cpt_obj;
					}

					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new WP_Error( 'forbidden', __( 'You do not have permission to edit this item.', 'enable-abilities-for-mcp' ) );
					}

					$taxonomy = sanitize_key( $input['taxonomy'] );

					if ( ! taxonomy_exists( $taxonomy ) ) {
						return new WP_Error( 'invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'enable-abilities-for-mcp' ) );
					}

					if ( ! in_array( $taxonomy, get_object_taxonomies( $post->post_type ), true ) ) {
						return new WP_Error(
							'taxonomy_mismatch',
							sprintf(
								/* translators: %1$s: taxonomy slug, %2$s: post type label */
								__( 'The taxonomy "%1$s" is not associated with the "%2$s" post type.', 'enable-abilities-for-mcp' ),
								$taxonomy,
								$cpt_obj->labels->singular_name
							)
						);
					}

					$tax_obj = get_taxonomy( $taxonomy );
					if ( ! current_user_can( $tax_obj->cap->assign_terms ) ) {
						return new WP_Error( 'forbidden', __( 'You do not have permission to assign terms for this taxonomy.', 'enable-abilities-for-mcp' ) );
					}

					$terms  = is_array( $input['terms'] ) ? $input['terms'] : array( $input['terms'] );
					$append = ! empty( $input['append'] );

					$result = wp_set_object_terms( $post_id, $terms, $taxonomy, $append );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					// Get the final assigned terms for confirmation.
					$final_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
					$terms_set   = array();
					if ( ! is_wp_error( $final_terms ) ) {
						foreach ( $final_terms as $term ) {
							$terms_set[] = array(
								'term_id' => $term->term_id,
								'name'    => $term->name,
								'slug'    => $term->slug,
							);
						}
					}

					return array(
						'post_id'   => $post_id,
						'taxonomy'  => $taxonomy,
						'terms_set' => $terms_set,
						'message'   => sprintf(
							/* translators: %1$d: number of terms, %2$s: taxonomy label */
							__( '%1$d term(s) assigned for %2$s.', 'enable-abilities-for-mcp' ),
							count( $terms_set ),
							$tax_obj->label
						),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Section E: WooCommerce abilities
	// -------------------------------------------------------------------------

	if ( ewpa_is_ability_enabled( 'ewpa/wc-get-products' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-get-products',
			array(
				'name'                => __( 'List WooCommerce products', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns a paginated list of WooCommerce products with price, stock, and status.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'description' => __( 'Product status: publish, draft, pending. Default: publish.', 'enable-abilities-for-mcp' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (1–100). Default: 10.', 'enable-abilities-for-mcp' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number. Default: 1.', 'enable-abilities-for-mcp' ),
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Keyword search in product name.', 'enable-abilities-for-mcp' ),
						),
						'category' => array(
							'type'        => 'string',
							'description' => __( 'Filter by product category slug.', 'enable-abilities-for-mcp' ),
						),
						'orderby'  => array(
							'type'        => 'string',
							'description' => __( 'Order by: date, price, popularity, rating. Default: date.', 'enable-abilities-for-mcp' ),
						),
						'order'    => array(
							'type'        => 'string',
							'description' => __( 'Sort order: ASC or DESC. Default: DESC.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_products' );
				},
				'callback'            => function ( $args ) {
					$status   = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'publish';
					$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, intval( $args['per_page'] ) ) ) : 10;
					$page     = isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1;
					$search   = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
					$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
					$orderby  = isset( $args['orderby'] ) ? sanitize_text_field( $args['orderby'] ) : 'date';
					$order    = isset( $args['order'] ) && strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

					$query_args = array(
						'status'  => $status,
						'limit'   => $per_page,
						'page'    => $page,
						'orderby' => $orderby,
						'order'   => $order,
						'return'  => 'objects',
					);

					if ( $search ) {
						$query_args['s'] = $search;
					}

					if ( $category ) {
						$query_args['category'] = array( $category );
					}

					$products = wc_get_products( $query_args );
					$items    = array();

					foreach ( $products as $product ) {
						$items[] = array(
							'id'            => $product->get_id(),
							'name'          => $product->get_name(),
							'sku'           => $product->get_sku(),
							'status'        => $product->get_status(),
							'type'          => $product->get_type(),
							'price'         => $product->get_price(),
							'regular_price' => $product->get_regular_price(),
							'sale_price'    => $product->get_sale_price(),
							'stock_status'  => $product->get_stock_status(),
							'stock_qty'     => $product->get_stock_quantity(),
							'permalink'     => get_permalink( $product->get_id() ),
						);
					}

					return array(
						'products' => $items,
						'page'     => $page,
						'per_page' => $per_page,
						'total'    => count( $items ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/wc-get-product' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-get-product',
			array(
				'name'                => __( 'Get WooCommerce product', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns full details of a single WooCommerce product by ID.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => __( 'The product post ID.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'product_id' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_products' );
				},
				'callback'            => function ( $args ) {
					$product_id = intval( $args['product_id'] );
					$product    = wc_get_product( $product_id );

					if ( ! $product ) {
						return new WP_Error( 'not_found', __( 'Product not found.', 'enable-abilities-for-mcp' ), array( 'status' => 404 ) );
					}

					$categories = array();
					foreach ( $product->get_category_ids() as $cat_id ) {
						$term = get_term( $cat_id, 'product_cat' );
						if ( $term && ! is_wp_error( $term ) ) {
							$categories[] = array(
								'id'   => $term->term_id,
								'name' => $term->name,
								'slug' => $term->slug,
							);
						}
					}

					$tags = array();
					foreach ( $product->get_tag_ids() as $tag_id ) {
						$term = get_term( $tag_id, 'product_tag' );
						if ( $term && ! is_wp_error( $term ) ) {
							$tags[] = array(
								'id'   => $term->term_id,
								'name' => $term->name,
								'slug' => $term->slug,
							);
						}
					}

					$attributes = array();
					foreach ( $product->get_attributes() as $key => $attribute ) {
						$attributes[] = array(
							'name'    => $attribute->get_name(),
							'options' => $attribute->get_options(),
						);
					}

					return array(
						'id'                => $product->get_id(),
						'name'              => $product->get_name(),
						'slug'              => $product->get_slug(),
						'sku'               => $product->get_sku(),
						'status'            => $product->get_status(),
						'type'              => $product->get_type(),
						'description'       => $product->get_description(),
						'short_description' => $product->get_short_description(),
						'price'             => $product->get_price(),
						'regular_price'     => $product->get_regular_price(),
						'sale_price'        => $product->get_sale_price(),
						'on_sale'           => $product->is_on_sale(),
						'stock_status'      => $product->get_stock_status(),
						'stock_qty'         => $product->get_stock_quantity(),
						'manage_stock'      => $product->get_manage_stock(),
						'weight'            => $product->get_weight(),
						'categories'        => $categories,
						'tags'              => $tags,
						'attributes'        => $attributes,
						'permalink'         => get_permalink( $product->get_id() ),
						'date_created'      => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
						'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : null,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/wc-update-product' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-update-product',
			array(
				'name'                => __( 'Update WooCommerce product', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Updates price, stock, status, or description of a WooCommerce product using WooCommerce hooks.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The product post ID.', 'enable-abilities-for-mcp' ),
						),
						'regular_price' => array(
							'type'        => 'string',
							'description' => __( 'New regular price (numeric string).', 'enable-abilities-for-mcp' ),
						),
						'sale_price'    => array(
							'type'        => 'string',
							'description' => __( 'New sale price (numeric string, empty to clear).', 'enable-abilities-for-mcp' ),
						),
						'stock_qty'     => array(
							'type'        => 'integer',
							'description' => __( 'New stock quantity. Requires manage_stock to be enabled.', 'enable-abilities-for-mcp' ),
						),
						'stock_status'  => array(
							'type'        => 'string',
							'description' => __( 'Stock status: instock, outofstock, onbackorder.', 'enable-abilities-for-mcp' ),
						),
						'status'        => array(
							'type'        => 'string',
							'description' => __( 'Product status: publish, draft, pending.', 'enable-abilities-for-mcp' ),
						),
						'description'   => array(
							'type'        => 'string',
							'description' => __( 'Full product description.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'product_id' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_products' );
				},
				'callback'            => function ( $args ) {
					$product_id = intval( $args['product_id'] );
					$product    = wc_get_product( $product_id );

					if ( ! $product ) {
						return new WP_Error( 'not_found', __( 'Product not found.', 'enable-abilities-for-mcp' ), array( 'status' => 404 ) );
					}

					if ( isset( $args['regular_price'] ) ) {
						$product->set_regular_price( wc_format_decimal( $args['regular_price'] ) );
					}
					if ( array_key_exists( 'sale_price', $args ) ) {
						$product->set_sale_price( '' !== $args['sale_price'] ? wc_format_decimal( $args['sale_price'] ) : '' );
					}
					if ( isset( $args['stock_qty'] ) ) {
						$product->set_manage_stock( true );
						$product->set_stock_quantity( intval( $args['stock_qty'] ) );
					}
					if ( isset( $args['stock_status'] ) ) {
						$product->set_stock_status( sanitize_text_field( $args['stock_status'] ) );
					}
					if ( isset( $args['status'] ) ) {
						$product->set_status( sanitize_text_field( $args['status'] ) );
					}
					if ( isset( $args['description'] ) ) {
						$product->set_description( wp_kses_post( $args['description'] ) );
					}

					$saved_id = $product->save();

					if ( is_wp_error( $saved_id ) ) {
						return $saved_id;
					}

					return array(
						'product_id'    => $product->get_id(),
						'regular_price' => $product->get_regular_price(),
						'sale_price'    => $product->get_sale_price(),
						'stock_status'  => $product->get_stock_status(),
						'stock_qty'     => $product->get_stock_quantity(),
						'status'        => $product->get_status(),
						'message'       => __( 'Product updated successfully.', 'enable-abilities-for-mcp' ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/wc-get-orders' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-get-orders',
			array(
				'name'                => __( 'List WooCommerce orders', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns a paginated list of WooCommerce orders. HPOS-compatible.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'      => array(
							'type'        => 'string',
							'description' => __( 'Order status: pending, processing, on-hold, completed, cancelled, refunded, failed. Default: any.', 'enable-abilities-for-mcp' ),
						),
						'per_page'    => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (1–100). Default: 10.', 'enable-abilities-for-mcp' ),
						),
						'page'        => array(
							'type'        => 'integer',
							'description' => __( 'Page number. Default: 1.', 'enable-abilities-for-mcp' ),
						),
						'customer_id' => array(
							'type'        => 'integer',
							'description' => __( 'Filter by customer user ID.', 'enable-abilities-for-mcp' ),
						),
						'date_after'  => array(
							'type'        => 'string',
							'description' => __( 'Filter orders created after this date (YYYY-MM-DD).', 'enable-abilities-for-mcp' ),
						),
						'date_before' => array(
							'type'        => 'string',
							'description' => __( 'Filter orders created before this date (YYYY-MM-DD).', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_shop_orders' );
				},
				'callback'            => function ( $args ) {
					$status   = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'any';
					$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, intval( $args['per_page'] ) ) ) : 10;
					$page     = isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1;

					$query_args = array(
						'status'  => $status,
						'limit'   => $per_page,
						'paged'   => $page,
						'return'  => 'objects',
						'orderby' => 'date',
						'order'   => 'DESC',
					);

					if ( isset( $args['customer_id'] ) ) {
						$query_args['customer_id'] = intval( $args['customer_id'] );
					}
					if ( isset( $args['date_after'] ) ) {
						$query_args['date_after'] = sanitize_text_field( $args['date_after'] );
					}
					if ( isset( $args['date_before'] ) ) {
						$query_args['date_before'] = sanitize_text_field( $args['date_before'] );
					}

					$orders = wc_get_orders( $query_args );
					$items  = array();

					foreach ( $orders as $order ) {
						$items[] = array(
							'id'             => $order->get_id(),
							'status'         => $order->get_status(),
							'total'          => $order->get_total(),
							'currency'       => $order->get_currency(),
							'customer_id'    => $order->get_customer_id(),
							'customer_email' => $order->get_billing_email(),
							'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
							'items_count'    => $order->get_item_count(),
							'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
							'payment_method' => $order->get_payment_method_title(),
						);
					}

					return array(
						'orders'   => $items,
						'page'     => $page,
						'per_page' => $per_page,
						'total'    => count( $items ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/wc-get-order' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-get-order',
			array(
				'name'                => __( 'Get WooCommerce order', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns full details of a single WooCommerce order including line items and billing address. HPOS-compatible.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array(
							'type'        => 'integer',
							'description' => __( 'The order ID.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'order_id' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_shop_orders' );
				},
				'callback'            => function ( $args ) {
					$order_id = intval( $args['order_id'] );
					$order    = wc_get_order( $order_id );

					if ( ! $order ) {
						return new WP_Error( 'not_found', __( 'Order not found.', 'enable-abilities-for-mcp' ), array( 'status' => 404 ) );
					}

					$line_items = array();
					foreach ( $order->get_items() as $item_id => $item ) {
						$line_items[] = array(
							'item_id'    => $item_id,
							'name'       => $item->get_name(),
							'product_id' => $item->get_product_id(),
							'qty'        => $item->get_quantity(),
							'total'      => $item->get_total(),
						);
					}

					return array(
						'id'             => $order->get_id(),
						'status'         => $order->get_status(),
						'currency'       => $order->get_currency(),
						'total'          => $order->get_total(),
						'subtotal'       => $order->get_subtotal(),
						'total_tax'      => $order->get_total_tax(),
						'shipping_total' => $order->get_shipping_total(),
						'payment_method' => $order->get_payment_method(),
						'payment_title'  => $order->get_payment_method_title(),
						'customer_id'    => $order->get_customer_id(),
						'customer_note'  => $order->get_customer_note(),
						'billing'        => array(
							'first_name' => $order->get_billing_first_name(),
							'last_name'  => $order->get_billing_last_name(),
							'email'      => $order->get_billing_email(),
							'phone'      => $order->get_billing_phone(),
							'address_1'  => $order->get_billing_address_1(),
							'address_2'  => $order->get_billing_address_2(),
							'city'       => $order->get_billing_city(),
							'state'      => $order->get_billing_state(),
							'postcode'   => $order->get_billing_postcode(),
							'country'    => $order->get_billing_country(),
						),
						'shipping'       => array(
							'first_name' => $order->get_shipping_first_name(),
							'last_name'  => $order->get_shipping_last_name(),
							'address_1'  => $order->get_shipping_address_1(),
							'address_2'  => $order->get_shipping_address_2(),
							'city'       => $order->get_shipping_city(),
							'state'      => $order->get_shipping_state(),
							'postcode'   => $order->get_shipping_postcode(),
							'country'    => $order->get_shipping_country(),
						),
						'line_items'     => $line_items,
						'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
						'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : null,
						'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : null,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/wc-update-order-status' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-update-order-status',
			array(
				'name'                => __( 'Update WooCommerce order status', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Changes the status of a WooCommerce order and optionally adds a note. HPOS-compatible.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array(
							'type'        => 'integer',
							'description' => __( 'The order ID.', 'enable-abilities-for-mcp' ),
						),
						'status'   => array(
							'type'        => 'string',
							'description' => __( 'New status: pending, processing, on-hold, completed, cancelled, refunded, failed.', 'enable-abilities-for-mcp' ),
						),
						'note'     => array(
							'type'        => 'string',
							'description' => __( 'Optional note to add to the order.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'order_id', 'status' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_shop_orders' );
				},
				'callback'            => function ( $args ) {
					$order_id = intval( $args['order_id'] );
					$order    = wc_get_order( $order_id );

					if ( ! $order ) {
						return new WP_Error( 'not_found', __( 'Order not found.', 'enable-abilities-for-mcp' ), array( 'status' => 404 ) );
					}

					$valid_statuses = array_keys( wc_get_order_statuses() );
					$new_status     = sanitize_text_field( $args['status'] );
					$prefixed       = 'wc-' . $new_status;

					if ( ! in_array( $prefixed, $valid_statuses, true ) && ! in_array( $new_status, $valid_statuses, true ) ) {
						return new WP_Error( 'invalid_status', __( 'Invalid order status.', 'enable-abilities-for-mcp' ), array( 'status' => 400 ) );
					}

					$old_status = $order->get_status();
					$order->update_status( $new_status, isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '' );

					return array(
						'order_id'   => $order->get_id(),
						'old_status' => $old_status,
						'new_status' => $order->get_status(),
						'message'    => __( 'Order status updated.', 'enable-abilities-for-mcp' ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/wc-get-customers' ) && class_exists( 'WooCommerce' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/wc-get-customers',
			array(
				'name'                => __( 'List WooCommerce customers', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns a list of WooCommerce customers with order stats.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (1–100). Default: 10.', 'enable-abilities-for-mcp' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number. Default: 1.', 'enable-abilities-for-mcp' ),
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Search by name or email.', 'enable-abilities-for-mcp' ),
						),
						'orderby'  => array(
							'type'        => 'string',
							'description' => __( 'Order by: registered, name, email. Default: registered.', 'enable-abilities-for-mcp' ),
						),
						'order'    => array(
							'type'        => 'string',
							'description' => __( 'Sort order: ASC or DESC. Default: DESC.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'list_users' );
				},
				'callback'            => function ( $args ) {
					$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, intval( $args['per_page'] ) ) ) : 10;
					$page     = isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1;
					$search   = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
					$orderby  = isset( $args['orderby'] ) ? sanitize_text_field( $args['orderby'] ) : 'registered';
					$order    = isset( $args['order'] ) && strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

					$query_args = array(
						'role__in' => array( 'customer', 'subscriber' ),
						'number'   => $per_page,
						'paged'    => $page,
						'orderby'  => $orderby,
						'order'    => $order,
					);

					if ( $search ) {
						$query_args['search'] = '*' . $search . '*';
					}

					$user_query = new WP_User_Query( $query_args );
					$users      = $user_query->get_results();
					$items      = array();

					foreach ( $users as $user ) {
						$customer = new WC_Customer( $user->ID );
						$items[]  = array(
							'id'              => $user->ID,
							'username'        => $user->user_login,
							'email'           => $user->user_email,
							'first_name'      => $customer->get_first_name(),
							'last_name'       => $customer->get_last_name(),
							'orders_count'    => $customer->get_order_count(),
							'total_spent'     => $customer->get_total_spent(),
							'date_registered' => $user->user_registered,
							'billing_city'    => $customer->get_billing_city(),
							'billing_country' => $customer->get_billing_country(),
						);
					}

					return array(
						'customers' => $items,
						'page'      => $page,
						'per_page'  => $per_page,
						'total'     => $user_query->get_total(),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Section F: The Events Calendar abilities
	// -------------------------------------------------------------------------

	if ( ewpa_is_ability_enabled( 'ewpa/tec-get-events' ) && class_exists( 'Tribe__Events__Main' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/tec-get-events',
			array(
				'name'                => __( 'List Events Calendar events', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns a list of upcoming or filtered events from The Events Calendar.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'per_page'     => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (1–100). Default: 10.', 'enable-abilities-for-mcp' ),
						),
						'page'         => array(
							'type'        => 'integer',
							'description' => __( 'Page number. Default: 1.', 'enable-abilities-for-mcp' ),
						),
						'start_after'  => array(
							'type'        => 'string',
							'description' => __( 'Return events starting after this date (YYYY-MM-DD). Default: today.', 'enable-abilities-for-mcp' ),
						),
						'start_before' => array(
							'type'        => 'string',
							'description' => __( 'Return events starting before this date (YYYY-MM-DD).', 'enable-abilities-for-mcp' ),
						),
						'search'       => array(
							'type'        => 'string',
							'description' => __( 'Keyword search in event title.', 'enable-abilities-for-mcp' ),
						),
					),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_tribe_events' );
				},
				'callback'            => function ( $args ) {
					$per_page     = isset( $args['per_page'] ) ? max( 1, min( 100, intval( $args['per_page'] ) ) ) : 10;
					$page         = isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1;
					$start_after  = isset( $args['start_after'] ) ? sanitize_text_field( $args['start_after'] ) : gmdate( 'Y-m-d' );
					$start_before = isset( $args['start_before'] ) ? sanitize_text_field( $args['start_before'] ) : '';
					$search       = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

					$query_args = array(
						'post_type'      => Tribe__Events__Main::POSTTYPE,
						'posts_per_page' => $per_page,
						'paged'          => $page,
						'post_status'    => 'publish',
						'orderby'        => 'meta_value',
						'meta_key'       => '_EventStartDate',
						'order'          => 'ASC',
						'meta_query'     => array(
							array(
								'key'     => '_EventStartDate',
								'value'   => $start_after . ' 00:00:00',
								'compare' => '>=',
								'type'    => 'DATETIME',
							),
						),
					);

					if ( $start_before ) {
						$query_args['meta_query'][] = array(
							'key'     => '_EventStartDate',
							'value'   => $start_before . ' 23:59:59',
							'compare' => '<=',
							'type'    => 'DATETIME',
						);
					}

					if ( $search ) {
						$query_args['s'] = $search;
					}

					$query = new WP_Query( $query_args );
					$items = array();

					foreach ( $query->posts as $post ) {
						$items[] = array(
							'id'         => $post->ID,
							'title'      => $post->post_title,
							'start_date' => get_post_meta( $post->ID, '_EventStartDate', true ),
							'end_date'   => get_post_meta( $post->ID, '_EventEndDate', true ),
							'timezone'   => get_post_meta( $post->ID, '_EventTimezone', true ),
							'venue_id'   => get_post_meta( $post->ID, '_EventVenueID', true ),
							'permalink'  => get_permalink( $post->ID ),
							'status'     => $post->post_status,
						);
					}

					return array(
						'events'    => $items,
						'page'      => $page,
						'per_page'  => $per_page,
						'total'     => $query->found_posts,
						'max_pages' => $query->max_num_pages,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/tec-get-event' ) && class_exists( 'Tribe__Events__Main' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/tec-get-event',
			array(
				'name'                => __( 'Get Events Calendar event', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Returns full details of a single event including venue address and organizer.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id' => array(
							'type'        => 'integer',
							'description' => __( 'The event post ID.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'event_id' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_tribe_events' );
				},
				'callback'            => function ( $args ) {
					$event_id = intval( $args['event_id'] );
					$post     = get_post( $event_id );

					if ( ! $post || Tribe__Events__Main::POSTTYPE !== $post->post_type ) {
						return new WP_Error( 'not_found', __( 'Event not found.', 'enable-abilities-for-mcp' ), array( 'status' => 404 ) );
					}

					// Resolve venue details from the linked tribe_venue post.
					$venue_id   = get_post_meta( $event_id, '_EventVenueID', true );
					$venue_data = array();
					if ( $venue_id ) {
						$venue_post = get_post( intval( $venue_id ) );
						if ( $venue_post ) {
							$venue_data = array(
								'id'      => $venue_post->ID,
								'name'    => $venue_post->post_title,
								'address' => get_post_meta( $venue_post->ID, '_VenueAddress', true ),
								'city'    => get_post_meta( $venue_post->ID, '_VenueCity', true ),
								'state'   => get_post_meta( $venue_post->ID, '_VenueStateProvince', true ),
								'zip'     => get_post_meta( $venue_post->ID, '_VenueZip', true ),
								'country' => get_post_meta( $venue_post->ID, '_VenueCountry', true ),
								'phone'   => get_post_meta( $venue_post->ID, '_VenuePhone', true ),
								'website' => get_post_meta( $venue_post->ID, '_VenueURL', true ),
							);
						}
					}

					// Resolve organizer details.
					$organizer_id   = get_post_meta( $event_id, '_EventOrganizerID', true );
					$organizer_data = array();
					if ( $organizer_id ) {
						$org_post = get_post( intval( $organizer_id ) );
						if ( $org_post ) {
							$organizer_data = array(
								'id'      => $org_post->ID,
								'name'    => $org_post->post_title,
								'email'   => get_post_meta( $org_post->ID, '_OrganizerEmail', true ),
								'website' => get_post_meta( $org_post->ID, '_OrganizerWebsite', true ),
								'phone'   => get_post_meta( $org_post->ID, '_OrganizerPhone', true ),
							);
						}
					}

					return array(
						'id'           => $post->ID,
						'title'        => $post->post_title,
						'description'  => $post->post_content,
						'status'       => $post->post_status,
						'start_date'   => get_post_meta( $event_id, '_EventStartDate', true ),
						'end_date'     => get_post_meta( $event_id, '_EventEndDate', true ),
						'timezone'     => get_post_meta( $event_id, '_EventTimezone', true ),
						'all_day'      => (bool) get_post_meta( $event_id, '_EventAllDay', true ),
						'cost'         => get_post_meta( $event_id, '_EventCost', true ),
						'currency'     => get_post_meta( $event_id, '_EventCurrencySymbol', true ),
						'website'      => get_post_meta( $event_id, '_EventURL', true ),
						'venue'        => $venue_data,
						'organizer'    => $organizer_data,
						'permalink'    => get_permalink( $post->ID ),
						'date_created' => $post->post_date,
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/tec-create-event' ) && class_exists( 'Tribe__Events__Main' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/tec-create-event',
			array(
				'name'                => __( 'Create Events Calendar event', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a new event in The Events Calendar with title, dates, and optional venue.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'        => array(
							'type'        => 'string',
							'description' => __( 'Event title.', 'enable-abilities-for-mcp' ),
						),
						'description'  => array(
							'type'        => 'string',
							'description' => __( 'Event description (HTML allowed).', 'enable-abilities-for-mcp' ),
						),
						'start_date'   => array(
							'type'        => 'string',
							'description' => __( 'Start date and time (YYYY-MM-DD HH:MM:SS).', 'enable-abilities-for-mcp' ),
						),
						'end_date'     => array(
							'type'        => 'string',
							'description' => __( 'End date and time (YYYY-MM-DD HH:MM:SS).', 'enable-abilities-for-mcp' ),
						),
						'timezone'     => array(
							'type'        => 'string',
							'description' => __( 'Timezone string, e.g. America/New_York. Defaults to site timezone.', 'enable-abilities-for-mcp' ),
						),
						'venue_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Existing tribe_venue post ID to link.', 'enable-abilities-for-mcp' ),
						),
						'organizer_id' => array(
							'type'        => 'integer',
							'description' => __( 'Existing tribe_organizer post ID to link.', 'enable-abilities-for-mcp' ),
						),
						'cost'         => array(
							'type'        => 'string',
							'description' => __( 'Ticket/admission cost (free-form string).', 'enable-abilities-for-mcp' ),
						),
						'website'      => array(
							'type'        => 'string',
							'description' => __( 'External event URL.', 'enable-abilities-for-mcp' ),
						),
						'status'       => array(
							'type'        => 'string',
							'description' => __( 'Post status: publish, draft. Default: publish.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'title', 'start_date', 'end_date' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'publish_tribe_events' );
				},
				'callback'            => function ( $args ) {
					$title       = sanitize_text_field( $args['title'] );
					$description = isset( $args['description'] ) ? wp_kses_post( $args['description'] ) : '';
					$start_date  = sanitize_text_field( $args['start_date'] );
					$end_date    = sanitize_text_field( $args['end_date'] );
					$status      = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'publish';
					$timezone    = isset( $args['timezone'] ) ? sanitize_text_field( $args['timezone'] ) : get_option( 'timezone_string', 'UTC' );

					$post_args = array(
						'post_title'   => $title,
						'post_content' => $description,
						'post_status'  => $status,
						'post_type'    => Tribe__Events__Main::POSTTYPE,
					);

					$event_id = wp_insert_post( $post_args, true );

					if ( is_wp_error( $event_id ) ) {
						return $event_id;
					}

					update_post_meta( $event_id, '_EventStartDate', $start_date );
					update_post_meta( $event_id, '_EventEndDate', $end_date );
					update_post_meta( $event_id, '_EventTimezone', $timezone );
					update_post_meta( $event_id, '_EventStartDateUTC', $start_date );
					update_post_meta( $event_id, '_EventEndDateUTC', $end_date );

					if ( isset( $args['venue_id'] ) ) {
						update_post_meta( $event_id, '_EventVenueID', intval( $args['venue_id'] ) );
					}
					if ( isset( $args['organizer_id'] ) ) {
						update_post_meta( $event_id, '_EventOrganizerID', intval( $args['organizer_id'] ) );
					}
					if ( isset( $args['cost'] ) ) {
						update_post_meta( $event_id, '_EventCost', sanitize_text_field( $args['cost'] ) );
					}
					if ( isset( $args['website'] ) ) {
						update_post_meta( $event_id, '_EventURL', esc_url_raw( $args['website'] ) );
					}

					return array(
						'event_id'   => $event_id,
						'title'      => $title,
						'start_date' => $start_date,
						'end_date'   => $end_date,
						'permalink'  => get_permalink( $event_id ),
						'message'    => __( 'Event created successfully.', 'enable-abilities-for-mcp' ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/tec-update-event' ) && class_exists( 'Tribe__Events__Main' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/tec-update-event',
			array(
				'name'                => __( 'Update Events Calendar event', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Updates an existing event\'s dates, title, description, venue, or status.', 'enable-abilities-for-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id'     => array(
							'type'        => 'integer',
							'description' => __( 'The event post ID.', 'enable-abilities-for-mcp' ),
						),
						'title'        => array(
							'type'        => 'string',
							'description' => __( 'New event title.', 'enable-abilities-for-mcp' ),
						),
						'description'  => array(
							'type'        => 'string',
							'description' => __( 'New event description.', 'enable-abilities-for-mcp' ),
						),
						'start_date'   => array(
							'type'        => 'string',
							'description' => __( 'New start date and time (YYYY-MM-DD HH:MM:SS).', 'enable-abilities-for-mcp' ),
						),
						'end_date'     => array(
							'type'        => 'string',
							'description' => __( 'New end date and time (YYYY-MM-DD HH:MM:SS).', 'enable-abilities-for-mcp' ),
						),
						'timezone'     => array(
							'type'        => 'string',
							'description' => __( 'Timezone string.', 'enable-abilities-for-mcp' ),
						),
						'venue_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Venue post ID to link.', 'enable-abilities-for-mcp' ),
						),
						'organizer_id' => array(
							'type'        => 'integer',
							'description' => __( 'Organizer post ID to link.', 'enable-abilities-for-mcp' ),
						),
						'cost'         => array(
							'type'        => 'string',
							'description' => __( 'Admission cost.', 'enable-abilities-for-mcp' ),
						),
						'website'      => array(
							'type'        => 'string',
							'description' => __( 'External event URL.', 'enable-abilities-for-mcp' ),
						),
						'status'       => array(
							'type'        => 'string',
							'description' => __( 'Post status: publish, draft, private.', 'enable-abilities-for-mcp' ),
						),
					),
					'required'   => array( 'event_id' ),
				),
				'permission_callback' => function ( $args ) {
					return current_user_can( 'edit_tribe_events' );
				},
				'callback'            => function ( $args ) {
					$event_id = intval( $args['event_id'] );
					$post     = get_post( $event_id );

					if ( ! $post || Tribe__Events__Main::POSTTYPE !== $post->post_type ) {
						return new WP_Error( 'not_found', __( 'Event not found.', 'enable-abilities-for-mcp' ), array( 'status' => 404 ) );
					}

					$post_args = array( 'ID' => $event_id );
					if ( isset( $args['title'] ) ) {
						$post_args['post_title'] = sanitize_text_field( $args['title'] );
					}
					if ( isset( $args['description'] ) ) {
						$post_args['post_content'] = wp_kses_post( $args['description'] );
					}
					if ( isset( $args['status'] ) ) {
						$post_args['post_status'] = sanitize_text_field( $args['status'] );
					}

					if ( count( $post_args ) > 1 ) {
						$result = wp_update_post( $post_args, true );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}

					if ( isset( $args['start_date'] ) ) {
						update_post_meta( $event_id, '_EventStartDate', sanitize_text_field( $args['start_date'] ) );
						update_post_meta( $event_id, '_EventStartDateUTC', sanitize_text_field( $args['start_date'] ) );
					}
					if ( isset( $args['end_date'] ) ) {
						update_post_meta( $event_id, '_EventEndDate', sanitize_text_field( $args['end_date'] ) );
						update_post_meta( $event_id, '_EventEndDateUTC', sanitize_text_field( $args['end_date'] ) );
					}
					if ( isset( $args['timezone'] ) ) {
						update_post_meta( $event_id, '_EventTimezone', sanitize_text_field( $args['timezone'] ) );
					}
					if ( isset( $args['venue_id'] ) ) {
						update_post_meta( $event_id, '_EventVenueID', intval( $args['venue_id'] ) );
					}
					if ( isset( $args['organizer_id'] ) ) {
						update_post_meta( $event_id, '_EventOrganizerID', intval( $args['organizer_id'] ) );
					}
					if ( isset( $args['cost'] ) ) {
						update_post_meta( $event_id, '_EventCost', sanitize_text_field( $args['cost'] ) );
					}
					if ( isset( $args['website'] ) ) {
						update_post_meta( $event_id, '_EventURL', esc_url_raw( $args['website'] ) );
					}

					$updated_post = get_post( $event_id );

					return array(
						'event_id'   => $event_id,
						'title'      => $updated_post->post_title,
						'start_date' => get_post_meta( $event_id, '_EventStartDate', true ),
						'end_date'   => get_post_meta( $event_id, '_EventEndDate', true ),
						'status'     => $updated_post->post_status,
						'permalink'  => get_permalink( $event_id ),
						'message'    => __( 'Event updated successfully.', 'enable-abilities-for-mcp' ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}
}
