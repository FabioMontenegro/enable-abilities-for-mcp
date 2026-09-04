<?php
/**
 * Multilanguage backend helpers for Enable Abilities for MCP.
 *
 * Supports Polylang, WPML and Linguator. Both Linguator releases are covered: the
 * "Linguator AI - Auto Translate & Create Multilingual Sites" one (slug `translate-words`)
 * and the "Multilingual AI Translator" one (slug `linguator-multilingual-ai-translation`).
 * They ship an identical public API in `includes/api/language-api.php`, so this adapter
 * targets that API and never writes Linguator taxonomies or translation metadata directly.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'EWPA_TESTING' ) ) {
	exit;
}

/**
 * Sanitizes a language slug/code.
 *
 * @param mixed $language Language input.
 * @return string
 */
function ewpa_sanitize_language_slug( $language ): string {
	return sanitize_key( (string) $language );
}

/**
 * Converts a value to a positive integer-like WordPress ID.
 *
 * @param mixed $value Raw value.
 * @return int
 */
function ewpa_absint( $value ): int {
	return function_exists( 'absint' ) ? absint( $value ) : max( 0, (int) $value );
}

/**
 * Tells whether an object exposes a callable method.
 *
 * Linguator's model routes get_languages_list(), get_language() and friends through
 * __call(), so method_exists() returns false for them. is_callable() accounts for the
 * magic method, which is why it is used everywhere the Linguator runtime is probed.
 *
 * @param mixed  $candidate Candidate object.
 * @param string $method    Method name.
 * @return bool
 */
function ewpa_object_can( $candidate, string $method ): bool {
	return is_object( $candidate ) && is_callable( array( $candidate, $method ) );
}

/**
 * Returns the Linguator runtime instance when available.
 *
 * @return object|null
 */
function ewpa_get_linguator_instance() {
	if ( function_exists( 'LMAT' ) ) {
		$linguator = LMAT();
		if ( is_object( $linguator ) ) {
			return $linguator;
		}
	}

	foreach ( array( 'linguator', 'lmat' ) as $global_key ) {
		if ( isset( $GLOBALS[ $global_key ] ) && is_object( $GLOBALS[ $global_key ] ) ) {
			return $GLOBALS[ $global_key ];
		}
	}

	return null;
}

/**
 * Returns the Linguator model when available.
 *
 * @return object|null
 */
function ewpa_get_linguator_model() {
	$linguator = ewpa_get_linguator_instance();

	return is_object( $linguator ) && isset( $linguator->model ) && is_object( $linguator->model ) ? $linguator->model : null;
}

/**
 * Detects whether Linguator is available with the runtime needed by EWPA.
 *
 * @return bool
 */
function ewpa_is_linguator_available(): bool {
	return ewpa_object_can( ewpa_get_linguator_model(), 'get_languages_list' );
}

/**
 * Detects which multilanguage plugin is active.
 *
 * @return string 'polylang' | 'wpml' | 'linguator' | '' (empty string = none detected)
 */
function ewpa_get_translation_plugin(): string {
	if ( function_exists( 'pll_set_post_language' ) ) {
		return 'polylang';
	}
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return 'wpml';
	}
	if ( ewpa_is_linguator_available() ) {
		return 'linguator';
	}
	return '';
}

/**
 * Returns the human-readable list of supported backends, for error messages.
 *
 * @return string
 */
function ewpa_multilanguage_supported_backends(): string {
	return 'Polylang, WPML, or Linguator AI';
}

/**
 * Builds a multilanguage WP_Error.
 *
 * @param string $code    Error code.
 * @param string $message Error message.
 * @return WP_Error
 */
function ewpa_multilanguage_error( string $code, string $message ) {
	return new WP_Error( $code, $message );
}

/**
 * Builds the "no backend detected" error.
 *
 * @return WP_Error
 */
function ewpa_multilanguage_no_plugin_error() {
	return ewpa_multilanguage_error(
		'no_plugin',
		sprintf( 'No multilanguage plugin detected (%s required).', ewpa_multilanguage_supported_backends() )
	);
}

/**
 * Validates that a post exists.
 *
 * @param int    $post_id Post ID.
 * @param string $label   Human-readable label.
 * @return true|WP_Error
 */
function ewpa_multilanguage_validate_post( int $post_id, string $label = 'Post' ) {
	if ( ! $post_id || ! get_post( $post_id ) ) {
		return ewpa_multilanguage_error( 'not_found', sprintf( '%s not found.', $label ) );
	}

	return true;
}

/**
 * Returns active languages for the detected multilanguage backend.
 *
 * @return array|WP_Error
 */
function ewpa_multilanguage_list_languages() {
	$plugin = ewpa_get_translation_plugin();

	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_list_languages();
	}

	if ( 'polylang' === $plugin && function_exists( 'pll_languages_list' ) ) {
		$slugs   = pll_languages_list( array( 'fields' => 'slug' ) );
		$names   = pll_languages_list( array( 'fields' => 'name' ) );
		$locales = pll_languages_list( array( 'fields' => 'locale' ) );
		$out     = array();

		foreach ( (array) $slugs as $index => $slug ) {
			$out[] = array(
				'slug'    => (string) $slug,
				'locale'  => isset( $locales[ $index ] ) ? (string) $locales[ $index ] : '',
				'name'    => isset( $names[ $index ] ) ? (string) $names[ $index ] : (string) $slug,
				'term_id' => 0,
			);
		}

		return $out;
	}

	if ( 'wpml' === $plugin && has_filter( 'wpml_active_languages' ) ) {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$out       = array();

		foreach ( (array) $languages as $language ) {
			$out[] = array(
				'slug'    => (string) ( $language['language_code'] ?? $language['code'] ?? '' ),
				'locale'  => (string) ( $language['default_locale'] ?? '' ),
				'name'    => (string) ( $language['native_name'] ?? $language['translated_name'] ?? $language['language_code'] ?? '' ),
				'term_id' => 0,
			);
		}

		return array_values( array_filter( $out, static fn( $language ) => '' !== $language['slug'] ) );
	}

	return array();
}

/**
 * Returns the raw list of Linguator language objects.
 *
 * @return array
 */
function ewpa_linguator_get_languages_objects(): array {
	// linguator_languages_list() defaults to slugs; an empty "fields" returns the objects.
	if ( function_exists( 'linguator_languages_list' ) ) {
		$languages = linguator_languages_list( array( 'fields' => '' ) );
		if ( ! empty( $languages ) && is_array( $languages ) ) {
			return $languages;
		}
	}

	$model = ewpa_get_linguator_model();
	if ( ewpa_object_can( $model, 'get_languages_list' ) ) {
		return (array) $model->get_languages_list();
	}

	return array();
}

/**
 * Reads a property from a Linguator language object.
 *
 * @param mixed  $language Language object.
 * @param string $property Property name.
 * @return string
 */
function ewpa_linguator_language_prop( $language, string $property ): string {
	if ( is_object( $language ) && isset( $language->$property ) ) {
		return (string) $language->$property;
	}
	if ( ewpa_object_can( $language, 'get_prop' ) ) {
		return (string) $language->get_prop( $property );
	}

	return '';
}

/**
 * Returns Linguator languages normalized for MCP output.
 *
 * @return array|WP_Error
 */
