<?php
/**
 * Tests for the CPT post type guard and the ewpa/update-cpt-item write path.
 *
 * Covers which post types the CPT abilities may address (the non-public
 * allowlist and its filter) and the parent/order fields of update-cpt-item.
 *
 * Run with: php tests/cpt-abilities-test.php
 *
 * @package EnableAbilitiesForMCP
 */

define( 'ABSPATH', __DIR__ . '/' );

/*
 * ==========================================================================
 * WORDPRESS FUNCTION DOUBLES
 * ==========================================================================
 */

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function add_action( ...$args ) {}

function add_filter( ...$args ) {}

function do_action( $hook, ...$args ) {}

/**
 * Runs the callbacks registered by the tests, mirroring the WordPress filter API.
 *
 * @param string $hook  Filter name.
 * @param mixed  $value Value to filter.
 * @return mixed
 */
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ewpa_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

function sanitize_title( $value ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '-', (string) $value ) );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function wp_slash( $value ) {
	return is_string( $value ) ? addslashes( $value ) : $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function post_type_exists( $post_type ) {
	return isset( $GLOBALS['ewpa_post_types'][ $post_type ] );
}

function get_post_type_object( $post_type ) {
	return $GLOBALS['ewpa_post_types'][ $post_type ] ?? null;
}

function get_post_types( ...$args ) {
	return array_keys( $GLOBALS['ewpa_post_types'] );
}

function get_object_taxonomies( $post_type ) {
	return array();
}

function get_post( $post_id ) {
	return $GLOBALS['ewpa_posts'][ absint( $post_id ) ] ?? null;
}

function get_permalink( $post_id ) {
	return 'https://example.test/?p=' . absint( $post_id );
}

function get_edit_post_link( $post_id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . absint( $post_id ) . '&action=edit';
}

function wp_update_post( $data, $wp_error = false ) {
	$post_id = absint( $data['ID'] );
	$post    = $GLOBALS['ewpa_posts'][ $post_id ] ?? null;
	if ( ! $post ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
	}
	foreach ( $data as $field => $value ) {
		if ( 'ID' === $field ) {
			continue;
		}
		$post->{$field} = $value;
	}
	return $post_id;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['ewpa_meta'][ absint( $post_id ) ][ $key ] = $value;
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return $GLOBALS['ewpa_meta'][ absint( $post_id ) ] ?? array();
	}
	return $GLOBALS['ewpa_meta'][ absint( $post_id ) ][ $key ] ?? '';
}

function set_post_thumbnail( $post_id, $thumbnail_id ) {
	return true;
}

function delete_post_thumbnail( $post_id ) {
	return true;
}

function wp_set_object_terms( ...$args ) {
	return array();
}

function taxonomy_exists( $taxonomy ) {
	return false;
}

function current_user_can( $capability, ...$args ) {
	return in_array( $capability, $GLOBALS['ewpa_caps'], true );
}

function ewpa_is_ability_enabled( $key ) {
	return true;
}

function ewpa_run_migrations() {}

function current_theme_supports( $feature, ...$args ) {
	return false;
}

function wp_is_block_theme() {
	return false;
}

// Tutor LMS presence decides the default allowlist, and a function cannot be
// undefined once declared, so the "no Tutor" case runs in its own process.
$ewpa_tutor_active = ! in_array( '--no-tutor', $argv, true );

if ( $ewpa_tutor_active ) {
	/**
	 * Minimal Tutor LMS marker; the abilities only call it inside their callbacks.
	 *
	 * @return object
	 */
	function tutor_utils() {
		return new stdClass();
	}
}

/**
 * Captures ability definitions instead of registering them with WordPress.
 *
 * @param string $name Ability name.
 * @param array  $args Ability definition.
 */
function ewpa_register_ability_with_log( $name, $args ) {
	$GLOBALS['ewpa_registered'][ $name ] = $args;
}

require dirname( __DIR__ ) . '/includes/abilities.php';

/*
 * ==========================================================================
 * TEST HARNESS
 * ==========================================================================
 */

$ewpa_checks   = 0;
$ewpa_failures = 0;

/**
 * Records one assertion.
 *
 * @param string $label     What is being checked.
 * @param bool   $condition Result of the check.
 */
function ewpa_check( string $label, bool $condition ): void {
	global $ewpa_failures, $ewpa_checks;
	++$ewpa_checks;
	if ( $condition ) {
		echo 'PASS ' . $label . PHP_EOL;
	} else {
		++$ewpa_failures;
		echo 'FAIL ' . $label . PHP_EOL;
	}
}

