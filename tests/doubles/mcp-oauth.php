<?php
/**
 * In-memory doubles for the wp-media/mcp-oauth classes used by the plugin.
 *
 * @package EnableAbilitiesForMCP
 */

// phpcs:disable

namespace WPMedia\MCP\OAuth\Auth;

class SecretManager {
	public static function get_secret() {
		return 'secret';
	}
}

class JWT {
	/**
	 * Decodes only the literal token "valid-token", returning the claims the
	 * test placed in $GLOBALS['t_jwt_claims'].
	 */
	public static function decode( $token, $secret ) {
		return 'valid-token' === $token ? $GLOBALS['t_jwt_claims'] : null;
	}
}
