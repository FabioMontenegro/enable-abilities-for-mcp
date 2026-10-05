<?php
/**
 * Regression tests for OAuth session revocation and auditing.
 *
 * Covers includes/oauth-sessions.php: which events revoke plugin OAuth
 * sessions, that user-created Application Passwords survive, and that
 * sessions are renamed and their usage recorded.
 *
 * Run with: php tests/oauth-sessions-test.php
 *
 * @package EnableAbilitiesForMCP
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_meta']         = array(); // [ user_id => [ key => value ] ].
$GLOBALS['t_hooks']        = array(); // [ hook => [ callbacks ] ].
$GLOBALS['t_users']        = array(); // [ user_id => object ].
$GLOBALS['t_nonce_ok']     = true;
$GLOBALS['t_can']          = true;
$GLOBALS['t_query_var']    = '';
$GLOBALS['t_current_user'] = 0;
$GLOBALS['t_jwt_claims']   = null;
$GLOBALS['t_multisite']    = false;
$GLOBALS['t_blog_id']      = 1;

/*
 * WordPress doubles.
 */

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][ $hook ][] = array( $callback, $priority );
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	add_action( $hook, $callback, $priority, $args );
}

function get_option( $name, $default_value = false ) {
	return $GLOBALS['t_options'][ $name ] ?? $default_value;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return $GLOBALS['t_meta'][ $user_id ] ?? array();
	}
	return $GLOBALS['t_meta'][ $user_id ][ $key ] ?? '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['t_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function is_multisite() {
	return $GLOBALS['t_multisite'];
}

function get_current_blog_id() {
	return $GLOBALS['t_blog_id'];
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['t_meta'][ $user_id ][ $key ] );
	return true;
}

function get_userdata( $user_id ) {
	return $GLOBALS['t_users'][ $user_id ] ?? false;
}

function absint( $v ) {
	return abs( (int) $v );
}

function check_ajax_referer( $action, $arg, $die ) {
	$GLOBALS['t_nonce_action'] = $action;
	return $GLOBALS['t_nonce_ok'];
}

function current_user_can( $cap, $id = 0 ) {
	return $GLOBALS['t_can'];
}

function get_query_var( $name ) {
	return $GLOBALS['t_query_var'];
}

function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}

function wp_date( $format ) {
	return '2026-10-02';
}

function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}

function get_current_user_id() {
	return $GLOBALS['t_current_user'];
}

class WP_Error {}

/**
 * In-memory Application Passwords.
 */
class WP_Application_Passwords {
	public static $items   = array(); // [ user_id => [ uuid => name ] ].
	public static $updated = array();
	public static $usage   = array();

	public static function delete_application_password( $user_id, $uuid ) {
		if ( ! isset( self::$items[ $user_id ][ $uuid ] ) ) {
			return new WP_Error();
		}
		unset( self::$items[ $user_id ][ $uuid ] );
		return true;
	}

	public static function update_application_password( $user_id, $uuid, $update ) {
		self::$updated[] = array( $user_id, $uuid, $update );
		return true;
	}

	public static function record_application_password_usage( $user_id, $uuid ) {
		self::$usage[] = array( $user_id, $uuid );
		return true;
	}
}

/**
 * $wpdb double: get_col() evaluates the prepared LIKE over the meta store.
 */
class Test_Wpdb {
	public $usermeta = 'wp_usermeta';

	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	public function prepare( $query, $arg ) {
		return array( $query, $arg );
	}

	public function get_col( $prepared ) {
		$prefix = rtrim( stripslashes( $prepared[1] ), '%' );
		$ids    = array();
		foreach ( $GLOBALS['t_meta'] as $user_id => $meta ) {
			foreach ( array_keys( $meta ) as $key ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$ids[ $user_id ] = (string) $user_id;
				}
			}
		}
		return array_values( $ids );
	}
}
$wpdb = new Test_Wpdb();

class Test_Request {
	public $route  = '/mcp/mcp-oauth-server';
	public $header = 'Bearer valid-token';

	public function get_route() {
		return $this->route;
	}