/**
 * Builds a post type object double.
 *
 * @param string $name         Post type slug.
 * @param bool   $public       Whether the type is public.
 * @param bool   $show_in_rest Whether the type is exposed in REST.
 * @return object
 */
function ewpa_fake_post_type( string $name, bool $public, bool $show_in_rest ) {
	return (object) array(
		'name'         => $name,
		'public'       => $public,
		'show_in_rest' => $show_in_rest,
		'cap'          => (object) array(
			'edit_posts'   => 'edit_posts',
			'create_posts' => 'edit_posts',
			'delete_posts' => 'delete_posts',
		),
		'labels'       => (object) array( 'singular_name' => ucfirst( $name ) ),
	);
}

/**
 * Resets every double to a known state.
 *
 * `topics` models Tutor LMS: non-public and not exposed in REST.
 */
function ewpa_reset(): void {
	$GLOBALS['ewpa_post_types'] = array(
		'courses' => ewpa_fake_post_type( 'courses', true, true ),
		'topics'  => ewpa_fake_post_type( 'topics', false, false ),
		'acf_hidden' => ewpa_fake_post_type( 'acf_hidden', false, false ),
		'product' => ewpa_fake_post_type( 'product', false, true ),
		'post'    => ewpa_fake_post_type( 'post', true, true ),
	);
	$GLOBALS['ewpa_posts']   = array(
		10 => (object) array(
			'ID'          => 10,
			'post_title'  => 'Module 1',
			'post_type'   => 'topics',
			'post_parent' => 0,
			'menu_order'  => 0,
			'post_status' => 'publish',
		),
		20 => (object) array(
			'ID'          => 20,
			'post_title'  => 'Course A',
			'post_type'   => 'courses',
			'post_parent' => 0,
			'menu_order'  => 0,
			'post_status' => 'publish',
		),
	);
	$GLOBALS['ewpa_meta']    = array();
	$GLOBALS['ewpa_filters'] = array();
	$GLOBALS['ewpa_caps']    = array( 'read', 'edit_posts', 'edit_post', 'delete_posts' );
	$GLOBALS['ewpa_tutor']   = true;
}

/**
 * Returns the execute callback of a registered ability.
 *
 * @param string $ability Ability name.
 * @return callable
 */
function ewpa_callback( string $ability ): callable {
	return $GLOBALS['ewpa_registered'][ $ability ]['execute_callback'];
}

if ( ! $ewpa_tutor_active ) {
	// Sub-process: without Tutor LMS the default list is empty, because `topics`
	// is a slug any plugin could use.
	ewpa_reset();
	$result = ewpa_validate_cpt( 'topics' );
	ewpa_check(
		'does_not_allow_topics_without_tutor',
		is_wp_error( $result ) && 'private_post_type' === $result->get_error_code()
	);
	exit( $ewpa_failures > 0 ? 1 : 0 );
}

/*
 * ==========================================================================
 * PART A — WHICH POST TYPES ARE ADDRESSABLE
 * ==========================================================================
 */

ewpa_reset();
ewpa_register_custom_abilities();

$result = ewpa_validate_cpt( 'courses' );
ewpa_check( 'accepts_public_post_type', ! is_wp_error( $result ) && 'courses' === $result->name );

$result = ewpa_validate_cpt( 'product' );
ewpa_check( 'accepts_rest_exposed_post_type', ! is_wp_error( $result ) );

$result = ewpa_validate_cpt( 'topics' );
ewpa_check(
	'accepts_allowlisted_private_post_type',
	! is_wp_error( $result ) && 'topics' === $result->name
);

$result = ewpa_validate_cpt( 'acf_hidden' );
ewpa_check(
	'rejects_private_post_type_outside_the_allowlist',
	is_wp_error( $result ) && 'private_post_type' === $result->get_error_code()
);

$result = ewpa_validate_cpt( 'post' );
ewpa_check(
	'rejects_builtin_post_type',
	is_wp_error( $result ) && 'builtin_type' === $result->get_error_code()
);

$result = ewpa_validate_cpt( 'missing_type' );
ewpa_check(
	'rejects_unknown_post_type',
	is_wp_error( $result ) && 'invalid_post_type' === $result->get_error_code()
);

