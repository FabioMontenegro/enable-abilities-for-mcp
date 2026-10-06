<?php
/**
 * Regression tests for object-level authorization in the write abilities.
 *
 * Covers duplicate-post, update-post, create-post, create-cpt-item,
 * update-cpt-item and tec-update-event: a capability held globally must not
 * grant access to a specific object, and publishing needs the publish
 * capability of the post type. It also covers the data-exposure readers
 * (listings, Tutor student records, single-object readers, SEO readers,
 * upload-image, accessibility snapshot). The translation ability is covered in
 * tests/multilanguage-backend-test.php.
 *
 * Run with: php tests/authz-test.php
 *
 * @package EnableAbilitiesForMCP
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EWPA_TESTING', true );

/*
 * ==========================================================================
 * WORDPRESS FUNCTION DOUBLES
 * ==========================================================================
 */

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '', $data = null ) {
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

class Tribe__Events__Main {
	const POSTTYPE = 'tribe_events';
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

function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_title( $value ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '-', (string) $value ) );
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function wp_slash( $value ) {
	return $value;
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

function get_object_taxonomies( $post_type, $output = 'names' ) {
	$out = array();
	foreach ( $GLOBALS['ewpa_taxonomies'] ?? array() as $slug => $taxonomy ) {
		if ( in_array( $post_type, $taxonomy->object_type, true ) ) {
			$out[ $slug ] = $taxonomy;
		}
	}
	return 'objects' === $output ? $out : array_keys( $out );
}

function wp_get_object_terms( ...$args ) {
	return array();
}

function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
	$GLOBALS['ewpa_set_terms'][] = array( $post_id, $terms, $taxonomy, $append );
	return array();
}

function taxonomy_exists( $taxonomy ) {
	return isset( $GLOBALS['ewpa_taxonomies'][ $taxonomy ] );
}

function get_taxonomy( $taxonomy ) {
	return $GLOBALS['ewpa_taxonomies'][ $taxonomy ] ?? false;
}

/**
 * Models term_exists(): an integer is a term ID, a string is a slug or name.
 */
function term_exists( $term, $taxonomy = '' ) {
	if ( is_int( $term ) ) {
		return in_array( $term, $GLOBALS['ewpa_term_ids'][ $taxonomy ] ?? array(), true ) ? array( 'term_id' => $term ) : null;
	}
	return in_array( $term, $GLOBALS['ewpa_terms'][ $taxonomy ] ?? array(), true ) ? array( 'term_id' => 1 ) : null;
}

function get_post_stati( $args = array(), $output = 'names' ) {
	return $GLOBALS['ewpa_post_stati'];
}

function get_comments( $args = array() ) {
	return array();
}

function get_option( $name, $default = false ) {
	return $GLOBALS['ewpa_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['ewpa_options'][ $name ] = $value;
	return true;
}

function wp_delete_post( $post_id, $force = false ) {
	$GLOBALS['ewpa_deleted'][] = $post_id;
	unset( $GLOBALS['ewpa_posts'][ absint( $post_id ) ], $GLOBALS['ewpa_meta'][ absint( $post_id ) ] );
	return true;
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

function get_userdata( $user_id ) {
	return in_array( (int) $user_id, array( 1, 2, 3 ), true ) ? (object) array( 'ID' => (int) $user_id ) : false;
}

function get_current_user_id() {
	return $GLOBALS['ewpa_current_user'];
}

function wp_insert_post( $data, $wp_error = false ) {
	$post_id                           = $GLOBALS['ewpa_next_id']++;
	$GLOBALS['ewpa_inserted'][]        = $data;
	$GLOBALS['ewpa_posts'][ $post_id ] = (object) array_merge(
		array(
			'ID'          => $post_id,
			'post_author' => $GLOBALS['ewpa_current_user'],
			'post_parent' => 0,
			'menu_order'  => 0,
		),
		$data
	);
	return $post_id;
}

function wp_update_post( $data, $wp_error = false ) {
	$post_id = absint( $data['ID'] );
	$post    = $GLOBALS['ewpa_posts'][ $post_id ] ?? null;
	if ( ! $post ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
	}
	foreach ( $data as $field => $value ) {
		if ( 'ID' !== $field ) {
			$post->{$field} = $value;
		}
	}
	return $post_id;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['ewpa_meta'][ absint( $post_id ) ][ $key ] = $value;
	return true;
}

function add_post_meta( $post_id, $key, $value ) {
	$GLOBALS['ewpa_meta'][ absint( $post_id ) ][ $key ] = $value;
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		$out = array();
		foreach ( $GLOBALS['ewpa_meta'][ absint( $post_id ) ] ?? array() as $meta_key => $value ) {
			$out[ $meta_key ] = array( $value );
		}
		return $out;
	}
	return $GLOBALS['ewpa_meta'][ absint( $post_id ) ][ $key ] ?? '';
}

function set_post_thumbnail( $post_id, $thumbnail_id ) {
	$GLOBALS['ewpa_thumbnails'][ absint( $post_id ) ] = $thumbnail_id;
	return true;
}

function delete_post_thumbnail( $post_id ) {
	return true;
}

/**
 * Models WordPress meta capability mapping closely enough for these checks.
 *
 * Object caps map to the post type's own capabilities, as map_meta_cap() does:
 * editing someone else's post needs edit_others_*, publishing needs publish_*.
 *
 * @param string $capability Capability or meta capability.
 * @param mixed  ...$args    Optional object ID.
 * @return bool
 */
function current_user_can( $capability, ...$args ) {
	$caps = $GLOBALS['ewpa_caps'];

	if ( 'edit_post_meta' === $capability ) {
		// Core maps it to edit_post and, for a protected key, to a capability named after the key.
		$key = (string) ( $args[1] ?? '' );
		return current_user_can( 'edit_post', $args[0] ?? 0 ) && ( ! is_protected_meta( $key, 'post' ) || in_array( $key, $caps, true ) );
	}

	if ( ! in_array( $capability, array( 'edit_post', 'read_post', 'publish_post' ), true ) ) {
		return in_array( $capability, $caps, true );
	}

	$post = get_post( $args[0] ?? 0 );
	if ( ! $post ) {
		return false;
	}
	$type = get_post_type_object( $post->post_type );
	$own  = (int) $post->post_author === (int) $GLOBALS['ewpa_current_user'];

	switch ( $capability ) {
		case 'read_post':
			return 'publish' === $post->post_status || $own || in_array( $type->cap->edit_others_posts, $caps, true );
		case 'publish_post':
			return in_array( $type->cap->publish_posts, $caps, true )
				&& ( $own || in_array( $type->cap->edit_others_posts, $caps, true ) );
		default:
			return in_array( $type->cap->edit_posts, $caps, true )
				&& ( $own || in_array( $type->cap->edit_others_posts, $caps, true ) );
	}
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

function ewpa_get_seo_meta_keys() {
	return array(
		'title'       => 'seo_title',
		'description' => 'seo_description',
	);
}

class SFWD_LMS {
}

class Ewpa_Test_Tutor_Utils {
	public function get_course_completed_percent( $course_id, $user_id, $stats = false ) {
		return array(
			'completed_percent' => 40,
			'completed_count'   => 2,
			'total_count'       => 5,
		);
	}

	public function get_all_quiz_attempts_by_user( $user_id ) {
		return array(
			(object) array(
				'attempt_id'          => 1,
				'quiz_id'             => 7,
				'course_id'           => 110,
				'total_questions'     => 4,
				'earned_marks'        => 3,
				'total_marks'         => 4,
				'result'              => 'pass',
				'attempt_status'      => 'attempt_ended',
				'attempt_started_at'  => '2026-01-01 10:00:00',
				'attempt_ended_at'    => '2026-01-01 10:10:00',
			),
		);
	}
}

function tutor_utils() {
	return new Ewpa_Test_Tutor_Utils();
}

function tutor() {
	return (object) array( 'course_post_type' => 'courses' );
}

eval( 'namespace Tutor\Models; class EnrollmentModel { public static function is_enrolled( $course_id, $user_id, $a = false ) { return (object) array( "ID" => 900, "post_date" => "2026-01-01 00:00:00" ); } } class QuizModel { public function quiz_attempts( $quiz_id, $user_id ) { return array(); } }' );

class WP_Query {
	public $posts         = array( 300, 301 );
	public $found_posts   = 2;
	public $max_num_pages = 1;

	public function __construct( $args = array() ) {
		$GLOBALS['ewpa_wp_query_args'][] = $args;
	}
}

function get_posts( $args = array() ) {
	$GLOBALS['ewpa_get_posts_args'][] = $args;
	$type                             = $args['post_type'] ?? 'post';
	$status                           = $args['post_status'] ?? 'publish';
	$out                              = array();
	foreach ( $GLOBALS['ewpa_posts'] as $post ) {
		if ( $post->post_type === $type && ( 'any' === $status || $post->post_status === $status ) ) {
			$out[] = $post;
		}
	}
	return $out;
}

function wp_get_post_categories( $post_id, $args = array() ) {
	return array();
}

function wp_get_post_tags( $post_id, $args = array() ) {
	return array();
}

function get_the_author_meta( $field, $user_id = 0 ) {
	return 'Author ' . $user_id;
}

function maybe_unserialize( $value ) {
	return $value;
}

function is_protected_meta( $key, $type = '' ) {
	return 0 === strpos( (string) $key, '_' );
}

function get_post_thumbnail_id( $post_id ) {
	return 0;
}

function wp_get_attachment_url( $attachment_id ) {
	return 'https://example.test/uploads/' . absint( $attachment_id ) . '.jpg';
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function learndash_get_course_lessons_list( $course ) {
	return array();
}

function learndash_get_topic_list( $lesson_id, $course_id ) {
	return array();
}

function learndash_get_course_quiz_list( $course ) {
	return array();
}

function get_the_title( $post_id ) {
	return 'Title ' . $post_id;
}

function get_attached_file( $attachment_id ) {
	return '/uploads/file-' . $attachment_id . '.jpg';
}

function wp_basename( $path ) {
	return basename( $path );
}

function media_sideload_image() {}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^a-zA-Z0-9._\-]/', '', (string) $name );
}

function download_url( $url, $timeout = 300 ) {
	$GLOBALS['ewpa_downloads'][] = $url;
	return '/tmp/ewpa-download.jpg';
}

function media_handle_sideload( $file_array, $parent_post_id ) {
	$id                                = $GLOBALS['ewpa_next_id']++;
	$GLOBALS['ewpa_posts'][ $id ]      = (object) array(
		'ID'             => $id,
		'post_type'      => 'attachment',
		'post_author'    => $GLOBALS['ewpa_current_user'],
		'post_status'    => 'inherit',
		'post_parent'    => $parent_post_id,
		'post_title'     => 'Upload',
		'post_mime_type' => 'image/jpeg',
	);
	$GLOBALS['ewpa_sideloads'][]       = $parent_post_id;
	return $id;
}

function wp_delete_file( $file ) {}

/**
 * Captures ability definitions instead of registering them with WordPress.
 *
 * @param string $name Ability name.
 * @param array  $args Ability definition.
 */
function ewpa_register_ability_with_log( $name, $args ) {
	$GLOBALS['ewpa_registered'][ $name ] = $args;
}

require dirname( __DIR__ ) . '/includes/multilanguage.php';
require dirname( __DIR__ ) . '/includes/thirdparty.php';
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
 * Builds a post type object double with its own capability names.
 *
 * @param string $name   Post type slug.
 * @param string $suffix Suffix of the type's capabilities.
 * @param bool   $plain  True for the built-in "post" capability names.
 * @return object
 */
function ewpa_fake_post_type( string $name, string $suffix, bool $plain = false ) {
	return (object) array(
		'name'         => $name,
		'public'       => true,
		'show_in_rest' => true,
		'cap'          => (object) array(
			'edit_posts'        => $plain ? 'edit_posts' : 'edit_' . $suffix,
			'create_posts'      => $plain ? 'edit_posts' : 'edit_' . $suffix,
			'edit_others_posts' => $plain ? 'edit_others_posts' : 'edit_others_' . $suffix,
			'publish_posts'     => $plain ? 'publish_posts' : 'publish_' . $suffix,
		),
		'labels'       => (object) array( 'singular_name' => ucfirst( $name ) ),
	);
}

/**
 * Builds a post double.
 *
 * @param int    $id     Post ID.
 * @param string $type   Post type.
 * @param int    $author Author user ID.
 * @param string $status Post status.
 * @return object
 */
function ewpa_fake_post( int $id, string $type, int $author, string $status ) {
	return (object) array(
		'ID'             => $id,
		'post_title'     => 'Post ' . $id,
		'post_content'   => 'Body ' . $id,
		'post_excerpt'   => '',
		'post_type'      => $type,
		'post_author'    => $author,
		'post_status'    => $status,
		'post_date'      => '2026-01-01 00:00:00',
		'post_parent'    => 0,
		'menu_order'     => 0,
		'comment_status' => 'open',
		'ping_status'    => 'open',
	);
}

/**
 * Resets every double. The current user is a Contributor (ID 2): it can edit
 * its own posts but cannot publish or touch other people's posts.
 *
 * Posts: 100 draft by user 3, 101 published by user 3, 102 draft by user 2,
 * 110 published course by user 3, 111 draft course by user 2,
 * 200 draft event by user 3, 201 draft event by user 2.
 */
function ewpa_reset(): void {
	$GLOBALS['ewpa_post_types'] = array(
		'post'         => ewpa_fake_post_type( 'post', 'posts', true ),
		'courses'      => ewpa_fake_post_type( 'courses', 'courses' ),
		'tribe_events' => ewpa_fake_post_type( 'tribe_events', 'tribe_events' ),
		'page'         => ewpa_fake_post_type( 'page', 'pages' ),
		'sfwd-courses' => ewpa_fake_post_type( 'sfwd-courses', 'courses' ),
	);
	$GLOBALS['ewpa_posts']      = array(
		100 => ewpa_fake_post( 100, 'post', 3, 'draft' ),
		101 => ewpa_fake_post( 101, 'post', 3, 'publish' ),
		102 => ewpa_fake_post( 102, 'post', 2, 'draft' ),
		110 => ewpa_fake_post( 110, 'courses', 3, 'publish' ),
		111 => ewpa_fake_post( 111, 'courses', 2, 'draft' ),
		200 => ewpa_fake_post( 200, 'tribe_events', 3, 'draft' ),
		201 => ewpa_fake_post( 201, 'tribe_events', 2, 'draft' ),
		112 => ewpa_fake_post( 112, 'courses', 3, 'draft' ),
		202 => ewpa_fake_post( 202, 'tribe_events', 3, 'publish' ),
		120 => ewpa_fake_post( 120, 'sfwd-courses', 3, 'draft' ),
		121 => ewpa_fake_post( 121, 'sfwd-courses', 3, 'publish' ),
		130 => ewpa_fake_post( 130, 'page', 3, 'draft' ),
		131 => ewpa_fake_post( 131, 'page', 3, 'publish' ),
	);
	$GLOBALS['ewpa_meta']       = array(
		101 => array( 'seo_title' => 'Source SEO' ),
		110 => array(
			'subtitle'         => 'Public subtitle',
			'_internal_secret' => 'Protected value',
		),
		111 => array(
			'subtitle'         => 'Own subtitle',
			'_internal_secret' => 'Own protected value',
		),
	);
	$GLOBALS['ewpa_get_posts_args'] = array();
	$GLOBALS['ewpa_set_terms']      = array();
	$GLOBALS['ewpa_deleted']        = array();
	$GLOBALS['ewpa_options']        = array();
	$GLOBALS['ewpa_post_stati']     = array_combine( $std = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ), $std );
	$GLOBALS['ewpa_taxonomies']     = array(
		'genre' => (object) array(
			'name'        => 'genre',
			'label'       => 'Genre',
			'object_type' => array( 'post', 'courses' ),
			'cap'         => (object) array(
				'assign_terms' => 'edit_posts',
				'edit_terms'   => 'manage_categories',
			),
		),
	);
	$GLOBALS['ewpa_terms']          = array( 'genre' => array( 'drama' ) );
	$GLOBALS['ewpa_term_ids']       = array( 'genre' => array( 7 ) );
	$GLOBALS['ewpa_wp_query_args']  = array();
	$GLOBALS['ewpa_thumbnails']     = array();
	$GLOBALS['ewpa_downloads']      = array();
	$GLOBALS['ewpa_sideloads']      = array();
	$GLOBALS['ewpa_inserted']   = array();
	$GLOBALS['ewpa_next_id']    = 500;
	$GLOBALS['ewpa_current_user'] = 2;
	$GLOBALS['ewpa_caps']       = ewpa_contributor_caps();
}

/**
 * Capabilities of a Contributor on posts, courses and events.
 *
 * @return string[]
 */
function ewpa_contributor_caps(): array {
	return array( 'read', 'edit_posts', 'edit_courses', 'edit_tribe_events' );
}

/**
 * Capabilities of an Administrator: everything the abilities check.
 *
 * @return string[]
 */
function ewpa_admin_caps(): array {
	return array(
		'read',
		'edit_posts',
		'edit_others_posts',
		'publish_posts',
		'edit_courses',
		'edit_others_courses',
		'publish_courses',
		'edit_tribe_events',
		'edit_others_tribe_events',
		'publish_tribe_events',
		'edit_pages',
		'edit_others_pages',
		'publish_pages',
		'edit_users',
		'manage_options',
		'upload_files',
		'manage_categories',
		'moderate_comments',
	);
}

/**
 * Switches to a user with full capabilities.
 */
function ewpa_become_admin(): void {
	$GLOBALS['ewpa_current_user'] = 1;
	$GLOBALS['ewpa_caps']        = ewpa_admin_caps();
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

/**
 * Runs the permission callback of a registered ability.
 *
 * @param string $ability Ability name.
 * @param mixed  $input   Ability input.
 * @return mixed
 */
function ewpa_callback_permission( string $ability, $input = array() ) {
	return $GLOBALS['ewpa_registered'][ $ability ]['permission_callback']( $input );
}

/**
 * Tells whether a result is a WP_Error with the given code.
 *
 * @param mixed  $result Result to inspect.
 * @param string $code   Expected error code.
 * @return bool
 */
function ewpa_is_error( $result, string $code ): bool {
	return is_wp_error( $result ) && $code === $result->get_error_code();
}

/**
 * Extracts the IDs from a list result.
 *
 * @param mixed $result Ability result.
 * @return int[]
 */
function ewpa_ids( $result ): array {
	if ( ! is_array( $result ) ) {
		return array();
	}
	$ids = array_map(
		function ( $row ) {
			return (int) $row['ID'];
		},
		$result
	);
	sort( $ids );
	return $ids;
}

/**
 * Builds the input of the listing abilities with every optional field present.
 *
 * @param array $overrides Fields to override.
 * @return array
 */
function ewpa_list_input( array $overrides = array() ): array {
	return array_merge(
		array(
			'status'  => 'publish',
			'orderby' => 'date',
			'order'   => 'DESC',
		),
		$overrides
	);
}

/**
 * Returns the arguments of the last get_posts() call.
 *
 * @return array
 */
function ewpa_last_get_posts_args(): array {
	$calls = $GLOBALS['ewpa_get_posts_args'];
	return $calls ? end( $calls ) : array();
}

ewpa_reset();
ewpa_register_custom_abilities();

/*
 * ==========================================================================
 * T1.1 — ewpa/duplicate-post
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/duplicate-post' )( array( 'post_id' => 100 ) );
ewpa_check( 'duplicate_refuses_source_the_user_cannot_read', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'duplicate_does_not_insert_when_source_is_unreadable', array() === $GLOBALS['ewpa_inserted'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/duplicate-post' )( array( 'post_id' => 101 ) );
ewpa_check( 'duplicate_allows_reading_a_published_source', ! is_wp_error( $result ) );
ewpa_check(
	'duplicate_defaults_to_draft',
	! is_wp_error( $result ) && 'draft' === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_status && 'draft' === $result['status']
);
ewpa_check(
	'duplicate_belongs_to_current_user_without_edit_others',
	! is_wp_error( $result ) && 2 === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_author
);

// Part A: a refused status is an error, as in the core REST controller, never a silent draft.
foreach ( array( 'publish', 'private', 'future' ) as $refused_status ) {
	ewpa_reset();
	$result = ewpa_callback( 'ewpa/duplicate-post' )(
		array(
			'post_id' => 101,
			'status'  => $refused_status,
		)
	);
	ewpa_check( 'duplicate_refuses_' . $refused_status . '_without_publish_capability', ewpa_is_error( $result, 'forbidden' ) );
	ewpa_check( 'duplicate_does_not_insert_when_' . $refused_status . '_is_refused', array() === $GLOBALS['ewpa_inserted'] );
}

ewpa_reset();
$result = ewpa_callback( 'ewpa/duplicate-post' )(
	array(
		'post_id' => 102,
		'status'  => 'private',
	)
);
ewpa_check(
	'duplicate_private_refusal_says_the_status_was_refused',
	is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'private' ) && false !== stripos( $result->get_error_message(), 'refused' )
);

ewpa_reset();
$result = ewpa_callback( 'ewpa/duplicate-post' )(
	array(
		'post_id' => 102,
		'status'  => 'pending',
	)
);
ewpa_check(
	'duplicate_allows_pending_without_publish_capability',
	! is_wp_error( $result ) && 'pending' === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_status
);

ewpa_reset();
$GLOBALS['ewpa_caps'] = array( 'read', 'edit_posts' );
$result               = ewpa_callback( 'ewpa/duplicate-post' )( array( 'post_id' => 110 ) );
ewpa_check( 'duplicate_refuses_post_type_the_user_cannot_create', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'publish_posts' ) );
$result               = ewpa_callback( 'ewpa/duplicate-post' )(
	array(
		'post_id' => 101,
		'status'  => 'publish',
	)
);
ewpa_check(
	'duplicate_honours_publish_with_publish_capability',
	! is_wp_error( $result ) && 'publish' === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_status
);
ewpa_check(
	'duplicate_still_assigns_current_user_with_publish_but_not_edit_others',
	! is_wp_error( $result ) && 2 === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_author
);

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'edit_others_posts' ) );
$result               = ewpa_callback( 'ewpa/duplicate-post' )( array( 'post_id' => 100 ) );
ewpa_check(
	'duplicate_keeps_original_author_with_edit_others',
	! is_wp_error( $result ) && 3 === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_author
);

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/duplicate-post' )(
	array(
		'post_id' => 101,
		'status'  => 'publish',
	)
);
ewpa_check( 'admin_duplicate_succeeds', ! is_wp_error( $result ) );
ewpa_check(
	'admin_duplicate_keeps_status_and_author',
	! is_wp_error( $result )
		&& 'publish' === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_status
		&& 3 === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_author
);
ewpa_check(
	'admin_duplicate_copies_meta',
	! is_wp_error( $result ) && 'Source SEO' === ( $GLOBALS['ewpa_meta'][ $result['new_post_id'] ]['seo_title'] ?? '' )
);
$result = ewpa_callback( 'ewpa/duplicate-post' )( array( 'post_id' => 110 ) );
ewpa_check(
	'admin_duplicate_of_unpublished_cpt_keeps_draft_default',
	! is_wp_error( $result ) && 'draft' === $GLOBALS['ewpa_posts'][ $result['new_post_id'] ]->post_status
);

/*
 * ==========================================================================
 * T1.2 — ewpa/update-post
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-post' )(
	array(
		'post_id' => 102,
		'status'  => 'publish',
	)
);
ewpa_check( 'update_post_refuses_publish_without_capability', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'update_post_leaves_status_untouched_when_refused', 'draft' === $GLOBALS['ewpa_posts'][102]->post_status );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-post' )(
	array(
		'post_id' => 102,
		'status'  => 'private',
	)
);
ewpa_check( 'update_post_refuses_private_without_capability', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-post' )(
	array(
		'post_id' => 102,
		'status'  => 'pending',
		'title'   => 'Renamed',
	)
);
ewpa_check(
	'update_post_allows_pending_without_publish_capability',
	! is_wp_error( $result ) && 'pending' === $GLOBALS['ewpa_posts'][102]->post_status && 'Renamed' === $GLOBALS['ewpa_posts'][102]->post_title
);

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'publish_posts' ) );
$result               = ewpa_callback( 'ewpa/update-post' )(
	array(
		'post_id' => 102,
		'status'  => 'publish',
	)
);
ewpa_check(
	'update_post_allows_publish_with_capability',
	! is_wp_error( $result ) && 'publish' === $GLOBALS['ewpa_posts'][102]->post_status
);

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/update-post' )(
	array(
		'post_id' => 100,
		'status'  => 'publish',
		'title'   => 'Admin edit',
	)
);
ewpa_check(
	'admin_update_post_publishes_anyones_post',
	! is_wp_error( $result ) && 'publish' === $GLOBALS['ewpa_posts'][100]->post_status
);

/*
 * ==========================================================================
 * T1.3 — ewpa/create-post
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-post' )(
	array(
		'title'     => 'Spoofed',
		'content'   => 'x',
		'author_id' => 3,
	)
);
ewpa_check( 'create_post_refuses_other_author_without_edit_others', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'create_post_does_not_insert_when_author_refused', array() === $GLOBALS['ewpa_inserted'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-post' )(
	array(
		'title'     => 'Mine',
		'content'   => 'x',
		'author_id' => 2,
	)
);
ewpa_check( 'create_post_allows_own_author_id', ! is_wp_error( $result ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'edit_others_posts' ) );
$result               = ewpa_callback( 'ewpa/create-post' )(
	array(
		'title'     => 'On behalf',
		'content'   => 'x',
		'author_id' => 3,
	)
);
ewpa_check(
	'create_post_allows_other_author_with_edit_others',
	! is_wp_error( $result ) && 3 === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_author
);

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/create-post' )(
	array(
		'title'     => 'Admin',
		'content'   => 'x',
		'status'    => 'publish',
		'author_id' => 3,
	)
);
ewpa_check(
	'admin_create_post_sets_author_and_status',
	! is_wp_error( $result ) && 3 === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_author && 'publish' === $result['status']
);

// ewpa/create-post is gated by publish_posts in its permission callback, so a user who cannot
// publish never reaches its status handling: there is no silent draft fallback to replace.
// This case characterizes that gate and passes before and after Part A.
ewpa_reset();
ewpa_check(
	'create_post_is_denied_to_users_without_publish_posts',
	false === ewpa_callback_permission( 'ewpa/create-post' )
);

/*
 * ==========================================================================
 * T1.4 — ewpa/create-cpt-item and ewpa/update-cpt-item
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'status'    => 'publish',
	)
);
ewpa_check( 'create_cpt_refuses_publish_without_the_types_publish_capability', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'create_cpt_does_not_insert_when_publish_is_refused', array() === $GLOBALS['ewpa_inserted'] );

// 'future' is not part of this ability's status enum, so it is not a case here.
foreach ( array( 'private' ) as $refused_status ) {
	ewpa_reset();
	$result = ewpa_callback( 'ewpa/create-cpt-item' )(
		array(
			'post_type' => 'courses',
			'title'     => 'Course',
			'status'    => $refused_status,
		)
	);
	ewpa_check( 'create_cpt_refuses_' . $refused_status . '_without_the_types_publish_capability', ewpa_is_error( $result, 'forbidden' ) );
}

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'status'    => 'pending',
	)
);
ewpa_check(
	'create_cpt_allows_pending_without_publish_capability',
	! is_wp_error( $result ) && 'pending' === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_status
);

// A global publish_posts must not publish a type that has its own capability.
ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'publish_posts' ) );
$result               = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'status'    => 'publish',
	)
);
ewpa_check( 'create_cpt_checks_the_types_own_publish_capability', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'publish_courses' ) );
$result               = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'status'    => 'publish',
	)
);
ewpa_check(
	'create_cpt_allows_publish_with_the_types_capability',
	! is_wp_error( $result ) && 'publish' === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_status
);

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'author_id' => 3,
	)
);
ewpa_check( 'create_cpt_refuses_other_author_without_edit_others', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'author_id' => 999,
	)
);
ewpa_check( 'create_cpt_rejects_author_that_does_not_exist', ewpa_is_error( $result, 'invalid_author' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type'   => 'courses',
		'title'       => 'Child',
		'post_parent' => 110,
	)
);
ewpa_check( 'create_cpt_refuses_parent_the_user_cannot_edit', ewpa_is_error( $result, 'invalid_parent' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type'   => 'courses',
		'title'       => 'Child',
		'post_parent' => 999,
	)
);
ewpa_check( 'create_cpt_refuses_missing_parent', ewpa_is_error( $result, 'invalid_parent' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type'   => 'courses',
		'title'       => 'Child',
		'post_parent' => 111,
	)
);
ewpa_check(
	'create_cpt_allows_parent_the_user_can_edit',
	! is_wp_error( $result ) && 111 === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_parent
);

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type'   => 'courses',
		'title'       => 'Admin item',
		'status'      => 'publish',
		'author_id'   => 3,
		'post_parent' => 110,
	)
);
ewpa_check(
	'admin_create_cpt_keeps_status_author_and_parent',
	! is_wp_error( $result )
		&& 'publish' === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_status
		&& 3 === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_author
		&& 110 === $GLOBALS['ewpa_posts'][ $result['post_id'] ]->post_parent
);

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'status'  => 'publish',
	)
);
ewpa_check( 'update_cpt_refuses_publish_without_the_types_capability', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'update_cpt_leaves_status_untouched_when_refused', 'draft' === $GLOBALS['ewpa_posts'][111]->post_status );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'publish_posts' ) );
$result               = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'status'  => 'publish',
	)
);
ewpa_check( 'update_cpt_ignores_a_global_publish_capability', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'publish_courses' ) );
$result               = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'status'  => 'publish',
	)
);
ewpa_check(
	'update_cpt_allows_publish_with_the_types_capability',
	! is_wp_error( $result ) && 'publish' === $GLOBALS['ewpa_posts'][111]->post_status
);

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'     => 111,
		'post_parent' => 110,
	)
);
ewpa_check( 'update_cpt_refuses_parent_the_user_cannot_edit', ewpa_is_error( $result, 'invalid_parent' ) );
ewpa_check( 'update_cpt_leaves_parent_untouched_when_refused', 0 === $GLOBALS['ewpa_posts'][111]->post_parent );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'     => 111,
		'status'      => 'publish',
		'post_parent' => 110,
		'title'       => 'Admin edit',
	)
);
ewpa_check(
	'admin_update_cpt_publishes_and_reparents',
	! is_wp_error( $result )
		&& 'publish' === $GLOBALS['ewpa_posts'][111]->post_status
		&& 110 === $GLOBALS['ewpa_posts'][111]->post_parent
		&& 'Admin edit' === $GLOBALS['ewpa_posts'][111]->post_title
);

/*
 * ==========================================================================
 * T1.6 — ewpa/tec-update-event
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/tec-update-event' )(
	array(
		'event_id' => 200,
		'title'    => 'Hijacked',
	)
);
ewpa_check( 'tec_update_refuses_event_the_user_cannot_edit', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'tec_update_leaves_title_untouched_when_refused', 'Post 200' === $GLOBALS['ewpa_posts'][200]->post_title );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tec-update-event' )(
	array(
		'event_id' => 201,
		'status'   => 'publish',
	)
);
ewpa_check( 'tec_update_refuses_publish_without_capability', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'tec_update_leaves_status_untouched_when_refused', 'draft' === $GLOBALS['ewpa_posts'][201]->post_status );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tec-update-event' )(
	array(
		'event_id' => 201,
		'title'    => 'Own event',
		'status'   => 'draft',
	)
);
ewpa_check(
	'tec_update_allows_own_event_without_publishing',
	! is_wp_error( $result ) && 'Own event' === $GLOBALS['ewpa_posts'][201]->post_title
);

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/tec-update-event' )(
	array(
		'event_id' => 200,
		'title'    => 'Admin edit',
		'status'   => 'publish',
	)
);
ewpa_check(
	'admin_tec_update_edits_and_publishes',
	! is_wp_error( $result ) && 'publish' === $GLOBALS['ewpa_posts'][200]->post_status && 'Admin edit' === $GLOBALS['ewpa_posts'][200]->post_title
);

/*
 * ==========================================================================
 * T2.1 — get-posts, get-pages, get-cpt-items
 * ==========================================================================
 */

foreach ( array( 'draft', 'private', 'any', 'trash' ) as $status ) {
	ewpa_reset();
	$GLOBALS['ewpa_caps'] = array( 'read' );
	$result               = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => $status ) ) );
	ewpa_check( 'get_posts_refuses_' . $status . '_without_editing_capability', ewpa_is_error( $result, 'forbidden' ) );
}