	public function get_header( $name ) {
		return $this->header;
	}
}

class Test_Response {
	public $status = 200;

	public function get_status() {
		return $this->status;
	}
}

require __DIR__ . '/doubles/mcp-oauth.php';
require __DIR__ . '/../includes/oauth-sessions.php';

/*
 * Harness.
 */

$failures = 0;

function t_assert( $cond, $label ) {
	global $failures;
	if ( $cond ) {
		echo "ok   - $label\n";
	} else {
		echo "FAIL - $label\n";
		++$failures;
	}
}

/**
 * Seeds a plugin OAuth session (app password + library meta) and one manual
 * Application Password for the user.
 */
function t_seed( $user_id, $uuid ) {
	WP_Application_Passwords::$items[ $user_id ][ $uuid ]                 = 'MCP OAuth';
	WP_Application_Passwords::$items[ $user_id ]['manual-' . $user_id]    = 'My script';
	$GLOBALS['t_meta'][ $user_id ]['mcp_refresh_jti_' . $uuid]            = 'jti';
	$GLOBALS['t_meta'][ $user_id ]['unrelated_meta']                      = 'x';
}

function t_has_session( $user_id, $uuid ) {
	return isset( WP_Application_Passwords::$items[ $user_id ][ $uuid ] )
		|| isset( $GLOBALS['t_meta'][ $user_id ]['mcp_refresh_jti_' . $uuid] );
}

function t_manual_survives( $user_id ) {
	return isset( WP_Application_Passwords::$items[ $user_id ]['manual-' . $user_id] );
}

function t_hooked( $hook, $callback, $priority = null ) {
	foreach ( $GLOBALS['t_hooks'][ $hook ] ?? array() as $entry ) {
		if ( $entry[0] === $callback && ( null === $priority || $entry[1] === $priority ) ) {
			return true;
		}
	}
	return false;
}

/*
 * Wiring.
 */
t_assert( t_hooked( 'wp_ajax_destroy-sessions', 'ewpa_oauth_on_destroy_sessions', 1 ), 'destroy-sessions hooked at priority 1' );
t_assert( t_hooked( 'after_password_reset', 'ewpa_oauth_on_password_reset' ), 'after_password_reset hooked' );
t_assert( t_hooked( 'wp_set_password', 'ewpa_oauth_on_set_password' ), 'wp_set_password hooked' );
t_assert( t_hooked( 'profile_update', 'ewpa_oauth_on_profile_update' ), 'profile_update hooked' );
t_assert( t_hooked( 'wp_create_application_password', 'ewpa_oauth_on_session_created' ), 'rename hooked' );
t_assert( t_hooked( 'rest_request_after_callbacks', 'ewpa_oauth_record_usage' ), 'usage filter hooked' );

/*
 * Switch transitions.
 */
t_seed( 1, 'aaa' );
t_seed( 2, 'bbb' );
t_assert( 0 === ewpa_oauth_on_toggle( false, true ), 'off->on revokes nothing' );
t_assert( 0 === ewpa_oauth_on_toggle( '1', true ), 'on->on revokes nothing' );
t_assert( 0 === ewpa_oauth_on_toggle( false, false ), 'off->off revokes nothing' );
t_assert( t_has_session( 1, 'aaa' ) && t_has_session( 2, 'bbb' ), 'sessions intact after non-revoking transitions' );

t_assert( 2 === ewpa_oauth_on_toggle( '1', false ), 'on->off revokes every session' );
t_assert( ! t_has_session( 1, 'aaa' ) && ! t_has_session( 2, 'bbb' ), 'on->off removed app passwords and meta for all users' );
t_assert( t_manual_survives( 1 ) && t_manual_survives( 2 ), 'on->off keeps manual app passwords' );
t_assert( isset( $GLOBALS['t_meta'][1]['unrelated_meta'] ), 'on->off keeps unrelated user meta' );

// Meta whose app password is already gone is still purged.
$GLOBALS['t_meta'][3]['mcp_refresh_jti_gone'] = 'jti';
t_assert( 1 === ewpa_oauth_revoke_user_sessions( 3 ), 'orphaned meta counted' );
t_assert( empty( $GLOBALS['t_meta'][3] ), 'orphaned meta purged' );

