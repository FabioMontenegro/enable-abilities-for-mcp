<?php
/**
 * Plugin Name:       Enable Abilities for MCP
 * Description:       Manage which WordPress Abilities are exposed to MCP servers. Enable or disable each ability individually from the dashboard.
 * Version:           1.7.0
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

define( 'EWPA_VERSION', '1.7.0' );
define( 'EWPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EWPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EWPA_OPTION_KEY', 'ewpa_enabled_abilities' );
define( 'EWPA_API_KEY_OPTION', 'ewpa_api_key' );

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
		'core'    => array(
			'section_label' => __( 'Core de WordPress', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Abilities nativas del core de WordPress. Se exponen al MCP con el flag público.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-wordpress',
			'abilities'     => array(
				'core/get-site-info'        => array(
					'label' => __( 'Información del Sitio', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Datos generales del sitio: nombre, URL, descripción, idioma, zona horaria, versión de WP.', 'enable-abilities-for-mcp' ),
				),
				'core/get-user-info'        => array(
					'label' => __( 'Información del Usuario', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Datos del usuario actual: nombre, email, rol, avatar.', 'enable-abilities-for-mcp' ),
				),
				'core/get-environment-info' => array(
					'label' => __( 'Información del Entorno', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Detalles técnicos: versión de PHP, servidor de BD, tipo de entorno.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'read'    => array(
			'section_label' => __( 'Lectura (Solo Consulta)', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Solo consultan datos, no modifican nada. Las más seguras para exponer vía MCP.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-visibility',
			'abilities'     => array(
				'ewpa/obtener-posts'       => array(
					'label' => __( 'Obtener Posts', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista posts con filtros por estado, categoría, cantidad y orden.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-post'        => array(
					'label' => __( 'Obtener Post Individual', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Detalle completo de un post por ID, incluyendo contenido, meta datos e imagen destacada.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-categorias'  => array(
					'label' => __( 'Obtener Categorías', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista todas las categorías con ID, nombre, slug y cantidad de posts.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-tags'        => array(
					'label' => __( 'Obtener Etiquetas', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista todas las etiquetas (tags) con ID, nombre, slug y cantidad de posts.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-paginas'     => array(
					'label' => __( 'Obtener Páginas', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista páginas del sitio con título, estado y jerarquía.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-comentarios' => array(
					'label' => __( 'Obtener Comentarios', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista comentarios con filtros por estado, post y cantidad.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-medios'      => array(
					'label' => __( 'Obtener Medios', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista archivos de la biblioteca de medios con filtros por tipo.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/obtener-usuarios'    => array(
					'label' => __( 'Obtener Usuarios', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Lista usuarios del sitio con ID, nombre, email y rol.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'write'   => array(
			'section_label' => __( 'Escritura (Crear y Modificar)', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Crean o modifican contenido. Requieren permisos apropiados del usuario MCP.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-edit',
			'section_badge' => 'warning',
			'abilities'     => array(
				'ewpa/crear-post'           => array(
					'label' => __( 'Crear Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Crea un nuevo post con título, contenido, categorías, etiquetas, imagen destacada y más.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/actualizar-post'      => array(
					'label' => __( 'Actualizar Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Modifica un post existente. Solo actualiza los campos enviados.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/eliminar-post'        => array(
					'label' => __( 'Eliminar Post', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Envía un post a la papelera o lo elimina permanentemente.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/crear-categoria'      => array(
					'label' => __( 'Crear Categoría', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Crea una nueva categoría con nombre, slug, descripción y padre.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/crear-tag'            => array(
					'label' => __( 'Crear Etiqueta', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Crea una nueva etiqueta (tag) con nombre, slug y descripción.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/crear-pagina'         => array(
					'label' => __( 'Crear Página', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Crea una nueva página con título, contenido, estado y página padre.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/moderar-comentario'   => array(
					'label' => __( 'Moderar Comentario', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Cambia el estado de un comentario: aprobar, espera, spam o papelera.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/responder-comentario' => array(
					'label' => __( 'Responder Comentario', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Responde a un comentario existente como el usuario autenticado.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/subir-imagen'         => array(
					'label' => __( 'Subir Imagen desde URL', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Descarga una imagen desde una URL externa y la registra en la biblioteca de medios. Retorna el ID del attachment.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'seo'     => array(
			'section_label' => __( 'SEO — Rank Math', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Consulta y actualiza la metadata SEO de Rank Math en posts y páginas.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-search',
			'abilities'     => array(
				'ewpa/obtener-rankmath'    => array(
					'label' => __( 'Obtener Metadata Rank Math', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Obtiene la metadata SEO de Rank Math de un post o página: título, descripción, keywords, robots, Open Graph y más.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/actualizar-rankmath' => array(
					'label' => __( 'Actualizar SEO / Focus Keyword de Rank Math', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Actualiza la palabra clave objetivo (focus keyword), título SEO, descripción, URL canónica, robots y Open Graph de Rank Math.', 'enable-abilities-for-mcp' ),
				),
			),
		),
		'utility' => array(
			'section_label' => __( 'Utilidad', 'enable-abilities-for-mcp' ),
			'section_desc'  => __( 'Herramientas auxiliares que complementan el flujo de trabajo.', 'enable-abilities-for-mcp' ),
			'section_icon'  => 'dashicons-admin-tools',
			'abilities'     => array(
				'ewpa/buscar-reemplazar'  => array(
					'label' => __( 'Buscar y Reemplazar', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Busca un texto en el contenido de un post y lo reemplaza por otro.', 'enable-abilities-for-mcp' ),
				),
				'ewpa/estadisticas-sitio' => array(
					'label' => __( 'Estadísticas del Sitio', 'enable-abilities-for-mcp' ),
					'desc'  => __( 'Resumen del sitio: total de posts, páginas, categorías, tags, comentarios y usuarios.', 'enable-abilities-for-mcp' ),
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
