<?php
/**
 * Regression tests for the OAuth connector callback allowlist.
 *
 * These functions are the security boundary for RFC 7591 dynamic client
 * registration: a self-registered client may only ever return a user to a
 * URL the administrator listed. Run this suite whenever
 * includes/oauth-connectors.php changes and on every wp-media/mcp-oauth
 * upgrade.
 *
 * Run with: php tests/oauth-connectors-test.php
 *
 * @package EnableAbilitiesForMCP
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ewpa_test_options'] = array();

/**
 * Minimal get_option() backed by an in-memory store.
 *
 * @param string $name    Option name.
 * @param mixed  $default Value returned when the option is absent.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ewpa_test_options'] ) ? $GLOBALS['ewpa_test_options'][ $name ] : $default;
}

/*
 * wp_parse_url() and its helpers, copied verbatim from wp-includes/http.php.
 * A hand-written approximation would defeat the purpose: this suite exists to
 * catch parser-level redirect tricks, so the parser must be core's own.
 */

// phpcs:disable
function wp_parse_url( $url, $component = -1 ) {
	$to_unset = array();
	$url      = (string) $url;

	if ( str_starts_with( $url, '//' ) ) {
		$to_unset[] = 'scheme';
		$url        = 'placeholder:' . $url;
	} elseif ( str_starts_with( $url, '/' ) ) {
		$to_unset[] = 'scheme';
		$to_unset[] = 'host';
		$url        = 'placeholder://placeholder' . $url;
	}

	$parts = parse_url( $url );

	if ( false === $parts ) {
		// Parsing failure.
		return $parts;
	}

	// Remove the placeholder values.
	foreach ( $to_unset as $key ) {
		unset( $parts[ $key ] );
	}

	return _get_component_from_parsed_url_array( $parts, $component );
}

function _get_component_from_parsed_url_array( $url_parts, $component = -1 ) {
	if ( -1 === $component ) {
		return $url_parts;
	}

	$key = _wp_translate_php_url_constant_to_key( $component );
	if ( false !== $key && is_array( $url_parts ) && isset( $url_parts[ $key ] ) ) {
		return $url_parts[ $key ];
	} else {
		return null;
	}
}

function _wp_translate_php_url_constant_to_key( $constant ) {
	$translation = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_USER     => 'user',
		PHP_URL_PASS     => 'pass',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);

	return $translation[ $constant ] ?? false;
}
// phpcs:enable

require dirname( __DIR__ ) . '/includes/oauth-connectors.php';

$ewpa_failures = 0;
$ewpa_total    = 0;

/**
 * Records one boolean expectation.
 *
 * @param string $label    Case description.
 * @param bool   $expected Expected result.
 * @param bool   $actual   Actual result.
 */
function ewpa_check( string $label, bool $expected, bool $actual ): void {
	global $ewpa_failures, $ewpa_total;
	++$ewpa_total;

	if ( $expected === $actual ) {
		echo 'PASS ' . $label . PHP_EOL;
		return;
	}

	++$ewpa_failures;
	echo 'FAIL ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' . PHP_EOL;
}

/**
 * Records one array expectation.
 *
 * @param string $label    Case description.
 * @param array  $expected Expected result.
 * @param array  $actual   Actual result.
 */
function ewpa_check_same( string $label, array $expected, array $actual ): void {
	global $ewpa_failures, $ewpa_total;
	++$ewpa_total;

	if ( $expected === $actual ) {
		echo 'PASS ' . $label . PHP_EOL;
		return;
	}

	++$ewpa_failures;
	echo 'FAIL ' . $label . PHP_EOL . '  expected: ' . var_export( $expected, true ) . PHP_EOL . '  got:      ' . var_export( $actual, true ) . PHP_EOL;
}

/**
 * Sets the stored allowlist. Null removes the option so defaults apply.
 *
 * @param string|null $raw Raw allowlist, or null for "never saved".
 */
function ewpa_set_allowlist( ?string $raw ): void {
	if ( null === $raw ) {
		unset( $GLOBALS['ewpa_test_options'][ EWPA_CALLBACKS_OPTION ] );
		return;
	}

	$GLOBALS['ewpa_test_options'][ EWPA_CALLBACKS_OPTION ] = $raw;
}