ewpa_reset();
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input() );
ewpa_check( 'get_posts_still_lists_published_for_a_reader', array( 101 ) === ewpa_ids( $result ) );
ewpa_check( 'get_posts_asks_wordpress_for_readable_posts_only', 'readable' === ( ewpa_last_get_posts_args()['perm'] ?? '' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'any' ) ) );
ewpa_check( 'get_posts_hides_other_authors_drafts_from_a_contributor', array( 101, 102 ) === ewpa_ids( $result ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'any' ) ) );
ewpa_check( 'admin_get_posts_lists_every_status', array( 100, 101, 102 ) === ewpa_ids( $result ) );
$result = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'draft' ) ) );
ewpa_check( 'admin_get_posts_lists_drafts', array( 100, 102 ) === ewpa_ids( $result ) );

foreach ( array( 'draft', 'private', 'any' ) as $status ) {
	ewpa_reset();
	$GLOBALS['ewpa_caps'] = array( 'read' );
	$result               = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input( array( 'status' => $status ) ) );
	ewpa_check( 'get_pages_refuses_' . $status . '_without_editing_capability', ewpa_is_error( $result, 'forbidden' ) );
}

ewpa_reset();
$result = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input( array( 'status' => 'draft' ) ) );
ewpa_check( 'get_pages_refuses_drafts_to_a_user_who_cannot_edit_pages', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input() );
ewpa_check( 'get_pages_still_lists_published_for_a_reader', array( 131 ) === ewpa_ids( $result ) );
ewpa_check( 'get_pages_asks_wordpress_for_readable_pages_only', 'readable' === ( ewpa_last_get_posts_args()['perm'] ?? '' ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input( array( 'status' => 'any' ) ) );
ewpa_check( 'admin_get_pages_lists_every_status', array( 130, 131 ) === ewpa_ids( $result ) );

foreach ( array( 'draft', 'private', 'any' ) as $status ) {
	ewpa_reset();
	$GLOBALS['ewpa_caps'] = array( 'read' );
	$result               = ewpa_callback( 'ewpa/get-cpt-items' )(
		array(
			'post_type' => 'courses',
			'order'     => 'DESC',
			'status'    => $status,
		)
	);
	ewpa_check( 'get_cpt_items_refuses_' . $status . '_without_editing_capability', ewpa_is_error( $result, 'forbidden' ) );
}

ewpa_reset();
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/get-cpt-items' )( array( 'post_type' => 'courses', 'order' => 'DESC' ) );
ewpa_check( 'get_cpt_items_still_lists_published_for_a_reader', ! is_wp_error( $result ) && array( 110 ) === ewpa_ids( $result ) );
ewpa_check( 'get_cpt_items_asks_wordpress_for_readable_items_only', 'readable' === ( ewpa_last_get_posts_args()['perm'] ?? '' ) );

ewpa_reset();
ewpa_callback( 'ewpa/get-cpt-items' )(
	array(
		'post_type' => 'courses',
		'order'     => 'DESC',
		'status'    => 'not-a-status',
	)
);
ewpa_check( 'get_cpt_items_refuses_an_unknown_status_instead_of_resolving_it_to_publish', array() === $GLOBALS['ewpa_get_posts_args'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/get-cpt-items' )(
	array(
		'post_type' => 'courses',
		'order'     => 'DESC',
		'status'    => 'any',
	)
);
ewpa_check( 'get_cpt_items_hides_other_authors_drafts_from_an_editor_of_the_type', array( 110, 111 ) === ewpa_ids( $result ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-cpt-items' )(
	array(
		'post_type' => 'courses',
		'order'     => 'DESC',
		'status'    => 'any',
	)
);
ewpa_check( 'admin_get_cpt_items_lists_every_status', array( 110, 111, 112 ) === ewpa_ids( $result ) );
$result = ewpa_callback( 'ewpa/get-cpt-items' )( array( 'post_type' => 'courses', 'order' => 'DESC' ) );
ewpa_check( 'admin_get_cpt_items_lists_published_by_default', array( 110 ) === ewpa_ids( $result ) );

/*
 * ==========================================================================
 * T2.2 — tutor-get-user-progress and tutor-get-quiz-results
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/tutor-get-user-progress' )(
	array(
		'user_id'   => 3,
		'course_id' => 110,
	)
);
ewpa_check( 'tutor_progress_refuses_another_students_record_to_an_editor', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tutor-get-user-progress' )(
	array(
		'user_id'   => 2,
		'course_id' => 110,
	)
);
ewpa_check( 'tutor_progress_allows_reading_ones_own_record', ! is_wp_error( $result ) && 40 === $result['completed_percent'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/tutor-get-user-progress' )(
	array(
		'user_id'   => 3,
		'course_id' => 110,
	)
);
ewpa_check( 'admin_tutor_progress_reads_any_student', ! is_wp_error( $result ) && 40 === $result['completed_percent'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tutor-get-quiz-results' )( array( 'user_id' => 3 ) );
ewpa_check( 'tutor_quiz_results_refuse_another_students_record_to_an_editor', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tutor-get-quiz-results' )( array( 'user_id' => 2 ) );
ewpa_check( 'tutor_quiz_results_allow_reading_ones_own_record', ! is_wp_error( $result ) && 1 === $result['total'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/tutor-get-quiz-results' )( array( 'user_id' => 3 ) );
ewpa_check( 'admin_tutor_quiz_results_read_any_student', ! is_wp_error( $result ) && 1 === $result['total'] );

/*
 * ==========================================================================
 * T2.3 — tec-get-event and ld-get-course
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/tec-get-event' )( array( 'event_id' => 200 ) );
ewpa_check( 'tec_get_event_refuses_a_draft_the_user_cannot_read', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tec-get-event' )( array( 'event_id' => 201 ) );
ewpa_check( 'tec_get_event_allows_ones_own_draft', ! is_wp_error( $result ) && 201 === $result['id'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/tec-get-event' )( array( 'event_id' => 202 ) );
ewpa_check( 'tec_get_event_allows_a_published_event', ! is_wp_error( $result ) && 202 === $result['id'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/tec-get-event' )( array( 'event_id' => 200 ) );
ewpa_check( 'admin_tec_get_event_reads_any_event', ! is_wp_error( $result ) && 200 === $result['id'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/ld-get-course' )( array( 'course_id' => 120 ) );
ewpa_check( 'ld_get_course_refuses_a_draft_the_user_cannot_read', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/ld-get-course' )( array( 'course_id' => 121 ) );
ewpa_check( 'ld_get_course_allows_a_published_course', ! is_wp_error( $result ) && 121 === $result['id'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/ld-get-course' )( array( 'course_id' => 120 ) );
ewpa_check( 'admin_ld_get_course_reads_any_course', ! is_wp_error( $result ) && 120 === $result['id'] );

/*
 * ==========================================================================
 * T2.4 — SEO readers
 * ==========================================================================
 */

foreach ( array( 'ewpa/get-rankmath', 'ewpa/get-seopress', 'ewpa/yoast-get-seo' ) as $ability ) {
	$slug = str_replace( '/', '_', $ability );

	ewpa_reset();
	$permission = ewpa_callback_permission( $ability, array( 'post_id' => 100 ) );
	ewpa_check( $slug . '_refuses_a_post_the_user_cannot_edit', ewpa_is_error( $permission, 'forbidden' ) );

	ewpa_reset();
	$permission = ewpa_callback_permission( $ability, array( 'post_id' => 102 ) );
	ewpa_check( $slug . '_allows_a_post_the_user_can_edit', true === $permission );

	ewpa_reset();
	ewpa_become_admin();
	$permission = ewpa_callback_permission( $ability, array( 'post_id' => 100 ) );
	ewpa_check( 'admin_' . $slug . '_reads_any_post', true === $permission );
}

/*
 * ==========================================================================
 * T2.5 — upload-image
 * ==========================================================================
 */

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'upload_files' ) );
$result               = ewpa_callback( 'ewpa/upload-image' )(
	array(
		'url'     => 'https://cdn.example.test/pic.jpg',
		'post_id' => 100,
	)
);
ewpa_check( 'upload_image_refuses_attaching_to_a_post_the_user_cannot_edit', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'upload_image_downloads_nothing_when_refused', array() === $GLOBALS['ewpa_downloads'] );
ewpa_check( 'upload_image_sets_no_thumbnail_when_refused', array() === $GLOBALS['ewpa_thumbnails'] );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'upload_files' ) );
$result               = ewpa_callback( 'ewpa/upload-image' )(
	array(
		'url'     => 'https://cdn.example.test/pic.jpg',
		'post_id' => 102,
	)
);
ewpa_check( 'upload_image_allows_attaching_to_an_own_post', ! is_wp_error( $result ) && isset( $GLOBALS['ewpa_thumbnails'][102] ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'upload_files' ) );
$result               = ewpa_callback( 'ewpa/upload-image' )( array( 'url' => 'https://cdn.example.test/pic.jpg' ) );
ewpa_check( 'upload_image_without_a_post_still_works', ! is_wp_error( $result ) && array() === $GLOBALS['ewpa_thumbnails'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/upload-image' )(
	array(
		'url'     => 'https://cdn.example.test/pic.jpg',
		'post_id' => 100,
	)
);
ewpa_check( 'admin_upload_image_attaches_to_any_post', ! is_wp_error( $result ) && isset( $GLOBALS['ewpa_thumbnails'][100] ) );

/*
 * ==========================================================================
 * T2.6 — get-cpt-item protected meta
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/get-cpt-item' )( array( 'post_id' => 110 ) );
ewpa_check(
	'get_cpt_item_gives_a_reader_the_public_meta',
	! is_wp_error( $result ) && 'Public subtitle' === ( $result['meta']['subtitle'] ?? '' )
);
ewpa_check(
	'get_cpt_item_hides_protected_meta_from_a_reader',
	! is_wp_error( $result ) && ! array_key_exists( '_internal_secret', $result['meta'] )
);

ewpa_reset();
$result = ewpa_callback( 'ewpa/get-cpt-item' )( array( 'post_id' => 111 ) );
ewpa_check(
	'get_cpt_item_keeps_protected_meta_for_a_user_who_can_edit_the_item',
	! is_wp_error( $result ) && 'Own protected value' === ( $result['meta']['_internal_secret'] ?? '' )
);

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-cpt-item' )( array( 'post_id' => 110 ) );
ewpa_check(
	'admin_get_cpt_item_keeps_protected_meta',
	! is_wp_error( $result ) && 'Protected value' === ( $result['meta']['_internal_secret'] ?? '' ) && 'Public subtitle' === ( $result['meta']['subtitle'] ?? '' )
);

/*
 * ==========================================================================
 * T2.7 — get-accessibility-snapshot
 * ==========================================================================
 */

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'upload_files' ) );
ewpa_callback( 'ewpa/get-accessibility-snapshot' )( array() );
$limited = array_filter(
	$GLOBALS['ewpa_wp_query_args'],
	function ( $args ) {
		return 2 === ( $args['author'] ?? null );
	}
);
ewpa_check( 'accessibility_snapshot_limits_both_queries_to_own_uploads_without_edit_others', 2 === count( $GLOBALS['ewpa_wp_query_args'] ) && 2 === count( $limited ) );

