<?php
/**
 * Code Snippets 3.x API doubles (namespaced, like the real plugin).
 *
 * @package EnableAbilitiesForMCP
 */

namespace Code_Snippets\Model;

/**
 * Minimal stand-in for Code_Snippets\Model\Snippet.
 */
class Snippet {
	public $id       = 0;
	public $name     = '';
	public $desc     = '';
	public $code     = '';
	public $tags     = array();
	public $scope    = 'global';
	public $active   = false;
	public $trashed  = false;
	public $locked   = false;
	public $priority = 10;
	public $modified = '';
	public $type     = 'php';

	/**
	 * Mirrors Snippet::get_type().
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}
}

namespace Code_Snippets;

/**
 * Returns a stored snippet or an empty one, like the real get_snippet().
 *
 * @param int $id Snippet ID.
 * @return Model\Snippet
 */
function get_snippet( int $id = 0 ) {
	return $GLOBALS['ewpa_cs_snippets'][ $id ] ?? new Model\Snippet();
}

/**
 * Returns all stored snippets.
 *
 * @return Model\Snippet[]
 */
function get_snippets(): array {
	return array_values( $GLOBALS['ewpa_cs_snippets'] );
}

/**
 * Stores a snippet.
 *
 * @param Model\Snippet $snippet Snippet.
 * @return Model\Snippet
 */
function save_snippet( $snippet ) {
	if ( ! $snippet->id ) {
		$snippet->id = ++$GLOBALS['ewpa_cs_next_id'];
	}
	$GLOBALS['ewpa_cs_snippets'][ $snippet->id ] = $snippet;
	return $snippet;
}

/**
 * Activates a snippet; returns a message string on failure, like the real API.
 *
 * @param int $id Snippet ID.
 * @return Model\Snippet|string
 */
function activate_snippet( int $id ) {
	if ( ! empty( $GLOBALS['ewpa_cs_refuse_activation'] ) ) {
		return 'Could not activate snippet: code did not pass validation.';
	}
	$GLOBALS['ewpa_cs_snippets'][ $id ]->active = true;
	$GLOBALS['ewpa_cs_activated'][]             = $id;
	return $GLOBALS['ewpa_cs_snippets'][ $id ];
}

/**
 * Deactivates a snippet.
 *
 * @param int $id Snippet ID.
 * @return Model\Snippet|null
 */
function deactivate_snippet( int $id ) {
	if ( ! isset( $GLOBALS['ewpa_cs_snippets'][ $id ] ) ) {
		return null;
	}
	$GLOBALS['ewpa_cs_snippets'][ $id ]->active = false;
	return $GLOBALS['ewpa_cs_snippets'][ $id ];
}
