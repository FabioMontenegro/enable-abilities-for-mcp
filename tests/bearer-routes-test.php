<?php
/**
 * Covers which REST routes the Bearer token is accepted on.
 *
 * The token is not scoped: ewpa_validate_api_key() only accepts it for a user who can
 * manage_options, so it carries full administrator rights. The route list is the only
 * thing keeping it away from the rest of the REST API.
 *
 * Until 2.13.7 the gate matched $_SERVER['REQUEST_URI'] with a substring search, and
 * that was bypassable: WordPress dispatches $wp->query_vars['rest_route'], and
 * WP::parse_request() lets an explicit query var beat the one the permalink rewrite
 * produced. Appending ?x=/wp-json/mcp/ to any REST URL put the allowed text in the URI
 * while a different route ran. Those requests are the first thing checked here.
 *
 * This loads the real includes/auth.php rather than copying its functions.
 *
 * Run: php tests/bearer-routes-test.php
 */

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
 * json_encode for failure messages.
 *
 * @param mixed $value Value.
 * @return string
 */
function ewpa_show( $value ): string {
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test output only.
}

// ─── WordPress doubles ───────────────────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );
define( 'EWPA_API_KEY_OPTION', 'ewpa_api_key' );

$GLOBALS['ewpa_options'] = array();
$GLOBALS['ewpa_filters'] = array();
$GLOBALS['wp']           = (object) array( 'query_vars' => array() );

/**
 * @param string $option  Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['ewpa_options'] ) ? $GLOBALS['ewpa_options'][ $option ] : $default;
}

/**
 * A real enough hook registry: the filter has to actually run for these checks.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @return bool
 */
function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['ewpa_filters'][ $hook ][ $priority ][] = $callback;
	return true;
}

/**
 * @param string $hook  Hook name.
 * @param mixed  $value Value to filter.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	if ( empty( $GLOBALS['ewpa_filters'][ $hook ] ) ) {
		return $value;
	}

	$by_priority = $GLOBALS['ewpa_filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func( $callback, $value );
		}
	}

	return $value;
}

/**
 * Drops every callback on one hook, so each check starts clean.
 *
 * @param string $hook Hook name.
 * @return void
 */
function ewpa_reset_filter( string $hook ): void {
	unset( $GLOBALS['ewpa_filters'][ $hook ] );
}

/**
 * @param string $string Value.
 * @return string
 */
function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

/**
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * @param mixed $str Value.
 * @return string
 */
function sanitize_text_field( $str ) {
	return is_string( $str ) ? trim( $str ) : '';
}

/**
 * @param int $user_id User ID.
 * @return object|false
 */
function get_userdata( $user_id ) {
	return 7 === (int) $user_id ? (object) array( 'ID' => 7 ) : false;
}

/**
 * The token is only ever accepted for a user who can manage_options.
 *
 * @param object $user       User.
 * @param string $capability Capability.
 * @return bool
 */
function user_can( $user, $capability ) {
	return isset( $user->ID ) && 7 === (int) $user->ID && 'manage_options' === $capability;
}

/**
 * Stands in for WordPress asking who the current user is.
 *
 * The real one re-applies determine_current_user, which is exactly the re-entrancy
 * the recursion guard exists to stop.
 *
 * @return int
 */
function wp_get_current_user() {
	++$GLOBALS['ewpa_depth'];

	if ( $GLOBALS['ewpa_depth'] > 50 ) {
		throw new RuntimeException( 'unbounded recursion' );
	}

	$user = apply_filters( 'determine_current_user', false );
	--$GLOBALS['ewpa_depth'];

	return (int) $user;
}

require_once dirname( __DIR__ ) . '/includes/auth.php';

/**
 * Points the doubles at one request: the route WordPress would dispatch.
 *
 * @param string $route Route, as WordPress would put it in query_vars.
 * @return void
 */
function ewpa_serving( string $route ): void {
	$GLOBALS['wp']->query_vars['rest_route'] = $route;
}

// ─── The bypass that this release fixes ─────────────────────────────────────

echo "== the bypass, as it was reported ==\n";

/*
 * Each of these has /wp-json/mcp/ somewhere in the request URI, which is what the old
 * gate looked at, while WordPress dispatches the route on the right. Every one must be
 * refused now.
 */
$ewpa_smuggled = array(
	'users_through_a_query_var'   => '/wp/v2/users',
	'settings_through_a_query_var' => '/wp/v2/settings',
	'a_plain_rest_route_request'  => '/wp/v2/posts',
);

foreach ( $ewpa_smuggled as $ewpa_name => $ewpa_route ) {
	ewpa_serving( $ewpa_route );

	ewpa_check(
		'refuses_' . $ewpa_name,
		! ewpa_bearer_route_allowed( ewpa_current_rest_route(), ewpa_bearer_route_prefixes() ),
		'the token would authenticate on ' . $ewpa_route
	);
}

