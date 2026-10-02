<?php
/**
 * OAuth session revocation and auditing.
 *
 * Every OAuth connector session (claude.ai, ChatGPT) is anchored to a WordPress
 * Application Password created by the embedded wp-media/mcp-oauth library, which
 * also writes the user meta `mcp_refresh_jti_<app_password_uuid>` for every token
 * pair it issues. That meta is how this module tells the plugin's sessions apart
 * from Application Passwords a user created by hand: only passwords with that meta
 * are ever revoked.
 *
 * Deleting the Application Password kills both the access and the refresh token.
 * The library only registers its own cleanup while OAuth is enabled, so this
 * module deletes the meta itself and is loaded regardless of the OAuth switch.
 *
 * Nothing in vendor/ is patched.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// User meta prefix written by the library for every issued token pair.
define( 'EWPA_OAUTH_SESSION_META_PREFIX', 'mcp_refresh_jti_' );

// User meta prefix recording the site (blog ID) that created each session.
define( 'EWPA_OAUTH_SESSION_SITE_META_PREFIX', 'ewpa_oauth_session_site_' );

// REST route of the library's JWT-authenticated MCP server.
define( 'EWPA_OAUTH_MCP_ROUTE', '/mcp/mcp-oauth-server' );

add_action( 'after_password_reset', 'ewpa_oauth_on_password_reset' );
add_action( 'wp_set_password', 'ewpa_oauth_on_set_password', 10, 3 );
add_action( 'profile_update', 'ewpa_oauth_on_profile_update', 10, 2 );
// Priority 1: core's handler at priority 10 sends the response and exits.
add_action( 'wp_ajax_destroy-sessions', 'ewpa_oauth_on_destroy_sessions', 1 );
add_action( 'wp_create_application_password', 'ewpa_oauth_on_session_created', 10, 2 );
add_filter( 'rest_request_after_callbacks', 'ewpa_oauth_record_usage', 10, 3 );

/*
 * ==========================================================================
 * REVOCATION PRIMITIVES
 * ==========================================================================
 */

/**
 * Returns the Application Password UUIDs of a user's OAuth sessions.
 *
 * @param int $user_id User ID.
 * @return string[]
 */
function ewpa_oauth_session_uuids( int $user_id ): array {
	$meta = get_user_meta( $user_id );
	if ( ! is_array( $meta ) ) {
		return array();
	}

	$uuids = array();
	$len   = strlen( EWPA_OAUTH_SESSION_META_PREFIX );
	foreach ( array_keys( $meta ) as $key ) {
		$key = (string) $key;
		if ( 0 === strpos( $key, EWPA_OAUTH_SESSION_META_PREFIX ) && strlen( $key ) > $len ) {
			$uuids[] = substr( $key, $len );
		}
	}

	return $uuids;
}

/**
 * Revokes the OAuth sessions of one user.
 *
 * User meta and Application Passwords are network-wide on multisite, but a
 * token is bound to the site that issued it. With $blog_id only that site's
 * sessions go, plus legacy sessions that carry no site meta (created before
 * sites were recorded): their origin is unknown, so they fail closed and are
 * revoked. Application Passwords without the library's refresh meta are left
 * alone.
 *
 * @param int      $user_id User ID.
 * @param int|null $blog_id Restrict to this site, or null for every session.
 * @return int Number of sessions revoked.
 */
function ewpa_oauth_revoke_user_sessions( int $user_id, ?int $blog_id = null ): int {
	$count = 0;

	foreach ( ewpa_oauth_session_uuids( $user_id ) as $uuid ) {
		$site = get_user_meta( $user_id, EWPA_OAUTH_SESSION_SITE_META_PREFIX . $uuid, true );

		if ( null !== $blog_id && '' !== $site && (int) $site !== $blog_id ) {
			continue;
		}

		// A WP_Error here means the password is already gone; the meta still goes.
		WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		delete_user_meta( $user_id, EWPA_OAUTH_SESSION_META_PREFIX . $uuid );
		delete_user_meta( $user_id, EWPA_OAUTH_SESSION_SITE_META_PREFIX . $uuid );
		++$count;
	}

	return $count;
}