function ewpa_linguator_list_languages() {
	if ( ! ewpa_is_linguator_available() ) {
		return ewpa_multilanguage_error( 'no_plugin', 'Linguator AI is not available.' );
	}

	$out = array();
	foreach ( ewpa_linguator_get_languages_objects() as $language ) {
		$slug = ewpa_linguator_language_prop( $language, 'slug' );
		if ( '' === $slug ) {
			continue;
		}

		$term_id = 0;
		if ( ewpa_object_can( $language, 'get_tax_prop' ) ) {
			$term_id = (int) $language->get_tax_prop( 'lmat_language', 'term_id' );
		}
		if ( ! $term_id && is_object( $language ) && isset( $language->term_id ) ) {
			$term_id = (int) $language->term_id;
		}

		$name = ewpa_linguator_language_prop( $language, 'name' );

		$out[] = array(
			'slug'    => $slug,
			'locale'  => ewpa_linguator_language_prop( $language, 'locale' ),
			'name'    => '' !== $name ? $name : $slug,
			'term_id' => $term_id,
		);
	}

	return $out;
}

/**
 * Returns a Linguator language object by slug.
 *
 * @param string $language Language slug.
 * @return object|null
 */
function ewpa_linguator_get_language( string $language ) {
	$model = ewpa_get_linguator_model();
	if ( ewpa_object_can( $model, 'get_language' ) ) {
		$lang = $model->get_language( $language );
		if ( ! empty( $lang ) ) {
			return $lang;
		}
	}

	foreach ( ewpa_linguator_get_languages_objects() as $lang ) {
		if ( ewpa_linguator_language_prop( $lang, 'slug' ) === $language ) {
			return $lang;
		}
	}

	return null;
}

/**
 * Assigns a Linguator language using public wrappers when loaded, or the model runtime.
 *
 * @param int    $post_id  Post ID.
 * @param object $language Language object.
 * @return bool
 */
function ewpa_linguator_set_post_language_value( int $post_id, $language ): bool {
	if ( function_exists( 'linguator_set_post_language' ) ) {
		return (bool) linguator_set_post_language( $post_id, $language );
	}

	$model = ewpa_get_linguator_model();
	if ( is_object( $model ) && ewpa_object_can( $model->post ?? null, 'set_language' ) ) {
		return (bool) $model->post->set_language( $post_id, $language );
	}

	return false;
}

/**
 * Returns a Linguator post language slug.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ewpa_linguator_get_post_language_slug( int $post_id ): string {
	if ( function_exists( 'linguator_get_post_language' ) ) {
		return (string) linguator_get_post_language( $post_id );
	}

	$model = ewpa_get_linguator_model();
	if ( ! is_object( $model ) || ! ewpa_object_can( $model->post ?? null, 'get_language' ) ) {
		return '';
	}

	$lang = $model->post->get_language( $post_id );
	if ( empty( $lang ) ) {
		return '';
	}

	return ewpa_linguator_language_prop( $lang, 'slug' );
}

/**
 * Returns Linguator post translations.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function ewpa_linguator_get_post_translations_map( int $post_id ): array {
	if ( function_exists( 'linguator_get_post_translations' ) ) {
		return (array) linguator_get_post_translations( $post_id );
	}

	$model = ewpa_get_linguator_model();
	if ( is_object( $model ) && ewpa_object_can( $model->post ?? null, 'get_translations' ) ) {
		return (array) $model->post->get_translations( $post_id );
	}

	return array();
}

/**
 * Saves Linguator post translations.
 *
 * @param array $translations Translation map keyed by language slug.
 * @return array
 */
function ewpa_linguator_save_post_translations_map( array $translations ): array {
	if ( function_exists( 'linguator_save_post_translations' ) ) {
		return (array) linguator_save_post_translations( $translations );
	}

	$model = ewpa_get_linguator_model();
	$id    = reset( $translations );
	if ( $id && is_object( $model ) && ewpa_object_can( $model->post ?? null, 'save_translations' ) ) {
		return (array) $model->post->save_translations( $id, $translations );
	}

	return array();
}

/**
 * Sets the language for a post using the detected backend.
 *
 * @param int    $post_id  Post ID.
 * @param string $language Language slug/code.
 * @return array|WP_Error
 */
function ewpa_multilanguage_set_post_language( int $post_id, string $language ) {
	$validation = ewpa_multilanguage_validate_post( $post_id );
	if ( true !== $validation ) {
		return $validation;
	}

	$lang   = ewpa_sanitize_language_slug( $language );
	$plugin = ewpa_get_translation_plugin();

	if ( '' === $lang ) {
		return ewpa_multilanguage_error( 'invalid_language', 'Language is required.' );
	}
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_set_post_language( $post_id, $lang );
	}

	if ( 'polylang' === $plugin ) {
		pll_set_post_language( $post_id, $lang );
	} elseif ( 'wpml' === $plugin ) {
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $post_id,
				'element_type'         => 'post_' . get_post_type( $post_id ),
				'trid'                 => false,
				'language_code'        => $lang,
				'source_language_code' => null,
			)
		);
	}

	return array(
		'post_id'  => $post_id,
		'language' => $lang,
		'plugin'   => $plugin,
		'message'  => sprintf( 'Language "%s" set successfully via %s.', $lang, $plugin ),
	);
}

/**
 * Sets a post language through Linguator public APIs.
 *
 * @param int    $post_id  Post ID.
 * @param string $language Language slug.
 * @return array|WP_Error
 */
function ewpa_linguator_set_post_language( int $post_id, string $language ) {
	$lang = ewpa_linguator_get_language( $language );
	if ( empty( $lang ) ) {
		return ewpa_multilanguage_error( 'invalid_language', sprintf( 'Invalid Linguator AI language: %s.', $language ) );
	}

	$current = ewpa_linguator_get_post_language_slug( $post_id );
	$changed = ewpa_linguator_set_post_language_value( $post_id, $lang );

	// set_language() returns false when the requested language is already assigned.
	if ( ! $changed && $current !== $language ) {
		return ewpa_multilanguage_error( 'language_not_set', 'Linguator AI could not assign the requested language.' );
	}

	return array(
		'post_id'  => $post_id,
		'language' => $language,
		'plugin'   => 'linguator',
		'message'  => sprintf( 'Language "%s" set successfully via Linguator AI.', $language ),
	);
}

/**
 * Returns the post language for the detected backend.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ewpa_multilanguage_get_post_language( int $post_id ): string {
	$plugin = ewpa_get_translation_plugin();

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_get_post_language_slug( $post_id );
	}
	if ( 'polylang' === $plugin && function_exists( 'pll_get_post_language' ) ) {
		return (string) pll_get_post_language( $post_id );
	}
	if ( 'wpml' === $plugin ) {
		return (string) apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => 'post_' . get_post_type( $post_id ),
			)
		);
	}

	return '';
}

/**
 * Links a translated post to a source post using the detected backend.
 *
 * @param int    $original_id     Source post ID.
 * @param int    $translated_id   Translated post ID.
 * @param string $translated_lang Translated language slug/code.
 * @return array|WP_Error
 */
