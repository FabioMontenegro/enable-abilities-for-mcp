<?php
/**
 * Third-party OAuth connectors (ChatGPT and other MCP clients).
 *
 * The embedded wp-media/mcp-oauth library only admits clients that publish a
 * Client ID Metadata Document (CIMD) on an allowlisted host — in practice,
 * claude.ai. ChatGPT connectors instead expect RFC 7591 Dynamic Client
 * Registration (or a client_id/secret pair pasted into the connector form),
 * so this module adds a locally-registered client model beside the CIMD one.
 *
 * Nothing in vendor/ is patched. Every handler here runs on template_redirect
 * at priority 9 — one tick before the library's Router and discovery endpoints
 * — and only takes over the request when the client belongs to this module.
 * Otherwise it returns and the library's own CIMD path handles the request
 * exactly as before. Consent, auth-code issuance, token issuance, refresh
 * rotation and transport authentication are all still the library's.
 *
 * Every locally-registered redirect_uri must match the admin-managed callback
 * allowlist (Settings › WP Abilities › Connection), both at registration time
 * and again at every authorization request.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Master switch for this module (the site-wide OAuth toggle still gates it).
define( 'EWPA_CONNECTORS_OPTION', 'ewpa_oauth_connectors_enabled' );

// Newline-separated allowlist of callback (redirect_uri) patterns.
define( 'EWPA_CALLBACKS_OPTION', 'ewpa_oauth_callback_allowlist' );

// Registered client records, keyed by client_id.
define( 'EWPA_CLIENTS_OPTION', 'ewpa_oauth_clients' );

// Bumped whenever the /oauth/register rewrite rule changes.
define( 'EWPA_CONNECTORS_REWRITE_VERSION', '1' );
define( 'EWPA_CONNECTORS_REWRITE_OPTION', 'ewpa_oauth_connectors_rewrite_version' );

// Query var routing /oauth/register. Deliberately distinct from the library's
// mcp_oauth_endpoint, whose Router 404s any value it does not know.
define( 'EWPA_CONNECTORS_QUERY_VAR', 'ewpa_oauth_endpoint' );

// Ceiling on stored clients. /oauth/register is unauthenticated, so the option
// must not be allowed to grow without bound; oldest DCR records are evicted.
define( 'EWPA_MAX_CLIENTS', 50 );

// Registrations allowed per IP per hour.
define( 'EWPA_REGISTER_RATE_LIMIT', 20 );

/*
 * ==========================================================================
 * BOOT
 * ==========================================================================
 */

/**
 * Wires the connector endpoints. Called from ewpa_maybe_boot_oauth(), so it
 * only ever runs when the site-wide OAuth layer is on.
 *
 * @return void
 */
function ewpa_oauth_connectors_boot(): void {
	add_action( 'init', 'ewpa_oauth_connectors_rewrite' );
	add_filter( 'query_vars', 'ewpa_oauth_connectors_query_vars' );
	add_action( 'init', 'ewpa_oauth_connectors_maybe_flush', 21 );

	// Priority 9: ahead of the library's Router and Discovery\Endpoints (10).
	add_action( 'template_redirect', 'ewpa_oauth_connectors_dispatch', 9 );
}

/**
 * Registers the /oauth/register rewrite rule.
 *
 * @return void
 */
function ewpa_oauth_connectors_rewrite(): void {
	add_rewrite_rule(
		'^oauth/register$',
		'index.php?' . EWPA_CONNECTORS_QUERY_VAR . '=register',
		'top'
	);
}

/**
 * Whitelists this module's query var.
 *
 * @param string[] $vars Existing query vars.
 * @return string[]
 */
function ewpa_oauth_connectors_query_vars( array $vars ): array {
	$vars[] = EWPA_CONNECTORS_QUERY_VAR;

	return $vars;
}

/**
 * Persists the /oauth/register rule once per rewrite-version bump.
 *
 * Runs at init priority 21, after the library's own flush at 20, so a single
 * flush covers both rule sets on a fresh install.
 *
 * @return void
 */
function ewpa_oauth_connectors_maybe_flush(): void {
	$flagged = get_option( EWPA_CONNECTORS_REWRITE_OPTION ) === EWPA_CONNECTORS_REWRITE_VERSION;

	// Plain permalinks never persist pretty rules, so there is nothing to
	// check and nothing to flush — the flag alone stops this repeating.
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		if ( ! $flagged ) {
			update_option( EWPA_CONNECTORS_REWRITE_OPTION, EWPA_CONNECTORS_REWRITE_VERSION, false );
		}

		return;
	}

	$rules = get_option( 'rewrite_rules' );

	// Presence of the rule is the real test, and it self-heals every case the
	// flag cannot see: a fresh site, the OAuth layer switched on after init,
	// another plugin's flush dropping our rule, or a changed regex in a later
	// version. The library flushes at init priority 20, one tick earlier, so
	// on a fresh enable our rule has usually been persisted already.
	if ( is_array( $rules ) && array_key_exists( '^oauth/register$', $rules ) ) {
		if ( ! $flagged ) {
			update_option( EWPA_CONNECTORS_REWRITE_OPTION, EWPA_CONNECTORS_REWRITE_VERSION, false );
		}

		return;
	}

	flush_rewrite_rules( false );
	update_option( EWPA_CONNECTORS_REWRITE_OPTION, EWPA_CONNECTORS_REWRITE_VERSION, false );
}

/**
 * Forces the next init to re-persist the /oauth/register rule.
 *
 * @return void
 */
function ewpa_oauth_connectors_schedule_flush(): void {
	delete_option( EWPA_CONNECTORS_REWRITE_OPTION );
}

/**
 * Routes an incoming request to the right connector handler.
 *
 * Each handler returns without output when the request is not this module's,
 * leaving the library's Router (template_redirect priority 10) to serve it.
 *
 * @return void
 */
function ewpa_oauth_connectors_dispatch(): void {
	if ( 'register' === (string) get_query_var( EWPA_CONNECTORS_QUERY_VAR, '' ) ) {
		ewpa_oauth_handle_register();
		return;
	}

	if ( 'authorization-server' === (string) get_query_var( 'mcp_oauth_discovery', '' ) ) {
		ewpa_oauth_serve_metadata();
		return;
	}

	switch ( (string) get_query_var( 'mcp_oauth_endpoint', '' ) ) {
		case 'authorize':
			ewpa_oauth_maybe_handle_authorize();
			break;
		case 'authorize-callback':
			ewpa_oauth_maybe_handle_callback();
			break;
		case 'token':
			ewpa_oauth_maybe_authenticate_client();
			break;
	}
}