/**
 * Revokes sessions of every user, optionally limited to one site.
 *
 * @param int|null $blog_id Restrict to this site, or null for every session.
 * @return int Number of sessions revoked.
 */
function ewpa_oauth_revoke_sessions_of_all_users( ?int $blog_id ): int {
	global $wpdb;

	// Sessions can belong to any user and the revocation must be exhaustive, so this is uncached.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( EWPA_OAUTH_SESSION_META_PREFIX ) . '%'
		)
	);

	$count = 0;
	foreach ( (array) $user_ids as $user_id ) {
		$count += ewpa_oauth_revoke_user_sessions( (int) $user_id, $blog_id );
	}

	return $count;
}

/**
 * Revokes the OAuth sessions of every user on the current site.
 *
 * On multisite only this site's sessions (and legacy ones of unknown origin)
 * are revoked, so a switch-off on one subsite leaves the others connected.
 * Outside multisite there is a single site and no scoping applies.
 *
 * @param int|null $blog_id Site to revoke for; defaults to the current site on multisite.
 * @return int Number of sessions revoked.
 */
function ewpa_oauth_revoke_all_sessions( ?int $blog_id = null ): int {
	if ( ! is_multisite() ) {
		$blog_id = null;
	} elseif ( null === $blog_id ) {
		$blog_id = get_current_blog_id();
	}

	return ewpa_oauth_revoke_sessions_of_all_users( $blog_id );
}

/**
 * Plugin deactivation callback. Network-wide deactivation revokes every
 * session on every site; a single-site deactivation only the current site's.
 *
 * @param bool $network_wide Whether the plugin is deactivated network-wide.
 * @return void
 */
function ewpa_oauth_on_deactivate( $network_wide = false ): void {
	if ( $network_wide && is_multisite() ) {
		ewpa_oauth_revoke_sessions_of_all_users( null );
		return;
	}

	ewpa_oauth_revoke_all_sessions();
}

/**
 * Revokes a user's sessions at most once per request.
 *
 * One password change can fire several hooks; the first one wins.
 *
 * @param int $user_id User ID.
 * @return void
 */
function ewpa_oauth_revoke_user_once( int $user_id ): void {
	static $done = array();

	if ( $user_id <= 0 || isset( $done[ $user_id ] ) ) {
		return;
	}

	$done[ $user_id ] = true;
	ewpa_oauth_revoke_user_sessions( $user_id );
}

/*
 * ==========================================================================
 * REVOCATION TRIGGERS
 * ==========================================================================
 */

/**
 * Revokes sessions after a password reset.
 *
 * @param WP_User $user User whose password was reset.
 * @return void
 */
function ewpa_oauth_on_password_reset( $user ): void {
	if ( is_object( $user ) && ! empty( $user->ID ) ) {
		ewpa_oauth_revoke_user_once( (int) $user->ID );
	}
}

/**
 * Revokes sessions when a password is set through wp_set_password().
 *
 * @param string $password Plain-text password (unused).
 * @param int    $user_id  User ID.
 * @return void
 */
function ewpa_oauth_on_set_password( $password = '', $user_id = 0 ): void {
	ewpa_oauth_revoke_user_once( (int) $user_id );
}

/**
 * Revokes sessions when a profile update changed the stored password hash.
 *
 * @param int     $user_id       User ID.
 * @param WP_User $old_user_data User data before the update.
 * @return void
 */
function ewpa_oauth_on_profile_update( $user_id, $old_user_data = null ): void {
	$user_id = (int) $user_id;
	$user    = get_userdata( $user_id );

	if ( ! $user || ! is_object( $old_user_data ) || ! isset( $old_user_data->user_pass ) ) {
		return;
	}

	if ( $user->user_pass !== $old_user_data->user_pass ) {
		ewpa_oauth_revoke_user_once( $user_id );
	}
}