ewpa_reset();
$GLOBALS['ewpa_caps'] = array_merge( ewpa_contributor_caps(), array( 'upload_files', 'edit_others_posts' ) );
ewpa_callback( 'ewpa/get-accessibility-snapshot' )( array() );
$limited = array_filter(
	$GLOBALS['ewpa_wp_query_args'],
	function ( $args ) {
		return isset( $args['author'] );
	}
);
ewpa_check( 'accessibility_snapshot_lists_every_attachment_with_edit_others', 2 === count( $GLOBALS['ewpa_wp_query_args'] ) && array() === $limited );

ewpa_reset();
ewpa_become_admin();
$result  = ewpa_callback( 'ewpa/get-accessibility-snapshot' )( array() );
$limited = array_filter(
	$GLOBALS['ewpa_wp_query_args'],
	function ( $args ) {
		return isset( $args['author'] );
	}
);
ewpa_check(
	'admin_accessibility_snapshot_lists_every_attachment',
	array() === $limited && 2 === $result['total_images'] && 2 === count( $result['missing_alt_items'] )
);

/*
 * ==========================================================================
 * Part A — listing statuses come from the registry, not from a fixed list
 * ==========================================================================
 */

/**
 * Registers a custom post status the way WooCommerce or any plugin would.
 *
 * @param string $status Status slug.
 */
