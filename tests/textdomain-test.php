<?php
/**
 * Covers loading the translations bundled in languages/.
 *
 * WordPress only looks in wp-content/languages/plugins on its own. The .mo files this
 * plugin ships in languages/ are read only when the plugin declares a Domain Path and
 * calls load_plugin_textdomain(). That call must run on init, not earlier: since
 * WordPress 6.7, loading a text domain before init raises the "translation loading was
 * triggered too early" notice.
 *
 * The loader is read out of the shipped plugin file, the same way
 * key-migrations-test.php reads its functions, so the test exercises the real code.
 *
 * Run: php tests/textdomain-test.php
 */

$ewpa_source        = dirname( __DIR__ ) . '/enable-abilities-for-mcp.php';
$ewpa_plugin_source = (string) file_get_contents( $ewpa_source );

$ewpa_pass = 0;
$ewpa_fail = 0;

/**
 * Records one expectation.
 *
 * @param string $name Check name.
 * @param bool   $ok   Whether it held.
 * @param string $got  Observed value, for a failure message.
 * @return void
 */
function ewpa_check( string $name, bool $ok, string $got = '' ): void {
	global $ewpa_pass, $ewpa_fail;

	if ( $ok ) {
		++$ewpa_pass;
		echo "PASS $name\n";
		return;
	}

	++$ewpa_fail;
	echo "FAIL $name", '' !== $got ? "  <- $got" : '', "\n";
}

// ─── WordPress doubles ───────────────────────────────────────────────────────

// The directory that holds this repository plays wp-content/plugins.
define( 'WP_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) );
define( 'WP_LANG_DIR', '/site/wp-content/languages' );

$GLOBALS['ewpa_textdomain_calls'] = array();
$GLOBALS['ewpa_load_calls']       = array();

/**
 * @return string
 */
function determine_locale() {
	return 'es_ES';
}

/**
 * Records each file WordPress is asked to load for a domain.
 *
 * @param string      $domain Text domain.
 * @param string      $mofile Path to the .mo file.
 * @param string|null $locale Locale.
 * @return bool
 */
function load_textdomain( $domain, $mofile, $locale = null ) {
	$GLOBALS['ewpa_load_calls'][] = array( $domain, $mofile, $locale );
	return true;
}

/**
 * Same contract as core: the path of a plugin file relative to WP_PLUGIN_DIR.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_basename( $file ) {
	$file = str_replace( '\\', '/', $file );
	$base = str_replace( '\\', '/', WP_PLUGIN_DIR ) . '/';
	return 0 === strpos( $file, $base ) ? substr( $file, strlen( $base ) ) : basename( $file );
}

/**
 * Records each call instead of loading anything.
 *
 * @param string       $domain          Text domain.
 * @param string|false $deprecated      Deprecated argument.
 * @param string|false $plugin_rel_path Path relative to WP_PLUGIN_DIR.
 * @return bool
 */
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
	$GLOBALS['ewpa_textdomain_calls'][] = array( $domain, $deprecated, $plugin_rel_path );
	return true;
}

// ─── header ──────────────────────────────────────────────────────────────────

echo "== plugin header ==\n";

$ewpa_has_domain_path = (bool) preg_match( '/^\s*\*\s*Domain Path:\s*(\S+)\s*$/m', $ewpa_plugin_source, $ewpa_matches );
ewpa_check( 'header_declares_domain_path', $ewpa_has_domain_path, 'no Domain Path header' );
ewpa_check( 'domain_path_is_languages', $ewpa_has_domain_path && '/languages' === $ewpa_matches[1], $ewpa_matches[1] ?? '' );

// ─── hook ────────────────────────────────────────────────────────────────────

echo "\n== when the text domain loads ==\n";

$ewpa_hooked = preg_match_all( "/add_action\(\s*'([a-z_]+)',\s*'ewpa_load_textdomain'/", $ewpa_plugin_source, $ewpa_matches );
ewpa_check( 'loader_is_hooked_once', 1 === $ewpa_hooked, (string) $ewpa_hooked . ' hooks' );
ewpa_check( 'loader_runs_on_init', 1 === $ewpa_hooked && 'init' === $ewpa_matches[1][0], implode( ',', $ewpa_matches[1] ) );

