<?php
/**
 * Bearer token authentication for Enable Abilities for MCP.
 *
 * Optional single-admin API key via Bearer token on MCP REST routes.
 * Only active when the ewpa_bearer_enabled option is on.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a new API key, stores its hash, and returns the plain key.
 *
 * @param int $user_id The user ID to associate with the key.
 * @return string The plain API key (shown only once).
 */
function ewpa_generate_api_key( int $user_id ): string {
	$plain_key = wp_generate_password( 48, false );

	update_option(
		EWPA_API_KEY_OPTION,
		array(
			'hash'       => hash( 'sha256', $plain_key ),
			'user_id'    => $user_id,
			'created_at' => time(),
		),
		false
	);

	return $plain_key;
}

/**
 * Revokes the current API key.
 */
function ewpa_revoke_api_key(): bool {
	return delete_option( EWPA_API_KEY_OPTION );
}

/**
 * Validates a plain API key against the stored hash.
 *
 * @param string $plain_key The plain API key to validate.
 * @return int|false The associated user ID on success, false on failure.
 */
function ewpa_validate_api_key( string $plain_key ) {
	$stored = get_option( EWPA_API_KEY_OPTION );

	if ( ! is_array( $stored ) || empty( $stored['hash'] ) || empty( $stored['user_id'] ) ) {
		return false;
	}

	if ( ! hash_equals( $stored['hash'], hash( 'sha256', $plain_key ) ) ) {
		return false;
	}

	$user = get_userdata( $stored['user_id'] );
	if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
		return false;
	}

	return (int) $stored['user_id'];
}

/**
 * Retrieves the Authorization header from the request, checking multiple
 * sources for server-configuration compatibility.
 */
function ewpa_get_authorization_header(): string {
	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
	}

	if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	}

	if ( function_exists( 'apache_request_headers' ) ) {
		$headers = apache_request_headers();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'authorization' ) {
					return sanitize_text_field( $value );
				}
			}
		}
	}

	if ( function_exists( 'getallheaders' ) ) {
		$headers = getallheaders();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'authorization' ) {
					return sanitize_text_field( $value );
				}
			}
		}
	}

	return '';
}

/**
 * Returns the REST route WordPress is serving for this request.
 *
 * Not the request URI, which is a different thing and must never be used to
 * decide authorization. WordPress dispatches `$wp->query_vars['rest_route']`
 * (wp-includes/rest-api.php, rest_api_loaded()), and for every public query var
 * WP::parse_request() prefers an explicit `$_GET`/`$_POST` value over the one the
 * permalink rewrite produced. A request to `/wp-json/mcp/x?rest_route=/wp/v2/users`
 * therefore has `/wp-json/mcp/` in its URI while being dispatched to
 * `/wp/v2/users`, and a guard reading the URI authorizes the wrong route.
 *
 * Callers must already have established that REST_REQUEST is defined. That
 * constant is only defined after rest_api_loaded() has confirmed the query var
 * is a non-empty string, so by then it is both present and authoritative.
 *
 * @return string The route, normalized the way core normalizes it, or '' if unknown.
 */
function ewpa_current_rest_route(): string {
	if ( ! isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		return '';
	}

	$route = $GLOBALS['wp']->query_vars['rest_route'];

	if ( ! is_string( $route ) ) {
		return '';
	}

	// Mirrors rest_api_loaded(), so this sees exactly what gets served.
	$route = untrailingslashit( $route );

	return '' === $route ? '/' : $route;
}

/**
 * Returns the REST route prefixes the Bearer token is accepted on.
 *
 * The token is not a scoped credential: ewpa_validate_api_key() only accepts it
 * for a user who can manage_options, so it carries full administrator rights.
 * This list is the only thing keeping it away from the rest of the REST API,
 * which is why it is deliberately narrow and has no setting behind it. Widening
 * it is a decision that belongs in reviewed code, not one click away in wp-admin.
 *
 * @return array Route prefixes, each ending in '/', matched against the start of the route.
 */
