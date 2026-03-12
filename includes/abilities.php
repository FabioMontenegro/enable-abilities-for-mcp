<?php
/**
 * Ability registration for Enable Abilities for MCP.
 *
 * Each ability is only registered if enabled in the admin settings.
 *
 * @package EnableAbilitiesForMCP
 */

if (! defined('ABSPATH')) {
    exit;
}

/* ==========================================================================
 * CORE ABILITIES FILTER
 * ==========================================================================
 * WordPress 6.9 core abilities exist but aren't exposed to MCP by default.
 * This filter adds the meta.mcp.public flag for enabled core abilities.
 * ========================================================================== */

/**
 * Exposes enabled core abilities to MCP.
 */
function ewpa_filter_core_abilities(array $args, string $ability_name): array
{
    $core_abilities = array(
        'core/get-site-info',
        'core/get-user-info',
        'core/get-environment-info',
    );

    if (in_array($ability_name, $core_abilities, true) && ewpa_is_ability_enabled($ability_name)) {
        $args['meta']['mcp']['public'] = true;
    }

    return $args;
}

/* ==========================================================================
 * ABILITY CATEGORIES
 * ========================================================================== */

/**
 * Registers ability categories for the Abilities Explorer.
 */
function ewpa_register_ability_categories(): void
{
    if (! function_exists('wp_register_ability_category')) {
        return;
    }

    wp_register_ability_category(
        'content-management',
        array(
            'label'       => __('Gestión de Contenido', 'enable-abilities-for-mcp'),
            'description' => __('Abilities para crear, leer, actualizar y eliminar contenido del blog.', 'enable-abilities-for-mcp'),
        )
    );

    wp_register_ability_category(
        'user-management',
        array(
            'label'       => __('Gestión de Usuarios', 'enable-abilities-for-mcp'),
            'description' => __('Abilities para consultar información de usuarios del sitio.', 'enable-abilities-for-mcp'),
        )
    );

    wp_register_ability_category(
        'site-information',
        array(
            'label'       => __('Información del Sitio', 'enable-abilities-for-mcp'),
            'description' => __('Abilities para obtener información general y estadísticas del sitio.', 'enable-abilities-for-mcp'),
        )
    );
}

/* ==========================================================================
 * CUSTOM ABILITIES REGISTRATION
 * ==========================================================================
 * Each ability checks ewpa_is_ability_enabled() before registering.
 * ========================================================================== */

/**
 * Registers all enabled custom abilities.
 */