// The URI is not consulted at all any more, so a hostile one changes nothing.
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/x?rest_route=/wp/v2/users&context=edit';
ewpa_serving( '/wp/v2/users' );

ewpa_check(
	'the_request_uri_no_longer_decides',
	! ewpa_bearer_route_allowed( ewpa_current_rest_route(), ewpa_bearer_route_prefixes() ),
	'a crafted REQUEST_URI still authorized the request'
);

// ─── Reading the route ───────────────────────────────────────────────────────

echo "\n== the route the plugin reads ==\n";

$ewpa_routes = array(
	'/mcp/mcp-adapter-default-server' => '/mcp/mcp-adapter-default-server',
	'/mcp/'                           => '/mcp',
	'/'                               => '/',
	''                                => '/',
);

foreach ( $ewpa_routes as $ewpa_given => $ewpa_expected ) {
	ewpa_serving( $ewpa_given );

	ewpa_check(
		'normalizes_' . ( '' === $ewpa_given ? 'the_empty_route' : str_replace( '/', '_', $ewpa_given ) ),
		ewpa_current_rest_route() === $ewpa_expected,
		'got ' . ewpa_show( ewpa_current_rest_route() )
	);
}

unset( $GLOBALS['wp']->query_vars['rest_route'] );

ewpa_check(
	'an_absent_route_reads_as_empty',
	'' === ewpa_current_rest_route(),
	'got ' . ewpa_show( ewpa_current_rest_route() )
);

$GLOBALS['wp']->query_vars['rest_route'] = array( '/mcp/' );

ewpa_check(
	'a_non_string_route_reads_as_empty',
	'' === ewpa_current_rest_route(),
	'got ' . ewpa_show( ewpa_current_rest_route() )
);

// ─── What may enter the list ─────────────────────────────────────────────────

echo "\n== prefixes that must be rejected ==\n";

$ewpa_rejected = array(
	'the_empty_string'       => '',
	'a_single_space'         => ' ',
	'a_lone_slash'           => '/',
	'a_double_slash'         => '//',
	'a_prefix_with_no_slash' => 'mcp/',
	'an_integer'             => 42,
	'null'                   => null,
	'an_array'               => array( '/mcp/' ),
	'a_boolean'              => true,
);

foreach ( $ewpa_rejected as $ewpa_name => $ewpa_value ) {
	ewpa_check(
		'rejects_' . $ewpa_name,
		array() === ewpa_filter_route_prefixes( array( $ewpa_value ) ),
		'kept ' . ewpa_show( ewpa_filter_route_prefixes( array( $ewpa_value ) ) )
	);
}

foreach ( array( 'a_bare_string' => 'nope', 'null' => null, 'an_integer' => 7 ) as $ewpa_name => $ewpa_value ) {
	ewpa_check(
		'a_filter_returning_' . $ewpa_name . '_yields_nothing',
		array() === ewpa_filter_route_prefixes( $ewpa_value ),
		'got ' . ewpa_show( ewpa_filter_route_prefixes( $ewpa_value ) )
	);
}

echo "\n== prefixes that must be accepted, normalized to a segment ==\n";

$ewpa_accepted = array(
	'/mcp/'                => '/mcp/',
	'/mcp'                 => '/mcp/',
	'  /jet-engine/v1/mcp  ' => '/jet-engine/v1/mcp/',
	'/jet-engine/v1/mcp//' => '/jet-engine/v1/mcp/',
);

$ewpa_n = 0;

foreach ( $ewpa_accepted as $ewpa_given => $ewpa_expected ) {
	++$ewpa_n;

	ewpa_check(
		'accepts_' . $ewpa_n . '_' . trim( str_replace( array( '/', ' ' ), array( '_', '' ), $ewpa_given ), '_' ),
		array( $ewpa_expected ) === ewpa_filter_route_prefixes( array( $ewpa_given ) ),
		ewpa_show( $ewpa_given ) . ' gave ' . ewpa_show( ewpa_filter_route_prefixes( array( $ewpa_given ) ) )
	);
}

ewpa_check(
	'a_bad_entry_does_not_discard_the_good_ones',
	array( '/mcp/', '/jet-engine/v1/mcp/' ) === ewpa_filter_route_prefixes( array( '/mcp/', '', '/jet-engine/v1/mcp', '/' ) ),
	'got ' . ewpa_show( ewpa_filter_route_prefixes( array( '/mcp/', '', '/jet-engine/v1/mcp', '/' ) ) )
);

