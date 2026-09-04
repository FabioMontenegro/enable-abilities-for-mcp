<?php
/**
 * Linguator public API doubles.
 *
 * Mirrors `includes/api/language-api.php` of the Linguator plugin, which is
 * identical in both releases (slug `translate-words` and slug
 * `linguator-multilingual-ai-translation`).
 *
 * @package EnableAbilitiesForMCP
 */

function LMAT() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	return $GLOBALS['ewpa_linguator_runtime'] ?? null;
}

function linguator_languages_list( $args = array() ) {
	$linguator = LMAT();
	if ( ! is_object( $linguator ) || ! isset( $linguator->model ) ) {
		return array();
	}

	$languages = $linguator->model->get_languages_list();

	if ( ! empty( $args['fields'] ) ) {
		return wp_list_pluck( $languages, $args['fields'] );
	}

	return $languages;
}

function linguator_set_post_language( $post_id, $language ) {
	$slug = is_object( $language ) ? $language->get_prop( 'slug' ) : $language;
	if ( ! isset( $GLOBALS['ewpa_linguator_languages'][ $slug ] ) ) {
		return false;
	}
	if ( ( $GLOBALS['ewpa_linguator_post_languages'][ $post_id ] ?? '' ) === $slug ) {
		return false; // Already assigned, as in the real API.
	}
	$GLOBALS['ewpa_linguator_post_languages'][ $post_id ] = $slug;

	return true;
}

function linguator_get_post_language( $post_id, $field = 'slug' ) {
	return $GLOBALS['ewpa_linguator_post_languages'][ $post_id ] ?? false;
}

function linguator_get_post_translations( $post_id ) {
	return $GLOBALS['ewpa_linguator_translations'];
}

function linguator_save_post_translations( $translations ) {
	$GLOBALS['ewpa_linguator_translations'] = $translations;

	return $translations;
}

function linguator_set_term_language( $term_id, $language ) {
	$slug = is_object( $language ) ? $language->get_prop( 'slug' ) : $language;
	if ( ! isset( $GLOBALS['ewpa_linguator_languages'][ $slug ] ) ) {
		return false;
	}
	if ( ( $GLOBALS['ewpa_linguator_term_languages'][ $term_id ] ?? '' ) === $slug ) {
		return false;
	}
	$GLOBALS['ewpa_linguator_term_languages'][ $term_id ] = $slug;

	return true;
}

function linguator_get_term_language( $term_id, $field = 'slug' ) {
	return $GLOBALS['ewpa_linguator_term_languages'][ $term_id ] ?? false;
}

function linguator_get_term_translations( $term_id ) {
	return $GLOBALS['ewpa_linguator_term_translations'];
}

function linguator_save_term_translations( $translations ) {
	$GLOBALS['ewpa_linguator_term_translations'] = $translations;

	return $translations;
}

function linguator_insert_term( $name, $taxonomy, $language, $args = array() ) {
	if ( ! isset( $GLOBALS['ewpa_linguator_languages'][ $language ] ) ) {
		return new WP_Error( 'invalid_language', 'Please provide a valid language.' );
	}

	$term_id = ewpa_test_store_term( $name, $taxonomy, $args );

	$GLOBALS['ewpa_inserted_terms'][] = array(
		'via'      => 'linguator_insert_term',
		'name'     => $name,
		'taxonomy' => $taxonomy,
		'language' => $language,
		'args'     => $args,
	);

	$GLOBALS['ewpa_linguator_term_languages'][ $term_id ] = $language;

	$translations = $args['translations'] ?? array();
	$translations[ $language ] = $term_id;
	$GLOBALS['ewpa_linguator_term_translations'] = $translations;

	return array(
		'term_id'          => $term_id,
		'term_taxonomy_id' => $term_id,
	);
}

function linguator_update_term( $term_id, $args = array() ) {
	$GLOBALS['ewpa_updated_terms'][] = array(
		'via'     => 'linguator_update_term',
		'term_id' => $term_id,
		'args'    => $args,
	);

	return array(
		'term_id'          => $term_id,
		'term_taxonomy_id' => $term_id,
	);
}

function linguator_get_term( $term_id, $lang = '' ) {
	return 0;
}