function ewpa_register_status( string $status ): void {
	$GLOBALS['ewpa_post_stati'][ $status ] = $status;
}

/**
 * Adds posts in a custom status: 113 own and 114 foreign courses, 103 own and
 * 104 foreign posts, 132 foreign page.
 */
function ewpa_add_custom_status_posts(): void {
	$GLOBALS['ewpa_posts'][113] = ewpa_fake_post( 113, 'courses', 2, 'wc-processing' );
	$GLOBALS['ewpa_posts'][114] = ewpa_fake_post( 114, 'courses', 3, 'wc-processing' );
	$GLOBALS['ewpa_posts'][103] = ewpa_fake_post( 103, 'post', 2, 'wc-processing' );
	$GLOBALS['ewpa_posts'][104] = ewpa_fake_post( 104, 'post', 3, 'wc-processing' );
	$GLOBALS['ewpa_posts'][132] = ewpa_fake_post( 132, 'page', 3, 'wc-processing' );
}

/**
 * Builds the input of get-cpt-items for the courses type.
 *
 * @param string $status Requested status.
 * @return array
 */
function ewpa_cpt_list_input( string $status ): array {
	return array(
		'post_type' => 'courses',
		'status'    => $status,
		'order'     => 'DESC',
		'orderby'   => 'date',
	);
}