// A key that is only the prefix is not a session.
$GLOBALS['t_meta'][4]['mcp_refresh_jti_'] = 'x';
t_assert( array() === ewpa_oauth_session_uuids( 4 ), 'bare prefix is not a session' );

/*
 * Password change hooks (distinct user per scenario: the per-request guard
 * is intentionally process-wide).
 */
t_seed( 10, 'u10' );
t_seed( 11, 'u11' );
ewpa_oauth_on_password_reset( (object) array( 'ID' => 10 ) );
t_assert( ! t_has_session( 10, 'u10' ), 'after_password_reset revokes that user' );
t_assert( t_has_session( 11, 'u11' ), 'after_password_reset leaves other users' );
t_assert( t_manual_survives( 10 ), 'after_password_reset keeps manual app passwords' );

t_seed( 12, 'u12' );
ewpa_oauth_on_set_password( 'plain', 12 );
t_assert( ! t_has_session( 12, 'u12' ), 'wp_set_password revokes that user' );
t_assert( t_has_session( 11, 'u11' ), 'wp_set_password leaves other users' );

t_seed( 13, 'u13' );
$GLOBALS['t_users'][13] = (object) array( 'user_pass' => 'hash-new' );
ewpa_oauth_on_profile_update( 13, (object) array( 'user_pass' => 'hash-new' ) );
t_assert( t_has_session( 13, 'u13' ), 'profile_update without hash change revokes nothing' );
ewpa_oauth_on_profile_update( 13, (object) array( 'user_pass' => 'hash-old' ) );
t_assert( ! t_has_session( 13, 'u13' ), 'profile_update with hash change revokes' );

// Two hooks for one change revoke once: sessions created in between survive.
t_seed( 14, 'first' );
$GLOBALS['t_users'][14] = (object) array( 'user_pass' => 'hash-new' );
ewpa_oauth_on_set_password( 'plain', 14 );
t_seed( 14, 'second' );
ewpa_oauth_on_profile_update( 14, (object) array( 'user_pass' => 'hash-old' ) );
t_assert( ! t_has_session( 14, 'first' ), 'first hook revoked the original session' );
t_assert( t_has_session( 14, 'second' ), 'second hook in the same request is a no-op' );

/*
 * Log Out Everywhere.
 */
t_seed( 20, 'u20' );
$_POST['user_id'] = '20';
$GLOBALS['t_nonce_ok'] = false;
ewpa_oauth_on_destroy_sessions();
t_assert( t_has_session( 20, 'u20' ), 'destroy-sessions with bad nonce revokes nothing' );
$GLOBALS['t_nonce_ok'] = true;
$GLOBALS['t_can']      = false;
ewpa_oauth_on_destroy_sessions();
t_assert( t_has_session( 20, 'u20' ), 'destroy-sessions without capability revokes nothing' );
$GLOBALS['t_can'] = true;
$_POST['user_id'] = '0';
ewpa_oauth_on_destroy_sessions();
t_assert( t_has_session( 20, 'u20' ), 'destroy-sessions without user id revokes nothing' );
$_POST['user_id'] = '20';
ewpa_oauth_on_destroy_sessions();
t_assert( 'update-user_20' === $GLOBALS['t_nonce_action'], 'destroy-sessions checks the per-user nonce action' );
t_assert( ! t_has_session( 20, 'u20' ), 'destroy-sessions with valid nonce and capability revokes' );
t_assert( t_manual_survives( 20 ), 'destroy-sessions keeps manual app passwords' );

/*
 * Deactivation.
 */
t_seed( 30, 'u30' );
t_seed( 31, 'u31' );
$revoked = ewpa_oauth_revoke_all_sessions();
t_assert( $revoked >= 2 && ! t_has_session( 30, 'u30' ) && ! t_has_session( 31, 'u31' ), 'deactivation callback revokes all users' );
t_assert( t_manual_survives( 30 ) && t_manual_survives( 31 ), 'deactivation keeps manual app passwords' );