function ewpa_multilanguage_link_post_translation( int $original_id, int $translated_id, string $translated_lang ) {
	$original_validation = ewpa_multilanguage_validate_post( $original_id, 'Original post' );
	if ( true !== $original_validation ) {
		return $original_validation;
	}
	$translated_validation = ewpa_multilanguage_validate_post( $translated_id, 'Translated post' );
	if ( true !== $translated_validation ) {
		return $translated_validation;
	}

	$lang   = ewpa_sanitize_language_slug( $translated_lang );
	$plugin = ewpa_get_translation_plugin();

	if ( '' === $lang ) {
		return ewpa_multilanguage_error( 'invalid_language', 'Translated language is required.' );
	}
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_link_post_translation( $original_id, $translated_id, $lang );
	}

	if ( 'polylang' === $plugin ) {
		$translations          = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $original_id ) : array();
		$translations[ $lang ] = $translated_id;
		pll_save_post_translations( $translations );
	} elseif ( 'wpml' === $plugin ) {
		$trid            = apply_filters( 'wpml_element_trid', null, $original_id, 'post_' . get_post_type( $original_id ) );
		$source_language = ewpa_multilanguage_get_post_language( $original_id );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $translated_id,
				'element_type'         => 'post_' . get_post_type( $translated_id ),
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => '' !== $source_language ? $source_language : null,
			)
		);
	}

	return array(
		'original_post_id'   => $original_id,
		'translated_post_id' => $translated_id,
		'plugin'             => $plugin,
		'message'            => sprintf( 'Posts %d and %d linked as translations via %s.', $original_id, $translated_id, $plugin ),
	);
}

/**
 * Links a translated post through Linguator public APIs.
 *
 * @param int    $original_id   Source post ID.
 * @param int    $translated_id Translated post ID.
 * @param string $language      Translated language slug.
 * @return array|WP_Error
 */
function ewpa_linguator_link_post_translation( int $original_id, int $translated_id, string $language ) {
	$lang = ewpa_linguator_get_language( $language );
	if ( empty( $lang ) ) {
		return ewpa_multilanguage_error( 'invalid_language', sprintf( 'Invalid Linguator AI language: %s.', $language ) );
	}

	$set_language = ewpa_linguator_set_post_language( $translated_id, $language );
	if ( $set_language instanceof WP_Error ) {
		return $set_language;
	}

	$translations = ewpa_linguator_get_post_translations_map( $original_id );
	if ( ! is_array( $translations ) ) {
		$translations = array();
	}

	$source_language = ewpa_linguator_get_post_language_slug( $original_id );
	if ( $source_language ) {
		$translations[ $source_language ] = $original_id;
	}
	$translations[ $language ] = $translated_id;

	$saved = ewpa_linguator_save_post_translations_map( $translations );
	if ( empty( $saved[ $language ] ) || (int) $saved[ $language ] !== $translated_id ) {
		return ewpa_multilanguage_error( 'translation_not_linked', 'Linguator AI could not link the requested translation.' );
	}

	return array(
		'original_post_id'   => $original_id,
		'translated_post_id' => $translated_id,
		'plugin'             => 'linguator',
		'message'            => sprintf( 'Posts %d and %d linked as translations via Linguator AI.', $original_id, $translated_id ),
	);
}

/**
 * Returns the raw translation map (language slug => post ID) for the detected backend.
 *
 * @param int    $post_id Post ID.
 * @param string $plugin  Detected backend. Resolved when empty.
 * @return array
 */
function ewpa_multilanguage_get_post_translations_map( int $post_id, string $plugin = '' ): array {
	$plugin = '' !== $plugin ? $plugin : ewpa_get_translation_plugin();

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_get_post_translations_map( $post_id );
	}

	if ( 'polylang' === $plugin && function_exists( 'pll_get_post_translations' ) ) {
		return (array) pll_get_post_translations( $post_id );
	}

	if ( 'wpml' === $plugin ) {
		$map     = array();
		$trid    = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . get_post_type( $post_id ) );
		$raw_map = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . get_post_type( $post_id ) );
		if ( is_array( $raw_map ) ) {
			foreach ( $raw_map as $lang => $translation ) {
				$map[ $lang ] = $translation->element_id ?? 0;
			}
		}
		return $map;
	}

	return array();
}

/**
 * Returns the translation map for a post using the detected backend.
 *
 * @param int $post_id Post ID.
 * @return array|WP_Error
 */
function ewpa_multilanguage_get_post_translations( int $post_id ) {
	$validation = ewpa_multilanguage_validate_post( $post_id );
	if ( true !== $validation ) {
		return $validation;
	}

	$plugin = ewpa_get_translation_plugin();
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	$result = array();
	foreach ( ewpa_multilanguage_get_post_translations_map( $post_id, $plugin ) as $lang => $translated_id ) {
		$translated_id   = ewpa_absint( $translated_id );
		$translated_post = get_post( $translated_id );
		if ( ! $translated_post ) {
			continue;
		}
		$result[] = array(
			'language'  => (string) $lang,
			'post_id'   => $translated_id,
			'title'     => $translated_post->post_title,
			'permalink' => get_permalink( $translated_id ),
			'status'    => $translated_post->post_status,
		);
	}

	return array(
		'post_id'      => $post_id,
		'language'     => ewpa_multilanguage_get_post_language( $post_id ),
		'plugin'       => $plugin,
		'translations' => $result,
	);
}

/*
 * ==========================================================================
 * TRANSLATION CREATION
 *
 * The MCP client (the AI assistant) produces the translated strings; these
 * helpers persist them as a real translated post, wired into the backend's
 * translation group. On Linguator the work is delegated to the plugin's own
 * Linguator_Sync_Post_Model::copy_post(), which is what the plugin's bulk and
 * page translation screens use, so taxonomies, custom fields, the featured
 * image and the translation link are handled exactly as they are in the UI.
 * ==========================================================================
 */

/**
 * Normalizes the optional overrides accepted by the translation helpers.
 *
 * @param array $overrides Raw overrides.
 * @return array
 */
function ewpa_multilanguage_normalize_overrides( array $overrides ): array {
	$clean = array();

	if ( isset( $overrides['title'] ) && '' !== (string) $overrides['title'] ) {
		$clean['post_title'] = sanitize_text_field( (string) $overrides['title'] );
	}
	if ( isset( $overrides['content'] ) && '' !== (string) $overrides['content'] ) {
		$clean['post_content'] = (string) $overrides['content'];
	}
	if ( isset( $overrides['excerpt'] ) && '' !== (string) $overrides['excerpt'] ) {
		$clean['post_excerpt'] = sanitize_text_field( (string) $overrides['excerpt'] );
	}
	if ( isset( $overrides['slug'] ) && '' !== (string) $overrides['slug'] ) {
		$clean['post_name'] = sanitize_title( (string) $overrides['slug'] );
	}
	if ( isset( $overrides['status'] ) && '' !== (string) $overrides['status'] ) {
		$clean['post_status'] = sanitize_key( (string) $overrides['status'] );
	}

	return $clean;
}

/**
 * Creates (or updates) the translation of a post in the target language.
 *
 * @param int    $source_id       Source post ID.
 * @param string $target_language Target language slug/code.
 * @param array  $overrides       Translated fields: title, content, excerpt, slug, status.
 * @return array|WP_Error
 */
