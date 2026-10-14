<?php
/**
 * Checks the surface of wp-media/mcp-oauth that this plugin actually depends on.
 *
 * Every other suite runs against WordPress doubles, so none of them loads the real
 * library: when it went from 1.0.3 to 2.0 all of them stayed green without exercising a
 * single line of it. A renamed class, a method that stopped being static, a changed
 * signature or a filter renamed upstream would be found by a user, not by a test.
 *
 * Every call site here is already guarded with class_exists(), so a class that
 * disappears degrades instead of fatally failing. A *changed signature* does not: that
 * still throws at runtime, which is the main thing this file is here to catch.
 *
 * It is deliberately narrow — a contract check against a dependency, not a test of the
 * library's behaviour, which is its authors' job.
 *
 * Run: php tests/oauth-library-contract-test.php
 */

$ewpa_root = dirname( __DIR__ );

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

/**
 * Asserts that a method exists and that our call sites still fit its signature.
 *
 * @param string $class    Fully qualified class name.
 * @param string $method   Method name.
 * @param bool   $static   Whether we call it statically.
 * @param int    $passed   How many arguments our call sites pass.
 * @return void
 */
function ewpa_check_method( string $class, string $method, bool $static, int $passed ): void {
	$label = substr( $class, strrpos( $class, '\\' ) + 1 ) . '_' . $method;

	if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
		ewpa_check( 'exists_' . $label, false, 'missing' );
		return;
	}

	ewpa_check( 'exists_' . $label, true );

	$reflection = new ReflectionMethod( $class, $method );

	ewpa_check(
		'still_public_' . $label,
		$reflection->isPublic(),
		$reflection->isPublic() ? '' : 'no longer public'
	);

	ewpa_check(
		( $static ? 'still_static_' : 'still_an_instance_method_' ) . $label,
		$reflection->isStatic() === $static,
		$reflection->isStatic() ? 'is static' : 'is an instance method'
	);

	$required = $reflection->getNumberOfRequiredParameters();
	$accepts  = $reflection->getNumberOfParameters();

	ewpa_check(
		'accepts_our_' . $passed . '_arguments_' . $label,
		$required <= $passed && $passed <= $accepts,
		"requires $required, accepts up to $accepts, we pass $passed"
	);
}

// ─── The autoloader has to be real ──────────────────────────────────────────

$ewpa_autoload = $ewpa_root . '/vendor/autoload.php';

if ( ! is_readable( $ewpa_autoload ) ) {
	echo "FAIL vendor/autoload.php is missing — run composer install\n";
	exit( 1 );
}

require $ewpa_autoload;
ewpa_check( 'the_real_composer_autoloader_loads', true );

// ─── The version we ship is the one we pinned ───────────────────────────────

$ewpa_installed = json_decode( (string) file_get_contents( $ewpa_root . '/vendor/composer/installed.json' ), true );
$ewpa_packages  = $ewpa_installed['packages'] ?? $ewpa_installed ?? array();
$ewpa_library   = '';

foreach ( (array) $ewpa_packages as $ewpa_package ) {
	if ( 'wp-media/mcp-oauth' === ( $ewpa_package['name'] ?? '' ) ) {
		$ewpa_library = (string) ( $ewpa_package['version'] ?? '' );
	}
}

ewpa_check( 'mcp_oauth_is_installed', '' !== $ewpa_library, 'not found in installed.json' );

$ewpa_composer = json_decode( (string) file_get_contents( $ewpa_root . '/composer.json' ), true );
$ewpa_pinned   = (string) ( $ewpa_composer['require']['wp-media/mcp-oauth'] ?? '' );

ewpa_check(
	'installed_version_matches_the_pin',
	ltrim( $ewpa_library, 'v' ) === ltrim( $ewpa_pinned, 'v^~' ),
	"installed $ewpa_library, pinned $ewpa_pinned"
);

// The adapter is a separate plugin and must never end up inside our vendor tree: a copy
// in here is what makes MCP Adapter 0.7.0 stand down on a site that also has the plugin.
ewpa_check(
	'mcp_adapter_is_not_vendored',
	! is_dir( $ewpa_root . '/vendor/wordpress/mcp-adapter' ),
	'vendor/wordpress/mcp-adapter exists'
);

// ─── Classes we construct or call ───────────────────────────────────────────

echo "\n== classes used in executable code ==\n";