ewpa_reset();
ewpa_add_custom_status_posts();
$result = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'wc-processing' ) );
ewpa_check( 'get_cpt_items_refuses_an_unregistered_status_with_an_error', ewpa_is_error( $result, 'invalid_status' ) );
ewpa_check( 'get_cpt_items_does_not_query_for_an_unregistered_status', array() === $GLOBALS['ewpa_get_posts_args'] );

ewpa_reset();
ewpa_add_custom_status_posts();
ewpa_register_status( 'wc-processing' );
$result = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'wc-processing' ) );
ewpa_check( 'get_cpt_items_accepts_a_registered_custom_status', ! is_wp_error( $result ) && 'wc-processing' === ( ewpa_last_get_posts_args()['post_status'] ?? '' ) );
ewpa_check( 'get_cpt_items_custom_status_keeps_the_per_post_filter', array( 113 ) === ewpa_ids( $result ) );

ewpa_reset();
ewpa_add_custom_status_posts();
ewpa_register_status( 'wc-processing' );
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'wc-processing' ) );
ewpa_check( 'get_cpt_items_custom_status_still_needs_the_editing_capability', ewpa_is_error( $result, 'forbidden' ) );

ewpa_reset();
ewpa_add_custom_status_posts();
ewpa_register_status( 'wc-processing' );
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'wc-processing' ) );
ewpa_check( 'admin_get_cpt_items_lists_a_registered_custom_status', array( 113, 114 ) === ewpa_ids( $result ) );
$result = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'trash' ) );
ewpa_check( 'admin_get_cpt_items_still_accepts_standard_statuses', ! is_wp_error( $result ) );
$result = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'any' ) );
ewpa_check( 'admin_get_cpt_items_still_accepts_any', ! is_wp_error( $result ) && 'any' === ( ewpa_last_get_posts_args()['post_status'] ?? '' ) );
$result = ewpa_callback( 'ewpa/get-cpt-items' )( ewpa_cpt_list_input( 'bogus' ) );
ewpa_check( 'admin_get_cpt_items_refuses_an_unregistered_status', ewpa_is_error( $result, 'invalid_status' ) );