function ewpa_multilanguage_create_post_translation( int $source_id, string $target_language, array $overrides = array() ) {
	$validation = ewpa_multilanguage_validate_post( $source_id, 'Source post' );
	if ( true !== $validation ) {
		return $validation;
	}

	$target = ewpa_sanitize_language_slug( $target_language );
	if ( '' === $target ) {
		return ewpa_multilanguage_error( 'invalid_language', 'Target language is required.' );
	}

	$plugin = ewpa_get_translation_plugin();
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	$known = array();
	$list  = ewpa_multilanguage_list_languages();
	if ( is_array( $list ) ) {
		$known = wp_list_pluck( $list, 'slug' );
	}
	if ( ! empty( $known ) && ! in_array( $target, $known, true ) ) {
		return ewpa_multilanguage_error(
			'invalid_language',
			sprintf( 'Unknown language "%s". Available: %s.', $target, implode( ', ', $known ) )
		);
	}

	$source_language = ewpa_multilanguage_get_post_language( $source_id );
	if ( '' === $source_language ) {
		return ewpa_multilanguage_error(
			'source_language_missing',
			sprintf( 'Post %d has no language assigned. Assign one with ewpa/set-post-language first.', $source_id )
		);
	}
	if ( $source_language === $target ) {
		return ewpa_multilanguage_error(
			'same_language',
			sprintf( 'Post %d is already in "%s".', $source_id, $target )
		);
	}

	$fields = ewpa_multilanguage_normalize_overrides( $overrides );

	// Reuse an existing translation rather than creating a duplicate.
	$existing_map = ewpa_multilanguage_get_post_translations_map( $source_id, $plugin );
	$existing_id  = ewpa_absint( $existing_map[ $target ] ?? 0 );
	if ( $existing_id && $existing_id !== $source_id && get_post( $existing_id ) ) {
		return ewpa_multilanguage_update_post_translation( $source_id, $existing_id, $target, $plugin, $fields );
	}

	if ( 'linguator' === $plugin ) {
		$result = ewpa_linguator_create_post_translation( $source_id, $source_language, $target, $fields );
		if ( ! ( $result instanceof WP_Error ) ) {
			return $result;
		}

		// Fall through to the generic duplicator when the sync module is unavailable.
		if ( 'linguator_sync_unavailable' !== $result->get_error_code() ) {
			return $result;
		}
	}

	return ewpa_multilanguage_duplicate_post_translation( $source_id, $source_language, $target, $plugin, $fields );
}

/**
 * Updates an already existing translation with freshly translated fields.
 *
 * @param int    $source_id     Source post ID.
 * @param int    $translated_id Existing translated post ID.
 * @param string $target        Target language slug.
 * @param string $plugin        Detected backend.
 * @param array  $fields        Normalized post fields.
 * @return array|WP_Error
 */
function ewpa_multilanguage_update_post_translation( int $source_id, int $translated_id, string $target, string $plugin, array $fields ) {
	if ( ! empty( $fields ) ) {
		$update       = $fields;
		$update['ID'] = $translated_id;

		$updated = wp_update_post( $update, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	return array(
		'post_id'          => $translated_id,
		'source_post_id'   => $source_id,
		'language'         => $target,
		'created'          => false,
		'plugin'           => $plugin,
		'permalink'        => get_permalink( $translated_id ),
		'edit_link'        => (string) get_edit_post_link( $translated_id, 'raw' ),
		'status'           => (string) get_post_status( $translated_id ),
		'message'          => sprintf(
			'Post %d already had a "%s" translation (post %d); it was updated with the supplied fields.',
			$source_id,
			$target,
			$translated_id
		),
	);
}

/**
 * Creates the translation through Linguator's own post duplication model.
 *
 * @param int    $source_id       Source post ID.
 * @param string $source_language Source language slug.
 * @param string $target          Target language slug.
 * @param array  $fields          Normalized post fields.
 * @return array|WP_Error
 */
function ewpa_linguator_create_post_translation( int $source_id, string $source_language, string $target, array $fields ) {
	$linguator = ewpa_get_linguator_instance();

	if ( ! class_exists( 'Linguator_Sync_Post_Model' )
		|| ! is_object( $linguator )
		|| ! isset( $linguator->sync )
		|| ! is_object( $linguator->sync ) ) {
		return ewpa_multilanguage_error(
			'linguator_sync_unavailable',
			'Linguator synchronization module is not loaded.'
		);
	}

	// copy_post() forces its own status for new posts; apply ours afterwards.
	$requested_status = $fields['post_status'] ?? '';
	unset( $fields['post_status'] );

	try {
		$copier      = new Linguator_Sync_Post_Model( $linguator );
		$translated_id = (int) $copier->copy_post( $source_id, $source_language, $target, false, $fields );
	} catch ( Throwable $e ) {
		return ewpa_multilanguage_error(
			'translation_failed',
			sprintf( 'Linguator AI could not create the translation: %s', $e->getMessage() )
		);
	}

	if ( ! $translated_id ) {
		return ewpa_multilanguage_error(
			'translation_failed',
			sprintf( 'Linguator AI could not create the "%s" translation of post %d.', $target, $source_id )
		);
	}

	if ( '' !== $requested_status && $requested_status !== get_post_status( $translated_id ) ) {
		wp_update_post(
			array(
				'ID'          => $translated_id,
				'post_status' => $requested_status,
			)
		);
	}

	// copy_post() links the translation itself, but re-assert it in case the
	// lmat_bulk_post_language_link filter is disabled on the site.
	$map = ewpa_linguator_get_post_translations_map( $source_id );
	if ( ewpa_absint( $map[ $target ] ?? 0 ) !== $translated_id ) {
		$linked = ewpa_linguator_link_post_translation( $source_id, $translated_id, $target );
		if ( $linked instanceof WP_Error ) {
			return $linked;
		}
	}

	return array(
		'post_id'        => $translated_id,
		'source_post_id' => $source_id,
		'language'       => $target,
		'created'        => true,
		'plugin'         => 'linguator',
		'permalink'      => get_permalink( $translated_id ),
		'edit_link'      => (string) get_edit_post_link( $translated_id, 'raw' ),
		'status'         => (string) get_post_status( $translated_id ),
		'message'        => sprintf(
			'Created post %d as the "%s" translation of post %d via Linguator AI.',
			$translated_id,
			$target,
			$source_id
		),
	);
}

/**
 * Creates the translation by duplicating the source post (Polylang, WPML, fallback).
 *
 * @param int    $source_id       Source post ID.
 * @param string $source_language Source language slug.
 * @param string $target          Target language slug.
 * @param string $plugin          Detected backend.
 * @param array  $fields          Normalized post fields.
 * @return array|WP_Error
 */
function ewpa_multilanguage_duplicate_post_translation( int $source_id, string $source_language, string $target, string $plugin, array $fields ) {
	$source = get_post( $source_id );
	if ( ! $source ) {
		return ewpa_multilanguage_error( 'not_found', 'Source post not found.' );
	}

	$args = array(
		'post_type'      => $source->post_type,
		'post_author'    => $source->post_author,
		'post_status'    => $fields['post_status'] ?? 'draft',
		'post_title'     => $fields['post_title'] ?? $source->post_title,
		'post_content'   => $fields['post_content'] ?? $source->post_content,
		'post_excerpt'   => $fields['post_excerpt'] ?? $source->post_excerpt,
		'comment_status' => $source->comment_status,
		'ping_status'    => $source->ping_status,
		'menu_order'     => $source->menu_order,
		'post_parent'    => 0,
	);

	if ( isset( $fields['post_name'] ) ) {
		$args['post_name'] = $fields['post_name'];
	}

	$translated_id = wp_insert_post( $args, true );
	if ( is_wp_error( $translated_id ) ) {
		return $translated_id;
	}
	$translated_id = (int) $translated_id;

	ewpa_multilanguage_copy_post_meta( $source_id, $translated_id );
	ewpa_multilanguage_copy_post_terms( $source_id, $translated_id, $target, $plugin );

	$thumbnail_id = get_post_thumbnail_id( $source_id );
	if ( $thumbnail_id ) {
		set_post_thumbnail( $translated_id, $thumbnail_id );
	}

	$linked = ewpa_multilanguage_link_post_translation( $source_id, $translated_id, $target );
	if ( $linked instanceof WP_Error ) {
		wp_delete_post( $translated_id, true );
		return $linked;
	}

	return array(
		'post_id'        => $translated_id,
		'source_post_id' => $source_id,
		'language'       => $target,
		'created'        => true,
		'plugin'         => $plugin,
		'permalink'      => get_permalink( $translated_id ),
		'edit_link'      => (string) get_edit_post_link( $translated_id, 'raw' ),
		'status'         => (string) get_post_status( $translated_id ),
		'message'        => sprintf(
			'Created post %d as the "%s" translation of post %d via %s.',
			$translated_id,
			$target,
			$source_id,
			$plugin
		),
	);
}

/**
 * Copies custom fields from the source post to the translated post.
 *
 * @param int $source_id     Source post ID.
 * @param int $translated_id Target post ID.
 * @return void
 */
function ewpa_multilanguage_copy_post_meta( int $source_id, int $translated_id ): void {
	$skip = array( '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_old_date' );

	/**
	 * Filters the meta keys skipped when duplicating a post into another language.
	 *
	 * @param string[] $skip          Meta keys to skip.
	 * @param int      $source_id     Source post ID.
	 * @param int      $translated_id Target post ID.
	 */
	$skip = (array) apply_filters( 'ewpa_translation_skipped_meta_keys', $skip, $source_id, $translated_id );

	foreach ( get_post_meta( $source_id ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) || 0 === strpos( (string) $key, '_lmat_' ) ) {
			continue;
		}
		foreach ( (array) $values as $value ) {
			add_post_meta( $translated_id, $key, maybe_unserialize( $value ) );
		}
	}
}

/**
 * Copies taxonomy terms from the source post to the translated post.
 *
 * Language taxonomies are skipped; translated terms are remapped to the target
 * language when the backend exposes a term translation lookup.
 *
 * @param int    $source_id     Source post ID.
 * @param int    $translated_id Target post ID.
 * @param string $target        Target language slug.
 * @param string $plugin        Detected backend.
 * @return void
 */
function ewpa_multilanguage_copy_post_terms( int $source_id, int $translated_id, string $target, string $plugin ): void {
	$language_taxonomies = array( 'language', 'post_translations', 'term_language', 'term_translations', 'lmat_language', 'lmat_post_translations' );

	foreach ( get_object_taxonomies( get_post_type( $source_id ) ) as $taxonomy ) {
		if ( in_array( $taxonomy, $language_taxonomies, true ) ) {
			continue;
		}

		$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}

		$mapped = array();
		foreach ( $terms as $term_id ) {
			$mapped[] = ewpa_multilanguage_map_term( (int) $term_id, $target, $plugin );
		}

		wp_set_object_terms( $translated_id, array_values( array_unique( array_filter( $mapped ) ) ), $taxonomy );
	}
}