// The filter opens one more type without touching the plugin.
$GLOBALS['ewpa_filters']['ewpa_manageable_private_post_types'][] = function ( $types ) {
	$types[] = 'acf_hidden';
	return $types;
};
$result = ewpa_validate_cpt( 'acf_hidden' );
ewpa_check( 'filter_can_allow_another_private_post_type', ! is_wp_error( $result ) );

// Built-ins are rejected before the allowlist is read, so the filter cannot reach them.
$GLOBALS['ewpa_filters']['ewpa_manageable_private_post_types'][] = function ( $types ) {
	$types[] = 'post';
	return $types;
};
$result = ewpa_validate_cpt( 'post' );
ewpa_check(
	'filter_cannot_allow_builtin_post_type',
	is_wp_error( $result ) && 'builtin_type' === $result->get_error_code()
);

// A filter returning a non-array leaves the guard in place.
ewpa_reset();
$GLOBALS['ewpa_filters']['ewpa_manageable_private_post_types'][] = function () {
	return 'topics';
};
$result = ewpa_validate_cpt( 'topics' );
ewpa_check(
	'ignores_non_array_filter_value',
	is_wp_error( $result ) && 'private_post_type' === $result->get_error_code()
);


/*
 * ==========================================================================
 * CAPABILITY CHECKS SURVIVE THE ALLOWLIST
 * ==========================================================================
 */

ewpa_reset();
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 10,
		'title'   => 'Renamed',
	)
);
ewpa_check(
	'update_rejects_user_without_edit_posts',
	is_wp_error( $result ) && 'forbidden' === $result->get_error_code()
);

ewpa_reset();
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'topics',
		'title'     => 'Module 2',
	)
);
ewpa_check(
	'create_rejects_user_without_create_posts',
	is_wp_error( $result ) && 'forbidden' === $result->get_error_code()
);

/*
 * ==========================================================================
 * PART B — post_parent AND menu_order ON UPDATE
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'     => 10,
		'post_parent' => 20,
		'menu_order'  => 3,
	)
);
ewpa_check( 'update_reparents_topic', ! is_wp_error( $result ) && 20 === $GLOBALS['ewpa_posts'][10]->post_parent );
ewpa_check( 'update_sets_menu_order', 3 === $GLOBALS['ewpa_posts'][10]->menu_order );

ewpa_reset();
$GLOBALS['ewpa_posts'][10]->post_parent = 20;
$result                                 = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'     => 10,
		'post_parent' => 0,
	)
);
ewpa_check( 'update_detaches_with_zero_parent', ! is_wp_error( $result ) && 0 === $GLOBALS['ewpa_posts'][10]->post_parent );

ewpa_reset();
$GLOBALS['ewpa_posts'][10]->post_parent = 20;
$result                                 = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 10,
		'title'   => 'Renamed',
	)
);
ewpa_check(
	'update_keeps_parent_when_not_supplied',
	! is_wp_error( $result ) && 20 === $GLOBALS['ewpa_posts'][10]->post_parent
);

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'     => 10,
		'post_parent' => 10,
	)
);
ewpa_check(
	'update_rejects_self_parent',
	is_wp_error( $result ) && 'invalid_parent' === $result->get_error_code()
);
ewpa_check( 'update_leaves_parent_untouched_on_self_parent', 0 === $GLOBALS['ewpa_posts'][10]->post_parent );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'     => 10,
		'post_parent' => 999,
	)
);
ewpa_check(
	'update_rejects_missing_parent',
	is_wp_error( $result ) && 'invalid_parent' === $result->get_error_code()
);

$schema = $GLOBALS['ewpa_registered']['ewpa/update-cpt-item']['input_schema']['properties'];
ewpa_check( 'update_schema_exposes_post_parent', isset( $schema['post_parent'] ) );
ewpa_check( 'update_schema_exposes_menu_order', isset( $schema['menu_order'] ) );

// The "no Tutor LMS" case needs a process where tutor_utils() was never declared.
$ewpa_sub_output = array();
$ewpa_sub_status = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --no-tutor 2>&1', $ewpa_sub_output, $ewpa_sub_status );
echo implode( PHP_EOL, $ewpa_sub_output ) . PHP_EOL;
++$ewpa_checks;
if ( 0 !== $ewpa_sub_status ) {
	++$ewpa_failures;
}

echo PHP_EOL;
if ( $ewpa_failures > 0 ) {
	echo $ewpa_failures . ' check(s) FAILED out of ' . $ewpa_checks . '.' . PHP_EOL;
	exit( 1 );
}
echo $ewpa_checks . ' check(s) passed.' . PHP_EOL;
