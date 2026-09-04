<?php
/**
 * Lightweight tests for the multilanguage backend adapter.
 *
 * Every case runs in its own PHP process so that conditionally declared
 * functions and constants (pll_*, ICL_SITEPRESS_VERSION, linguator_*) can model
 * one active backend at a time.
 *
 * Run with: php tests/multilanguage-backend-test.php
 *
 * @package EnableAbilitiesForMCP
 */

if ( isset( $argv[1] ) && '--case' === $argv[1] ) {
	define( 'EWPA_TESTING', true );
	ewpa_bootstrap_case( $argv[2] ?? '' );
	require dirname( __DIR__ ) . '/includes/multilanguage.php';
	ewpa_run_case( $argv[2] ?? '' );
	exit( 0 );
}

$cases = array(
	'detects_none_when_no_backend_is_available',
	'detects_polylang',
	'detects_wpml',
	'detects_linguator',
	'detects_linguator_from_global_model_only',
	'keeps_polylang_precedence_over_linguator',
	'keeps_wpml_precedence_over_linguator',
	'lists_linguator_languages',
	'lists_linguator_languages_from_global_model_only',
	'lists_wpml_languages',
	'sets_linguator_post_language',
	'gets_linguator_post_language',
	'links_linguator_post_translation',
	'gets_linguator_post_translations',
	'rejects_linguator_invalid_language',
	'rejects_linguator_missing_post',
	'rejects_linguator_when_runtime_disappears',
	'creates_linguator_translation_via_sync_model',
	'creates_linguator_translation_with_requested_status',
	'creates_translation_by_duplicating_when_sync_is_unavailable',
	'updates_existing_translation_instead_of_duplicating',
	'rejects_translation_when_source_has_no_language',
	'rejects_translation_into_the_same_language',
	'rejects_translation_into_unknown_language',
	'creates_polylang_translation_by_duplicating',
	'sets_linguator_term_language',
	'gets_linguator_term_translations',
	'links_linguator_term_translation',
	'creates_linguator_term_translation',
	'updates_existing_linguator_term_translation',
	'rejects_term_translation_when_source_has_no_language',
	'rejects_term_link_across_taxonomies',
	'creates_polylang_term_translation_by_duplicating',
);

$failures = 0;
foreach ( $cases as $case ) {
	$output = array();
	$cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --case ' . escapeshellarg( $case );
	exec( $cmd . ' 2>&1', $output, $code );
	if ( 0 === $code ) {
		echo 'PASS ' . $case . PHP_EOL;
	} else {
		++$failures;
		echo 'FAIL ' . $case . PHP_EOL;
		echo implode( PHP_EOL, $output ) . PHP_EOL;
	}
}

if ( $failures ) {
	echo $failures . ' test(s) failed.' . PHP_EOL;
	exit( 1 );
}

echo count( $cases ) . ' test(s) passed.' . PHP_EOL;

/**
 * Tells whether a case needs the Linguator public API doubles.
 *
 * @param string $case Test case name.
 * @return bool
 */
function ewpa_case_needs_linguator_api( string $case ): bool {
	if ( false !== strpos( $case, 'global_model_only' ) ) {
		return false;
	}
	if ( false !== strpos( $case, 'linguator' ) ) {
		return true;
	}

	return in_array(
		$case,
		array(
			'creates_translation_by_duplicating_when_sync_is_unavailable',
			'updates_existing_translation_instead_of_duplicating',
			'rejects_translation_when_source_has_no_language',
			'rejects_translation_into_the_same_language',
			'rejects_translation_into_unknown_language',
			'rejects_term_translation_when_source_has_no_language',
			'rejects_term_link_across_taxonomies',
		),
		true
	);
}

/**
 * Boots test doubles for a scenario.
 *
 * @param string $case Test case name.
 * @return void
 */