/**
 * Revokes sessions on the profile screen's "Log Out Everywhere" action.
 *
 * Repeats the checks of core's wp_ajax_destroy_sessions() and never responds:
 * core's handler runs afterwards and sends the reply.
 *
 * @return void
 */
function ewpa_oauth_on_destroy_sessions(): void {
	$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

	if ( ! $user_id || ! check_ajax_referer( 'update-user_' . $user_id, 'nonce', false ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	ewpa_oauth_revoke_user_once( $user_id );
}

/**
 * Handles the OAuth switch changing state. Turning it off revokes everything,
 * so re-enabling it cannot revive old refresh tokens.
 *
 * @param mixed $was Previous option value.
 * @param bool  $now New value.
 * @return int Number of sessions revoked.
 */
function ewpa_oauth_on_toggle( $was, bool $now ): int {
	if ( $was && ! $now ) {
		return ewpa_oauth_revoke_all_sessions();
	}

	return 0;
}

/*
 * ==========================================================================
 * AUDITING
 * ==========================================================================
 */

/**
 * Labels a freshly created OAuth Application Password and records the site
 * that issued it. The name makes the session recognizable in the user profile;
 * the site meta lets revocation stay scoped on multisite. Only acts during the
 * library's token endpoint request.
 *
 * @param int   $user_id  User ID.
 * @param array $new_item Application Password data (uuid, name, ...).
 * @return void
 */
function ewpa_oauth_on_session_created( $user_id, $new_item ): void {
	if ( 'token' !== get_query_var( 'mcp_oauth_endpoint' ) || empty( $new_item['uuid'] ) ) {
		return;
	}

	update_user_meta( (int) $user_id, EWPA_OAUTH_SESSION_SITE_META_PREFIX . $new_item['uuid'], get_current_blog_id() );

	$name = sprintf(
		/* translators: 1: OAuth client name, 2: creation date. Keep the en dashes. */
		'MCP OAuth – %1$s – %2$s',
		sanitize_text_field( (string) ( $new_item['name'] ?? '' ) ),
		wp_date( 'Y-m-d' )
	);

	WP_Application_Passwords::update_application_password( (int) $user_id, $new_item['uuid'], array( 'name' => $name ) );
}

/**
 * Records Application Password usage ("Last Used" / "Last IP") for every
 * successful authenticated OAuth MCP request. Never alters the response.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param array                                            $handler  Route handler (unused).
 * @param WP_REST_Request                                  $request  Request.
 * @return mixed The response, unchanged.
 */
function ewpa_oauth_record_usage( $response, $handler = array(), $request = null ) {
	try {
		if ( ! is_object( $request ) || EWPA_OAUTH_MCP_ROUTE !== $request->get_route() || is_wp_error( $response ) ) {
			return $response;
		}

		if ( is_object( $response ) && method_exists( $response, 'get_status' ) && $response->get_status() >= 400 ) {
			return $response;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0
			|| ! class_exists( '\WPMedia\MCP\OAuth\Auth\JWT' )
			|| ! class_exists( '\WPMedia\MCP\OAuth\Auth\SecretManager' ) ) {
			return $response;
		}

		$authorization = (string) $request->get_header( 'Authorization' );
		if ( 0 !== strpos( $authorization, 'Bearer ' ) ) {
			return $response;
		}

		$claims = \WPMedia\MCP\OAuth\Auth\JWT::decode(
			substr( $authorization, 7 ),
			\WPMedia\MCP\OAuth\Auth\SecretManager::get_secret()
		);

		if ( is_array( $claims ) && ! empty( $claims['app_pass_id'] ) && (int) ( $claims['sub'] ?? 0 ) === $user_id ) {
			WP_Application_Passwords::record_application_password_usage( $user_id, (string) $claims['app_pass_id'] );
		}
	} catch ( \Throwable $e ) {
		unset( $e ); // Usage bookkeeping must never break the request.
	}

	return $response;
}