// Any other place the loader could be called directly, before init.
// Counted on PHP tokens, so mentions in comments do not count.
$ewpa_direct = 0;
foreach ( token_get_all( $ewpa_plugin_source ) as $ewpa_token ) {
	if ( is_array( $ewpa_token ) && T_STRING === $ewpa_token[0] && 'load_plugin_textdomain' === $ewpa_token[1] ) {
		++$ewpa_direct;
	}
}
ewpa_check( 'load_plugin_textdomain_called_only_in_the_loader', 1 === $ewpa_direct, (string) $ewpa_direct . ' calls' );

// ─── the call ────────────────────────────────────────────────────────────────

echo "\n== what the loader asks WordPress for ==\n";

$ewpa_loaded = false;
if ( preg_match( '/^function ewpa_load_textdomain\s*\(.*?^\}/ms', $ewpa_plugin_source, $ewpa_matches ) ) {
	// __FILE__ inside eval() is not the plugin file, so put the plugin file back.
	eval( str_replace( '__FILE__', var_export( $ewpa_source, true ), $ewpa_matches[0] ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- loads the shipped function instead of a copy of it.
	$ewpa_loaded = true;
}
ewpa_check( 'loader_function_exists', $ewpa_loaded, 'ewpa_load_textdomain() not found' );

if ( $ewpa_loaded ) {
	ewpa_load_textdomain();

	$ewpa_calls = $GLOBALS['ewpa_textdomain_calls'];
	ewpa_check( 'one_call', 1 === count( $ewpa_calls ), (string) count( $ewpa_calls ) );

	$ewpa_call = $ewpa_calls[0] ?? array( null, null, null );
	ewpa_check( 'domain_is_the_plugin_slug', 'enable-abilities-for-mcp' === $ewpa_call[0], (string) $ewpa_call[0] );
	ewpa_check( 'deprecated_argument_is_false', false === $ewpa_call[1], var_export( $ewpa_call[1], true ) );

	$ewpa_expected_rel = basename( dirname( __DIR__ ) ) . '/languages';
	ewpa_check( 'path_is_the_plugin_languages_dir', $ewpa_expected_rel === $ewpa_call[2], (string) $ewpa_call[2] );

	// The path WordPress resolves must hold the Spanish catalog the plugin ships.
	$ewpa_mo = WP_PLUGIN_DIR . '/' . $ewpa_call[2] . '/enable-abilities-for-mcp-es_ES.mo';
	ewpa_check( 'resolved_path_holds_es_ES_mo', is_string( $ewpa_call[2] ) && is_file( $ewpa_mo ), $ewpa_mo );

	/*
	 * load_plugin_textdomain() only registers a fallback path: a WordPress.org pack in
	 * wp-content/languages/plugins hides the bundled catalog, even when the pack lacks
	 * strings. Both files are loaded explicitly instead. WordPress returns the first
	 * file that has a string, so the pack goes first (its community fixes win) and the
	 * bundled catalog fills every string the pack lacks.
	 */
	echo "\n== the WordPress.org pack and the bundled catalog are both loaded ==\n";

	$ewpa_loads = $GLOBALS['ewpa_load_calls'];
	ewpa_check( 'two_files_loaded', 2 === count( $ewpa_loads ), (string) count( $ewpa_loads ) );

	$ewpa_pack    = $ewpa_loads[0] ?? array( null, null, null );
	$ewpa_bundled = $ewpa_loads[1] ?? array( null, null, null );

	ewpa_check( 'first_is_the_wporg_pack', WP_LANG_DIR . '/plugins/enable-abilities-for-mcp-es_ES.mo' === $ewpa_pack[1], (string) $ewpa_pack[1] );
	ewpa_check(
		'second_is_the_bundled_catalog',
		is_string( $ewpa_bundled[1] ) && realpath( $ewpa_bundled[1] ) === realpath( dirname( __DIR__ ) . '/languages/enable-abilities-for-mcp-es_ES.mo' ),
		(string) $ewpa_bundled[1]
	);
	ewpa_check( 'both_use_the_plugin_domain', 'enable-abilities-for-mcp' === $ewpa_pack[0] && 'enable-abilities-for-mcp' === $ewpa_bundled[0] );
	ewpa_check( 'both_use_the_current_locale', 'es_ES' === $ewpa_pack[2] && 'es_ES' === $ewpa_bundled[2] );
}

echo "\n$ewpa_pass passed, $ewpa_fail failed\n";
exit( $ewpa_fail > 0 ? 1 : 0 );