$ewpa_classes = array(
	'WPMedia\\MCP\\OAuth\\Bootstrap',
	'WPMedia\\MCP\\OAuth\\Context',
	'WPMedia\\MCP\\OAuth\\Auth\\JWT',
	'WPMedia\\MCP\\OAuth\\Auth\\SecretManager',
	'WPMedia\\MCP\\OAuth\\Auth\\ClaudeClientVerifier',
	'WPMedia\\MCP\\OAuth\\Logging\\McpLogger',
	'WPMedia\\MCP\\OAuth\\Views\\Render',
);

foreach ( $ewpa_classes as $ewpa_class ) {
	ewpa_check( 'resolves_' . substr( $ewpa_class, strrpos( $ewpa_class, '\\' ) + 1 ), class_exists( $ewpa_class ) );
}

// ─── Signatures our call sites rely on ──────────────────────────────────────

echo "\n== signatures our call sites rely on ==\n";

// includes/admin.php:349
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Bootstrap', 'schedule_rewrite_flush', true, 0 );

// includes/oauth-connectors.php:200 — constructed, then called on the instance.
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Context', 'is_enabled', false, 0 );

// These two are called together, and only in these two places:
//
//   includes/oauth-connectors.php:1344
//   includes/oauth-sessions.php:363
//
// Both read JWT::decode( $token, SecretManager::get_secret() ), so both sites use the
// same arities — decode with 2 arguments, get_secret with none — and one check each
// covers them. Should a third call site appear with a different arity, it needs its own
// check: this file is the only place that mismatch would ever surface.
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Auth\\JWT', 'decode', true, 2 );
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Auth\\SecretManager', 'get_secret', true, 0 );

// includes/oauth-connectors.php:1090
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Auth\\ClaudeClientVerifier', 'is_trusted_host', false, 1 );

// includes/oauth-connectors.php:1562
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Logging\\McpLogger', 'log', true, 3 );

// includes/oauth-connectors.php:1275
ewpa_check_method( 'WPMedia\\MCP\\OAuth\\Views\\Render', 'view', false, 2 );

// Those three are constructed with no arguments.
foreach ( array( 'WPMedia\\MCP\\OAuth\\Context', 'WPMedia\\MCP\\OAuth\\Auth\\ClaudeClientVerifier', 'WPMedia\\MCP\\OAuth\\Views\\Render' ) as $ewpa_class ) {
	$ewpa_short = substr( $ewpa_class, strrpos( $ewpa_class, '\\' ) + 1 );

	if ( ! class_exists( $ewpa_class ) ) {
		ewpa_check( 'constructible_with_no_arguments_' . $ewpa_short, false, 'class missing' );
		continue;
	}

	$ewpa_constructor = ( new ReflectionClass( $ewpa_class ) )->getConstructor();
	$ewpa_required    = null === $ewpa_constructor ? 0 : $ewpa_constructor->getNumberOfRequiredParameters();

	ewpa_check(
		'constructible_with_no_arguments_' . $ewpa_short,
		0 === $ewpa_required,
		"constructor requires $ewpa_required arguments"
	);
}

// ─── Filters we hook ────────────────────────────────────────────────────────

echo "\n== filters this plugin hooks ==\n";

/**
 * Tells whether the library still applies a filter name.
 *
 * A filter is a string, not a symbol, so a rename is invisible to PHP: our hook would
 * simply stop running and nothing would complain. Reading the library source is the
 * only way to catch that. A mention inside a comment or an apply_filters_deprecated()
 * shim does not count, because neither one runs our callback.
 *
 * @param string $root   Plugin root.
 * @param string $filter Filter name.
 * @return bool
 */
function ewpa_library_applies_filter( string $root, string $filter ): bool {
	$dir = $root . '/vendor/wp-media/mcp-oauth/inc';

	if ( ! is_dir( $dir ) ) {
		return false;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$source = (string) file_get_contents( $file->getPathname() );

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) ) {
				$source = str_replace( $token[1], '', $source );
			}
		}

		if ( preg_match( '/(?:wpm_)?apply_filters(?:_typed)?\s*\(\s*(?:[\'"][a-z]+[\'"]\s*,\s*)?[\'"]' . preg_quote( $filter, '/' ) . '[\'"]/', $source ) ) {
			return true;
		}
	}

	return false;
}

foreach ( array( 'wpmedia_mcp_oauth_server_enabled', 'wpmedia_mcp_oauth_trusted_publishers' ) as $ewpa_filter ) {
	ewpa_check( 'library_still_applies_' . $ewpa_filter, ewpa_library_applies_filter( $ewpa_root, $ewpa_filter ) );
}

echo "\n$ewpa_pass passed, $ewpa_fail failed.\n";
exit( $ewpa_fail > 0 ? 1 : 0 );