function ewpa_register_custom_abilities(): void
{

    /* ======================================================================
     * SECTION A: READ ABILITIES
     * ====================================================================== */

    // ── A1: Obtener posts ────────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-posts')) {
        wp_register_ability(
            'ewpa/obtener-posts',
            array(
                'label'       => __('Obtener Posts', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene una lista de posts/entradas del blog con filtros opcionales por estado, categoría, cantidad y orden.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'numberposts' => array(
                            'type'        => 'integer',
                            'description' => 'Cantidad de posts a obtener (máx. 100)',
                            'default'     => 10,
                            'minimum'     => 1,
                            'maximum'     => 100,
                        ),
                        'post_status' => array(
                            'type'        => 'string',
                            'description' => 'Estado del post: publish, draft, pending, private, trash',
                            'enum'        => array('publish', 'draft', 'pending', 'private', 'trash', 'any'),
                            'default'     => 'publish',
                        ),
                        'category_name' => array(
                            'type'        => 'string',
                            'description' => 'Slug de la categoría para filtrar (opcional)',
                        ),
                        'tag' => array(
                            'type'        => 'string',
                            'description' => 'Slug de la etiqueta para filtrar (opcional)',
                        ),
                        'orderby' => array(
                            'type'        => 'string',
                            'description' => 'Ordenar por: date, title, modified, rand',
                            'enum'        => array('date', 'title', 'modified', 'rand'),
                            'default'     => 'date',
                        ),
                        'order' => array(
                            'type'        => 'string',
                            'description' => 'Dirección del orden: ASC o DESC',
                            'enum'        => array('ASC', 'DESC'),
                            'default'     => 'DESC',
                        ),
                        's' => array(
                            'type'        => 'string',
                            'description' => 'Término de búsqueda para filtrar posts (opcional)',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'ID'            => array('type' => 'integer'),
                            'post_title'    => array('type' => 'string'),
                            'post_status'   => array('type' => 'string'),
                            'post_date'     => array('type' => 'string'),
                            'post_excerpt'  => array('type' => 'string'),
                            'post_author'   => array('type' => 'string'),
                            'permalink'     => array('type' => 'string'),
                            'categories'    => array('type' => 'array', 'items' => array('type' => 'string')),
                            'tags'          => array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'execute_callback' => function ($input) {
                    $allowed_status  = array('publish', 'draft', 'pending', 'private', 'trash', 'any');
                    $allowed_orderby = array('date', 'title', 'modified', 'rand');
                    $allowed_order   = array('ASC', 'DESC');

                    $numberposts = min(100, max(1, absint($input['numberposts'] ?? 10)));
                    $post_status = in_array($input['post_status'] ?? 'publish', $allowed_status, true)
                        ? $input['post_status'] : 'publish';
                    $orderby = in_array($input['orderby'] ?? 'date', $allowed_orderby, true)
                        ? $input['orderby'] : 'date';
                    $order = in_array($input['order'] ?? 'DESC', $allowed_order, true)
                        ? $input['order'] : 'DESC';

                    $args = array(
                        'numberposts' => $numberposts,
                        'post_status' => $post_status,
                        'orderby'     => $orderby,
                        'order'       => $order,
                    );
                    if (! empty($input['category_name'])) {
                        $args['category_name'] = sanitize_text_field($input['category_name']);
                    }
                    if (! empty($input['tag'])) {
                        $args['tag'] = sanitize_text_field($input['tag']);
                    }
                    if (! empty($input['s'])) {
                        $args['s'] = sanitize_text_field($input['s']);
                    }

                    $posts  = get_posts($args);
                    $result = array();

                    foreach ($posts as $post) {
                        $cats = wp_get_post_categories($post->ID, array('fields' => 'names'));
                        $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
                        $result[] = array(
                            'ID'           => $post->ID,
                            'post_title'   => $post->post_title,
                            'post_status'  => $post->post_status,
                            'post_date'    => $post->post_date,
                            'post_excerpt' => $post->post_excerpt,
                            'post_author'  => get_the_author_meta('display_name', $post->post_author),
                            'permalink'    => get_permalink($post->ID),
                            'categories'   => $cats,
                            'tags'         => $tags,
                        );
                    }

                    return $result;
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A2: Obtener post individual ──────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-post')) {
        wp_register_ability(
            'ewpa/obtener-post',
            array(
                'label'       => __('Obtener Post Individual', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene todos los detalles de un post específico por su ID, incluyendo contenido completo, meta datos e imagen destacada.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('post_id'),
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID del post a obtener',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'ID'              => array('type' => 'integer'),
                        'post_title'      => array('type' => 'string'),
                        'post_content'    => array('type' => 'string'),
                        'post_excerpt'    => array('type' => 'string'),
                        'post_status'     => array('type' => 'string'),
                        'post_date'       => array('type' => 'string'),
                        'post_modified'   => array('type' => 'string'),
                        'post_author'     => array('type' => 'string'),
                        'permalink'       => array('type' => 'string'),
                        'featured_image'  => array('type' => 'string'),
                        'categories'      => array('type' => 'array', 'items' => array('type' => 'string')),
                        'tags'            => array('type' => 'array', 'items' => array('type' => 'string')),
                        'meta_title'      => array('type' => 'string'),
                        'meta_description' => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'execute_callback' => function ($input) {
                    $post_id = absint($input['post_id']);
                    $post = get_post($post_id);
                    if (! $post || 'post' !== $post->post_type) {
                        return new WP_Error('not_found', 'Post no encontrado.');
                    }

                    $thumbnail_url = get_the_post_thumbnail_url($post->ID, 'full');
                    $cats = wp_get_post_categories($post->ID, array('fields' => 'names'));
                    $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));

                    $meta_title = get_post_meta($post->ID, '_yoast_wpseo_title', true)
                        ?: get_post_meta($post->ID, 'rank_math_title', true)
                        ?: '';
                    $meta_desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true)
                        ?: get_post_meta($post->ID, 'rank_math_description', true)
                        ?: '';

                    return array(
                        'ID'               => $post->ID,
                        'post_title'       => $post->post_title,
                        'post_content'     => $post->post_content,
                        'post_excerpt'     => $post->post_excerpt,
                        'post_status'      => $post->post_status,
                        'post_date'        => $post->post_date,
                        'post_modified'    => $post->post_modified,
                        'post_author'      => get_the_author_meta('display_name', $post->post_author),
                        'permalink'        => get_permalink($post->ID),
                        'featured_image'   => $thumbnail_url ?: '',
                        'categories'       => $cats,
                        'tags'             => $tags,
                        'meta_title'       => $meta_title,
                        'meta_description' => $meta_desc,
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A3: Obtener categorías ───────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-categorias')) {
        wp_register_ability(
            'ewpa/obtener-categorias',
            array(
                'label'       => __('Obtener Categorías', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene todas las categorías del blog con su ID, nombre, slug, descripción y cantidad de posts asociados.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'hide_empty' => array(
                            'type'        => 'boolean',
                            'description' => 'Ocultar categorías sin posts (true/false)',
                            'default'     => false,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'term_id'     => array('type' => 'integer'),
                            'name'        => array('type' => 'string'),
                            'slug'        => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                            'count'       => array('type' => 'integer'),
                            'parent'      => array('type' => 'integer'),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'execute_callback' => function ($input) {
                    $categories = get_categories(array(
                        'hide_empty' => $input['hide_empty'] ?? false,
                    ));
                    $result = array();
                    foreach ($categories as $cat) {
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
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A4: Obtener etiquetas (tags) ─────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-tags')) {
        wp_register_ability(
            'ewpa/obtener-tags',
            array(
                'label'       => __('Obtener Etiquetas', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene todas las etiquetas (tags) del blog con su ID, nombre, slug y cantidad de posts asociados.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'hide_empty' => array(
                            'type'        => 'boolean',
                            'description' => 'Ocultar etiquetas sin posts (true/false)',
                            'default'     => false,
                        ),
                        'number' => array(
                            'type'        => 'integer',
                            'description' => 'Cantidad máxima de etiquetas a obtener',
                            'default'     => 100,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'term_id'     => array('type' => 'integer'),
                            'name'        => array('type' => 'string'),
                            'slug'        => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                            'count'       => array('type' => 'integer'),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'execute_callback' => function ($input) {
                    $tags = get_tags(array(
                        'hide_empty' => ! empty($input['hide_empty']),
                        'number'     => min(500, max(1, absint($input['number'] ?? 100))),
                    ));
                    $result = array();
                    foreach ($tags as $tag) {
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
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A5: Obtener páginas ──────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-paginas')) {
        wp_register_ability(
            'ewpa/obtener-paginas',
            array(
                'label'       => __('Obtener Páginas', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene las páginas del sitio WordPress con su título, estado, contenido y jerarquía (páginas padre/hijo).', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'numberposts' => array(
                            'type'        => 'integer',
                            'description' => 'Cantidad de páginas a obtener',
                            'default'     => 20,
                            'minimum'     => 1,
                            'maximum'     => 100,
                        ),
                        'post_status' => array(
                            'type'        => 'string',
                            'description' => 'Estado de la página: publish, draft, private',
                            'enum'        => array('publish', 'draft', 'private', 'any'),
                            'default'     => 'publish',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'ID'          => array('type' => 'integer'),
                            'post_title'  => array('type' => 'string'),
                            'post_status' => array('type' => 'string'),
                            'post_parent' => array('type' => 'integer'),
                            'menu_order'  => array('type' => 'integer'),
                            'permalink'   => array('type' => 'string'),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'execute_callback' => function ($input) {
                    $allowed_status = array('publish', 'draft', 'private', 'any');
                    $numberposts = min(100, max(1, absint($input['numberposts'] ?? 20)));
                    $post_status = in_array($input['post_status'] ?? 'publish', $allowed_status, true)
                        ? $input['post_status'] : 'publish';

                    $pages = get_posts(array(
                        'post_type'   => 'page',
                        'numberposts' => $numberposts,
                        'post_status' => $post_status,
                        'orderby'     => 'menu_order',
                        'order'       => 'ASC',
                    ));
                    $result = array();
                    foreach ($pages as $page) {
                        $result[] = array(
                            'ID'          => $page->ID,
                            'post_title'  => $page->post_title,
                            'post_status' => $page->post_status,
                            'post_parent' => $page->post_parent,
                            'menu_order'  => $page->menu_order,
                            'permalink'   => get_permalink($page->ID),
                        );
                    }
                    return $result;
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A6: Obtener comentarios ──────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-comentarios')) {
        wp_register_ability(
            'ewpa/obtener-comentarios',
            array(
                'label'       => __('Obtener Comentarios', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene los comentarios del blog con filtros opcionales por estado, post y cantidad. Útil para moderación y análisis.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'number' => array(
                            'type'        => 'integer',
                            'description' => 'Cantidad de comentarios a obtener',
                            'default'     => 20,
                            'minimum'     => 1,
                            'maximum'     => 100,
                        ),
                        'status' => array(
                            'type'        => 'string',
                            'description' => 'Estado del comentario: approve, hold, spam, trash, all',
                            'enum'        => array('approve', 'hold', 'spam', 'trash', 'all'),
                            'default'     => 'approve',
                        ),
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => 'Filtrar comentarios por ID de post (opcional, 0 = todos)',
                            'default'     => 0,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'comment_ID'      => array('type' => 'integer'),
                            'comment_author'  => array('type' => 'string'),
                            'comment_content' => array('type' => 'string'),
                            'comment_date'    => array('type' => 'string'),
                            'comment_post_ID' => array('type' => 'integer'),
                            'post_title'      => array('type' => 'string'),
                            'comment_approved' => array('type' => 'string'),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('moderate_comments');
                },
                'execute_callback' => function ($input) {
                    $allowed_status = array('approve', 'hold', 'spam', 'trash', 'all');
                    $number = min(100, max(1, absint($input['number'] ?? 20)));
                    $status = in_array($input['status'] ?? 'approve', $allowed_status, true)
                        ? $input['status'] : 'approve';

                    $args = array(
                        'number' => $number,
                        'status' => $status,
                    );
                    if (! empty($input['post_id'])) {
                        $args['post_id'] = absint($input['post_id']);
                    }
                    $comments = get_comments($args);
                    $result   = array();
                    foreach ($comments as $comment) {
                        $result[] = array(
                            'comment_ID'       => (int) $comment->comment_ID,
                            'comment_author'   => $comment->comment_author,
                            'comment_content'  => $comment->comment_content,
                            'comment_date'     => $comment->comment_date,
                            'comment_post_ID'  => (int) $comment->comment_post_ID,
                            'post_title'       => get_the_title($comment->comment_post_ID),
                            'comment_approved' => $comment->comment_approved,
                        );
                    }
                    return $result;
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A7: Obtener medios ───────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-medios')) {
        wp_register_ability(
            'ewpa/obtener-medios',
            array(
                'label'       => __('Obtener Medios', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene archivos de la biblioteca de medios (imágenes, videos, documentos) con filtros por tipo MIME y búsqueda.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'numberposts' => array(
                            'type'        => 'integer',
                            'description' => 'Cantidad de medios a obtener',
                            'default'     => 20,
                            'minimum'     => 1,
                            'maximum'     => 100,
                        ),
                        'post_mime_type' => array(
                            'type'        => 'string',
                            'description' => 'Tipo MIME para filtrar: image, video, audio, application (opcional)',
                            'enum'        => array('image', 'video', 'audio', 'application', ''),
                            'default'     => '',
                        ),
                        's' => array(
                            'type'        => 'string',
                            'description' => 'Término de búsqueda (opcional)',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'ID'        => array('type' => 'integer'),
                            'title'     => array('type' => 'string'),
                            'url'       => array('type' => 'string'),
                            'mime_type' => array('type' => 'string'),
                            'alt_text'  => array('type' => 'string'),
                            'date'      => array('type' => 'string'),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('upload_files');
                },
                'execute_callback' => function ($input) {
                    $numberposts = min(100, max(1, absint($input['numberposts'] ?? 20)));
                    $args = array(
                        'post_type'   => 'attachment',
                        'post_status' => 'inherit',
                        'numberposts' => $numberposts,
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    );
                    if (! empty($input['post_mime_type'])) {
                        $args['post_mime_type'] = sanitize_text_field($input['post_mime_type']);
                    }
                    if (! empty($input['s'])) {
                        $args['s'] = sanitize_text_field($input['s']);
                    }

                    $medios  = get_posts($args);
                    $result  = array();
                    foreach ($medios as $medio) {
                        $result[] = array(
                            'ID'        => $medio->ID,
                            'title'     => $medio->post_title,
                            'url'       => wp_get_attachment_url($medio->ID),
                            'mime_type' => $medio->post_mime_type,
                            'alt_text'  => get_post_meta($medio->ID, '_wp_attachment_image_alt', true) ?: '',
                            'date'      => $medio->post_date,
                        );
                    }
                    return $result;
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── A8: Obtener usuarios ─────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/obtener-usuarios')) {
        wp_register_ability(
            'ewpa/obtener-usuarios',
            array(
                'label'       => __('Obtener Usuarios', 'enable-abilities-for-mcp'),
                'description' => __('Obtiene la lista de usuarios del sitio con su ID, nombre, email y rol. Útil para asignar autores a posts.', 'enable-abilities-for-mcp'),
                'category'    => 'user-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'role' => array(
                            'type'        => 'string',
                            'description' => 'Filtrar por rol: administrator, editor, author, contributor, subscriber (opcional)',
                            'enum'        => array('administrator', 'editor', 'author', 'contributor', 'subscriber', ''),
                            'default'     => '',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'ID'           => array('type' => 'integer'),
                            'display_name' => array('type' => 'string'),
                            'user_email'   => array('type' => 'string'),
                            'roles'        => array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('list_users');
                },
                'execute_callback' => function ($input) {
                    $args = array();
                    if (! empty($input['role'])) {
                        $args['role'] = sanitize_text_field($input['role']);
                    }
                    $users  = get_users($args);
                    $result = array();
                    foreach ($users as $user) {
                        $result[] = array(
                            'ID'           => $user->ID,
                            'display_name' => $user->display_name,
                            'user_email'   => $user->user_email,
                            'roles'        => $user->roles,
                        );
                    }
                    return $result;
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    /* ======================================================================
     * SECTION B: WRITE ABILITIES
     * ====================================================================== */

    // ── B1: Crear post ───────────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/crear-post')) {
        wp_register_ability(
            'ewpa/crear-post',
            array(
                'label'       => __('Crear Post', 'enable-abilities-for-mcp'),
                'description' => __('Crea un nuevo post/entrada en el blog. Acepta título, contenido HTML, extracto, categorías, etiquetas, imagen destacada y estado. Por defecto crea como borrador.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('title', 'content'),
                    'properties' => array(
                        'title' => array(
                            'type'        => 'string',
                            'description' => 'Título del post (obligatorio)',
                        ),
                        'content' => array(
                            'type'        => 'string',
                            'description' => 'Contenido del post en HTML o bloques Gutenberg (obligatorio)',
                        ),
                        'excerpt' => array(
                            'type'        => 'string',
                            'description' => 'Extracto/resumen del post (opcional)',
                        ),
                        'status' => array(
                            'type'        => 'string',
                            'description' => 'Estado: draft, publish, pending, private, future',
                            'enum'        => array('draft', 'publish', 'pending', 'private', 'future'),
                            'default'     => 'draft',
                        ),
                        'categories' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'integer'),
                            'description' => 'Array de IDs de categorías a asignar (opcional)',
                        ),
                        'tags' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'string'),
                            'description' => 'Array de nombres de etiquetas a asignar (opcional)',
                        ),
                        'featured_image_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID de la imagen destacada (opcional)',
                        ),
                        'post_date' => array(
                            'type'        => 'string',
                            'description' => 'Fecha de publicación YYYY-MM-DD HH:MM:SS (opcional)',
                        ),
                        'author_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID del autor del post (opcional)',
                        ),
                        'slug' => array(
                            'type'        => 'string',
                            'description' => 'Slug/permalink personalizado (opcional)',
                        ),
                        'meta_title' => array(
                            'type'        => 'string',
                            'description' => 'Meta título SEO para Yoast/RankMath (opcional)',
                        ),
                        'meta_description' => array(
                            'type'        => 'string',
                            'description' => 'Meta descripción SEO para Yoast/RankMath (opcional)',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_id'   => array('type' => 'integer'),
                        'permalink' => array('type' => 'string'),
                        'status'    => array('type' => 'string'),
                        'message'   => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('publish_posts');
                },
                'execute_callback' => function ($input) {
                    $allowed_status = array('draft', 'publish', 'pending', 'private', 'future');
                    $status = in_array($input['status'] ?? 'draft', $allowed_status, true)
                        ? $input['status'] : 'draft';

                    $post_data = array(
                        'post_title'   => sanitize_text_field($input['title']),
                        'post_content' => wp_kses_post($input['content']),
                        'post_status'  => $status,
                        'post_type'    => 'post',
                    );

                    if (! empty($input['excerpt'])) {
                        $post_data['post_excerpt'] = sanitize_textarea_field($input['excerpt']);
                    }
                    if (! empty($input['categories'])) {
                        $post_data['post_category'] = array_map('absint', (array) $input['categories']);
                    }
                    if (! empty($input['post_date'])) {
                        $date = sanitize_text_field($input['post_date']);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $date)) {
                            $post_data['post_date'] = $date;
                        }
                    }
                    if (! empty($input['author_id'])) {
                        $author_id = absint($input['author_id']);
                        if (get_userdata($author_id)) {
                            $post_data['post_author'] = $author_id;
                        }
                    }
                    if (! empty($input['slug'])) {
                        $post_data['post_name'] = sanitize_title($input['slug']);
                    }

                    $post_id = wp_insert_post($post_data, true);

                    if (is_wp_error($post_id)) {
                        return $post_id;
                    }

                    if (! empty($input['tags'])) {
                        $tags = array_map('sanitize_text_field', (array) $input['tags']);
                        wp_set_post_tags($post_id, $tags);
                    }
                    if (! empty($input['featured_image_id'])) {
                        $img_id = absint($input['featured_image_id']);
                        if (wp_attachment_is_image($img_id)) {
                            set_post_thumbnail($post_id, $img_id);
                        }
                    }
                    if (! empty($input['meta_title'])) {
                        update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($input['meta_title']));
                        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($input['meta_title']));
                    }
                    if (! empty($input['meta_description'])) {
                        update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($input['meta_description']));
                        update_post_meta($post_id, 'rank_math_description', sanitize_text_field($input['meta_description']));
                    }

                    return array(
                        'post_id'   => $post_id,
                        'permalink' => get_permalink($post_id),
                        'status'    => $status,
                        'message'   => 'Post creado exitosamente.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── B2: Actualizar post ──────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/actualizar-post')) {
        wp_register_ability(
            'ewpa/actualizar-post',
            array(
                'label'       => __('Actualizar Post', 'enable-abilities-for-mcp'),
                'description' => __('Actualiza un post existente. Solo se modifican los campos enviados, los demás permanecen intactos.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('post_id'),
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID del post a actualizar (obligatorio)',
                        ),
                        'title' => array(
                            'type'        => 'string',
                            'description' => 'Nuevo título (opcional)',
                        ),
                        'content' => array(
                            'type'        => 'string',
                            'description' => 'Nuevo contenido en HTML (opcional)',
                        ),
                        'excerpt' => array(
                            'type'        => 'string',
                            'description' => 'Nuevo extracto (opcional)',
                        ),
                        'status' => array(
                            'type'        => 'string',
                            'description' => 'Nuevo estado: draft, publish, pending, private',
                            'enum'        => array('draft', 'publish', 'pending', 'private'),
                        ),
                        'categories' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'integer'),
                            'description' => 'Nuevos IDs de categorías (reemplaza las existentes)',
                        ),
                        'tags' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'string'),
                            'description' => 'Nuevas etiquetas (reemplaza las existentes)',
                        ),
                        'featured_image_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID de nueva imagen destacada (0 para remover)',
                        ),
                        'meta_title' => array(
                            'type'        => 'string',
                            'description' => 'Meta título SEO (opcional)',
                        ),
                        'meta_description' => array(
                            'type'        => 'string',
                            'description' => 'Meta descripción SEO (opcional)',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_id'   => array('type' => 'integer'),
                        'permalink' => array('type' => 'string'),
                        'message'   => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
                'execute_callback' => function ($input) {
                    $post_id = absint($input['post_id']);
                    $post = get_post($post_id);
                    if (! $post) {
                        return new WP_Error('not_found', 'Post no encontrado.');
                    }
                    if (! current_user_can('edit_post', $post_id)) {
                        return new WP_Error('forbidden', 'No tienes permiso para editar este post.');
                    }

                    $post_data = array('ID' => $post_id);

                    if (isset($input['title'])) {
                        $post_data['post_title'] = sanitize_text_field($input['title']);
                    }
                    if (isset($input['content'])) {
                        $post_data['post_content'] = wp_kses_post($input['content']);
                    }
                    if (isset($input['excerpt'])) {
                        $post_data['post_excerpt'] = sanitize_textarea_field($input['excerpt']);
                    }
                    if (isset($input['status'])) {
                        $allowed_status = array('draft', 'publish', 'pending', 'private');
                        if (in_array($input['status'], $allowed_status, true)) {
                            $post_data['post_status'] = $input['status'];
                        }
                    }
                    if (isset($input['categories'])) {
                        $post_data['post_category'] = array_map('absint', (array) $input['categories']);
                    }

                    $result = wp_update_post($post_data, true);
                    if (is_wp_error($result)) {
                        return $result;
                    }

                    if (isset($input['tags'])) {
                        $tags = array_map('sanitize_text_field', (array) $input['tags']);
                        wp_set_post_tags($post_id, $tags);
                    }
                    if (isset($input['featured_image_id'])) {
                        $img_id = absint($input['featured_image_id']);
                        if (0 === $img_id) {
                            delete_post_thumbnail($post_id);
                        } elseif (wp_attachment_is_image($img_id)) {
                            set_post_thumbnail($post_id, $img_id);
                        }
                    }
                    if (! empty($input['meta_title'])) {
                        update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($input['meta_title']));
                        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($input['meta_title']));
                    }
                    if (! empty($input['meta_description'])) {
                        update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($input['meta_description']));
                        update_post_meta($post_id, 'rank_math_description', sanitize_text_field($input['meta_description']));
                    }

                    return array(
                        'post_id'   => $post_id,
                        'permalink' => get_permalink($post_id),
                        'message'   => 'Post actualizado exitosamente.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── B3: Eliminar post ────────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/eliminar-post')) {
        wp_register_ability(
            'ewpa/eliminar-post',
            array(
                'label'       => __('Eliminar Post', 'enable-abilities-for-mcp'),
                'description' => __('Envía un post a la papelera o lo elimina permanentemente. Por defecto envía a la papelera.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('post_id'),
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID del post a eliminar (obligatorio)',
                        ),
                        'force_delete' => array(
                            'type'        => 'boolean',
                            'description' => 'true = eliminar permanentemente, false = papelera',
                            'default'     => false,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_id' => array('type' => 'integer'),
                        'deleted' => array('type' => 'boolean'),
                        'message' => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('delete_posts');
                },
                'execute_callback' => function ($input) {
                    $post_id = absint($input['post_id']);
                    $post = get_post($post_id);
                    if (! $post) {
                        return new WP_Error('not_found', 'Post no encontrado.');
                    }
                    if (! current_user_can('delete_post', $post_id)) {
                        return new WP_Error('forbidden', 'No tienes permiso para eliminar este post.');
                    }

                    $force  = ! empty($input['force_delete']);
                    $result = wp_delete_post($post_id, $force);

                    if (! $result) {
                        return new WP_Error('delete_failed', 'No se pudo eliminar el post.');
                    }

                    $action = $force ? 'eliminado permanentemente' : 'enviado a la papelera';
                    return array(
                        'post_id' => $post_id,
                        'deleted' => true,
                        'message' => "Post {$action} exitosamente.",
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── B4: Crear categoría ──────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/crear-categoria')) {
        wp_register_ability(
            'ewpa/crear-categoria',
            array(
                'label'       => __('Crear Categoría', 'enable-abilities-for-mcp'),
                'description' => __('Crea una nueva categoría en el blog con nombre, slug, descripción y categoría padre (opcional).', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('name'),
                    'properties' => array(
                        'name' => array(
                            'type'        => 'string',
                            'description' => 'Nombre de la categoría (obligatorio)',
                        ),
                        'slug' => array(
                            'type'        => 'string',
                            'description' => 'Slug de la categoría (opcional)',
                        ),
                        'description' => array(
                            'type'        => 'string',
                            'description' => 'Descripción de la categoría (opcional)',
                        ),
                        'parent' => array(
                            'type'        => 'integer',
                            'description' => 'ID de la categoría padre (0 = sin padre)',
                            'default'     => 0,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'term_id' => array('type' => 'integer'),
                        'name'    => array('type' => 'string'),
                        'slug'    => array('type' => 'string'),
                        'message' => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('manage_categories');
                },
                'execute_callback' => function ($input) {
                    $args = array();
                    if (! empty($input['slug'])) {
                        $args['slug'] = sanitize_title($input['slug']);
                    }
                    if (! empty($input['description'])) {
                        $args['description'] = sanitize_textarea_field($input['description']);
                    }
                    if (isset($input['parent'])) {
                        $args['parent'] = absint($input['parent']);
                    }

                    $result = wp_insert_term(
                        sanitize_text_field($input['name']),
                        'category',
                        $args
                    );

                    if (is_wp_error($result)) {
                        return $result;
                    }

                    $term = get_term($result['term_id'], 'category');
                    return array(
                        'term_id' => $result['term_id'],
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                        'message' => 'Categoría creada exitosamente.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── B5: Crear etiqueta ───────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/crear-tag')) {
        wp_register_ability(
            'ewpa/crear-tag',
            array(
                'label'       => __('Crear Etiqueta', 'enable-abilities-for-mcp'),
                'description' => __('Crea una nueva etiqueta (tag) en el blog con nombre, slug y descripción.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('name'),
                    'properties' => array(
                        'name' => array(
                            'type'        => 'string',
                            'description' => 'Nombre de la etiqueta (obligatorio)',
                        ),
                        'slug' => array(
                            'type'        => 'string',
                            'description' => 'Slug de la etiqueta (opcional)',
                        ),
                        'description' => array(
                            'type'        => 'string',
                            'description' => 'Descripción de la etiqueta (opcional)',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'term_id' => array('type' => 'integer'),
                        'name'    => array('type' => 'string'),
                        'slug'    => array('type' => 'string'),
                        'message' => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('manage_categories');
                },
                'execute_callback' => function ($input) {
                    $args = array();
                    if (! empty($input['slug'])) {
                        $args['slug'] = sanitize_title($input['slug']);
                    }
                    if (! empty($input['description'])) {
                        $args['description'] = sanitize_textarea_field($input['description']);
                    }

                    $result = wp_insert_term(
                        sanitize_text_field($input['name']),
                        'post_tag',
                        $args
                    );

                    if (is_wp_error($result)) {
                        return $result;
                    }

                    $term = get_term($result['term_id'], 'post_tag');
                    return array(
                        'term_id' => $result['term_id'],
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                        'message' => 'Etiqueta creada exitosamente.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── B6: Crear página ─────────────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/crear-pagina')) {
        wp_register_ability(
            'ewpa/crear-pagina',
            array(
                'label'       => __('Crear Página', 'enable-abilities-for-mcp'),
                'description' => __('Crea una nueva página en WordPress con título, contenido, estado y página padre (para jerarquía).', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('title', 'content'),
                    'properties' => array(
                        'title' => array(
                            'type'        => 'string',
                            'description' => 'Título de la página (obligatorio)',
                        ),
                        'content' => array(
                            'type'        => 'string',
                            'description' => 'Contenido de la página en HTML (obligatorio)',
                        ),
                        'status' => array(
                            'type'        => 'string',
                            'description' => 'Estado: draft, publish, pending, private',
                            'enum'        => array('draft', 'publish', 'pending', 'private'),
                            'default'     => 'draft',
                        ),
                        'parent_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID de la página padre (0 = sin padre)',
                            'default'     => 0,
                        ),
                        'menu_order' => array(
                            'type'        => 'integer',
                            'description' => 'Orden en el menú',
                            'default'     => 0,
                        ),
                        'template' => array(
                            'type'        => 'string',
                            'description' => 'Template/plantilla de página a usar (opcional)',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'page_id'   => array('type' => 'integer'),
                        'permalink' => array('type' => 'string'),
                        'status'    => array('type' => 'string'),
                        'message'   => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('publish_pages');
                },
                'execute_callback' => function ($input) {
                    $allowed_status = array('draft', 'publish', 'pending', 'private');
                    $status = in_array($input['status'] ?? 'draft', $allowed_status, true)
                        ? $input['status'] : 'draft';

                    $post_data = array(
                        'post_title'   => sanitize_text_field($input['title']),
                        'post_content' => wp_kses_post($input['content']),
                        'post_status'  => $status,
                        'post_type'    => 'page',
                        'post_parent'  => absint($input['parent_id'] ?? 0),
                        'menu_order'   => absint($input['menu_order'] ?? 0),
                    );

                    $page_id = wp_insert_post($post_data, true);

                    if (is_wp_error($page_id)) {
                        return $page_id;
                    }

                    if (! empty($input['template'])) {
                        update_post_meta($page_id, '_wp_page_template', sanitize_file_name($input['template']));
                    }

                    return array(
                        'page_id'   => $page_id,
                        'permalink' => get_permalink($page_id),
                        'status'    => $status,
                        'message'   => 'Página creada exitosamente.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── B7: Moderar comentario ───────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/moderar-comentario')) {
        wp_register_ability(
            'ewpa/moderar-comentario',
            array(
                'label'       => __('Moderar Comentario', 'enable-abilities-for-mcp'),
                'description' => __('Cambia el estado de un comentario: aprobar, poner en espera, marcar como spam o enviar a la papelera.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('comment_id', 'action'),
                    'properties' => array(
                        'comment_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID del comentario a moderar (obligatorio)',
                        ),
                        'action' => array(
                            'type'        => 'string',
                            'description' => 'Acción: approve, hold, spam, trash',
                            'enum'        => array('approve', 'hold', 'spam', 'trash'),
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'comment_id' => array('type' => 'integer'),
                        'new_status' => array('type' => 'string'),
                        'message'    => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('moderate_comments');
                },
                'execute_callback' => function ($input) {
                    $comment_id = absint($input['comment_id']);
                    $comment = get_comment($comment_id);
                    if (! $comment) {
                        return new WP_Error('not_found', 'Comentario no encontrado.');
                    }

                    $status_map = array(
                        'approve' => '1',
                        'hold'    => '0',
                        'spam'    => 'spam',
                        'trash'   => 'trash',
                    );

                    if (! isset($status_map[$input['action']])) {
                        return new WP_Error('invalid_action', 'Acción no válida. Usa: approve, hold, spam o trash.');
                    }

                    $new_status = $status_map[$input['action']];
                    $result = wp_set_comment_status($comment_id, $new_status);

                    if (! $result) {
                        return new WP_Error('update_failed', 'No se pudo moderar el comentario.');
                    }

                    $action_labels = array(
                        'approve' => 'aprobado',
                        'hold'    => 'puesto en espera',
                        'spam'    => 'marcado como spam',
                        'trash'   => 'enviado a la papelera',
                    );

                    return array(
                        'comment_id' => $comment_id,
                        'new_status' => $input['action'],
                        'message'    => 'Comentario ' . ($action_labels[$input['action']] ?? 'moderado') . ' exitosamente.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    /* ======================================================================
     * SECTION C: UTILITY ABILITIES
     * ====================================================================== */

    // ── C1: Buscar y reemplazar ──────────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/buscar-reemplazar')) {
        wp_register_ability(
            'ewpa/buscar-reemplazar',
            array(
                'label'       => __('Buscar y Reemplazar', 'enable-abilities-for-mcp'),
                'description' => __('Busca un texto en el contenido de un post específico y lo reemplaza por otro. Útil para correcciones y actualizaciones.', 'enable-abilities-for-mcp'),
                'category'    => 'content-management',
                'input_schema' => array(
                    'type'       => 'object',
                    'required'   => array('post_id', 'search', 'replace'),
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => 'ID del post donde buscar y reemplazar',
                        ),
                        'search' => array(
                            'type'        => 'string',
                            'description' => 'Texto a buscar',
                        ),
                        'replace' => array(
                            'type'        => 'string',
                            'description' => 'Texto de reemplazo',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_id'       => array('type' => 'integer'),
                        'replacements'  => array('type' => 'integer'),
                        'message'       => array('type' => 'string'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
                'execute_callback' => function ($input) {
                    $post_id = absint($input['post_id']);
                    $post = get_post($post_id);
                    if (! $post) {
                        return new WP_Error('not_found', 'Post no encontrado.');
                    }
                    if (! current_user_can('edit_post', $post_id)) {
                        return new WP_Error('forbidden', 'No tienes permiso para editar este post.');
                    }

                    $search  = sanitize_text_field($input['search']);
                    $replace = wp_kses_post($input['replace']);

                    if (empty($search)) {
                        return new WP_Error('invalid_input', 'El texto de búsqueda no puede estar vacío.');
                    }

                    $count       = 0;
                    $new_content = str_replace(
                        $search,
                        $replace,
                        $post->post_content,
                        $count
                    );

                    if ($count > 0) {
                        wp_update_post(array(
                            'ID'           => $post_id,
                            'post_content' => $new_content,
                        ));
                    }

                    return array(
                        'post_id'      => $post_id,
                        'replacements' => $count,
                        'message'      => $count > 0
                            ? "{$count} reemplazo(s) realizados exitosamente."
                            : 'No se encontraron coincidencias.',
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }

    // ── C2: Estadísticas del sitio ───────────────────────────────────────
    if (ewpa_is_ability_enabled('ewpa/estadisticas-sitio')) {
        wp_register_ability(
            'ewpa/estadisticas-sitio',
            array(
                'label'       => __('Estadísticas del Sitio', 'enable-abilities-for-mcp'),
                'description' => __('Devuelve un resumen con el total de posts, páginas, categorías, etiquetas, comentarios y usuarios del sitio.', 'enable-abilities-for-mcp'),
                'category'    => 'site-information',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'posts_published'  => array('type' => 'integer'),
                        'posts_draft'      => array('type' => 'integer'),
                        'posts_pending'    => array('type' => 'integer'),
                        'pages_published'  => array('type' => 'integer'),
                        'categories_total' => array('type' => 'integer'),
                        'tags_total'       => array('type' => 'integer'),
                        'comments_approved' => array('type' => 'integer'),
                        'comments_pending' => array('type' => 'integer'),
                        'comments_spam'    => array('type' => 'integer'),
                        'users_total'      => array('type' => 'integer'),
                        'media_total'      => array('type' => 'integer'),
                    ),
                ),
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'execute_callback' => function ($input) {
                    $post_counts    = wp_count_posts('post');
                    $page_counts    = wp_count_posts('page');
                    $comment_counts = wp_count_comments();
                    $media_counts   = wp_count_posts('attachment');

                    return array(
                        'posts_published'   => (int) $post_counts->publish,
                        'posts_draft'       => (int) $post_counts->draft,
                        'posts_pending'     => (int) $post_counts->pending,
                        'pages_published'   => (int) $page_counts->publish,
                        'categories_total'  => (int) wp_count_terms('category'),
                        'tags_total'        => (int) wp_count_terms('post_tag'),
                        'comments_approved' => (int) $comment_counts->approved,
                        'comments_pending'  => (int) $comment_counts->moderated,
                        'comments_spam'     => (int) $comment_counts->spam,
                        'users_total'       => (int) count_users()['total_users'],
                        'media_total'       => (int) $media_counts->inherit,
                    );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                ),
            )
        );
    }
}