function ewpa_bootstrap_case( string $case ): void {
	$GLOBALS['ewpa_posts'] = array(
		10 => (object) array(
			'ID'             => 10,
			'post_title'     => 'Hello',
			'post_content'   => '<p>Hello world</p>',
			'post_excerpt'   => 'Hi',
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'post_author'    => 1,
			'post_name'      => 'hello',
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'menu_order'     => 0,
			'post_parent'    => 0,
		),
		20 => (object) array(
			'ID'             => 20,
			'post_title'     => 'Ciao',
			'post_content'   => '<p>Ciao mondo</p>',
			'post_excerpt'   => '',
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'post_author'    => 1,
			'post_name'      => 'ciao',
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'menu_order'     => 0,
			'post_parent'    => 0,
		),
	);
	$GLOBALS['ewpa_linguator_languages'] = array(
		'en' => new Ewpa_Fake_Language( 'en', 'en_US', 'English', 101 ),
		'it' => new Ewpa_Fake_Language( 'it', 'it_IT', 'Italiano', 102 ),
	);
	$GLOBALS['ewpa_linguator_post_languages'] = array( 10 => 'en' );
	$GLOBALS['ewpa_linguator_translations']   = array( 'en' => 10 );
	$GLOBALS['ewpa_terms'] = array(
		5 => (object) array(
			'term_id'     => 5,
			'name'        => 'News',
			'slug'        => 'news',
			'description' => 'Latest news',
			'taxonomy'    => 'category',
			'parent'      => 0,
			'count'       => 3,
		),
		6 => (object) array(
			'term_id'     => 6,
			'name'        => 'Notizie',
			'slug'        => 'notizie',
			'description' => '',
			'taxonomy'    => 'category',
			'parent'      => 0,
			'count'       => 0,
		),
		7 => (object) array(
			'term_id'     => 7,
			'name'        => 'Featured',
			'slug'        => 'featured',
			'description' => '',
			'taxonomy'    => 'post_tag',
			'parent'      => 0,
			'count'       => 1,
		),
	);
	$GLOBALS['ewpa_linguator_term_languages'] = array( 5 => 'en' );
	$GLOBALS['ewpa_linguator_term_translations'] = array( 'en' => 5 );
	$GLOBALS['ewpa_pll_term_languages']       = array( 5 => 'en' );
	$GLOBALS['ewpa_pll_term_translations']    = array( 'en' => 5 );
	$GLOBALS['ewpa_inserted_terms']           = array();
	$GLOBALS['ewpa_updated_terms']            = array();
	$GLOBALS['ewpa_next_term_id']             = 40;
	$GLOBALS['ewpa_inserted_posts']           = array();
	$GLOBALS['ewpa_updated_posts']            = array();
	$GLOBALS['ewpa_copied_posts']             = array();
	$GLOBALS['ewpa_next_post_id']             = 30;

	if ( in_array( $case, array( 'detects_polylang', 'keeps_polylang_precedence_over_linguator', 'creates_polylang_translation_by_duplicating', 'creates_polylang_term_translation_by_duplicating' ), true ) ) {
		require_once __DIR__ . '/doubles/polylang.php';
	}

	if ( in_array( $case, array( 'detects_wpml', 'keeps_wpml_precedence_over_linguator', 'lists_wpml_languages' ), true ) ) {
		define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
	}

	if ( false !== strpos( $case, 'global_model_only' ) ) {
		$GLOBALS['linguator'] = (object) array( 'model' => new Ewpa_Fake_Linguator_Model() );
	}

	if ( ewpa_case_needs_linguator_api( $case ) ) {
		require_once __DIR__ . '/doubles/linguator.php';

		$runtime = (object) array( 'model' => new Ewpa_Fake_Linguator_Model() );

		// The sync module is only loaded by Linguator when languages exist.
		if ( ! in_array( $case, array( 'creates_translation_by_duplicating_when_sync_is_unavailable' ), true ) ) {
			$runtime->sync = (object) array( 'taxonomies' => null );
		}

		$GLOBALS['ewpa_linguator_runtime'] = $runtime;
	}

	if ( 'rejects_linguator_when_runtime_disappears' === $case ) {
		$GLOBALS['ewpa_linguator_runtime'] = null;
	}

	if ( 'rejects_translation_when_source_has_no_language' === $case ) {
		$GLOBALS['ewpa_linguator_post_languages'] = array();
	}

	if ( 'updates_existing_translation_instead_of_duplicating' === $case ) {
		$GLOBALS['ewpa_linguator_translations'] = array(
			'en' => 10,
			'it' => 20,
		);
	}

	if ( 'rejects_term_translation_when_source_has_no_language' === $case ) {
		$GLOBALS['ewpa_linguator_term_languages'] = array();
	}

	if ( 'updates_existing_linguator_term_translation' === $case ) {
		$GLOBALS['ewpa_linguator_term_translations'] = array(
			'en' => 5,
			'it' => 6,
		);
	}
}