ewpa_check(
	'duplicates_collapse_after_normalizing',
	array( '/mcp/' ) === ewpa_filter_route_prefixes( array( '/mcp/', '/mcp', '/mcp//' ) ),
	'got ' . ewpa_show( ewpa_filter_route_prefixes( array( '/mcp/', '/mcp', '/mcp//' ) ) )
);

// ─── Matching ────────────────────────────────────────────────────────────────

echo "\n== which routes match ==\n";

$ewpa_matches = array(
	'the_mcp_server'               => array( '/mcp/mcp-adapter-default-server', true ),
	'the_namespace_index'          => array( '/mcp', true ),
	'the_namespace_index_slashed'  => array( '/mcp/', true ),
	'a_core_route'                 => array( '/wp/v2/users', false ),
	'the_rest_index'               => array( '/', false ),
	'a_namespace_merely_prefixed'  => array( '/mcp-other/x', false ),
	'a_namespace_containing_mcp'   => array( '/thing/mcp/x', false ),
	'the_same_name_in_upper_case'  => array( '/MCP/server', false ),
);

foreach ( $ewpa_matches as $ewpa_name => $ewpa_case ) {
	list( $ewpa_route, $ewpa_expected ) = $ewpa_case;

	ewpa_check(
		( $ewpa_expected ? 'allows_' : 'refuses_' ) . $ewpa_name,
		ewpa_bearer_route_allowed( $ewpa_route, array( '/mcp/' ) ) === $ewpa_expected,
		'got ' . ( $ewpa_expected ? 'refused' : 'allowed' )
	);
}

ewpa_check(
	'an_empty_prefix_list_allows_nothing',
	! ewpa_bearer_route_allowed( '/mcp/server', array() ),
	'an empty list allowed a route through'
);

// ─── The default, and what the filter can do to it ──────────────────────────

echo "\n== the list the plugin actually uses ==\n";

ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );

ewpa_check(
	'the_default_is_the_mcp_namespace_only',
	array( '/mcp/' ) === ewpa_bearer_route_prefixes(),
	'got ' . ewpa_show( ewpa_bearer_route_prefixes() )
);

ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
add_filter(
	'ewpa_bearer_route_prefixes',
	function ( $prefixes ) {
		$prefixes[] = '/jet-engine/v1/mcp';
		return $prefixes;
	}
);

$ewpa_prefixes = ewpa_bearer_route_prefixes();

ewpa_check(
	'a_filter_can_add_another_mcp_namespace',
	array( '/mcp/', '/jet-engine/v1/mcp/' ) === $ewpa_prefixes,
	'got ' . ewpa_show( $ewpa_prefixes )
);

ewpa_check(
	'and_then_that_namespace_matches',
	ewpa_bearer_route_allowed( '/jet-engine/v1/mcp-tools', $ewpa_prefixes ) === false
		&& ewpa_bearer_route_allowed( '/jet-engine/v1/mcp/run', $ewpa_prefixes ),
	'segment matching did not behave as expected'
);

ewpa_check(
	'while_the_rest_of_the_api_still_does_not',
	! ewpa_bearer_route_allowed( '/wp/v2/users', $ewpa_prefixes ),
	'/wp/v2/users matched'
);

foreach ( array( 'nothing' => array(), 'garbage' => 'not an array', 'only_invalid_entries' => array( '', '/' ) ) as $ewpa_name => $ewpa_value ) {
	ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
	add_filter(
		'ewpa_bearer_route_prefixes',
		function () use ( $ewpa_value ) {
			return $ewpa_value;
		}
	);

	ewpa_check(
		'a_filter_returning_' . $ewpa_name . '_falls_back_to_the_default',
		array( '/mcp/' ) === ewpa_bearer_route_prefixes(),
		'got ' . ewpa_show( ewpa_bearer_route_prefixes() )
	);
}

/*
 * A truncated or mistyped prefix reaches nothing, because matching is anchored on a
 * segment. This is the difference that matters: under the old URI substring gate a
 * typo like '/wp-jso' matched every REST URL on the site and silently opened the whole
 * API. Here '/w' is simply not a namespace.
 */
ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
add_filter(
	'ewpa_bearer_route_prefixes',
	function () {
		return array( '/w', '/mc', '/wp-jso' );
	}
);

$ewpa_prefixes = ewpa_bearer_route_prefixes();

foreach ( array( '/wp/v2/users', '/wp/v2/settings', '/mcp/server' ) as $ewpa_route ) {
	ewpa_check(
		'a_mistyped_prefix_does_not_reach_' . str_replace( '/', '_', trim( $ewpa_route, '/' ) ),
		! ewpa_bearer_route_allowed( $ewpa_route, $ewpa_prefixes ),
		$ewpa_route . ' was reachable from ' . ewpa_show( $ewpa_prefixes )
	);
}