/*
 * Rename on creation.
 */
$item = array(
	'uuid' => 'new-uuid',
	'name' => 'Claude',
);
$GLOBALS['t_query_var'] = '';
ewpa_oauth_on_session_created( 40, $item, 'pw', array() );
t_assert( array() === WP_Application_Passwords::$updated, 'no rename outside the token endpoint' );
$GLOBALS['t_query_var'] = 'authorize';
ewpa_oauth_on_session_created( 40, $item, 'pw', array() );
t_assert( array() === WP_Application_Passwords::$updated, 'no rename on other OAuth endpoints' );
$GLOBALS['t_query_var'] = 'token';
ewpa_oauth_on_session_created( 40, $item, 'pw', array() );
t_assert(
	array( array( 40, 'new-uuid', array( 'name' => "MCP OAuth \u{2013} Claude \u{2013} 2026-10-02" ) ) ) === WP_Application_Passwords::$updated,
	'rename during token endpoint uses the documented format'
);
WP_Application_Passwords::$updated = array();
ewpa_oauth_on_session_created( 40, array( 'name' => 'x' ), 'pw', array() );
t_assert( array() === WP_Application_Passwords::$updated, 'rename needs a uuid' );

// Site meta is written on creation, only at the token endpoint.
$GLOBALS['t_blog_id'] = 7;
ewpa_oauth_on_session_created( 41, $item );
t_assert( 7 === ( $GLOBALS['t_meta'][41]['ewpa_oauth_session_site_new-uuid'] ?? null ), 'site meta written on creation' );
$GLOBALS['t_query_var'] = '';
ewpa_oauth_on_session_created( 42, $item );
t_assert( ! isset( $GLOBALS['t_meta'][42] ), 'no site meta outside the token endpoint' );
$GLOBALS['t_blog_id'] = 1;
$GLOBALS['t_meta'][41]['mcp_refresh_jti_new-uuid'] = 'jti';
ewpa_oauth_revoke_user_sessions( 41 );
t_assert( empty( $GLOBALS['t_meta'][41] ), 'site meta removed together with the jti meta' );

/*
 * Multisite scoping.
 */
function t_seed_site( $user_id, $uuid, $blog ) {
	t_seed( $user_id, $uuid );
	if ( null !== $blog ) {
		$GLOBALS['t_meta'][ $user_id ]['ewpa_oauth_session_site_' . $uuid] = $blog;
	}
}

$GLOBALS['t_multisite'] = true;
$GLOBALS['t_blog_id']   = 2;
t_seed_site( 60, 'b2', 2 );
t_seed_site( 61, 'b3', 3 );
t_seed_site( 62, 'legacy', null );
$GLOBALS['t_meta'][60]['mcp_refresh_jti_b2x'] = 'jti';
WP_Application_Passwords::$items[60]['b2x']   = 'x';
$GLOBALS['t_meta'][60]['ewpa_oauth_session_site_b2x'] = '3';
t_assert( 2 === ewpa_oauth_on_toggle( '1', false ), 'multisite toggle off on blog 2 revokes blog-2 and legacy sessions' );
t_assert( ! t_has_session( 60, 'b2' ) && ! t_has_session( 62, 'legacy' ), 'blog-2 and legacy sessions gone' );
t_assert( t_has_session( 61, 'b3' ) && t_has_session( 60, 'b2x' ), 'blog-3 sessions kept' );
t_assert( ! isset( $GLOBALS['t_meta'][60]['ewpa_oauth_session_site_b2'] ), 'site meta of revoked session removed' );
t_assert( isset( $GLOBALS['t_meta'][61]['ewpa_oauth_session_site_b3'] ), 'site meta of kept session kept' );

ewpa_oauth_on_deactivate( false );
t_assert( t_has_session( 61, 'b3' ), 'single-site deactivation keeps other sites' );
ewpa_oauth_on_deactivate( true );
t_assert( ! t_has_session( 61, 'b3' ) && ! t_has_session( 60, 'b2x' ), 'network-wide deactivation revokes every site' );

