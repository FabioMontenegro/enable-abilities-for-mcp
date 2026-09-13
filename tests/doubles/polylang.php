<?php
/**
 * Polylang public API doubles.
 *
 * @package EnableAbilitiesForMCP
 */

function pll_set_post_language( $post_id, $language ) {
	$GLOBALS['ewpa_pll_post_languages'][ $post_id ] = $language;
}

function pll_get_post_language( $post_id, $field = 'slug' ) {
	// Polylang returns false for a post that has no language yet.
	return $GLOBALS['ewpa_pll_post_languages'][ $post_id ] ?? false;
}

function pll_get_post_translations( $post_id ) {
	return $GLOBALS['ewpa_pll_translations'] ?? array( 'en' => 10 );
}

function pll_save_post_translations( $translations ) {
	if ( ! empty( $GLOBALS['ewpa_pll_refuse_save'] ) ) {
		return array();
	}

	// PLL_Translated_Object::validate_translations() silently drops every entry
	// whose object does not already carry that language.
	$translations = array_filter(
		$translations,
		static function ( $post_id, $lang ) {
			return ( $GLOBALS['ewpa_pll_post_languages'][ $post_id ] ?? null ) === $lang;
		},
		ARRAY_FILTER_USE_BOTH
	);

	$GLOBALS['ewpa_pll_translations'] = $translations;

	return $translations;
}

function pll_set_term_language( $term_id, $language ) {
	$GLOBALS['ewpa_pll_term_languages'][ $term_id ] = $language;
}

function pll_get_term_language( $term_id, $field = 'slug' ) {
	return $GLOBALS['ewpa_pll_term_languages'][ $term_id ] ?? '';
}

function pll_get_term_translations( $term_id ) {
	return $GLOBALS['ewpa_pll_term_translations'] ?? array();
}

function pll_save_term_translations( $translations ) {
	if ( ! empty( $GLOBALS['ewpa_pll_refuse_save'] ) ) {
		return array();
	}

	// Same validation as for posts: a term without that language is dropped.
	$translations = array_filter(
		$translations,
		static function ( $term_id, $lang ) {
			return ( $GLOBALS['ewpa_pll_term_languages'][ $term_id ] ?? null ) === $lang;
		},
		ARRAY_FILTER_USE_BOTH
	);

	$GLOBALS['ewpa_pll_term_translations'] = $translations;

	return $translations;
}

function pll_languages_list( $args = array() ) {
	$languages = array(
		'slug'    => array( 'en', 'it' ),
		'name'    => array( 'English', 'Italiano' ),
		'locale'  => array( 'en_US', 'it_IT' ),
		'term_id' => array( 201, 202 ),
	);

	return $languages[ $args['fields'] ?? 'slug' ] ?? $languages['slug'];
}

function pll_get_term( $term_id, $language ) {
	return 0;
}