/**
 * Runs one named test case.
 *
 * @param string $case Test case name.
 * @return void
 */
function ewpa_run_case( string $case ): void {
	switch ( $case ) {
		case 'detects_none_when_no_backend_is_available':
			ewpa_assert_same( '', ewpa_get_translation_plugin() );
			break;
		case 'detects_polylang':
			ewpa_assert_same( 'polylang', ewpa_get_translation_plugin() );
			break;
		case 'detects_wpml':
			ewpa_assert_same( 'wpml', ewpa_get_translation_plugin() );
			break;
		case 'detects_linguator':
			// Regression: the real Linguator_Model exposes get_languages_list()
			// through __call(), so method_exists() misses it and the backend used
			// to be reported as "no multilanguage plugin detected".
			ewpa_assert_true( ! method_exists( ewpa_get_linguator_model(), 'get_languages_list' ) );
			ewpa_assert_same( 'linguator', ewpa_get_translation_plugin() );
			break;
		case 'detects_linguator_from_global_model_only':
			ewpa_assert_same( 'linguator', ewpa_get_translation_plugin() );
			break;
		case 'keeps_polylang_precedence_over_linguator':
			ewpa_assert_same( 'polylang', ewpa_get_translation_plugin() );
			break;
		case 'keeps_wpml_precedence_over_linguator':
			ewpa_assert_same( 'wpml', ewpa_get_translation_plugin() );
			break;
		case 'lists_linguator_languages':
			$languages = ewpa_multilanguage_list_languages();
			ewpa_assert_same( 'en', $languages[0]['slug'] );
			ewpa_assert_same( 'en_US', $languages[0]['locale'] );
			ewpa_assert_same( 101, $languages[0]['term_id'] );
			break;
		case 'lists_linguator_languages_from_global_model_only':
			$languages = ewpa_multilanguage_list_languages();
			ewpa_assert_same( 'en', $languages[0]['slug'] );
			ewpa_assert_same( 101, $languages[0]['term_id'] );
			break;
		case 'lists_wpml_languages':
			$languages = ewpa_multilanguage_list_languages();
			ewpa_assert_same( 'en', $languages[0]['slug'] );
			ewpa_assert_same( 'English', $languages[0]['name'] );
			break;
		case 'sets_linguator_post_language':
			$result = ewpa_multilanguage_set_post_language( 20, 'it' );
			ewpa_assert_same( 'it', $result['language'] );
			ewpa_assert_same( 'it', $GLOBALS['ewpa_linguator_post_languages'][20] );
			break;
		case 'gets_linguator_post_language':
			ewpa_assert_same( 'en', ewpa_multilanguage_get_post_language( 10 ) );
			break;
		case 'links_linguator_post_translation':
			$result = ewpa_multilanguage_link_post_translation( 10, 20, 'it' );
			ewpa_assert_same( 'linguator', $result['plugin'] );
			ewpa_assert_same( 20, $GLOBALS['ewpa_linguator_translations']['it'] );
			break;
		case 'gets_linguator_post_translations':
			$GLOBALS['ewpa_linguator_translations'] = array(
				'en' => 10,
				'it' => 20,
			);
			$result = ewpa_multilanguage_get_post_translations( 10 );
			ewpa_assert_same( 'linguator', $result['plugin'] );
			ewpa_assert_same( 'en', $result['language'] );
			ewpa_assert_same( 2, count( $result['translations'] ) );
			break;
		case 'rejects_linguator_invalid_language':
			$result = ewpa_multilanguage_set_post_language( 10, 'zz' );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'invalid_language', $result->get_error_code() );
			break;
		case 'rejects_linguator_missing_post':
			$result = ewpa_multilanguage_set_post_language( 999, 'it' );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'not_found', $result->get_error_code() );
			break;
		case 'rejects_linguator_when_runtime_disappears':
			ewpa_assert_same( '', ewpa_get_translation_plugin() );
			break;
		case 'creates_linguator_translation_via_sync_model':
			$result = ewpa_multilanguage_create_post_translation(
				10,
				'it',
				array(
					'title'   => 'Ciao mondo',
					'content' => '<p>Ciao mondo</p>',
				)
			);
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'linguator', $result['plugin'] );
			ewpa_assert_same( true, $result['created'] );
			ewpa_assert_same( 30, $result['post_id'] );
			// The plugin's own duplication engine must be the one doing the work.
			ewpa_assert_same( 1, count( $GLOBALS['ewpa_copied_posts'] ) );
			$copy = $GLOBALS['ewpa_copied_posts'][0];
			ewpa_assert_same( 'en', $copy['source_language'] );
			ewpa_assert_same( 'it', $copy['target_language'] );
			ewpa_assert_same( 'Ciao mondo', $copy['post_data']['post_title'] );
			ewpa_assert_same( 0, count( $GLOBALS['ewpa_inserted_posts'] ) );
			break;
		case 'creates_linguator_translation_with_requested_status':
			$result = ewpa_multilanguage_create_post_translation(
				10,
				'it',
				array(
					'title'  => 'Ciao mondo',
					'status' => 'publish',
				)
			);
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			// copy_post() forces its own status, so the adapter reapplies ours.
			ewpa_assert_true( ! isset( $GLOBALS['ewpa_copied_posts'][0]['post_data']['post_status'] ) );
			ewpa_assert_same( 'publish', $GLOBALS['ewpa_updated_posts'][0]['post_status'] );
			ewpa_assert_same( 'publish', $result['status'] );
			break;
		case 'creates_translation_by_duplicating_when_sync_is_unavailable':
			$result = ewpa_multilanguage_create_post_translation(
				10,
				'it',
				array(
					'title'   => 'Ciao mondo',
					'content' => '<p>Ciao mondo</p>',
				)
			);
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( true, $result['created'] );
			ewpa_assert_same( 0, count( $GLOBALS['ewpa_copied_posts'] ) );
			ewpa_assert_same( 1, count( $GLOBALS['ewpa_inserted_posts'] ) );
			ewpa_assert_same( 'Ciao mondo', $GLOBALS['ewpa_inserted_posts'][0]['post_title'] );
			ewpa_assert_same( 'draft', $GLOBALS['ewpa_inserted_posts'][0]['post_status'] );
			ewpa_assert_same( $result['post_id'], $GLOBALS['ewpa_linguator_translations']['it'] );
			break;
		case 'updates_existing_translation_instead_of_duplicating':
			$result = ewpa_multilanguage_create_post_translation( 10, 'it', array( 'title' => 'Ciao mondo' ) );
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( false, $result['created'] );
			ewpa_assert_same( 20, $result['post_id'] );
			ewpa_assert_same( 0, count( $GLOBALS['ewpa_copied_posts'] ) );
			ewpa_assert_same( 0, count( $GLOBALS['ewpa_inserted_posts'] ) );
			ewpa_assert_same( 'Ciao mondo', $GLOBALS['ewpa_updated_posts'][0]['post_title'] );
			break;
		case 'rejects_translation_when_source_has_no_language':
			$result = ewpa_multilanguage_create_post_translation( 10, 'it', array( 'title' => 'Ciao' ) );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'source_language_missing', $result->get_error_code() );
			break;
		case 'rejects_translation_into_the_same_language':
			$result = ewpa_multilanguage_create_post_translation( 10, 'en', array( 'title' => 'Hello' ) );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'same_language', $result->get_error_code() );
			break;
		case 'rejects_translation_into_unknown_language':
			$result = ewpa_multilanguage_create_post_translation( 10, 'zz', array( 'title' => 'Hello' ) );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'invalid_language', $result->get_error_code() );
			break;
		case 'creates_polylang_translation_by_duplicating':
			$result = ewpa_multilanguage_create_post_translation(
				10,
				'it',
				array(
					'title'  => 'Ciao mondo',
					'status' => 'publish',
				)
			);
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'polylang', $result['plugin'] );
			ewpa_assert_same( 'Ciao mondo', $GLOBALS['ewpa_inserted_posts'][0]['post_title'] );
			ewpa_assert_same( 'publish', $GLOBALS['ewpa_inserted_posts'][0]['post_status'] );
			// Source content is kept when no translation is supplied for a field.
			ewpa_assert_same( '<p>Hello world</p>', $GLOBALS['ewpa_inserted_posts'][0]['post_content'] );
			ewpa_assert_same( $result['post_id'], $GLOBALS['ewpa_pll_translations']['it'] );
			break;
		case 'sets_linguator_term_language':
			$result = ewpa_multilanguage_set_term_language( 6, 'it' );
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'it', $result['language'] );
			ewpa_assert_same( 'category', $result['taxonomy'] );
			ewpa_assert_same( 'it', $GLOBALS['ewpa_linguator_term_languages'][6] );
			break;
		case 'gets_linguator_term_translations':
			$GLOBALS['ewpa_linguator_term_translations'] = array(
				'en' => 5,
				'it' => 6,
			);
			$result = ewpa_multilanguage_get_term_translations( 5 );
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'linguator', $result['plugin'] );
			ewpa_assert_same( 'category', $result['taxonomy'] );
			ewpa_assert_same( 'en', $result['language'] );
			ewpa_assert_same( 2, count( $result['translations'] ) );
			ewpa_assert_same( 'Notizie', $result['translations'][1]['name'] );
			break;
		case 'links_linguator_term_translation':
			$result = ewpa_multilanguage_link_term_translation( 5, 6, 'it' );
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'linguator', $result['plugin'] );
			ewpa_assert_same( 6, $GLOBALS['ewpa_linguator_term_translations']['it'] );
			ewpa_assert_same( 'it', $GLOBALS['ewpa_linguator_term_languages'][6] );
			break;
		case 'creates_linguator_term_translation':
			$result = ewpa_multilanguage_create_term_translation(
				5,
				'it',
				array(
					'name'        => 'Notizie',
					'description' => 'Ultime notizie',
				)
			);
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'linguator', $result['plugin'] );
			ewpa_assert_same( true, $result['created'] );
			ewpa_assert_same( 'category', $result['taxonomy'] );
			// Linguator's own API must be the one creating the term.
			ewpa_assert_same( 1, count( $GLOBALS['ewpa_inserted_terms'] ) );
			$insert = $GLOBALS['ewpa_inserted_terms'][0];
			ewpa_assert_same( 'linguator_insert_term', $insert['via'] );
			ewpa_assert_same( 'Notizie', $insert['name'] );
			ewpa_assert_same( 'notizie', $insert['args']['slug'] );
			ewpa_assert_same( 'Ultime notizie', $insert['args']['description'] );
			ewpa_assert_same( 'it', $insert['language'] );
			ewpa_assert_same( $result['term_id'], $GLOBALS['ewpa_linguator_term_translations']['it'] );
			break;
		case 'updates_existing_linguator_term_translation':
			$result = ewpa_multilanguage_create_term_translation( 5, 'it', array( 'name' => 'Notiziario' ) );
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( false, $result['created'] );
			ewpa_assert_same( 6, $result['term_id'] );
			ewpa_assert_same( 0, count( $GLOBALS['ewpa_inserted_terms'] ) );
			ewpa_assert_same( 'Notiziario', $GLOBALS['ewpa_updated_terms'][0]['args']['name'] );
			break;
		case 'rejects_term_translation_when_source_has_no_language':
			$result = ewpa_multilanguage_create_term_translation( 5, 'it', array( 'name' => 'Notizie' ) );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'source_language_missing', $result->get_error_code() );
			break;
		case 'rejects_term_link_across_taxonomies':
			$result = ewpa_multilanguage_link_term_translation( 5, 7, 'it' );
			ewpa_assert_true( $result instanceof WP_Error );
			ewpa_assert_same( 'taxonomy_mismatch', $result->get_error_code() );
			break;
		case 'creates_polylang_term_translation_by_duplicating':
			$result = ewpa_multilanguage_create_term_translation( 5, 'it', array( 'name' => 'Notizie' ) );
			ewpa_assert_true( ! $result instanceof WP_Error, ewpa_describe( $result ) );
			ewpa_assert_same( 'polylang', $result['plugin'] );
			ewpa_assert_same( true, $result['created'] );
			ewpa_assert_same( 1, count( $GLOBALS['ewpa_inserted_terms'] ) );
			ewpa_assert_same( 'wp_insert_term', $GLOBALS['ewpa_inserted_terms'][0]['via'] );
			// No translation supplied for the description: the source one is kept.
			ewpa_assert_same( 'Latest news', $GLOBALS['ewpa_inserted_terms'][0]['args']['description'] );
			ewpa_assert_same( $result['term_id'], $GLOBALS['ewpa_pll_term_translations']['it'] );
			break;
		default:
			throw new RuntimeException( 'Unknown case: ' . $case );
	}
}