ewpa_reset();
ewpa_add_custom_status_posts();
$result = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'get_posts_refuses_an_unregistered_status_with_an_error', ewpa_is_error( $result, 'invalid_status' ) );
ewpa_register_status( 'wc-processing' );
$result = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'get_posts_accepts_a_registered_custom_status_and_filters_per_post', ! is_wp_error( $result ) && array( 103 ) === ewpa_ids( $result ) );
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'get_posts_custom_status_still_needs_the_editing_capability', ewpa_is_error( $result, 'forbidden' ) );
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-posts' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'admin_get_posts_lists_a_registered_custom_status', array( 103, 104 ) === ewpa_ids( $result ) );

ewpa_reset();
ewpa_add_custom_status_posts();
$result = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'get_pages_refuses_an_unregistered_status_with_an_error', ewpa_is_error( $result, 'invalid_status' ) );
ewpa_register_status( 'wc-processing' );
$GLOBALS['ewpa_caps'] = array( 'read' );
$result               = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'get_pages_custom_status_still_needs_the_editing_capability', ewpa_is_error( $result, 'forbidden' ) );
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/get-pages' )( ewpa_list_input( array( 'status' => 'wc-processing' ) ) );
ewpa_check( 'admin_get_pages_lists_a_registered_custom_status', array( 132 ) === ewpa_ids( $result ) );

foreach ( array( 'ewpa/get-posts', 'ewpa/get-pages', 'ewpa/get-cpt-items' ) as $ability ) {
	$status_schema = $GLOBALS['ewpa_registered'][ $ability ]['input_schema']['properties']['status'];
	ewpa_check( 'schema_of_' . $ability . '_does_not_promise_a_fixed_status_list', ! isset( $status_schema['enum'] ) );
}

/*
 * ==========================================================================
 * T3.2 — no PHP notices when optional input is absent
 * ==========================================================================
 */

/**
 * Runs a callback and returns the warnings and notices it raised.
 *
 * @param callable $callback Code to run.
 * @return string[]
 */