function ewpa_bearer_route_prefixes(): array {
	$default = '/mcp/';

	/**
	 * Filters the REST route prefixes the Bearer token authenticates on.
	 *
	 * These are route prefixes, not URL paths: '/mcp/', not '/wp-json/mcp/'.
	 * Every prefix added here can be driven by the token as an administrator,
	 * and whatever the route checks for itself becomes the only remaining
	 * protection. Add another MCP server's namespace if you run one — for
	 * example '/jet-engine/v1/mcp' — and nothing broader.
	 *
	 * Entries must be non-empty strings beginning with '/'. A bare '/' is
	 * rejected, since it would match every route on the site.
	 *
	 * @param array $prefixes Route prefixes, starting with the built-in MCP one.
	 */
	$prefixes = apply_filters( 'ewpa_bearer_route_prefixes', array( $default ) );

	$prefixes = ewpa_filter_route_prefixes( $prefixes );

	// A filter that returned nothing usable must not switch Bearer auth off by
	// accident; that is what the ewpa_bearer_enabled option is for.
	return array() === $prefixes ? array( $default ) : $prefixes;
}

/**
 * Keeps only the route prefixes that are safe to match a Bearer token against.
 *
 * Separate from the filter call so it can be tested on its own. Every surviving
 * entry is normalized to end in '/', which is what makes the comparison in
 * ewpa_bearer_route_allowed() a segment boundary rather than a text prefix:
 * '/mcp/' must not match the route '/mcp-something/x'.
 *
 * @param mixed $prefixes Whatever the filter returned.
 * @return array Valid prefixes, in the order given, without duplicates.
 */
function ewpa_filter_route_prefixes( $prefixes ): array {
	if ( ! is_array( $prefixes ) ) {
		return array();
	}

	$valid = array();

	foreach ( $prefixes as $prefix ) {
		if ( ! is_string( $prefix ) ) {
			continue;
		}

		$prefix = trim( $prefix );

		if ( '' === $prefix || '/' !== $prefix[0] ) {
			continue;
		}

		// rtrim leaves '' for '/' and '//', both of which match every route.
		$prefix = rtrim( $prefix, '/' );

		if ( '' === $prefix ) {
			continue;
		}

		$valid[] = $prefix . '/';
	}

	return array_values( array_unique( $valid ) );
}

/**
 * Tells whether the route being served falls under one of the allowed prefixes.
 *
 * The route gets a trailing slash before the comparison so that a prefix only
 * ever matches on a segment boundary: with prefix '/mcp/', the route '/mcp'
 * matches as '/mcp/', '/mcp/server' matches, and '/mcp-other/x' does not.
 *
 * Matching is anchored and case sensitive, because REST route matching in
 * WordPress is.
 *
 * @param string $route    Route from ewpa_current_rest_route().
 * @param array  $prefixes Prefixes already normalized by ewpa_filter_route_prefixes().
 * @return bool
 */
function ewpa_bearer_route_allowed( string $route, array $prefixes ): bool {
	if ( '' === $route ) {
		return false;
	}

	$haystack = $route . '/';

	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $haystack, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Authenticates REST API requests via Bearer token for MCP routes.
 *
 * @param int|false $user_id The current user ID or false.
 * @return int|false
 */
function ewpa_authenticate_api_key( $user_id ) {
	// Guard: prevent infinite recursion when user_can() inside ewpa_validate_api_key()
	// triggers map_meta_cap, which some plugins (e.g. Yoast SEO) handle by calling
	// wp_get_current_user() — re-entering this determine_current_user filter.
	static $resolving = false;
	if ( $resolving ) {
		return $user_id;
	}

	if ( ! empty( $user_id ) ) {
		return $user_id;
	}

	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return $user_id;
	}

	// The prefix list goes through a filter, and a callback is free to ask who the
	// current user is, which re-enters this very filter. Arm the guard around it for
	// the same reason it is armed around ewpa_validate_api_key() below.
	$resolving = true;
	$allowed   = ewpa_bearer_route_allowed( ewpa_current_rest_route(), ewpa_bearer_route_prefixes() );
	$resolving = false;

	if ( ! $allowed ) {
		return $user_id;
	}

	$auth_header = ewpa_get_authorization_header();
	if ( empty( $auth_header ) || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
		return $user_id;
	}

	$token = substr( $auth_header, 7 );
	if ( empty( $token ) ) {
		return $user_id;
	}

	$resolving = true;
	$validated = ewpa_validate_api_key( $token );
	$resolving = false;

	if ( false === $validated ) {
		return $user_id;
	}

	return $validated;
}

// Backward compat: if option never saved but a key exists, treat it as enabled.
$ewpa_bearer_on = get_option( 'ewpa_bearer_enabled' );
if ( false === $ewpa_bearer_on ) {
	$ewpa_bearer_on = (bool) get_option( EWPA_API_KEY_OPTION );
}

if ( $ewpa_bearer_on ) {
	add_filter( 'determine_current_user', 'ewpa_authenticate_api_key', 20 );
}