$backslash = chr( 92 );

echo '--- default allowlist (never saved) ---' . PHP_EOL;
ewpa_set_allowlist( null );
ewpa_check( 'accepts a ChatGPT connector callback from the defaults', true, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/conn_abc123' ) );
ewpa_check( 'accepts an exact default callback', true, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector_platform_oauth_redirect' ) );
ewpa_check( 'rejects a host absent from the defaults', false, ewpa_oauth_callback_is_allowed( 'https://evil.example.com/connector/oauth/abc' ) );

echo '--- pinned allowlist: rejections ---' . PHP_EOL;
ewpa_set_allowlist( 'https://chatgpt.com/connector/oauth/*' );
ewpa_check( 'rejects an off-list host', false, ewpa_oauth_callback_is_allowed( 'https://evil.example.com/steal' ) );
ewpa_check( 'rejects userinfo in the authority', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com@evil.example.com/connector/oauth/abc' ) );
ewpa_check( 'rejects user:pass in the authority', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com:x@evil.example.com/connector/oauth/abc' ) );
ewpa_check( 'rejects a backslash before the authority @', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com' . $backslash . '@evil.example.com/connector/oauth/abc' ) );
ewpa_check( 'rejects subdomain confusion', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com.evil.example.com/connector/oauth/abc' ) );
ewpa_check( 'rejects a port the pattern does not name', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com:8443/connector/oauth/abc' ) );
ewpa_check( 'rejects a lookalike path prefix', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauthX/abc' ) );
ewpa_check( 'rejects a query string smuggled through the wildcard', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/abc?next=https://evil.example.com' ) );
ewpa_check( 'rejects any fragment', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/abc#@evil.example.com' ) );
ewpa_check( 'rejects plain http on a non-loopback host', false, ewpa_oauth_callback_is_allowed( 'http://chatgpt.com/connector/oauth/abc' ) );
ewpa_check( 'rejects a protocol-relative URL', false, ewpa_oauth_callback_is_allowed( '//evil.example.com/connector/oauth/abc' ) );
ewpa_check( 'rejects a javascript: scheme', false, ewpa_oauth_callback_is_allowed( 'javascript://chatgpt.com/connector/oauth/abc' ) );
ewpa_check( 'rejects an empty string', false, ewpa_oauth_callback_is_allowed( '' ) );
ewpa_check( 'rejects whitespace only', false, ewpa_oauth_callback_is_allowed( '   ' ) );
ewpa_check( 'rejects a URI longer than 2000 characters', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/' . str_repeat( 'a', 2000 ) ) );

echo '--- pinned allowlist: acceptances ---' . PHP_EOL;
ewpa_check( 'accepts a legitimate connector callback', true, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/abc123' ) );
ewpa_check( 'accepts an uppercase host (DNS is case-insensitive)', true, ewpa_oauth_callback_is_allowed( 'https://CHATGPT.com/connector/oauth/abc' ) );
ewpa_check( 'accepts a deeper path under the wildcard', true, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/a/b/c' ) );
// An "@" after the first path slash is a literal path character, not userinfo
// (RFC 3986: the authority ends at that slash), so the destination host is
// still chatgpt.com. Accepting it is correct; this case guards against a
// future "fix" that would wrongly treat it as an attack.
ewpa_check( 'accepts an @ inside the path (host is still chatgpt.com)', true, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/x@evil.example.com/cb' ) );

echo '--- cleared allowlist ---' . PHP_EOL;
// An administrator who saves an empty list must block everything. Falling back
// to the defaults here would silently re-open what they meant to close.
ewpa_set_allowlist( '' );
ewpa_check( 'an empty saved allowlist blocks even a default callback', false, ewpa_oauth_callback_is_allowed( 'https://chatgpt.com/connector/oauth/abc123' ) );

echo '--- admin input parsing ---' . PHP_EOL;
$raw = implode(
	"\n",
	array(
		'# ChatGPT connectors',
		'https://chatgpt.com/connector/oauth/*',
		'',
		'https://*.example.com/cb',
		'ftp://chatgpt.com/cb',
		'chatgpt.com/no-scheme',
		'https://chatgpt.com/connector/oauth/*',
		'https://app.example.com/callback',
	)
);
ewpa_check_same(
	'keeps valid entries, drops comments, blanks, wildcard hosts, non-http schemes, schemeless entries and duplicates',
	array( 'https://chatgpt.com/connector/oauth/*', 'https://app.example.com/callback' ),
	ewpa_oauth_parse_callback_list( $raw )
);

echo '--- loopback (RFC 8252) ---' . PHP_EOL;
ewpa_check( '127.0.0.1 is loopback', true, ewpa_oauth_is_loopback_host( '127.0.0.1' ) );
ewpa_check( 'localhost is loopback', true, ewpa_oauth_is_loopback_host( 'localhost' ) );
ewpa_check( '::1 is loopback', true, ewpa_oauth_is_loopback_host( '::1' ) );
// PHP's parse_url() keeps the brackets around an IPv6 literal, so the host a
// matcher actually receives for http://[::1]:port/ is "[::1]", not "::1".
ewpa_check( '[::1] (as returned by parse_url) is loopback', true, ewpa_oauth_is_loopback_host( '[::1]' ) );
ewpa_check( 'a bracketed non-loopback host is not loopback', false, ewpa_oauth_is_loopback_host( '[evil.example.com]' ) );
ewpa_check( 'a bracketed IPv4 literal is not loopback (brackets are IPv6-only)', false, ewpa_oauth_is_loopback_host( '[127.0.0.1]' ) );
ewpa_check( '127.0.0.2 is not treated as loopback', false, ewpa_oauth_is_loopback_host( '127.0.0.2' ) );
ewpa_check( 'a public host is not loopback', false, ewpa_oauth_is_loopback_host( 'evil.example.com' ) );

ewpa_set_allowlist( 'http://127.0.0.1/callback' );
ewpa_check( 'accepts an IPv4 loopback callback on an ephemeral port', true, ewpa_oauth_callback_is_allowed( 'http://127.0.0.1:53214/callback' ) );
ewpa_check( 'rejects an IPv4 loopback callback on a different path', false, ewpa_oauth_callback_is_allowed( 'http://127.0.0.1:53214/other' ) );
ewpa_check( 'rejects a lookalike loopback host', false, ewpa_oauth_callback_is_allowed( 'http://localhost.evil.example.com/callback' ) );

ewpa_set_allowlist( 'http://[::1]/callback' );
ewpa_check( 'accepts an IPv6 loopback callback on an ephemeral port', true, ewpa_oauth_callback_is_allowed( 'http://[::1]:53214/callback' ) );

echo '--- authorization-time redirect_uri match ---' . PHP_EOL;
$registered = array( 'https://chatgpt.com/connector/oauth/abc123', 'http://127.0.0.1/callback' );
ewpa_check( 'exact https match', true, ewpa_oauth_redirect_uri_matches( 'https://chatgpt.com/connector/oauth/abc123', $registered ) );
ewpa_check( 'different https path does not match', false, ewpa_oauth_redirect_uri_matches( 'https://chatgpt.com/connector/oauth/other', $registered ) );
ewpa_check( 'added query string does not match', false, ewpa_oauth_redirect_uri_matches( 'https://chatgpt.com/connector/oauth/abc123?x=1', $registered ) );
ewpa_check( 'loopback on a different port matches', true, ewpa_oauth_redirect_uri_matches( 'http://127.0.0.1:61000/callback', $registered ) );
ewpa_check( 'loopback on a different path does not match', false, ewpa_oauth_redirect_uri_matches( 'http://127.0.0.1:61000/other', $registered ) );
ewpa_check( 'IPv6 loopback on a different port matches', true, ewpa_oauth_redirect_uri_matches( 'http://[::1]:61000/callback', array( 'http://[::1]/callback' ) ) );

echo PHP_EOL;

if ( $ewpa_failures > 0 ) {
	echo $ewpa_failures . ' of ' . $ewpa_total . ' check(s) failed.' . PHP_EOL;
	exit( 1 );
}

echo $ewpa_total . ' check(s) passed.' . PHP_EOL;