/**
 * Resolves the target-language counterpart of a term, falling back to the term itself.
 *
 * @param int    $term_id Source term ID.
 * @param string $target  Target language slug.
 * @param string $plugin  Detected backend.
 * @return int
 */
function ewpa_multilanguage_map_term( int $term_id, string $target, string $plugin ): int {
	if ( 'polylang' === $plugin && function_exists( 'pll_get_term' ) ) {
		$translated = ewpa_absint( pll_get_term( $term_id, $target ) );
		return $translated ? $translated : $term_id;
	}

	if ( 'linguator' === $plugin && function_exists( 'linguator_get_term' ) ) {
		$translated = ewpa_absint( linguator_get_term( $term_id, $target ) );
		return $translated ? $translated : $term_id;
	}

	if ( 'wpml' === $plugin ) {
		$term = get_term( $term_id );
		if ( $term && ! is_wp_error( $term ) ) {
			$translated = ewpa_absint(
				apply_filters( 'wpml_object_id', $term_id, $term->taxonomy, true, $target )
			);
			return $translated ? $translated : $term_id;
		}
	}

	return $term_id;
}

/*
 * ==========================================================================
 * TERMS AND TAXONOMIES
 *
 * Same three-backend shape as the post helpers above. On Linguator the work
 * goes through the documented public API (linguator_set_term_language(),
 * linguator_insert_term(), linguator_update_term(), ...), which is what the
 * plugin's own Translation_Term_Model uses under the hood.
 * ==========================================================================
 */

/**
 * Validates that a term exists and returns it.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Optional taxonomy to constrain the lookup.
 * @return WP_Term|WP_Error
 */
function ewpa_multilanguage_validate_term( int $term_id, string $taxonomy = '' ) {
	if ( ! $term_id ) {
		return ewpa_multilanguage_error( 'not_found', 'Term not found.' );
	}

	$term = '' !== $taxonomy ? get_term( $term_id, $taxonomy ) : get_term( $term_id );
	if ( ! $term || is_wp_error( $term ) ) {
		return ewpa_multilanguage_error( 'not_found', sprintf( 'Term %d not found.', $term_id ) );
	}

	return $term;
}

/**
 * Returns the taxonomy of a term, or an empty string.
 *
 * @param int $term_id Term ID.
 * @return string
 */
function ewpa_multilanguage_get_term_taxonomy( int $term_id ): string {
	$term = get_term( $term_id );

	return ( $term && ! is_wp_error( $term ) ) ? (string) $term->taxonomy : '';
}

/**
 * Assigns a Linguator language to a term.
 *
 * @param int   $term_id  Term ID.
 * @param mixed $language Language object or slug.
 * @return bool
 */
function ewpa_linguator_set_term_language_value( int $term_id, $language ): bool {
	if ( function_exists( 'linguator_set_term_language' ) ) {
		return (bool) linguator_set_term_language( $term_id, $language );
	}

	$model = ewpa_get_linguator_model();
	if ( is_object( $model ) && ewpa_object_can( $model->term ?? null, 'set_language' ) ) {
		return (bool) $model->term->set_language( $term_id, $language );
	}

	return false;
}

/**
 * Returns the Linguator language slug of a term.
 *
 * @param int $term_id Term ID.
 * @return string
 */
