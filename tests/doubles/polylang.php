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
	return $GLOBALS['ewpa_pll_post_languages'][ $post_id ] ?? 'en';
}

function pll_get_post_translations( $post_id ) {
	return $GLOBALS['ewpa_pll_translations'] ?? array( 'en' => 10 );
}

function pll_save_post_translations( $translations ) {
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
	$GLOBALS['ewpa_pll_term_translations'] = $translations;

	return $translations;
}

function pll_languages_list( $args = array() ) {
	$languages = array(
		'slug'   => array( 'en', 'it' ),
		'name'   => array( 'English', 'Italiano' ),
		'locale' => array( 'en_US', 'it_IT' ),
	);

	return $languages[ $args['fields'] ?? 'slug' ] ?? $languages['slug'];
}

function pll_get_term( $term_id, $language ) {
	return 0;
}