t_seed_site( 63, 'pw2', 2 );
t_seed_site( 63, 'pw3', 3 );
ewpa_oauth_on_set_password( 'plain', 63 );
t_assert( ! t_has_session( 63, 'pw2' ) && ! t_has_session( 63, 'pw3' ), 'password change revokes across all blogs' );

$GLOBALS['t_multisite'] = false;
t_seed_site( 64, 's2', 2 );
t_seed_site( 65, 's3', 3 );
t_assert( 2 === ewpa_oauth_revoke_all_sessions(), 'non-multisite ignores scoping' );
t_assert( ! t_has_session( 64, 's2' ) && ! t_has_session( 65, 's3' ), 'non-multisite revoked every session' );
$GLOBALS['t_blog_id'] = 1;

/*
 * Usage recording.
 */
$GLOBALS['t_current_user'] = 50;
$GLOBALS['t_jwt_claims']   = array(
	'sub'         => '50',
	'app_pass_id' => 'uuid-50',
);
$response = new Test_Response();
$request  = new Test_Request();

$out = ewpa_oauth_record_usage( $response, array(), $request );
t_assert( $out === $response, 'usage filter returns the response unchanged' );
t_assert( array( array( 50, 'uuid-50' ) ) === WP_Application_Passwords::$usage, 'usage recorded for OAuth route, success, matching sub' );

WP_Application_Passwords::$usage = array();
$other        = new Test_Request();
$other->route = '/mcp/mcp-adapter-default-server';
ewpa_oauth_record_usage( $response, array(), $other );
t_assert( array() === WP_Application_Passwords::$usage, 'usage not recorded for other routes' );

$failed         = new Test_Response();
$failed->status = 401;
ewpa_oauth_record_usage( $failed, array(), $request );
t_assert( array() === WP_Application_Passwords::$usage, 'usage not recorded for error status' );

ewpa_oauth_record_usage( new WP_Error(), array(), $request );
t_assert( array() === WP_Application_Passwords::$usage, 'usage not recorded for WP_Error' );

$GLOBALS['t_jwt_claims']['sub'] = '51';
ewpa_oauth_record_usage( $response, array(), $request );
t_assert( array() === WP_Application_Passwords::$usage, 'usage not recorded when sub differs from current user' );
$GLOBALS['t_jwt_claims']['sub'] = '50';

$GLOBALS['t_current_user'] = 0;
ewpa_oauth_record_usage( $response, array(), $request );
t_assert( array() === WP_Application_Passwords::$usage, 'usage not recorded for anonymous requests' );
$GLOBALS['t_current_user'] = 50;

$bad         = new Test_Request();
$bad->header = 'Bearer forged';
ewpa_oauth_record_usage( $response, array(), $bad );
t_assert( array() === WP_Application_Passwords::$usage, 'usage not recorded when the token does not decode' );

$bad->header = '';
t_assert( $response === ewpa_oauth_record_usage( $response, array(), $bad ), 'missing header never breaks the request' );

/*
 * Switch enforcement on the library's own enable filter.
 */

$enforced = false;
foreach ( $GLOBALS['t_hooks']['wpmedia_mcp_oauth_server_enabled'] ?? array() as $hook ) {
	$enforced = $enforced || 'ewpa_oauth_enforce_switch' === $hook[0];
}
t_assert( $enforced, 'switch enforcement is hooked on the library enable filter' );

$GLOBALS['t_options'] = array();
t_assert( false === ewpa_oauth_enforce_switch( true ), 'OAuth server disabled when the switch was never turned on' );

$GLOBALS['t_options']['ewpa_oauth_enabled'] = '';
t_assert( false === ewpa_oauth_enforce_switch( true ), 'OAuth server disabled when the switch is off, even if another plugin boots the library' );

$GLOBALS['t_options']['ewpa_oauth_enabled'] = '1';
t_assert( true === ewpa_oauth_enforce_switch( true ), 'OAuth server stays enabled when the switch is on' );
t_assert( false === ewpa_oauth_enforce_switch( false ), 'switch on never overrides another filter that disabled the server' );

echo $failures ? "\n$failures FAILED\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