function ewpa_linguator_get_term_language_slug( int $term_id ): string {
	if ( function_exists( 'linguator_get_term_language' ) ) {
		return (string) linguator_get_term_language( $term_id );
	}

	$model = ewpa_get_linguator_model();
	if ( ! is_object( $model ) || ! ewpa_object_can( $model->term ?? null, 'get_language' ) ) {
		return '';
	}

	$lang = $model->term->get_language( $term_id );
	if ( empty( $lang ) ) {
		return '';
	}

	return ewpa_linguator_language_prop( $lang, 'slug' );
}

/**
 * Returns the Linguator translation map of a term.
 *
 * @param int $term_id Term ID.
 * @return array
 */
function ewpa_linguator_get_term_translations_map( int $term_id ): array {
	if ( function_exists( 'linguator_get_term_translations' ) ) {
		return (array) linguator_get_term_translations( $term_id );
	}

	$model = ewpa_get_linguator_model();
	if ( is_object( $model ) && ewpa_object_can( $model->term ?? null, 'get_translations' ) ) {
		return (array) $model->term->get_translations( $term_id );
	}

	return array();
}

/**
 * Saves the Linguator translation map of a term.
 *
 * @param array $translations Translation map keyed by language slug.
 * @return array
 */
function ewpa_linguator_save_term_translations_map( array $translations ): array {
	if ( function_exists( 'linguator_save_term_translations' ) ) {
		return (array) linguator_save_term_translations( $translations );
	}

	$model = ewpa_get_linguator_model();
	$id    = reset( $translations );
	if ( $id && is_object( $model ) && ewpa_object_can( $model->term ?? null, 'save_translations' ) ) {
		return (array) $model->term->save_translations( $id, $translations );
	}

	return array();
}

/**
 * Returns the language of a term for the detected backend.
 *
 * @param int $term_id Term ID.
 * @return string
 */
function ewpa_multilanguage_get_term_language( int $term_id ): string {
	$plugin = ewpa_get_translation_plugin();

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_get_term_language_slug( $term_id );
	}
	if ( 'polylang' === $plugin && function_exists( 'pll_get_term_language' ) ) {
		return (string) pll_get_term_language( $term_id );
	}
	if ( 'wpml' === $plugin ) {
		return (string) apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $term_id,
				'element_type' => 'tax_' . ewpa_multilanguage_get_term_taxonomy( $term_id ),
			)
		);
	}

	return '';
}

/**
 * Sets the language of a term using the detected backend.
 *
 * @param int    $term_id  Term ID.
 * @param string $language Language slug/code.
 * @return array|WP_Error
 */
function ewpa_multilanguage_set_term_language( int $term_id, string $language ) {
	$term = ewpa_multilanguage_validate_term( $term_id );
	if ( $term instanceof WP_Error ) {
		return $term;
	}

	$lang   = ewpa_sanitize_language_slug( $language );
	$plugin = ewpa_get_translation_plugin();

	if ( '' === $lang ) {
		return ewpa_multilanguage_error( 'invalid_language', 'Language is required.' );
	}
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_set_term_language( $term_id, $lang );
	}

	if ( 'polylang' === $plugin && function_exists( 'pll_set_term_language' ) ) {
		pll_set_term_language( $term_id, $lang );
	} elseif ( 'wpml' === $plugin ) {
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $term_id,
				'element_type'         => 'tax_' . $term->taxonomy,
				'trid'                 => false,
				'language_code'        => $lang,
				'source_language_code' => null,
			)
		);
	}

	return array(
		'term_id'  => $term_id,
		'taxonomy' => $term->taxonomy,
		'language' => $lang,
		'plugin'   => $plugin,
		'message'  => sprintf( 'Language "%s" set successfully on term %d via %s.', $lang, $term_id, $plugin ),
	);
}

/**
 * Sets a term language through Linguator public APIs.
 *
 * @param int    $term_id  Term ID.
 * @param string $language Language slug.
 * @return array|WP_Error
 */
function ewpa_linguator_set_term_language( int $term_id, string $language ) {
	$lang = ewpa_linguator_get_language( $language );
	if ( empty( $lang ) ) {
		return ewpa_multilanguage_error( 'invalid_language', sprintf( 'Invalid Linguator AI language: %s.', $language ) );
	}

	$current = ewpa_linguator_get_term_language_slug( $term_id );
	$changed = ewpa_linguator_set_term_language_value( $term_id, $lang );

	// set_language() returns false when the requested language is already assigned.
	if ( ! $changed && $current !== $language ) {
		return ewpa_multilanguage_error( 'language_not_set', 'Linguator AI could not assign the requested language to the term.' );
	}

	return array(
		'term_id'  => $term_id,
		'taxonomy' => ewpa_multilanguage_get_term_taxonomy( $term_id ),
		'language' => $language,
		'plugin'   => 'linguator',
		'message'  => sprintf( 'Language "%s" set successfully on term %d via Linguator AI.', $language, $term_id ),
	);
}

/**
 * Returns the raw term translation map (language slug => term ID) for the detected backend.
 *
 * @param int    $term_id Term ID.
 * @param string $plugin  Detected backend. Resolved when empty.
 * @return array
 */
function ewpa_multilanguage_get_term_translations_map( int $term_id, string $plugin = '' ): array {
	$plugin = '' !== $plugin ? $plugin : ewpa_get_translation_plugin();

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_get_term_translations_map( $term_id );
	}

	if ( 'polylang' === $plugin && function_exists( 'pll_get_term_translations' ) ) {
		return (array) pll_get_term_translations( $term_id );
	}

	if ( 'wpml' === $plugin ) {
		$map          = array();
		$element_type = 'tax_' . ewpa_multilanguage_get_term_taxonomy( $term_id );
		$trid         = apply_filters( 'wpml_element_trid', null, $term_id, $element_type );
		$raw_map      = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
		if ( is_array( $raw_map ) ) {
			foreach ( $raw_map as $lang => $translation ) {
				$map[ $lang ] = $translation->term_id ?? $translation->element_id ?? 0;
			}
		}
		return $map;
	}

	return array();
}

/**
 * Returns the translation map for a term using the detected backend.
 *
 * @param int $term_id Term ID.
 * @return array|WP_Error
 */
function ewpa_multilanguage_get_term_translations( int $term_id ) {
	$term = ewpa_multilanguage_validate_term( $term_id );
	if ( $term instanceof WP_Error ) {
		return $term;
	}

	$plugin = ewpa_get_translation_plugin();
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	$result = array();
	foreach ( ewpa_multilanguage_get_term_translations_map( $term_id, $plugin ) as $lang => $translated_id ) {
		$translated_id   = ewpa_absint( $translated_id );
		$translated_term = $translated_id ? get_term( $translated_id ) : null;
		if ( ! $translated_term || is_wp_error( $translated_term ) ) {
			continue;
		}

		$link = get_term_link( $translated_id );

		$result[] = array(
			'language'    => (string) $lang,
			'term_id'     => $translated_id,
			'name'        => $translated_term->name,
			'slug'        => $translated_term->slug,
			'description' => $translated_term->description,
			'count'       => (int) $translated_term->count,
			'permalink'   => is_wp_error( $link ) ? '' : (string) $link,
		);
	}

	return array(
		'term_id'      => $term_id,
		'taxonomy'     => $term->taxonomy,
		'language'     => ewpa_multilanguage_get_term_language( $term_id ),
		'plugin'       => $plugin,
		'translations' => $result,
	);
}

