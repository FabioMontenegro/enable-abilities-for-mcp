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

function get_object_taxonomies( $post_type ) {
	return array();
}

function wp_get_object_terms( ...$args ) {
	return array();
}

function wp_set_object_terms( ...$args ) {
	return array();
}

function taxonomy_exists( $taxonomy ) {
	return false;
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
		'upload_files',
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
ewpa_check( 'get_cpt_items_whitelists_the_status', 'publish' === ( ewpa_last_get_posts_args()['post_status'] ?? '' ) );

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

echo PHP_EOL;
if ( $ewpa_failures > 0 ) {
	echo $ewpa_failures . ' check(s) FAILED out of ' . $ewpa_checks . '.' . PHP_EOL;
	exit( 1 );
}
echo $ewpa_checks . ' check(s) passed.' . PHP_EOL;