/*
 * ==========================================================================
 * WORDPRESS FUNCTION DOUBLES
 * ==========================================================================
 */

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_title( $value ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' );
}

function absint( $value ) {
	return max( 0, (int) $value );
}

function get_post( $post_id ) {
	return $GLOBALS['ewpa_posts'][ $post_id ] ?? null;
}

function get_post_type( $post_id ) {
	$post = get_post( $post_id );
	return $post->post_type ?? 'post';
}

function get_post_status( $post_id ) {
	$post = get_post( $post_id );
	return $post->post_status ?? '';
}

function get_permalink( $post_id ) {
	return 'https://example.test/?p=' . $post_id;
}

function get_edit_post_link( $post_id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
}

function wp_insert_post( $args, $wp_error = false ) {
	$post_id = $GLOBALS['ewpa_next_post_id']++;
	$GLOBALS['ewpa_inserted_posts'][] = $args;
	$GLOBALS['ewpa_posts'][ $post_id ] = (object) array_merge(
		array(
			'ID'        => $post_id,
			'post_type' => 'post',
		),
		$args
	);

	return $post_id;
}

function wp_update_post( $args, $wp_error = false ) {
	$GLOBALS['ewpa_updated_posts'][] = $args;
	$post_id                         = (int) $args['ID'];
	foreach ( $args as $key => $value ) {
		if ( 'ID' !== $key && isset( $GLOBALS['ewpa_posts'][ $post_id ] ) ) {
			$GLOBALS['ewpa_posts'][ $post_id ]->$key = $value;
		}
	}

	return $post_id;
}