/*
 * ==========================================================================
 * STATE
 * ==========================================================================
 */

/**
 * Whether the OAuth server itself is serving requests.
 *
 * Mirrors the library's Context::is_enabled() so a filter that disables the
 * server also disables everything here.
 *
 * @return bool
 */
function ewpa_oauth_server_is_enabled(): bool {
	if ( ! get_option( 'ewpa_oauth_enabled' ) ) {
		return false;
	}

	if ( class_exists( '\WPMedia\MCP\OAuth\Context' ) ) {
		return ( new \WPMedia\MCP\OAuth\Context() )->is_enabled();
	}

	return true;
}

/**
 * Whether third-party (ChatGPT / DCR) connectors are accepted.
 *
 * @return bool
 */
function ewpa_oauth_connectors_enabled(): bool {
	return ewpa_oauth_server_is_enabled() && (bool) get_option( EWPA_CONNECTORS_OPTION, false );
}

/**
 * The callback URLs prefilled into the allowlist on first use.
 *
 * These are the redirect URIs ChatGPT connectors have been observed to use.
 * They are a starting point, not a guarantee — the connector screen shows the
 * exact callback URL it will use, and the admin can edit this list freely.
 *
 * @return string[]
 */
function ewpa_oauth_default_callbacks(): array {
	return array(
		// ChatGPT mints a connector id per connector, so the callback is a
		// wildcard: https://chatgpt.com/connector/oauth/<connector id>.
		'https://chatgpt.com/connector/oauth/*',
		'https://chatgpt.com/connector_platform_oauth_redirect',
		'https://chatgpt.com/backend-api/aip/connectors/links/oauth/callback',
		'https://chat.openai.com/connector_platform_oauth_redirect',
		'https://chat.openai.com/aip/*/oauth/callback',
	);
}

/**
 * Returns the configured callback allowlist.
 *
 * @return string[] One pattern per entry, already trimmed and de-duplicated.
 */
function ewpa_oauth_get_callback_allowlist(): array {
	$stored = get_option( EWPA_CALLBACKS_OPTION, false );

	if ( false === $stored ) {
		return ewpa_oauth_default_callbacks();
	}

	return ewpa_oauth_parse_callback_list( (string) $stored );
}

/**
 * Splits a newline-separated allowlist into normalised patterns.
 *
 * @param string $raw Raw textarea contents.
 * @return string[]
 */
function ewpa_oauth_parse_callback_list( string $raw ): array {
	$lines    = preg_split( '/[\r\n]+/', $raw );
	$patterns = array();

	foreach ( (array) $lines as $line ) {
		$line = trim( (string) $line );

		// Blank lines and # comments let the admin annotate the list.
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}

		if ( strlen( $line ) > 2000 ) {
			continue;
		}

		$parts = wp_parse_url( $line );

		// A pattern must name a literal scheme and host: wildcards are only
		// ever expanded inside the path and query, never in the destination.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			continue;
		}

		if ( false !== strpos( (string) $parts['host'], '*' ) ) {
			continue;
		}

		if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'https', 'http' ), true ) ) {
			continue;
		}

		$patterns[] = $line;
	}

	return array_values( array_unique( $patterns ) );
}

/**
 * Stores a new callback allowlist.
 *
 * @param string $raw Raw textarea contents.
 * @return string[] The patterns that were kept.
 */
function ewpa_oauth_save_callback_allowlist( string $raw ): array {
	$patterns = ewpa_oauth_parse_callback_list( $raw );

	update_option( EWPA_CALLBACKS_OPTION, implode( "\n", $patterns ), false );

	return $patterns;
}

/*
 * ==========================================================================
 * CALLBACK MATCHING
 * ==========================================================================
 */

/**
 * Whether a redirect_uri is permitted by the admin's callback allowlist.
 *
 * @param string $uri The redirect_uri to test.
 * @return bool
 */