/*
 * Naming a real namespace does grant that namespace, and only it. '/wp' is core's own,
 * so a filter adding it hands the token core's REST API on purpose — which is why this
 * filter is documented as granting administrator reach and has no setting behind it.
 */
ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
add_filter(
	'ewpa_bearer_route_prefixes',
	function () {
		return array( '/wp' );
	}
);

$ewpa_prefixes = ewpa_bearer_route_prefixes();

ewpa_check(
	'naming_a_real_namespace_grants_exactly_it',
	ewpa_bearer_route_allowed( '/wp/v2/users', $ewpa_prefixes )
		&& ! ewpa_bearer_route_allowed( '/mcp/server', $ewpa_prefixes )
		&& ! ewpa_bearer_route_allowed( '/wp-site-health/v1/tests', $ewpa_prefixes ),
	'granting /wp did not behave as a namespace grant'
);

// ─── Re-entrancy ─────────────────────────────────────────────────────────────

echo "\n== a filter callback may ask who the current user is ==\n";

define( 'REST_REQUEST', true );

$GLOBALS['ewpa_depth'] = 0;

ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
ewpa_reset_filter( 'determine_current_user' );

add_filter( 'determine_current_user', 'ewpa_authenticate_api_key', 20 );
add_filter(
	'ewpa_bearer_route_prefixes',
	function ( $prefixes ) {
		// The hazard: resolving the user from inside the filter re-enters this very
		// callback, the way map_meta_cap does for some plugins.
		wp_get_current_user();
		return $prefixes;
	}
);

ewpa_serving( '/mcp/server' );

$ewpa_recursed = false;

try {
	ewpa_authenticate_api_key( false );
} catch ( RuntimeException $e ) {
	$ewpa_recursed = true;
}

ewpa_check(
	'a_callback_resolving_the_user_does_not_recurse',
	! $ewpa_recursed,
	'the filter re-entered itself without bound'
);

// ─── The gate, driven end to end ─────────────────────────────────────────────
//
// Everything above exercises the three helpers directly, which says nothing about
// whether the gate is wired into ewpa_authenticate_api_key() at all. Deleting the
// `if ( ! $allowed ) { return $user_id; }` line puts the vulnerability back and left
// the rest of this file green, so these checks drive the real callback with a real
// token instead.

echo "\n== the real callback, with a real token ==\n";

ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
ewpa_reset_filter( 'determine_current_user' );

$ewpa_token = 'a-valid-token';

$GLOBALS['ewpa_options']['ewpa_api_key'] = array(
	'hash'    => hash( 'sha256', $ewpa_token ),
	'user_id' => 7,
);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $ewpa_token;

ewpa_serving( '/mcp/mcp-oauth-server' );

ewpa_check(
	'a_valid_token_authenticates_on_an_allowed_route',
	7 === ewpa_authenticate_api_key( false ),
	'got ' . ewpa_show( ewpa_authenticate_api_key( false ) )
);

// The vulnerability this release fixes, at the only layer that can refuse it.
foreach ( array( '/wp/v2/users', '/wp/v2/settings', '/batch/v1' ) as $ewpa_route ) {
	ewpa_serving( $ewpa_route );

	ewpa_check(
		'a_valid_token_is_refused_on_' . str_replace( '/', '_', trim( $ewpa_route, '/' ) ),
		false === ewpa_authenticate_api_key( false ),
		'the token authenticated on ' . $ewpa_route
	);
}

// A refused route must not leave the recursion guard raised behind it.
ewpa_serving( '/mcp/mcp-oauth-server' );

ewpa_check(
	'a_refused_route_does_not_wedge_the_guard',
	7 === ewpa_authenticate_api_key( false ),
	'a valid token stopped authenticating after a refused call'
);

// And a throwing filter callback must not wedge it either.
ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );
add_filter(
	'ewpa_bearer_route_prefixes',
	function () {
		throw new RuntimeException( 'a third-party callback blew up' );
	}
);

try {
	ewpa_authenticate_api_key( false );
} catch ( RuntimeException $e ) {
	$ewpa_unused = $e->getMessage();
}

ewpa_reset_filter( 'ewpa_bearer_route_prefixes' );

ewpa_check(
	'a_throwing_callback_does_not_wedge_the_guard',
	7 === ewpa_authenticate_api_key( false ),
	'a valid token stopped authenticating after a callback threw'
);

// No route means nothing to authorize against.
unset( $GLOBALS['wp']->query_vars['rest_route'] );

ewpa_check(
	'no_route_means_no_authentication',
	false === ewpa_authenticate_api_key( false ),
	'the token authenticated with no route in query_vars'
);

echo "\n$ewpa_pass passed, $ewpa_fail failed.\n";
exit( $ewpa_fail > 0 ? 1 : 0 );