function wp_delete_post( $post_id, $force = false ) {
	unset( $GLOBALS['ewpa_posts'][ $post_id ] );
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	return array();
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	return true;
}

function maybe_unserialize( $value ) {
	return $value;
}

function get_object_taxonomies( $post_type ) {
	return array( 'category' );
}

function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
	return array( 7 );
}

function wp_set_object_terms( $post_id, $terms, $taxonomy ) {
	$GLOBALS['ewpa_assigned_terms'][ $taxonomy ] = $terms;
	return $terms;
}

function get_term( $term_id, $taxonomy = '' ) {
	return $GLOBALS['ewpa_terms'][ $term_id ] ?? null;
}

function get_term_link( $term_id, $taxonomy = '' ) {
	return 'https://example.test/?cat=' . ( is_object( $term_id ) ? $term_id->term_id : $term_id );
}

function get_edit_term_link( $term_id, $taxonomy = '' ) {
	return 'https://example.test/wp-admin/term.php?tag_ID=' . $term_id;
}

function get_taxonomy( $taxonomy ) {
	return (object) array( 'cap' => (object) array( 'edit_terms' => 'manage_categories' ) );
}

function get_term_meta( $term_id, $key = '', $single = false ) {
	return array();
}

function add_term_meta( $term_id, $key, $value, $unique = false ) {
	return true;
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function ewpa_test_store_term( $name, $taxonomy, $args ) {
	$term_id = $GLOBALS['ewpa_next_term_id']++;

	$GLOBALS['ewpa_terms'][ $term_id ] = (object) array(
		'term_id'     => $term_id,
		'name'        => $name,
		'slug'        => $args['slug'] ?? '',
		'description' => $args['description'] ?? '',
		'taxonomy'    => $taxonomy,
		'parent'      => $args['parent'] ?? 0,
		'count'       => 0,
	);

	return $term_id;
}

function wp_insert_term( $name, $taxonomy, $args = array() ) {
	$term_id = ewpa_test_store_term( $name, $taxonomy, $args );

	$GLOBALS['ewpa_inserted_terms'][] = array(
		'via'      => 'wp_insert_term',
		'name'     => $name,
		'taxonomy' => $taxonomy,
		'args'     => $args,
	);

	return array( 'term_id' => $term_id );
}

function wp_update_term( $term_id, $taxonomy, $args = array() ) {
	$GLOBALS['ewpa_updated_terms'][] = array(
		'term_id'  => $term_id,
		'taxonomy' => $taxonomy,
		'args'     => $args,
	);

	foreach ( $args as $key => $value ) {
		if ( isset( $GLOBALS['ewpa_terms'][ $term_id ] ) ) {
			$GLOBALS['ewpa_terms'][ $term_id ]->$key = $value;
		}
	}

	return array( 'term_id' => $term_id );
}

function wp_delete_term( $term_id, $taxonomy ) {
	unset( $GLOBALS['ewpa_terms'][ $term_id ] );
	return true;
}

function get_post_thumbnail_id( $post_id ) {
	return 0;
}

function set_post_thumbnail( $post_id, $thumbnail_id ) {
	return true;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		$out[] = is_array( $item ) ? ( $item[ $field ] ?? null ) : ( $item->$field ?? null );
	}
	return $out;
}