function ewpa_oauth_callback_is_allowed( string $uri ): bool {
	$uri = trim( $uri );

	if ( '' === $uri || strlen( $uri ) > 2000 ) {
		return false;
	}

	$parts = wp_parse_url( $uri );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}

	// A fragment or userinfo component in a redirect target is never legitimate
	// here and is a classic way to smuggle a different destination past a check.
	if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}

	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

	// HTTPS everywhere, with plain-HTTP loopback allowed for native apps (RFC 8252 §8.3).
	if ( 'https' !== $scheme && ! ( 'http' === $scheme && ewpa_oauth_is_loopback_host( (string) $parts['host'] ) ) ) {
		return false;
	}

	foreach ( ewpa_oauth_get_callback_allowlist() as $pattern ) {
		if ( ewpa_oauth_callback_pattern_matches( $pattern, $uri ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Matches one allowlist pattern against a redirect_uri.
 *
 * Scheme, host and port must match literally. Inside the path and query, `*`
 * stands for any run of characters other than `?` and `#`, so a wildcard can
 * widen the path but can never swallow a query string or reach a new host.
 * The port is ignored for loopback URIs, whose port is assigned per session.
 *
 * @param string $pattern Allowlist entry.
 * @param string $uri     The redirect_uri to test.
 * @return bool
 */
function ewpa_oauth_callback_pattern_matches( string $pattern, string $uri ): bool {
	$p = wp_parse_url( $pattern );
	$u = wp_parse_url( $uri );

	if ( ! is_array( $p ) || ! is_array( $u ) ) {
		return false;
	}

	if ( strtolower( (string) ( $p['scheme'] ?? '' ) ) !== strtolower( (string) ( $u['scheme'] ?? '' ) ) ) {
		return false;
	}

	$host = strtolower( (string) ( $u['host'] ?? '' ) );

	if ( strtolower( (string) ( $p['host'] ?? '' ) ) !== $host ) {
		return false;
	}

	if ( ! ewpa_oauth_is_loopback_host( $host ) ) {
		$p_port = isset( $p['port'] ) ? (int) $p['port'] : 0;
		$u_port = isset( $u['port'] ) ? (int) $u['port'] : 0;

		if ( $p_port !== $u_port ) {
			return false;
		}
	}

	$p_rest = (string) ( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	$u_rest = (string) ( $u['path'] ?? '' ) . ( isset( $u['query'] ) ? '?' . $u['query'] : '' );

	$regex = '#^' . str_replace( '\*', '[^?\#]*', preg_quote( $p_rest, '#' ) ) . '$#';

	return 1 === preg_match( $regex, $u_rest );
}

/**
 * Whether a host is a loopback address.
 *
 * PHP's parse_url() keeps the brackets around an IPv6 literal, so an
 * http://[::1]:port/ URI reaches this check as `[::1]`; both forms match.
 *
 * @param string $host Host component.
 * @return bool
 */
function ewpa_oauth_is_loopback_host( string $host ): bool {
	return in_array( strtolower( $host ), array( '127.0.0.1', 'localhost', '::1', '[::1]' ), true );
}

/**
 * Whether a runtime redirect_uri matches one registered for a client.
 *
 * Exact match, except for loopback URIs, whose ephemeral port varies per
 * session and is therefore ignored (RFC 8252 §7.3). Mirrors the library's
 * AuthorizeEndpoint::redirect_uri_matches().
 *
 * @param string   $provided   redirect_uri from the request.
 * @param string[] $registered redirect_uris stored on the client record.
 * @return bool
 */
function ewpa_oauth_redirect_uri_matches( string $provided, array $registered ): bool {
	if ( in_array( $provided, $registered, true ) ) {
		return true;
	}

	$p = wp_parse_url( $provided );

	if ( ! is_array( $p ) || 'http' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || ! ewpa_oauth_is_loopback_host( (string) ( $p['host'] ?? '' ) ) ) {
		return false;
	}

	foreach ( $registered as $candidate ) {
		$c = wp_parse_url( (string) $candidate );

		if ( ! is_array( $c ) || 'http' !== strtolower( (string) ( $c['scheme'] ?? '' ) ) || ! ewpa_oauth_is_loopback_host( (string) ( $c['host'] ?? '' ) ) ) {
			continue;
		}

		if (
			strtolower( (string) ( $p['host'] ?? '' ) ) === strtolower( (string) ( $c['host'] ?? '' ) )
			&& (string) ( $p['path'] ?? '' ) === (string) ( $c['path'] ?? '' )
		) {
			return true;
		}
	}

	return false;
}

/*
 * ==========================================================================
 * CIMD — CLIENT ID METADATA DOCUMENTS FOR NON-CLAUDE PUBLISHERS
 * ==========================================================================
 */

/**
 * Maximum size of a metadata document this site will read.
 */
define( 'EWPA_CIMD_MAX_BYTES', 8192 );

/**
 * Publisher hosts whose metadata documents may be dereferenced.
 *
 * Derived from the callback allowlist rather than a second setting: a host the
 * admin has already trusted to receive authorization codes is, by
 * construction, safe to fetch a metadata document from — strictly less
 * dangerous than redirecting a user there with a code. It also means there is
 * one list to maintain, and no way for the two to drift apart.
 *
 * Loopback entries are excluded; a native app on localhost does not publish a
 * metadata document, and dereferencing 127.0.0.1 server-side is pointless.
 *
 * @return string[] Lowercased hostnames.
 */
function ewpa_oauth_cimd_hosts(): array {
	$hosts = array();

	foreach ( ewpa_oauth_get_callback_allowlist() as $pattern ) {
		$parts = wp_parse_url( $pattern );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			continue;
		}

		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			continue;
		}

		$host = strtolower( (string) $parts['host'] );

		if ( ewpa_oauth_is_loopback_host( $host ) ) {
			continue;
		}

		$hosts[ $host ] = true;
	}

	return array_keys( $hosts );
}

/**
 * Resolve a Client ID Metadata Document into a client record.
 *
 * The bundled library also implements CIMD, but pins each publisher to an
 * exact, known client_id URL and refuses any document whose
 * token_endpoint_auth_method is not "none". Neither holds for ChatGPT: it
 * mints a fresh client_id URL per connector, and its document declares
 * private_key_jwt. This resolver takes the publisher host as the trust anchor
 * instead, and treats the client as public — this server only ever advertises
 * "none", PKCE binds the authorization code, and no client authentication is
 * relied upon at the token endpoint.
 *
 * @param string $client_id The client_id URL the connector presented.
 * @return array<string, mixed>|WP_Error|null Client record; WP_Error when the
 *         document resolves but is unusable; null when the host is not ours to
 *         resolve, leaving the caller to report a generic failure.
 */
function ewpa_oauth_resolve_cimd( string $client_id ) {
	$parts = wp_parse_url( $client_id );

	if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
		return null;
	}

	// A fragment or userinfo component is never legitimate in a client_id.
	if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return null;
	}

	$path = (string) ( $parts['path'] ?? '' );

	if ( '' === $path || '/' === $path ) {
		return null;
	}

	$host = strtolower( (string) $parts['host'] );

	if ( ! in_array( $host, ewpa_oauth_cimd_hosts(), true ) ) {
		return null;
	}

	$cache_key = 'ewpa_cimd_' . md5( $client_id );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_safe_remote_get(
		$client_id,
		array(
			'timeout'             => 5,
			'redirection'         => 0,
			'limit_response_size' => EWPA_CIMD_MAX_BYTES,
			'headers'             => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		ewpa_oauth_log(
			'CIMD',
			'fetch failed',
			array(
				'client_id' => $client_id,
				'error'     => $response->get_error_message(),
			)
		);

		return new WP_Error(
			'cimd_fetch_failed',
			sprintf(
				/* translators: %s: the metadata URL that could not be fetched */
				__( 'This site could not fetch the connector\'s metadata document at %s. Check that the site can make outbound HTTPS requests.', 'enable-abilities-for-mcp' ),
				$client_id
			)
		);
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error(
			'cimd_fetch_failed',
			sprintf(
				/* translators: 1: metadata URL, 2: HTTP status code */
				__( 'The connector\'s metadata document at %1$s returned HTTP %2$d.', 'enable-abilities-for-mcp' ),
				$client_id,
				(int) wp_remote_retrieve_response_code( $response )
			)
		);
	}

	$doc = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $doc ) || empty( $doc ) ) {
		return new WP_Error( 'cimd_invalid', __( 'The connector\'s metadata document is not a JSON object.', 'enable-abilities-for-mcp' ) );
	}

	// The document must claim the exact URL it was fetched from, so a publisher
	// cannot host a document that impersonates a different client.
	if ( ewpa_oauth_str( $doc['client_id'] ?? null ) !== $client_id ) {
		return new WP_Error( 'cimd_invalid', __( 'The connector\'s metadata document declares a different client_id than the URL it was fetched from.', 'enable-abilities-for-mcp' ) );
	}

	$redirects = ewpa_oauth_str_list( $doc['redirect_uris'] ?? null );

	if ( empty( $redirects ) ) {
		return new WP_Error( 'cimd_invalid', __( 'The connector\'s metadata document lists no redirect_uris.', 'enable-abilities-for-mcp' ) );
	}

	$grants = ewpa_oauth_str_list( $doc['grant_types'] ?? null );

	if ( ! empty( $grants ) && ! in_array( 'authorization_code', $grants, true ) ) {
		return new WP_Error( 'cimd_invalid', __( 'The connector does not offer the authorization_code grant.', 'enable-abilities-for-mcp' ) );
	}

	// The callback allowlist still governs, exactly as it does for a client
	// that registers itself. A publisher does not get to nominate its own
	// redirect targets just because its host is trusted.
	$allowed = array_values( array_filter( $redirects, 'ewpa_oauth_callback_is_allowed' ) );

	if ( empty( $allowed ) ) {
		return new WP_Error(
			'cimd_redirect_not_allowed',
			sprintf(
				/* translators: %s: the callback URL the connector wants to use */
				__( 'The connector wants to send users back to %s, which is not in this site\'s allowed callback URLs. Add it under Settings › WP Abilities › Connection › Allowed callback URLs, then try again.', 'enable-abilities-for-mcp' ),
				implode( ', ', $redirects )
			)
		);
	}

	$client_name = sanitize_text_field( ewpa_oauth_str( $doc['client_name'] ?? null ) );

	$record = array(
		'client_id'                  => $client_id,
		'client_name'                => '' !== $client_name ? $client_name : $host,
		'client_uri'                 => esc_url_raw( ewpa_oauth_str( $doc['client_uri'] ?? null ) ),
		'redirect_uris'              => $allowed,
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		// Treated as public regardless of what the document asks for: this
		// server advertises only "none" and never checks client credentials.
		'token_endpoint_auth_method' => 'none',
		'client_secret_hash'         => '',
		'source'                     => 'cimd',
		'publisher'                  => $host,
		'created'                    => time(),
	);

	set_transient( $cache_key, $record, HOUR_IN_SECONDS );

	ewpa_oauth_log(
		'CIMD',
		'client resolved',
		array(
			'client_id' => $client_id,
			'publisher' => $host,
		)
	);

	return $record;
}