/**
 * Links a translated term to a source term using the detected backend.
 *
 * @param int    $original_id     Source term ID.
 * @param int    $translated_id   Translated term ID.
 * @param string $translated_lang Translated language slug/code.
 * @return array|WP_Error
 */
function ewpa_multilanguage_link_term_translation( int $original_id, int $translated_id, string $translated_lang ) {
	$original = ewpa_multilanguage_validate_term( $original_id );
	if ( $original instanceof WP_Error ) {
		return $original;
	}
	$translated = ewpa_multilanguage_validate_term( $translated_id );
	if ( $translated instanceof WP_Error ) {
		return $translated;
	}

	if ( $original->taxonomy !== $translated->taxonomy ) {
		return ewpa_multilanguage_error(
			'taxonomy_mismatch',
			sprintf( 'Terms %d and %d belong to different taxonomies (%s / %s).', $original_id, $translated_id, $original->taxonomy, $translated->taxonomy )
		);
	}

	$lang   = ewpa_sanitize_language_slug( $translated_lang );
	$plugin = ewpa_get_translation_plugin();

	if ( '' === $lang ) {
		return ewpa_multilanguage_error( 'invalid_language', 'Translated language is required.' );
	}
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	if ( 'linguator' === $plugin ) {
		return ewpa_linguator_link_term_translation( $original_id, $translated_id, $lang );
	}

	if ( 'polylang' === $plugin ) {
		$translations          = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $original_id ) : array();
		$translations[ $lang ] = $translated_id;
		pll_save_term_translations( $translations );
	} elseif ( 'wpml' === $plugin ) {
		$element_type    = 'tax_' . $original->taxonomy;
		$trid            = apply_filters( 'wpml_element_trid', null, $original_id, $element_type );
		$source_language = ewpa_multilanguage_get_term_language( $original_id );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $translated_id,
				'element_type'         => $element_type,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => '' !== $source_language ? $source_language : null,
			)
		);
	}

	return array(
		'original_term_id'   => $original_id,
		'translated_term_id' => $translated_id,
		'taxonomy'           => $original->taxonomy,
		'plugin'             => $plugin,
		'message'            => sprintf( 'Terms %d and %d linked as translations via %s.', $original_id, $translated_id, $plugin ),
	);
}

/**
 * Links a translated term through Linguator public APIs.
 *
 * @param int    $original_id   Source term ID.
 * @param int    $translated_id Translated term ID.
 * @param string $language      Translated language slug.
 * @return array|WP_Error
 */
function ewpa_linguator_link_term_translation( int $original_id, int $translated_id, string $language ) {
	$lang = ewpa_linguator_get_language( $language );
	if ( empty( $lang ) ) {
		return ewpa_multilanguage_error( 'invalid_language', sprintf( 'Invalid Linguator AI language: %s.', $language ) );
	}

	$set_language = ewpa_linguator_set_term_language( $translated_id, $language );
	if ( $set_language instanceof WP_Error ) {
		return $set_language;
	}

	$translations = ewpa_linguator_get_term_translations_map( $original_id );
	if ( ! is_array( $translations ) ) {
		$translations = array();
	}

	$source_language = ewpa_linguator_get_term_language_slug( $original_id );
	if ( $source_language ) {
		$translations[ $source_language ] = $original_id;
	}
	$translations[ $language ] = $translated_id;

	$saved = ewpa_linguator_save_term_translations_map( $translations );
	if ( empty( $saved[ $language ] ) || (int) $saved[ $language ] !== $translated_id ) {
		return ewpa_multilanguage_error( 'translation_not_linked', 'Linguator AI could not link the requested term translation.' );
	}

	return array(
		'original_term_id'   => $original_id,
		'translated_term_id' => $translated_id,
		'taxonomy'           => ewpa_multilanguage_get_term_taxonomy( $original_id ),
		'plugin'             => 'linguator',
		'message'            => sprintf( 'Terms %d and %d linked as translations via Linguator AI.', $original_id, $translated_id ),
	);
}

/**
 * Normalizes the optional overrides accepted by the term translation helper.
 *
 * @param array   $overrides Raw overrides.
 * @param WP_Term $source    Source term.
 * @return array
 */
function ewpa_multilanguage_normalize_term_overrides( array $overrides, $source ): array {
	$clean = array();

	$name                = isset( $overrides['name'] ) ? sanitize_text_field( (string) $overrides['name'] ) : '';
	$clean['name']       = '' !== $name ? $name : $source->name;
	$clean['slug']       = isset( $overrides['slug'] ) && '' !== (string) $overrides['slug']
		? sanitize_title( (string) $overrides['slug'] )
		: sanitize_title( $clean['name'] );
	$description         = isset( $overrides['description'] ) ? (string) $overrides['description'] : '';
	$clean['description'] = '' !== $description ? wp_kses_post( $description ) : $source->description;

	return $clean;
}

/**
 * Creates (or updates) the translation of a term in the target language.
 *
 * @param int    $term_id         Source term ID.
 * @param string $target_language Target language slug/code.
 * @param array  $overrides       Translated fields: name, slug, description.
 * @return array|WP_Error
 */
function ewpa_multilanguage_create_term_translation( int $term_id, string $target_language, array $overrides = array() ) {
	$source = ewpa_multilanguage_validate_term( $term_id );
	if ( $source instanceof WP_Error ) {
		return $source;
	}

	$target = ewpa_sanitize_language_slug( $target_language );
	if ( '' === $target ) {
		return ewpa_multilanguage_error( 'invalid_language', 'Target language is required.' );
	}

	$plugin = ewpa_get_translation_plugin();
	if ( ! $plugin ) {
		return ewpa_multilanguage_no_plugin_error();
	}

	$known = array();
	$list  = ewpa_multilanguage_list_languages();
	if ( is_array( $list ) ) {
		$known = wp_list_pluck( $list, 'slug' );
	}
	if ( ! empty( $known ) && ! in_array( $target, $known, true ) ) {
		return ewpa_multilanguage_error(
			'invalid_language',
			sprintf( 'Unknown language "%s". Available: %s.', $target, implode( ', ', $known ) )
		);
	}

	$source_language = ewpa_multilanguage_get_term_language( $term_id );
	if ( '' === $source_language ) {
		return ewpa_multilanguage_error(
			'source_language_missing',
			sprintf( 'Term %d has no language assigned. Assign one with ewpa/set-term-language first.', $term_id )
		);
	}
	if ( $source_language === $target ) {
		return ewpa_multilanguage_error(
			'same_language',
			sprintf( 'Term %d is already in "%s".', $term_id, $target )
		);
	}

	$fields = ewpa_multilanguage_normalize_term_overrides( $overrides, $source );

	$existing_map = ewpa_multilanguage_get_term_translations_map( $term_id, $plugin );
	$existing_id  = ewpa_absint( $existing_map[ $target ] ?? 0 );
	if ( $existing_id && $existing_id !== $term_id && ! is_wp_error( get_term( $existing_id ) ) && get_term( $existing_id ) ) {
		return ewpa_multilanguage_update_term_translation( $term_id, $existing_id, $target, $plugin, $fields );
	}

	// Keep the hierarchy: attach to the target-language counterpart of the parent.
	$parent = 0;
	if ( $source->parent ) {
		$mapped = ewpa_multilanguage_map_term( (int) $source->parent, $target, $plugin );
		$parent = $mapped !== (int) $source->parent ? $mapped : 0;
	}

	if ( 'linguator' === $plugin && function_exists( 'linguator_insert_term' ) ) {
		return ewpa_linguator_create_term_translation( $source, $target, $fields, $parent, $existing_map );
	}

	return ewpa_multilanguage_duplicate_term_translation( $source, $target, $plugin, $fields, $parent );
}