function has_filter( $hook ) {
	return 'wpml_active_languages' === $hook;
}

function apply_filters( $hook, $value, ...$args ) {
	if ( 'wpml_active_languages' === $hook ) {
		return array(
			'en' => array(
				'language_code'  => 'en',
				'default_locale' => 'en_US',
				'native_name'    => 'English',
			),
			'it' => array(
				'language_code'  => 'it',
				'default_locale' => 'it_IT',
				'native_name'    => 'Italiano',
			),
		);
	}
	if ( 'wpml_element_language_code' === $hook ) {
		return 'en';
	}
	return $value;
}

function do_action( $hook, ...$args ) {}

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code, string $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

/*
 * ==========================================================================
 * LINGUATOR DOUBLES
 * ==========================================================================
 */

class Ewpa_Fake_Language {
	public string $slug;
	public string $locale;
	public string $name;
	private int $term_id;

	public function __construct( string $slug, string $locale, string $name, int $term_id ) {
		$this->slug    = $slug;
		$this->locale  = $locale;
		$this->name    = $name;
		$this->term_id = $term_id;
	}

	public function get_prop( string $prop ) {
		return $this->$prop ?? null;
	}

	public function get_tax_prop( string $taxonomy, string $prop ): int {
		return ( 'lmat_language' === $taxonomy && 'term_id' === $prop ) ? $this->term_id : 0;
	}
}