/*
 * ==========================================================================
 * CLIENT STORE
 * ==========================================================================
 */

/**
 * Returns every locally-registered client, keyed by client_id.
 *
 * @return array<string, array<string, mixed>>
 */
function ewpa_oauth_get_clients(): array {
	$clients = get_option( EWPA_CLIENTS_OPTION, array() );

	if ( ! is_array( $clients ) ) {
		return array();
	}

	// Every later reader indexes into these records directly, so a truncated
	// or hand-edited option must not be able to reach them malformed.
	return array_filter(
		$clients,
		static function ( $client ) {
			return is_array( $client ) && ! empty( $client['client_id'] ) && ! empty( $client['redirect_uris'] );
		}
	);
}

/**
 * Looks up one locally-registered client.
 *
 * @param string $client_id The client_id presented by the client.
 * @return array<string, mixed>|null Null when the id is not one of ours.
 */
function ewpa_oauth_get_client( string $client_id ): ?array {
	if ( '' === $client_id || ! preg_match( '/^ewpa_[0-9a-f]{32}$/', $client_id ) ) {
		return null;
	}

	$clients = ewpa_oauth_get_clients();

	return isset( $clients[ $client_id ] ) && is_array( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
}

/**
 * Deletes a locally-registered client, ending its ability to start new sessions.
 *
 * Sessions already issued to it are WordPress Application Passwords and are
 * revoked from Users › Profile › Application Passwords, as before.
 *
 * @param string $client_id The client to remove.
 * @return bool Whether a record was removed.
 */
function ewpa_oauth_delete_client( string $client_id ): bool {
	$clients = ewpa_oauth_get_clients();

	if ( ! isset( $clients[ $client_id ] ) ) {
		return false;
	}

	unset( $clients[ $client_id ] );
	update_option( EWPA_CLIENTS_OPTION, $clients, false );

	return true;
}

/**
 * Creates and stores a client record.
 *
 * @param array<string, mixed> $meta   Requested client metadata.
 * @param string               $source 'dcr' for /oauth/register, 'manual' for the admin screen.
 * @return array<string, mixed>|WP_Error The stored record plus a one-time
 *                                       'client_secret' key when confidential.
 */
function ewpa_oauth_create_client( array $meta, string $source ) {
	$requested = ewpa_oauth_str_list( $meta['redirect_uris'] ?? null );
	$redirects = array();

	foreach ( $requested as $uri ) {
		$uri = trim( $uri );

		if ( '' === $uri ) {
			continue;
		}

		if ( ! ewpa_oauth_callback_is_allowed( $uri ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				sprintf(
					/* translators: %s: the rejected callback URL */
					__( '%s is not in the allowed callback URLs list.', 'enable-abilities-for-mcp' ),
					$uri
				)
			);
		}

		$redirects[] = $uri;
	}

	$redirects = array_values( array_unique( $redirects ) );

	if ( empty( $redirects ) ) {
		return new WP_Error( 'invalid_client_metadata', __( 'At least one redirect_uri is required.', 'enable-abilities-for-mcp' ) );
	}

	if ( count( $redirects ) > 10 ) {
		return new WP_Error( 'invalid_client_metadata', __( 'Too many redirect_uris.', 'enable-abilities-for-mcp' ) );
	}

	// RFC 7591 defaults an omitted auth method to client_secret_basic, but an
	// MCP client that says nothing is a public PKCE client in practice, and
	// handing back a secret it never asked for would break its token request.
	$auth_method = isset( $meta['token_endpoint_auth_method'] ) ? ewpa_oauth_str( $meta['token_endpoint_auth_method'] ) : 'none';

	if ( '' === $auth_method ) {
		$auth_method = 'none';
	}

	if ( ! in_array( $auth_method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
		return new WP_Error( 'invalid_client_metadata', __( 'Unsupported token_endpoint_auth_method.', 'enable-abilities-for-mcp' ) );
	}

	$client_name = sanitize_text_field( ewpa_oauth_str( $meta['client_name'] ?? null ) );

	if ( '' === $client_name ) {
		$client_name = (string) wp_parse_url( $redirects[0], PHP_URL_HOST );
	}

	if ( '' === $client_name ) {
		$client_name = 'MCP Client';
	}

	$client_uri = esc_url_raw( ewpa_oauth_str( $meta['client_uri'] ?? null ) );

	if ( '' === $client_uri ) {
		$parsed     = wp_parse_url( $redirects[0] );
		$client_uri = is_array( $parsed ) && ! empty( $parsed['host'] )
			? $parsed['scheme'] . '://' . $parsed['host']
			: '';
	}

	$secret = '';
	$hash   = '';

	if ( 'none' !== $auth_method ) {
		$secret = wp_generate_password( 48, false );
		$hash   = wp_hash_password( $secret );
	}

	$client_id = 'ewpa_' . bin2hex( random_bytes( 16 ) );

	$record = array(
		'client_id'                  => $client_id,
		'client_name'                => $client_name,
		'client_uri'                 => $client_uri,
		'redirect_uris'              => $redirects,
		// The library's TokenEndpoint only implements these two.
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'token_endpoint_auth_method' => $auth_method,
		'client_secret_hash'         => $hash,
		'source'                     => 'manual' === $source ? 'manual' : 'dcr',
		'created'                    => time(),
		'created_by'                 => get_current_user_id(),
	);

	$clients               = ewpa_oauth_get_clients();
	$clients[ $client_id ] = $record;
	$clients               = ewpa_oauth_prune_clients( $clients );

	update_option( EWPA_CLIENTS_OPTION, $clients, false );

	if ( '' !== $secret ) {
		$record['client_secret'] = $secret;
	}

	return $record;
}

/**
 * Drops the oldest self-registered clients once the store exceeds its cap.
 *
 * Only 'dcr' records are evicted; a client an admin created by hand is never
 * removed behind their back.
 *
 * @param array<string, array<string, mixed>> $clients Current store.
 * @return array<string, array<string, mixed>>
 */
function ewpa_oauth_prune_clients( array $clients ): array {
	if ( count( $clients ) <= EWPA_MAX_CLIENTS ) {
		return $clients;
	}

	$evictable = array_filter(
		$clients,
		static function ( $client ) {
			return 'manual' !== ( $client['source'] ?? 'dcr' );
		}
	);

	uasort(
		$evictable,
		static function ( $a, $b ) {
			return ( (int) ( $a['created'] ?? 0 ) ) <=> ( (int) ( $b['created'] ?? 0 ) );
		}
	);

	foreach ( array_keys( $evictable ) as $client_id ) {
		if ( count( $clients ) <= EWPA_MAX_CLIENTS ) {
			break;
		}

		unset( $clients[ $client_id ] );
	}

	return $clients;
}

/*
 * ==========================================================================
 * DISCOVERY — RFC 8414 AUTHORIZATION SERVER METADATA
 * ==========================================================================
 */

/**
 * Serves the authorization-server metadata document with the registration
 * endpoint advertised.
 *
 * ChatGPT reads registration_endpoint from this document to register itself;
 * the library's own document omits it because CIMD clients never register.
 * The shared fields mirror WPMedia\MCP\OAuth\Auth\Discovery\Endpoints — when
 * connectors are off, this returns and that class serves the request instead.
 *
 * @return void
 */
function ewpa_oauth_serve_metadata(): void {
	if ( ! ewpa_oauth_connectors_enabled() ) {
		return;
	}

	// Rewrite endpoints resolve against the Site Address, so home_url() — not
	// get_site_url() — is the base on split-directory installs.
	$base = home_url();

	wp_send_json(
		array(
			'issuer'                                => $base,
			'authorization_endpoint'                => $base . '/oauth/authorize',
			'token_endpoint'                        => $base . '/oauth/token',
			'revocation_endpoint'                   => $base . '/oauth/revoke',
			'registration_endpoint'                 => $base . '/oauth/register',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'scopes_supported'                      => array( 'mcp' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'client_id_metadata_document_supported' => true,
		)
	);
}

/*
 * ==========================================================================
 * REGISTRATION — RFC 7591 DYNAMIC CLIENT REGISTRATION
 * ==========================================================================
 */

/**
 * Handles POST /oauth/register.
 *
 * @return void
 */
function ewpa_oauth_handle_register(): void {
	if ( ! ewpa_oauth_server_is_enabled() ) {
		// A stale rewrite rule can still route here after the server is turned
		// off; without this the main query falls through to the homepage.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		return;
	}

	nocache_headers();

	// Unauthenticated, credential-free endpoint — a browser-based MCP client
	// has to be able to reach it cross-origin.
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Access-Control-Allow-Headers: Content-Type' );
	header( 'Access-Control-Allow-Methods: POST, OPTIONS' );

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

	if ( 'OPTIONS' === $method ) {
		status_header( 204 );
		exit;
	}

	if ( 'POST' !== $method ) {
		ewpa_oauth_send_error( 405, 'invalid_request', 'Method not allowed.' );
	}

	if ( ! ewpa_oauth_connectors_enabled() ) {
		ewpa_oauth_send_error( 403, 'access_denied', 'Dynamic client registration is disabled on this site.' );
	}

	if ( ! ewpa_oauth_register_rate_limit_ok() ) {
		ewpa_oauth_send_error( 429, 'temporarily_unavailable', 'Too many registration attempts. Try again later.' );
	}

	$body = ewpa_oauth_parse_request_body();

	// response_types, when stated, must include the only flow we implement.
	if ( isset( $body['response_types'] ) && ! in_array( 'code', ewpa_oauth_str_list( $body['response_types'] ), true ) ) {
		ewpa_oauth_send_error( 400, 'invalid_client_metadata', 'Only the "code" response type is supported.' );
	}

	if ( isset( $body['grant_types'] ) && ! in_array( 'authorization_code', ewpa_oauth_str_list( $body['grant_types'] ), true ) ) {
		ewpa_oauth_send_error( 400, 'invalid_client_metadata', 'The authorization_code grant is required.' );
	}

	$client = ewpa_oauth_create_client( $body, 'dcr' );

	if ( is_wp_error( $client ) ) {
		$code = 'invalid_redirect_uri' === $client->get_error_code() ? 'invalid_redirect_uri' : 'invalid_client_metadata';
		ewpa_oauth_send_error( 400, $code, $client->get_error_message() );
	}

	ewpa_oauth_log(
		'REGISTER',
		'client registered',
		array(
			'client_id'     => $client['client_id'],
			'client_name'   => $client['client_name'],
			'redirect_uris' => $client['redirect_uris'],
		)
	);

	$response = array(
		'client_id'                  => $client['client_id'],
		'client_id_issued_at'        => $client['created'],
		'client_name'                => $client['client_name'],
		'redirect_uris'              => $client['redirect_uris'],
		'grant_types'                => $client['grant_types'],
		'response_types'             => array( 'code' ),
		'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
		'scope'                      => 'mcp',
	);

	if ( isset( $client['client_secret'] ) ) {
		$response['client_secret'] = $client['client_secret'];
		// 0 means the secret does not expire (RFC 7591 §3.2.1).
		$response['client_secret_expires_at'] = 0;
	}

	// 201 Created, per RFC 7591 §3.2.1.
	wp_send_json( $response, 201 );
}

/**
 * Throttles registration attempts per IP.
 *
 * @return bool True when the request is within the hourly budget.
 */
function ewpa_oauth_register_rate_limit_ok(): bool {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( '' === $ip ) {
		return true;
	}

	$key   = 'ewpa_oauth_dcr_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= EWPA_REGISTER_RATE_LIMIT ) {
		return false;
	}

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	return true;
}

/*
 * ==========================================================================
 * AUTHORIZATION
 * ==========================================================================
 */

/**
 * Handles GET /oauth/authorize for locally-registered clients.
 *
 * Validates exactly what the library's AuthorizeEndpoint validates, then
 * writes the same mcp_oauth_state_* transient it writes, so consent, code
 * issuance and the token exchange proceed through the library untouched.
 *
 * @return void
 */
function ewpa_oauth_maybe_handle_authorize(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth authorization request from an external client; CSRF is covered by state + PKCE, not a WP nonce.
	$client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';

	$client = ewpa_oauth_get_client( $client_id );

	if ( null === $client ) {
		// A missing client_id has its own, clearer message in the library.
		if ( '' === $client_id ) {
			return;
		}

		// Hand back only what the library can actually resolve: a client_id
		// whose host is a trusted CIMD publisher (claude.ai, plus anything the
		// wpmedia_mcp_oauth_trusted_publishers filter adds). Testing the host
		// rather than the "https://" prefix matters — the library answers an
		// opaque id and an untrusted URL with the same bare "Unknown OAuth
		// client.", so a prefix test would let untrusted URLs fall into that
		// dead end too.
		if ( ! ewpa_oauth_connectors_enabled() ) {
			return;
		}

		if ( class_exists( '\WPMedia\MCP\OAuth\Auth\ClaudeClientVerifier' ) ) {
			$verifier = new \WPMedia\MCP\OAuth\Auth\ClaudeClientVerifier();

			if ( $verifier->is_trusted_host( $client_id ) ) {
				return;
			}
		}

		// A metadata URL on a publisher host the admin already trusts (ChatGPT,
		// most often) is resolved here, since the library pins each publisher
		// to an exact client_id URL and ChatGPT mints a fresh one per connector.
		$resolved = ewpa_oauth_resolve_cimd( $client_id );

		if ( is_wp_error( $resolved ) ) {
			ewpa_oauth_log(
				'AUTHORIZE',
				'rejected: CIMD document unusable',
				array(
					'client_id' => $client_id,
					'error'     => $resolved->get_error_message(),
				)
			);

			wp_die(
				esc_html( $resolved->get_error_message() ),
				esc_html__( 'OAuth Error', 'enable-abilities-for-mcp' ),
				array( 'response' => 400 )
			);
		}

		if ( is_array( $resolved ) ) {
			$client = $resolved;
		} else {
			// Neither registered here nor resolvable — name the id, since the
			// library's bare "Unknown OAuth client." says nothing useful.
			ewpa_oauth_log(
				'AUTHORIZE',
				'rejected: client_id is not registered on this site',
				array( 'client_id' => $client_id )
			);

			$is_url = 0 === strpos( strtolower( $client_id ), 'https://' );

			$detail = $is_url
				? __( 'That is a metadata URL, but its host is not among this site\'s allowed callback URLs, so the document is never fetched. Add a callback URL on that host under Settings › WP Abilities › Connection.', 'enable-abilities-for-mcp' )
				: __( 'That is an opaque ID, so it should have been issued by this site. Remove and re-add the connector so it registers itself, or create a client under Settings › WP Abilities › Connection and paste that exact Client ID.', 'enable-abilities-for-mcp' );

			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: the client ID the connector presented, 2: what to do about it */
						__( 'This connector is not registered on this site. It presented the client ID: %1$s — %2$s', 'enable-abilities-for-mcp' ),
						$client_id,
						$detail
					)
				),
				esc_html__( 'Unknown OAuth client', 'enable-abilities-for-mcp' ),
				array( 'response' => 400 )
			);
		}
	}

	$redirect_uri          = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
	$response_type         = sanitize_text_field( wp_unslash( $_GET['response_type'] ?? '' ) );
	$code_challenge        = sanitize_text_field( wp_unslash( $_GET['code_challenge'] ?? '' ) );
	$code_challenge_method = sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ?? '' ) );
	$state                 = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	ewpa_oauth_log(
		'AUTHORIZE',
		'authorization request for registered client',
		array(
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => $response_type,
		)
	);

	if ( ! ewpa_oauth_connectors_enabled() ) {
		wp_die( esc_html__( 'Third-party OAuth connectors are disabled on this site.', 'enable-abilities-for-mcp' ), esc_html__( 'OAuth Error', 'enable-abilities-for-mcp' ), array( 'response' => 403 ) );
	}

	// Validate the redirect_uri before it is used as a redirect target
	// (OAuth 2.1 §7.5.2). It must both be registered to this client and still
	// pass the current allowlist, so removing a URL from the admin screen
	// immediately blocks clients that registered while it was allowed.
	if ( '' === $redirect_uri ) {
		wp_die( esc_html__( 'redirect_uri is required.', 'enable-abilities-for-mcp' ), esc_html__( 'OAuth Error', 'enable-abilities-for-mcp' ), array( 'response' => 400 ) );
	}

	$registered = isset( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] ) ? $client['redirect_uris'] : array();

	if ( ! ewpa_oauth_redirect_uri_matches( $redirect_uri, $registered ) || ! ewpa_oauth_callback_is_allowed( $redirect_uri ) ) {
		ewpa_oauth_log(
			'AUTHORIZE',
			'rejected: redirect_uri not registered or no longer allowed',
			array(
				'client_id' => $client_id,
				'provided'  => $redirect_uri,
			)
		);
		wp_die( esc_html__( 'redirect_uri does not match an allowed callback URL.', 'enable-abilities-for-mcp' ), esc_html__( 'OAuth Error', 'enable-abilities-for-mcp' ), array( 'response' => 400 ) );
	}

	// redirect_uri is validated — remaining errors may safely be reported to it.
	if ( 'code' !== $response_type ) {
		ewpa_oauth_redirect_error( $redirect_uri, 'unsupported_response_type', $state );
	}

	if ( '' === $code_challenge || 'S256' !== $code_challenge_method ) {
		ewpa_oauth_redirect_error( $redirect_uri, 'invalid_request', $state );
	}

	// OAuth 2.1 §4.1.1: a server-generated state never reaches the client in
	// time to be checked, so a missing one is rejected rather than filled in.
	if ( '' === $state ) {
		ewpa_oauth_redirect_error( $redirect_uri, 'invalid_request', '' );
	}

	set_transient(
		'mcp_oauth_state_' . $state,
		array(
			'client_id'             => $client_id,
			'client_name'           => (string) ( $client['client_name'] ?? '' ),
			'client_uri'            => (string) ( $client['client_uri'] ?? '' ),
			'verified'              => true,
			'publisher'             => (string) ( $client['publisher'] ?? '' ),
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'state'                 => $state,
		),
		60
	);

	$callback_url = add_query_arg( 'state', rawurlencode( $state ), home_url( '/oauth/authorize-callback' ) );

	if ( is_user_logged_in() ) {
		wp_redirect( $callback_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- server-constructed home_url() target.
		exit;
	}

	wp_redirect( wp_login_url( $callback_url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- server-constructed login URL.
	exit;
}

/**
 * Renders the consent screen for locally-registered clients.
 *
 * The library's AuthorizeCallback shows the client_id as a link, which reads
 * as a broken URL for an opaque, dynamically-issued id. This handler reuses
 * the library's own consent template with the id suppressed, so the screen
 * identifies the client by name and site instead.
 *
 * @return void
 */
function ewpa_oauth_maybe_handle_callback(): void {
	$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state echoed back by the login redirect, not a WP nonce.

	if ( '' === $state || ! is_user_logged_in() ) {
		return;
	}

	$state_key  = 'mcp_oauth_state_' . $state;
	$state_data = get_transient( $state_key );

	if ( ! is_array( $state_data ) ) {
		return;
	}

	$client = ewpa_oauth_get_client( (string) ( $state_data['client_id'] ?? '' ) );

	if ( null === $client ) {
		return;
	}

	if ( ! class_exists( '\WPMedia\MCP\OAuth\Views\Render' ) ) {
		return;
	}

	// Give the user time to read the screen; the library uses the same window.
	set_transient( $state_key, $state_data, 300 );

	$client_uri = esc_url( (string) ( $client['client_uri'] ?? '' ) );

	nocache_headers();

	( new \WPMedia\MCP\OAuth\Views\Render() )->view(
		'consent-screen',
		array(
			'state'        => $state,
			'client_name'  => (string) ( $client['client_name'] ?? '' ),
			// Empty: the template only renders the "ID:" line when it is set,
			// and an opaque client_id is not a URL worth linking to.
			'client_id'    => '',
			'client_uri'   => $client_uri,
			'verified'     => false,
			'publisher'    => '',
			'site_name'    => (string) get_bloginfo( 'name' ),
			'consent_url'  => esc_url( home_url( '/oauth/consent' ) ),
			'display_href' => $client_uri,
		)
	);

	exit;
}

/*
 * ==========================================================================
 * TOKEN ENDPOINT CLIENT AUTHENTICATION
 * ==========================================================================
 */

/**
 * Authenticates locally-registered clients at POST /oauth/token.
 *
 * The library treats every client as public, which is correct for CIMD. A
 * client registered here may hold a secret, so this handler verifies it (and
 * the client_id binding) first and then returns, letting the library perform
 * the PKCE check and issue the tokens as usual.
 *
 * @return void
 */
function ewpa_oauth_maybe_authenticate_client(): void {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

	if ( 'POST' !== $method ) {
		return;
	}

	$body       = ewpa_oauth_parse_request_body();
	$grant_type = sanitize_text_field( ewpa_oauth_str( $body['grant_type'] ?? null ) );
	$client_id  = '';

	if ( 'authorization_code' === $grant_type ) {
		$code = sanitize_text_field( ewpa_oauth_str( $body['code'] ?? null ) );

		if ( '' === $code ) {
			return;
		}

		// Read only — the library consumes the code atomically a moment later.
		$code_data = get_transient( 'mcp_oauth_code_' . $code );

		if ( ! is_array( $code_data ) ) {
			return;
		}

		$client_id = (string) ( $code_data['client_id'] ?? '' );
	} elseif ( 'refresh_token' === $grant_type ) {
		$refresh = sanitize_text_field( ewpa_oauth_str( $body['refresh_token'] ?? null ) );

		if ( '' === $refresh || ! class_exists( '\WPMedia\MCP\OAuth\Auth\JWT' ) ) {
			return;
		}

		$claims = \WPMedia\MCP\OAuth\Auth\JWT::decode( $refresh, \WPMedia\MCP\OAuth\Auth\SecretManager::get_secret() );

		if ( ! is_array( $claims ) ) {
			return;
		}

		$client_id = (string) ( $claims['client_id'] ?? '' );
	} else {
		return;
	}

	$client = ewpa_oauth_get_client( $client_id );

	if ( null === $client ) {
		// A CIMD client, or a grant issued before this module existed.
		return;
	}

	if ( ! ewpa_oauth_connectors_enabled() ) {
		ewpa_oauth_send_error( 400, 'invalid_client', 'Third-party OAuth connectors are disabled on this site.' );
	}

	list( $presented_id, $presented_secret ) = ewpa_oauth_client_credentials( $body );

	// Whenever the client names itself, that name must be the one the grant was
	// issued to — a client must not be able to redeem another client's code.
	if ( '' !== $presented_id && ! hash_equals( $client_id, $presented_id ) ) {
		ewpa_oauth_send_error( 401, 'invalid_client', 'client_id does not match the client this grant was issued to.' );
	}

	if ( 'none' === (string) ( $client['token_endpoint_auth_method'] ?? 'none' ) ) {
		return;
	}

	$hash = (string) ( $client['client_secret_hash'] ?? '' );

	if ( '' === $presented_secret || '' === $hash || ! wp_check_password( $presented_secret, $hash ) ) {
		ewpa_oauth_log( 'TOKEN', 'rejected: client secret missing or invalid', array( 'client_id' => $client_id ) );
		ewpa_oauth_send_error( 401, 'invalid_client', 'Client authentication failed.' );
	}
}

/**
 * Extracts client credentials from the request.
 *
 * Reads HTTP Basic first (client_secret_basic, RFC 6749 §2.3.1, where both
 * halves are form-urlencoded before being base64'd), then the request body
 * (client_secret_post).
 *
 * @param array<string, mixed> $body Parsed request body.
 * @return array{0: string, 1: string} client_id and client_secret, either may be ''.
 */
function ewpa_oauth_client_credentials( array $body ): array {
	$id     = '';
	$secret = '';

	// Credentials are read verbatim: they are compared byte for byte, and both
	// sanitize_text_field() and wp_unslash() would silently rewrite a secret
	// containing a backslash or a tag-like character.
	if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$id = urldecode( (string) $_SERVER['PHP_AUTH_USER'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$secret = isset( $_SERVER['PHP_AUTH_PW'] ) ? urldecode( (string) $_SERVER['PHP_AUTH_PW'] ) : '';
	} else {
		// Apache in CGI/FastCGI mode does not populate PHP_AUTH_*; the header
		// arrives verbatim (often via a RewriteRule) instead.
		$header = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$header = (string) $_SERVER[ $key ];
				break;
			}
		}

		if ( 0 === stripos( $header, 'basic ' ) ) {
			$decoded = base64_decode( substr( $header, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an HTTP Basic credential, not obfuscation.

			if ( is_string( $decoded ) && false !== strpos( $decoded, ':' ) ) {
				// RFC 6749 §2.3.1: both halves are form-urlencoded before being
				// base64'd, so `+` decodes to a space and %XX to its byte.
				list( $id, $secret ) = explode( ':', $decoded, 2 );
				$id                  = urldecode( $id );
				$secret              = urldecode( $secret );
			}
		}
	}

	if ( '' === $id ) {
		$id = sanitize_text_field( ewpa_oauth_str( $body['client_id'] ?? null ) );
	}

	if ( '' === $secret ) {
		// Deliberately not sanitised: a secret is compared byte for byte.
		$secret = ewpa_oauth_str( $body['client_secret'] ?? null );
	}

	return array( $id, $secret );
}

/*
 * ==========================================================================
 * SHARED HELPERS
 * ==========================================================================
 */

/**
 * Casts one value from a client-supplied body to a string.
 *
 * A JSON body can nest an array or object anywhere a string is expected;
 * casting one of those directly would raise a conversion notice and yield
 * "Array". Anything that is not a scalar is simply absent as far as the
 * OAuth parameters are concerned.
 *
 * @param mixed $value Raw value from the decoded body.
 * @return string
 */
function ewpa_oauth_str( $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Casts a client-supplied list to a flat array of strings.
 *
 * @param mixed $value Raw value from the decoded body.
 * @return string[]
 */
function ewpa_oauth_str_list( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$out = array();

	foreach ( $value as $item ) {
		if ( is_scalar( $item ) ) {
			$out[] = (string) $item;
		}
	}

	return $out;
}

/**
 * Parses a JSON or form-encoded request body.
 *
 * Mirrors the library's ParseBodyTrait, including its 32 KB cap on JSON.
 * php://input is re-readable, so reading it here does not stop the library
 * from reading the same body again afterwards.
 *
 * @return array<string, mixed>
 */
function ewpa_oauth_parse_request_body(): array {
	$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';

	if ( false !== strpos( $content_type, 'application/json' ) ) {
		$raw  = substr( (string) file_get_contents( 'php://input' ), 0, 32768 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body = json_decode( '' !== $raw ? $raw : '{}', true );

		return is_array( $body ) ? $body : array();
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth request from an external client; PKCE and the state parameter provide CSRF protection.
	return (array) wp_unslash( $_POST );
}

/**
 * Sends an OAuth JSON error response and exits.
 *
 * @param int    $status      HTTP status code.
 * @param string $error       OAuth error code.
 * @param string $description Human-readable description.
 * @return void
 */
function ewpa_oauth_send_error( int $status, string $error, string $description = '' ): void {
	status_header( $status );
	nocache_headers();

	$body = array( 'error' => $error );

	if ( '' !== $description ) {
		$body['error_description'] = $description;
	}

	wp_send_json( $body );
}

/**
 * Redirects to the client's validated redirect_uri with an OAuth error, then exits.
 *
 * @param string $redirect_uri Already-validated destination.
 * @param string $error        OAuth error code.
 * @param string $state        State to echo back, if any.
 * @return void
 */
function ewpa_oauth_redirect_error( string $redirect_uri, string $error, string $state ): void {
	$params = array( 'error' => $error );

	if ( '' !== $state ) {
		$params['state'] = $state;
	}

	wp_redirect( add_query_arg( $params, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the client's own registered redirect_uri, already validated against the callback allowlist.
	exit;
}

/**
 * Writes to the library's MCP log when it is available.
 *
 * @param string               $channel Log channel.
 * @param string               $message Log message.
 * @param array<string, mixed> $context Structured context.
 * @return void
 */
function ewpa_oauth_log( string $channel, string $message, array $context = array() ): void {
	if ( class_exists( '\WPMedia\MCP\OAuth\Logging\McpLogger' ) ) {
		\WPMedia\MCP\OAuth\Logging\McpLogger::log( $channel, $message, $context );
	}
}