function ewpa_collect_notices( callable $callback ): array {
	$notices = array();
	set_error_handler(
		function ( $errno, $errstr ) use ( &$notices ) {
			$notices[] = $errstr;
			return true;
		}
	);
	try {
		$callback();
	} catch ( Throwable $e ) {
		$notices[] = 'Uncaught ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	restore_error_handler();
	return $notices;
}

ewpa_reset();
ewpa_become_admin();
$notices = ewpa_collect_notices(
	function () {
		ewpa_callback( 'ewpa/get-posts' )( array() );
	}
);
ewpa_check( 'get_posts_emits_no_notice_without_optional_input', array() === $notices );

$notices = ewpa_collect_notices(
	function () {
		ewpa_callback( 'ewpa/get-pages' )( array() );
	}
);
ewpa_check( 'get_pages_emits_no_notice_without_optional_input', array() === $notices );

$notices = ewpa_collect_notices(
	function () {
		ewpa_callback( 'ewpa/get-cpt-items' )( array( 'post_type' => 'courses' ) );
	}
);
ewpa_check( 'get_cpt_items_emits_no_notice_without_optional_input', array() === $notices );

$notices = ewpa_collect_notices(
	function () {
		ewpa_callback( 'ewpa/get-cpt-items' )(
			array(
				'post_type' => 'courses',
				'tax_query' => array(
					array(
						'taxonomy' => 'genre',
						'terms'    => array( 'drama' ),
					),
				),
			)
		);
	}
);
ewpa_check( 'get_cpt_items_emits_no_notice_for_a_tax_query_without_field_or_operator', array() === $notices );

$notices = ewpa_collect_notices(
	function () {
		ewpa_callback( 'ewpa/get-comments' )( array() );
	}
);
ewpa_check( 'get_comments_emits_no_notice_without_optional_input', array() === $notices );

$notices = ewpa_collect_notices(
	function () {
		ewpa_callback( 'ewpa/create-page' )(
			array(
				'title'   => 'Page',
				'content' => 'Body',
			)
		);
	}
);
ewpa_check( 'create_page_emits_no_notice_without_a_status', array() === $notices );
ewpa_check( 'create_page_still_defaults_to_draft', 'draft' === ( end( $GLOBALS['ewpa_inserted'] )['post_status'] ?? '' ) );

ewpa_reset();
ewpa_become_admin();
ewpa_callback( 'ewpa/get-posts' )(
	array(
		'status'  => 'publish',
		'orderby' => 'title',
		'order'   => 'ASC',
	)
);
$args = ewpa_last_get_posts_args();
ewpa_check( 'get_posts_still_honours_a_valid_orderby_and_order', 'title' === $args['orderby'] && 'ASC' === $args['order'] );
ewpa_callback( 'ewpa/get-posts' )(
	array(
		'status'  => 'publish',
		'orderby' => 'bogus',
		'order'   => 'sideways',
	)
);
$args = ewpa_last_get_posts_args();
ewpa_check( 'get_posts_still_falls_back_on_an_invalid_orderby_and_order', 'date' === $args['orderby'] && 'DESC' === $args['order'] );

/*
 * ==========================================================================
 * T3.1 — protected meta on write
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => '_thumbnail_id',
		'meta_value' => '5',
	)
);
ewpa_check( 'update_post_meta_refuses_a_protected_key_without_edit_post_meta', ewpa_is_error( $result, 'protected_meta' ) );
ewpa_check( 'update_post_meta_error_names_the_refused_key', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '_thumbnail_id' ) );
ewpa_check( 'update_post_meta_writes_nothing_for_a_refused_key', ! isset( $GLOBALS['ewpa_meta'][102]['_thumbnail_id'] ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => 'subtitle',
		'meta_value' => 'Hello',
	)
);
ewpa_check( 'update_post_meta_still_writes_an_unprotected_key', ! is_wp_error( $result ) && 'Hello' === ( $GLOBALS['ewpa_meta'][102]['subtitle'] ?? '' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => '_edit_lock',
		'meta_value' => 'x',
	)
);
ewpa_check( 'update_post_meta_keeps_the_hard_denylist', ewpa_is_error( $result, 'blocked_key' ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => 'subtitle',
		'meta_value' => 'Admin',
	)
);
ewpa_check( 'admin_update_post_meta_writes_an_unprotected_key', ! is_wp_error( $result ) && 'Admin' === ( $GLOBALS['ewpa_meta'][102]['subtitle'] ?? '' ) );
$result = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => '_genesis_title',
		'meta_value' => 'SEO',
	)
);
ewpa_check( 'admin_update_post_meta_writes_a_protected_seo_key_without_a_site_grant', ! is_wp_error( $result ) && 'SEO' === ( $GLOBALS['ewpa_meta'][102]['_genesis_title'] ?? '' ) );
$result = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => '_edit_lock',
		'meta_value' => 'x',
	)
);
ewpa_check( 'admin_update_post_meta_keeps_the_hard_denylist', ewpa_is_error( $result, 'blocked_key' ) );
// A Contributor has no manage_options, so the site's own authorization is the only way in.
ewpa_reset();
$GLOBALS['ewpa_caps'][] = '_genesis_title'; // Models an auth_post_meta_{key} / register_post_meta auth_callback grant.
$result                 = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 102,
		'meta_key'   => '_genesis_title',
		'meta_value' => 'SEO',
	)
);
ewpa_check( 'contributor_update_post_meta_writes_a_protected_key_the_site_authorized', ! is_wp_error( $result ) && 'SEO' === ( $GLOBALS['ewpa_meta'][102]['_genesis_title'] ?? '' ) );

// An authorized key is still not a way around the object check.
ewpa_reset();
$GLOBALS['ewpa_caps'][] = '_genesis_title';
$result                 = ewpa_callback( 'ewpa/update-post-meta' )(
	array(
		'post_id'    => 110,
		'meta_key'   => '_genesis_title',
		'meta_value' => 'SEO',
	)
);
ewpa_check( 'contributor_update_post_meta_still_needs_edit_post_for_an_authorized_key', is_wp_error( $result ) && ! isset( $GLOBALS['ewpa_meta'][110]['_genesis_title'] ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'meta'      => array(
			'subtitle'         => 'Fine',
			'_internal_secret' => 'Nope',
		),
	)
);
ewpa_check( 'create_cpt_item_refuses_a_protected_meta_key_with_an_error', ewpa_is_error( $result, 'protected_meta' ) );
ewpa_check( 'create_cpt_item_error_names_the_refused_key', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '_internal_secret' ) );
ewpa_check( 'create_cpt_item_leaves_no_post_and_no_meta_behind_on_a_refused_key', array( 500 ) === $GLOBALS['ewpa_deleted'] && ! isset( $GLOBALS['ewpa_posts'][500] ) && ! isset( $GLOBALS['ewpa_meta'][500] ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'meta'      => array( '_edit_lock' => 'x' ),
	)
);
ewpa_check( 'create_cpt_item_refuses_a_denylisted_key_instead_of_skipping_it', ewpa_is_error( $result, 'blocked_key' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Course',
		'meta'      => array( 'subtitle' => 'Fine' ),
	)
);
ewpa_check( 'create_cpt_item_still_writes_unprotected_meta', ! is_wp_error( $result ) && 'Fine' === ( $GLOBALS['ewpa_meta'][500]['subtitle'] ?? '' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'meta'    => array( '_internal_secret' => 'Changed' ),
	)
);
ewpa_check( 'update_cpt_item_refuses_a_protected_meta_key_with_an_error', ewpa_is_error( $result, 'protected_meta' ) );
ewpa_check( 'update_cpt_item_leaves_the_protected_meta_untouched', 'Own protected value' === $GLOBALS['ewpa_meta'][111]['_internal_secret'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'title'   => 'Renamed',
		'meta'    => array( '_edit_lock' => 'x' ),
	)
);
ewpa_check( 'update_cpt_item_refuses_a_denylisted_key_instead_of_skipping_it', ewpa_is_error( $result, 'blocked_key' ) );
ewpa_check( 'update_cpt_item_does_not_update_the_post_when_a_meta_key_is_refused', 'Post 111' === $GLOBALS['ewpa_posts'][111]->post_title );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'meta'    => array( 'subtitle' => 'New' ),
	)
);
ewpa_check( 'update_cpt_item_still_writes_unprotected_meta', ! is_wp_error( $result ) && 'New' === $GLOBALS['ewpa_meta'][111]['subtitle'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Admin course',
		'meta'      => array( 'subtitle' => 'A' ),
	)
);
ewpa_check( 'admin_create_cpt_item_writes_unprotected_meta', ! is_wp_error( $result ) && 'A' === ( $GLOBALS['ewpa_meta'][500]['subtitle'] ?? '' ) );
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'meta'    => array( 'subtitle' => 'B' ),
	)
);
ewpa_check( 'admin_update_cpt_item_writes_unprotected_meta', ! is_wp_error( $result ) && 'B' === $GLOBALS['ewpa_meta'][111]['subtitle'] );
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'meta'    => array( '_internal_secret' => 'C' ),
	)
);
ewpa_check( 'admin_update_cpt_item_writes_a_protected_key_without_a_site_grant', ! is_wp_error( $result ) && 'C' === $GLOBALS['ewpa_meta'][111]['_internal_secret'] );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type' => 'courses',
		'title'     => 'Admin course',
		'meta'      => array( '_internal_secret' => 'D' ),
	)
);
ewpa_check( 'admin_create_cpt_item_writes_a_protected_key_without_a_site_grant', ! is_wp_error( $result ) && 'D' === ( $GLOBALS['ewpa_meta'][500]['_internal_secret'] ?? '' ) );
ewpa_check( 'admin_create_cpt_item_keeps_the_new_post', isset( $GLOBALS['ewpa_posts'][500] ) && array() === $GLOBALS['ewpa_deleted'] );

// A Contributor on their own item still needs the site's grant.
ewpa_reset();
$GLOBALS['ewpa_caps'][] = '_internal_secret';
$result                 = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id' => 111,
		'meta'    => array( '_internal_secret' => 'E' ),
	)
);
ewpa_check( 'contributor_update_cpt_item_writes_a_protected_key_the_site_authorized', ! is_wp_error( $result ) && 'E' === $GLOBALS['ewpa_meta'][111]['_internal_secret'] );

/*
 * ==========================================================================
 * T3.3 — third-party denylist must not drop entries that were not offered
 * ==========================================================================
 */

ewpa_check( 'tp_merge_functions_exist', function_exists( 'ewpa_tp_merge_disabled' ) && function_exists( 'ewpa_tp_save_submission' ) );

if ( function_exists( 'ewpa_tp_merge_disabled' ) ) {
	$merged = ewpa_tp_merge_disabled( array( 'a/x', 'b/y' ), array( 'a/x' ), array( 'a/x' ) );
	ewpa_check( 'tp_merge_keeps_a_disabled_entry_that_was_not_offered', in_array( 'b/y', $merged, true ) );
	ewpa_check( 'tp_merge_re_enables_an_offered_entry_that_was_checked', ! in_array( 'a/x', $merged, true ) );

	$merged = ewpa_tp_merge_disabled( array(), array( 'a/x', 'a/z' ), array( 'a/x' ) );
	ewpa_check( 'tp_merge_disables_an_offered_entry_that_was_unchecked', array( 'a/z' ) === $merged );

	$merged = ewpa_tp_merge_disabled( array( 'a/z' ), array( 'a/z' ), array( 'a/z' ) );
	ewpa_check( 'tp_merge_empties_the_list_when_everything_offered_is_checked', array() === $merged );

	$merged = ewpa_tp_merge_disabled( array( 'a/z', 'a/z' ), array( 'a/z' ), array() );
	ewpa_check( 'tp_merge_does_not_duplicate_entries', array( 'a/z' ) === $merged );
}

if ( function_exists( 'ewpa_tp_save_submission' ) ) {
	$tp_info = array(
		'label'    => 'Label',
		'desc'     => '',
		'category' => 'c',
	);

	// Disabled earlier, then absent from the snapshot (its plugin was inactive when the page rendered).
	ewpa_reset();
	$GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] = array( 'gone/ability' );
	$GLOBALS['ewpa_options']['ewpa_thirdparty_seen']     = array( 'a/x' => $tp_info );
	ewpa_tp_save_submission( array( 'a/x' ), array( 'a/x' ) );
	ewpa_check( 'tp_save_keeps_a_disabled_ability_absent_from_the_snapshot', array( 'gone/ability' ) === $GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] );

	ewpa_reset();
	$GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] = array( 'a/x' );
	$GLOBALS['ewpa_options']['ewpa_thirdparty_seen']     = array();
	ewpa_tp_save_submission( array(), array() );
	ewpa_check( 'tp_save_keeps_the_denylist_when_the_snapshot_is_empty', array( 'a/x' ) === $GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] );

	ewpa_reset();
	$GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] = array( 'a/x' );
	$GLOBALS['ewpa_options']['ewpa_thirdparty_seen']     = array(
		'a/x' => $tp_info,
		'a/y' => $tp_info,
	);
	ewpa_tp_save_submission( null, array( 'a/x' ) );
	ewpa_check( 'tp_save_without_an_offered_list_falls_back_to_the_snapshot', array( 'a/y' ) === $GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] );

	// The form marker is present but no third-party plugin was active, so the form offered
	// nothing and the submission decides nothing. Without the marker this reached the null
	// path instead and disabled every ability in the snapshot.
	ewpa_reset();
	$GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] = array( 'a/x' );
	$GLOBALS['ewpa_options']['ewpa_thirdparty_seen']     = array(
		'a/x' => $tp_info,
		'a/y' => $tp_info,
	);
	ewpa_tp_save_submission( array(), array() );
	ewpa_check( 'tp_save_with_an_empty_offered_list_leaves_the_denylist_untouched', array( 'a/x' ) === $GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'] );
	ewpa_check( 'tp_save_with_an_empty_offered_list_does_not_disable_the_snapshot', ! in_array( 'a/y', $GLOBALS['ewpa_options']['ewpa_thirdparty_disabled'], true ) );
}

