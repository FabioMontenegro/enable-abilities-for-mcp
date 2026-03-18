<?php
/**
 * API Key authentication for Enable Abilities for MCP.
 *
 * Generates and validates API keys to authenticate external MCP clients
 * (e.g. Perplexity) via Bearer token on REST API MCP routes.
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

	$data = array(
		'hash'       => hash( 'sha256', $plain_key ),
		'user_id'    => $user_id,
		'created_at' => time(),
	);

	update_option( EWPA_API_KEY_OPTION, $data, false );

	return $plain_key;
}

/**
 * Revokes the current API key.
 *
 * @return bool
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

	$provided_hash = hash( 'sha256', $plain_key );

	if ( ! hash_equals( $stored['hash'], $provided_hash ) ) {
		return false;
	}

	$user = get_userdata( $stored['user_id'] );
	if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
		return false;
	}

	return (int) $stored['user_id'];
}

/**
 * Retrieves the Authorization header from the request.
 *
 * Checks multiple sources because some server configurations
 * do not pass the header through to PHP directly.
 *
 * @return string The Authorization header value, or empty string.
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
 * Authenticates REST API requests via Bearer token for MCP routes.
 *
 * @param int|false $user_id The current user ID or false.
 * @return int|false
 */
function ewpa_authenticate_api_key( $user_id ) {
	if ( ! empty( $user_id ) ) {
		return $user_id;
	}

	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return $user_id;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';

	$rest_prefix = rest_get_url_prefix();
	if ( false === strpos( $request_uri, '/' . $rest_prefix . '/mcp/' ) ) {
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

	$validated_user_id = ewpa_validate_api_key( $token );
	if ( false === $validated_user_id ) {
		return $user_id;
	}

	return $validated_user_id;
}
add_filter( 'determine_current_user', 'ewpa_authenticate_api_key', 20 );