/**
 * Updates an already existing term translation with freshly translated fields.
 *
 * @param int    $source_id     Source term ID.
 * @param int    $translated_id Existing translated term ID.
 * @param string $target        Target language slug.
 * @param string $plugin        Detected backend.
 * @param array  $fields        Normalized term fields.
 * @return array|WP_Error
 */
function ewpa_multilanguage_update_term_translation( int $source_id, int $translated_id, string $target, string $plugin, array $fields ) {
	$taxonomy = ewpa_multilanguage_get_term_taxonomy( $translated_id );

	$updated = wp_update_term(
		$translated_id,
		$taxonomy,
		array(
			'name'        => $fields['name'],
			'slug'        => $fields['slug'],
			'description' => $fields['description'],
		)
	);
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return ewpa_multilanguage_term_translation_result( $source_id, $translated_id, $target, $plugin, false );
}

/**
 * Creates the term translation through Linguator's public API.
 *
 * @param WP_Term $source       Source term.
 * @param string  $target       Target language slug.
 * @param array   $fields       Normalized term fields.
 * @param int     $parent       Parent term ID in the target language, 0 for none.
 * @param array   $translations Existing translation map of the source term.
 * @return array|WP_Error
 */
function ewpa_linguator_create_term_translation( $source, string $target, array $fields, int $parent, array $translations ) {
	$args = array(
		'slug'         => $fields['slug'],
		'description'  => $fields['description'],
		'translations' => $translations,
	);
	if ( $parent ) {
		$args['parent'] = $parent;
	}

	$created = linguator_insert_term( $fields['name'], $source->taxonomy, $target, $args );
	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$translated_id = ewpa_absint( $created['term_id'] ?? 0 );
	if ( ! $translated_id ) {
		return ewpa_multilanguage_error(
			'translation_failed',
			sprintf( 'Linguator AI could not create the "%s" translation of term %d.', $target, $source->term_id )
		);
	}

	ewpa_multilanguage_copy_term_meta( (int) $source->term_id, $translated_id );

	// linguator_insert_term() stores the group; re-assert it if the site filters it out.
	$map = ewpa_linguator_get_term_translations_map( (int) $source->term_id );
	if ( ewpa_absint( $map[ $target ] ?? 0 ) !== $translated_id ) {
		$linked = ewpa_linguator_link_term_translation( (int) $source->term_id, $translated_id, $target );
		if ( $linked instanceof WP_Error ) {
			return $linked;
		}
	}

	return ewpa_multilanguage_term_translation_result( (int) $source->term_id, $translated_id, $target, 'linguator', true );
}

/**
 * Creates the term translation by duplicating the source term (Polylang, WPML, fallback).
 *
 * @param WP_Term $source Source term.
 * @param string  $target Target language slug.
 * @param string  $plugin Detected backend.
 * @param array   $fields Normalized term fields.
 * @param int     $parent Parent term ID in the target language, 0 for none.
 * @return array|WP_Error
 */
function ewpa_multilanguage_duplicate_term_translation( $source, string $target, string $plugin, array $fields, int $parent ) {
	$args = array(
		'slug'        => $fields['slug'],
		'description' => $fields['description'],
	);
	if ( $parent ) {
		$args['parent'] = $parent;
	}

	$created = wp_insert_term( $fields['name'], $source->taxonomy, $args );
	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$translated_id = ewpa_absint( $created['term_id'] ?? 0 );
	if ( ! $translated_id ) {
		return ewpa_multilanguage_error(
			'translation_failed',
			sprintf( 'Could not create the "%s" translation of term %d.', $target, $source->term_id )
		);
	}

	ewpa_multilanguage_copy_term_meta( (int) $source->term_id, $translated_id );

	$linked = ewpa_multilanguage_link_term_translation( (int) $source->term_id, $translated_id, $target );
	if ( $linked instanceof WP_Error ) {
		wp_delete_term( $translated_id, $source->taxonomy );
		return $linked;
	}

	return ewpa_multilanguage_term_translation_result( (int) $source->term_id, $translated_id, $target, $plugin, true );
}

/**
 * Builds the response returned by the term translation helpers.
 *
 * @param int    $source_id     Source term ID.
 * @param int    $translated_id Translated term ID.
 * @param string $target        Target language slug.
 * @param string $plugin        Detected backend.
 * @param bool   $created       Whether the term was created (false when updated).
 * @return array
 */
function ewpa_multilanguage_term_translation_result( int $source_id, int $translated_id, string $target, string $plugin, bool $created ): array {
	$term     = get_term( $translated_id );
	$taxonomy = ( $term && ! is_wp_error( $term ) ) ? (string) $term->taxonomy : '';
	$link     = get_term_link( $translated_id );

	return array(
		'term_id'        => $translated_id,
		'source_term_id' => $source_id,
		'taxonomy'       => $taxonomy,
		'language'       => $target,
		'created'        => $created,
		'plugin'         => $plugin,
		'name'           => ( $term && ! is_wp_error( $term ) ) ? $term->name : '',
		'slug'           => ( $term && ! is_wp_error( $term ) ) ? $term->slug : '',
		'permalink'      => is_wp_error( $link ) ? '' : (string) $link,
		'edit_link'      => (string) get_edit_term_link( $translated_id, $taxonomy ),
		'message'        => $created
			? sprintf( 'Created term %d as the "%s" translation of term %d via %s.', $translated_id, $target, $source_id, $plugin )
			: sprintf( 'Term %d already had a "%s" translation (term %d); it was updated with the supplied fields.', $source_id, $target, $translated_id ),
	);
}

/**
 * Copies term meta from the source term to the translated term.
 *
 * @param int $source_id     Source term ID.
 * @param int $translated_id Target term ID.
 * @return void
 */
function ewpa_multilanguage_copy_term_meta( int $source_id, int $translated_id ): void {
	$skip = array();

	/**
	 * Filters the term meta keys skipped when duplicating a term into another language.
	 *
	 * @param string[] $skip          Meta keys to skip.
	 * @param int      $source_id     Source term ID.
	 * @param int      $translated_id Target term ID.
	 */
	$skip = (array) apply_filters( 'ewpa_translation_skipped_term_meta_keys', $skip, $source_id, $translated_id );

	foreach ( get_term_meta( $source_id ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) || 0 === strpos( (string) $key, '_lmat_' ) || 0 === strpos( (string) $key, '_pll_' ) ) {
			continue;
		}
		foreach ( (array) $values as $value ) {
			add_term_meta( $translated_id, $key, maybe_unserialize( $value ) );
		}
	}
}