/*
 * ==========================================================================
 * T3.4 — creating a term by name needs edit_terms
 * ==========================================================================
 */

ewpa_reset();
$result = ewpa_callback( 'ewpa/assign-post-terms' )(
	array(
		'post_id'  => 102,
		'taxonomy' => 'genre',
		'terms'    => array( 'brand-new' ),
	)
);
ewpa_check( 'assign_post_terms_refuses_to_create_a_term_without_edit_terms', ewpa_is_error( $result, 'forbidden' ) );
ewpa_check( 'assign_post_terms_error_names_the_term', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'brand-new' ) );
ewpa_check( 'assign_post_terms_assigns_nothing_when_a_term_would_be_created', array() === $GLOBALS['ewpa_set_terms'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/assign-post-terms' )(
	array(
		'post_id'  => 102,
		'taxonomy' => 'genre',
		'terms'    => array( 'drama', 'brand-new' ),
	)
);
ewpa_check( 'assign_post_terms_refuses_the_whole_request_when_one_term_is_new', ewpa_is_error( $result, 'forbidden' ) && array() === $GLOBALS['ewpa_set_terms'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/assign-post-terms' )(
	array(
		'post_id'  => 102,
		'taxonomy' => 'genre',
		'terms'    => array( 'drama', 7 ),
	)
);
ewpa_check( 'assign_post_terms_still_assigns_existing_terms_with_assign_terms_only', ! is_wp_error( $result ) && 1 === count( $GLOBALS['ewpa_set_terms'] ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/assign-post-terms' )(
	array(
		'post_id'  => 102,
		'taxonomy' => 'genre',
		'terms'    => array( 'brand-new' ),
	)
);
ewpa_check( 'admin_assign_post_terms_creates_a_new_term', ! is_wp_error( $result ) && array( 'brand-new' ) === ( $GLOBALS['ewpa_set_terms'][0][1] ?? null ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/assign-cpt-terms' )(
	array(
		'post_id'  => 111,
		'taxonomy' => 'genre',
		'terms'    => array( 'brand-new' ),
	)
);
ewpa_check( 'assign_cpt_terms_refuses_to_create_a_term_without_edit_terms', ewpa_is_error( $result, 'forbidden' ) && array() === $GLOBALS['ewpa_set_terms'] );
ewpa_check( 'assign_cpt_terms_error_names_the_term', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'brand-new' ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/assign-cpt-terms' )(
	array(
		'post_id'  => 111,
		'taxonomy' => 'genre',
		'terms'    => array( 'drama' ),
	)
);
ewpa_check( 'assign_cpt_terms_still_assigns_existing_terms_with_assign_terms_only', ! is_wp_error( $result ) && 1 === count( $GLOBALS['ewpa_set_terms'] ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/assign-cpt-terms' )(
	array(
		'post_id'  => 111,
		'taxonomy' => 'genre',
		'terms'    => array( 'brand-new' ),
	)
);
ewpa_check( 'admin_assign_cpt_terms_creates_a_new_term', ! is_wp_error( $result ) && array( 'brand-new' ) === ( $GLOBALS['ewpa_set_terms'][0][1] ?? null ) );

ewpa_reset();
$result = ewpa_callback( 'ewpa/create-cpt-item' )(
	array(
		'post_type'  => 'courses',
		'title'      => 'Course',
		'taxonomies' => array( 'genre' => array( 'brand-new' ) ),
	)
);
ewpa_check( 'create_cpt_item_refuses_to_create_a_term_without_edit_terms', ewpa_is_error( $result, 'forbidden' ) && array() === $GLOBALS['ewpa_inserted'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'    => 111,
		'taxonomies' => array( 'genre' => array( 'brand-new' ) ),
	)
);
ewpa_check( 'update_cpt_item_refuses_to_create_a_term_without_edit_terms', ewpa_is_error( $result, 'forbidden' ) && array() === $GLOBALS['ewpa_set_terms'] );

ewpa_reset();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'    => 111,
		'taxonomies' => array( 'genre' => array( 'drama' ) ),
	)
);
ewpa_check( 'update_cpt_item_still_assigns_existing_terms', ! is_wp_error( $result ) && 1 === count( $GLOBALS['ewpa_set_terms'] ) );

ewpa_reset();
ewpa_become_admin();
$result = ewpa_callback( 'ewpa/update-cpt-item' )(
	array(
		'post_id'    => 111,
		'taxonomies' => array( 'genre' => array( 'brand-new' ) ),
	)
);
ewpa_check( 'admin_update_cpt_item_creates_a_new_term', ! is_wp_error( $result ) && 1 === count( $GLOBALS['ewpa_set_terms'] ) );

echo PHP_EOL;
if ( $ewpa_failures > 0 ) {
	echo $ewpa_failures . ' check(s) FAILED out of ' . $ewpa_checks . '.' . PHP_EOL;
	exit( 1 );
}
echo $ewpa_checks . ' check(s) passed.' . PHP_EOL;