/**
 * Mirrors the real Linguator_Model: get_languages_list() and get_language()
 * are reachable only through __call(), never as declared methods.
 */
class Ewpa_Fake_Linguator_Model {
	public function __call( string $name, array $arguments ) {
		switch ( $name ) {
			case 'get_languages_list':
				return array_values( $GLOBALS['ewpa_linguator_languages'] );
			case 'get_language':
				return $GLOBALS['ewpa_linguator_languages'][ $arguments[0] ] ?? null;
			case 'has_languages':
				return ! empty( $GLOBALS['ewpa_linguator_languages'] );
		}

		return null;
	}
}

/**
 * Stands in for the plugin's own duplication engine.
 */
class Linguator_Sync_Post_Model {
	public function __construct( &$linguator ) {}

	public function copy_post( $post_id, $source_language, $target_language, $save_group = true, $post_data = array(), $editor_type = '' ) {
		$new_id = $GLOBALS['ewpa_next_post_id']++;

		$GLOBALS['ewpa_copied_posts'][] = array(
			'post_id'         => $post_id,
			'source_language' => $source_language,
			'target_language' => $target_language,
			'save_group'      => $save_group,
			'post_data'       => $post_data,
			'editor_type'     => $editor_type,
		);

		$source                            = get_post( $post_id );
		$GLOBALS['ewpa_posts'][ $new_id ]  = (object) array_merge(
			(array) $source,
			$post_data,
			array(
				'ID'          => $new_id,
				'post_status' => 'draft',
			)
		);
		$GLOBALS['ewpa_linguator_post_languages'][ $new_id ] = $target_language;
		$GLOBALS['ewpa_linguator_translations'][ $target_language ] = $new_id;

		return $new_id;
	}
}

/*
 * ==========================================================================
 * ASSERTIONS
 * ==========================================================================
 */

function ewpa_describe( $value ): string {
	if ( $value instanceof WP_Error ) {
		return $value->get_error_code() . ': ' . $value->get_error_message();
	}

	return var_export( $value, true );
}

function ewpa_assert_same( $expected, $actual, string $context = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
			. ( '' !== $context ? ' (' . $context . ')' : '' )
		);
	}
}

function ewpa_assert_true( $actual, string $context = '' ): void {
	if ( true !== $actual ) {
		throw new RuntimeException(
			'Expected true, got ' . var_export( $actual, true )
			. ( '' !== $context ? ' (' . $context . ')' : '' )
		);
	}
}
