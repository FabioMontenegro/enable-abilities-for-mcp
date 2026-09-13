<?php
/**
 * Code Snippets integration: shared helpers and the human confirmation step
 * that guards snippet activation.
 *
 * Activating a PHP snippet runs arbitrary code on the site, so an MCP client can
 * never do it directly. ewpa/set-code-snippet-active only files an activation
 * request; a logged-in administrator has to open the confirmation screen in
 * wp-admin, read the code and approve it. The approval form carries a WordPress
 * nonce, which neither an Application Password, the Bearer token nor an OAuth
 * session can produce, and the request is bound to a hash of the code, so a
 * snippet edited after the request was filed cannot be activated with it.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EWPA_SNIPPET_REQUESTS_OPTION' ) ) {
	define( 'EWPA_SNIPPET_REQUESTS_OPTION', 'ewpa_snippet_activation_requests' );
}
if ( ! defined( 'EWPA_SNIPPET_REQUEST_TTL' ) ) {
	define( 'EWPA_SNIPPET_REQUEST_TTL', 86400 );
}
if ( ! defined( 'EWPA_SNIPPET_MAX_REQUESTS' ) ) {
	define( 'EWPA_SNIPPET_MAX_REQUESTS', 20 );
}

/**
 * Resolves a Code Snippets function name across plugin versions.
 *
 * Code Snippets 3.x declares its API in the Code_Snippets namespace; 2.x in the
 * global namespace.
 *
 * @param string $name Function name without namespace.
 * @return string|null Callable name, or null when unavailable.
 */
function ewpa_snippets_function( string $name ): ?string {
	if ( function_exists( '\\Code_Snippets\\' . $name ) ) {
		return '\\Code_Snippets\\' . $name;
	}
	if ( function_exists( $name ) ) {
		return $name;
	}
	return null;
}

/**
 * Whether the Code Snippets plugin API is available.
 *
 * @return bool
 */
function ewpa_snippets_available(): bool {
	return null !== ewpa_snippets_function( 'get_snippet' ) && null !== ewpa_snippets_function( 'save_snippet' );
}

/**
 * Returns the standard error for an inactive Code Snippets plugin.
 *
 * @return WP_Error
 */
function ewpa_snippets_unavailable_error(): WP_Error {
	return new WP_Error( 'plugin_inactive', 'Code Snippets plugin is not active or its API is unavailable.' );
}

/**
 * Instantiates an empty snippet object for the installed Code Snippets version.
 *
 * Code Snippets 3.10 moved the class from Code_Snippets\Snippet to
 * Code_Snippets\Model\Snippet.
 *
 * @return object|WP_Error
 */
function ewpa_snippets_new_snippet() {
	if ( class_exists( '\\Code_Snippets\\Model\\Snippet' ) ) {
		return new \Code_Snippets\Model\Snippet();
	}
	if ( class_exists( '\\Code_Snippets\\Snippet' ) ) {
		return new \Code_Snippets\Snippet();
	}
	if ( class_exists( 'Snippet' ) ) {
		return new Snippet(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
	return new WP_Error( 'plugin_error', 'Cannot instantiate Snippet class. Ensure Code Snippets 2.x or 3.x is active.' );
}

/**
 * Scopes an MCP client may assign to a PHP snippet.
 *
 * "single-use" is left out on purpose: it runs the code once, immediately.
 *
 * @return string[]
 */
function ewpa_snippets_php_scopes(): array {
	return array( 'global', 'admin', 'front-end' );
}

/**
 * Normalizes a requested scope to the value Code Snippets stores.
 *
 * Code Snippets calls the front-end scope "front-end" and silently falls back to
 * "global" for any value it does not know, so "frontend" used to create a
 * snippet that also ran in wp-admin. Both spellings are accepted.
 *
 * @param mixed $scope Requested scope.
 * @return string Normalized scope, or an empty string when it is not allowed.
 */
function ewpa_snippets_normalize_scope( $scope ): string {
	$scope = strtolower( trim( (string) $scope ) );
	if ( 'frontend' === $scope ) {
		$scope = 'front-end';
	}
	return in_array( $scope, ewpa_snippets_php_scopes(), true ) ? $scope : '';
}

/**
 * Function names that may not appear in snippet code written through MCP.
 *
 * @return string[]
 */
function ewpa_snippets_blocked_functions(): array {
	return array(
		'eval',
		'exec',
		'system',
		'passthru',
		'shell_exec',
		'popen',
		'proc_open',
		'base64_decode',
		'file_put_contents',
		'unlink',
		'chmod',
	);
}

/**
 * Checks PHP syntax and rejects blocked function calls.
 *
 * @param string $code Snippet code without the opening PHP tag.
 * @return true|WP_Error
 */
function ewpa_snippets_validate_php( string $code ) {
	try {
		token_get_all( '<?php ' . $code, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		return new WP_Error( 'syntax_error', 'PHP syntax error: ' . $e->getMessage() );
	}

	foreach ( ewpa_snippets_blocked_functions() as $fn ) {
		if ( preg_match( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/i', $code ) ) {
			return new WP_Error(
				'blocked_function',
				/* translators: %s: function name */
				sprintf( __( "The function '%s' is not allowed in code snippets for security reasons.", 'enable-abilities-for-mcp' ), $fn )
			);
		}
	}

	return true;
}

/**
 * Loads a snippet by ID.
 *
 * @param int $snippet_id Snippet ID.
 * @return object|WP_Error
 */
function ewpa_snippets_get( int $snippet_id ) {
	$get = ewpa_snippets_function( 'get_snippet' );
	if ( null === $get ) {
		return ewpa_snippets_unavailable_error();
	}
	if ( $snippet_id <= 0 ) {
		return new WP_Error( 'missing_parameter', 'The "snippet_id" parameter is required.' );
	}

	$snippet = $get( $snippet_id );
	if ( ! is_object( $snippet ) || empty( $snippet->id ) ) {
		return new WP_Error( 'not_found', sprintf( 'Code snippet %d was not found.', $snippet_id ) );
	}

	return $snippet;
}

/**
 * Returns a snippet's type (php, html, css, js or cond).
 *
 * @param object $snippet Snippet.
 * @return string
 */
function ewpa_snippets_type( $snippet ): string {
	return method_exists( $snippet, 'get_type' ) ? (string) $snippet->get_type() : 'php';
}

/**
 * Whether a snippet sits in the Code Snippets trash.
 *
 * @param object $snippet Snippet.
 * @return bool
 */
function ewpa_snippets_is_trashed( $snippet ): bool {
	return ! empty( $snippet->trashed );
}

/**
 * Builds the array returned by the snippet abilities.
 *
 * @param object $snippet   Snippet.
 * @param bool   $with_code Whether to include the code.
 * @return array
 */
function ewpa_snippets_summarize( $snippet, bool $with_code ): array {
	$summary = array(
		'snippet_id'  => (int) $snippet->id,
		'name'        => (string) $snippet->name,
		'description' => (string) $snippet->desc,
		'type'        => ewpa_snippets_type( $snippet ),
		'scope'       => (string) $snippet->scope,
		'active'      => (bool) $snippet->active,
		'tags'        => array_values( array_map( 'strval', (array) $snippet->tags ) ),
		'priority'    => (int) $snippet->priority,
		'locked'      => ! empty( $snippet->locked ),
		'modified'    => (string) $snippet->modified,
		'edit_url'    => admin_url( 'admin.php?page=edit-snippet&id=' . (int) $snippet->id ),
	);

	if ( $with_code ) {
		$summary['code'] = (string) $snippet->code;
	}

	return $summary;
}

/**
 * Returns the pending activation requests, dropping expired ones.
 *
 * @return array<string, array>
 */
function ewpa_snippets_requests(): array {
	$stored   = get_option( EWPA_SNIPPET_REQUESTS_OPTION, array() );
	$requests = array();
	$now      = time();

	foreach ( (array) $stored as $token => $request ) {
		if ( is_array( $request ) && (int) ( $request['expires_at'] ?? 0 ) > $now ) {
			$requests[ (string) $token ] = $request;
		}
	}

	if ( count( $requests ) !== count( (array) $stored ) ) {
		ewpa_snippets_save_requests( $requests );
	}

	return $requests;
}

/**
 * Stores the pending activation requests.
 *
 * @param array<string, array> $requests Requests keyed by token.
 * @return void
 */
function ewpa_snippets_save_requests( array $requests ): void {
	update_option( EWPA_SNIPPET_REQUESTS_OPTION, $requests, false );
}

/**
 * Files an activation request for a snippet, or reuses an open one for the same code.
 *
 * @param object $snippet Snippet to activate.
 * @param int    $user_id User who asked for the activation.
 * @return array The request, including its token.
 */
function ewpa_snippets_create_activation_request( $snippet, int $user_id ): array {
	$requests  = ewpa_snippets_requests();
	$code_hash = hash( 'sha256', (string) $snippet->code );

	foreach ( $requests as $token => $request ) {
		if ( (int) $request['snippet_id'] === (int) $snippet->id && hash_equals( (string) $request['code_hash'], $code_hash ) ) {
			return array_merge( $request, array( 'token' => $token ) );
		}
	}

	$token   = wp_generate_password( 32, false, false );
	$request = array(
		'snippet_id'   => (int) $snippet->id,
		'code_hash'    => $code_hash,
		'requested_by' => $user_id,
		'requested_at' => time(),
		'expires_at'   => time() + EWPA_SNIPPET_REQUEST_TTL,
	);

	$requests[ $token ] = $request;

	// Keep the list bounded: the oldest requests go first.
	if ( count( $requests ) > EWPA_SNIPPET_MAX_REQUESTS ) {
		uasort(
			$requests,
			static function ( $a, $b ) {
				return (int) $a['requested_at'] <=> (int) $b['requested_at'];
			}
		);
		$requests = array_slice( $requests, -EWPA_SNIPPET_MAX_REQUESTS, null, true );
	}

	ewpa_snippets_save_requests( $requests );

	return array_merge( $request, array( 'token' => $token ) );
}

/**
 * Returns the wp-admin URL where an administrator confirms an activation request.
 *
 * @param string $token Request token.
 * @return string
 */
function ewpa_snippets_confirmation_url( string $token ): string {
	return admin_url( 'options-general.php?page=ewpa-settings&ewpa_snippet_request=' . rawurlencode( $token ) );
}

/**
 * Removes one activation request.
 *
 * @param string $token Request token.
 * @return void
 */
function ewpa_snippets_delete_request( string $token ): void {
	$requests = ewpa_snippets_requests();
	unset( $requests[ $token ] );
	ewpa_snippets_save_requests( $requests );
}

/**
 * Applies an administrator's decision on an activation request.
 *
 * Every check is repeated here, at decision time: the snippet must still exist,
 * be an inactive PHP snippet outside the trash, carry exactly the code the
 * request was filed for, and still pass validation.
 *
 * @param string $token    Request token.
 * @param string $decision "approve" or "reject".
 * @param int    $user_id  Administrator deciding.
 * @return array|WP_Error Outcome with a "status" key.
 */
function ewpa_snippets_decide_activation( string $token, string $decision, int $user_id ) {
	$requests = ewpa_snippets_requests();
	if ( '' === $token || ! isset( $requests[ $token ] ) ) {
		return new WP_Error( 'request_not_found', __( 'This activation request does not exist or has expired.', 'enable-abilities-for-mcp' ) );
	}

	$request    = $requests[ $token ];
	$snippet_id = (int) $request['snippet_id'];

	if ( 'approve' !== $decision ) {
		ewpa_snippets_delete_request( $token );
		do_action( 'ewpa_code_snippet_activation_rejected', $snippet_id, $user_id );
		return array(
			'status'     => 'rejected',
			'snippet_id' => $snippet_id,
		);
	}

	$snippet = ewpa_snippets_get( $snippet_id );
	if ( is_wp_error( $snippet ) ) {
		ewpa_snippets_delete_request( $token );
		return $snippet;
	}

	if ( ewpa_snippets_is_trashed( $snippet ) || 'php' !== ewpa_snippets_type( $snippet ) ) {
		ewpa_snippets_delete_request( $token );
		return new WP_Error( 'not_activatable', __( 'Only PHP snippets that are not in the trash can be activated this way.', 'enable-abilities-for-mcp' ) );
	}

	if ( ! hash_equals( (string) $request['code_hash'], hash( 'sha256', (string) $snippet->code ) ) ) {
		ewpa_snippets_delete_request( $token );
		return new WP_Error( 'code_changed', __( 'The snippet code changed after this activation was requested, so the request was cancelled. Request the activation again to review the current code.', 'enable-abilities-for-mcp' ) );
	}

	$valid = ewpa_snippets_validate_php( (string) $snippet->code );
	if ( is_wp_error( $valid ) ) {
		ewpa_snippets_delete_request( $token );
		return $valid;
	}

	if ( ! empty( $snippet->active ) ) {
		ewpa_snippets_delete_request( $token );
		return array(
			'status'     => 'already_active',
			'snippet_id' => $snippet_id,
		);
	}

	$activate = ewpa_snippets_function( 'activate_snippet' );
	if ( null === $activate ) {
		return ewpa_snippets_unavailable_error();
	}

	$result = $activate( $snippet_id );
	if ( is_string( $result ) ) {
		return new WP_Error( 'activation_failed', $result );
	}
	if ( ! $result ) {
		return new WP_Error( 'activation_failed', __( 'Code Snippets could not activate the snippet.', 'enable-abilities-for-mcp' ) );
	}

	ewpa_snippets_delete_request( $token );
	do_action( 'ewpa_after_activate_code_snippet', $snippet_id, $user_id );

	return array(
		'status'     => 'activated',
		'snippet_id' => $snippet_id,
	);
}

// ─── Human confirmation screen ───────────────────────────────────────────────

add_action( 'admin_post_ewpa_snippet_activation', 'ewpa_snippets_handle_activation_post' );
add_action( 'admin_notices', 'ewpa_snippets_pending_notice' );

/**
 * Handles the approve/reject form posted from the confirmation screen.
 *
 * @return void
 */
function ewpa_snippets_handle_activation_post(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to activate code snippets.', 'enable-abilities-for-mcp' ), 403 );
	}

	$token = isset( $_POST['ewpa_snippet_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ewpa_snippet_token'] ) ) : '';
	check_admin_referer( 'ewpa_snippet_activation_' . $token );

	$decision = isset( $_POST['ewpa_snippet_decision'] ) && 'approve' === $_POST['ewpa_snippet_decision'] ? 'approve' : 'reject';
	$outcome  = ewpa_snippets_decide_activation( $token, $decision, get_current_user_id() );

	if ( is_wp_error( $outcome ) ) {
		$notice = array(
			'type'    => 'error',
			'message' => $outcome->get_error_message(),
		);
	} elseif ( 'activated' === $outcome['status'] ) {
		$notice = array(
			'type'    => 'success',
			/* translators: %d: snippet ID */
			'message' => sprintf( __( 'Code snippet %d is now active.', 'enable-abilities-for-mcp' ), $outcome['snippet_id'] ),
		);
	} elseif ( 'already_active' === $outcome['status'] ) {
		$notice = array(
			'type'    => 'info',
			/* translators: %d: snippet ID */
			'message' => sprintf( __( 'Code snippet %d was already active.', 'enable-abilities-for-mcp' ), $outcome['snippet_id'] ),
		);
	} else {
		$notice = array(
			'type'    => 'info',
			/* translators: %d: snippet ID */
			'message' => sprintf( __( 'The activation request for code snippet %d was rejected. The snippet stays inactive.', 'enable-abilities-for-mcp' ), $outcome['snippet_id'] ),
		);
	}

	set_transient( 'ewpa_snippet_notice_' . get_current_user_id(), $notice, 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=ewpa-settings' ) );
	exit;
}

/**
 * Shows administrators how many activation requests wait for confirmation.
 *
 * @return void
 */
function ewpa_snippets_pending_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || isset( $_GET['ewpa_snippet_request'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$requests = ewpa_snippets_requests();
	if ( empty( $requests ) ) {
		return;
	}

	uasort(
		$requests,
		static function ( $a, $b ) {
			return (int) $b['requested_at'] <=> (int) $a['requested_at'];
		}
	);
	$token = (string) array_key_first( $requests );
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'Enable Abilities for MCP:', 'enable-abilities-for-mcp' ); ?></strong>
			<?php
			printf(
				/* translators: %d: number of pending requests */
				esc_html( _n( '%d code snippet activation request is waiting for an administrator to review it.', '%d code snippet activation requests are waiting for an administrator to review them.', count( $requests ), 'enable-abilities-for-mcp' ) ),
				(int) count( $requests )
			);
			?>
			<a href="<?php echo esc_url( ewpa_snippets_confirmation_url( $token ) ); ?>"><?php esc_html_e( 'Review the latest request', 'enable-abilities-for-mcp' ); ?></a>
		</p>
	</div>
	<?php
}

/**
 * Renders the result notice and, when a request token is in the URL, the
 * confirmation card at the top of the settings page.
 *
 * @return void
 */
function ewpa_snippets_render_activation_screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice_key = 'ewpa_snippet_notice_' . get_current_user_id();
	$notice     = get_transient( $notice_key );
	if ( is_array( $notice ) ) {
		delete_transient( $notice_key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'error', 'info', 'warning' ), true ) ? $notice['type'] : 'info' ),
			esc_html( (string) $notice['message'] )
		);
	}

	if ( ! isset( $_GET['ewpa_snippet_request'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$token    = sanitize_text_field( wp_unslash( $_GET['ewpa_snippet_request'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$requests = ewpa_snippets_requests();

	if ( ! isset( $requests[ $token ] ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'This activation request does not exist or has expired.', 'enable-abilities-for-mcp' ) . '</p></div>';
		return;
	}

	$request = $requests[ $token ];
	$snippet = ewpa_snippets_get( (int) $request['snippet_id'] );
	if ( is_wp_error( $snippet ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $snippet->get_error_message() ) . '</p></div>';
		return;
	}

	$code_matches = hash_equals( (string) $request['code_hash'], hash( 'sha256', (string) $snippet->code ) );
	$valid        = ewpa_snippets_validate_php( (string) $snippet->code );
	$requester    = get_userdata( (int) $request['requested_by'] );
	$can_approve  = $code_matches && ! is_wp_error( $valid ) && ! ewpa_snippets_is_trashed( $snippet ) && 'php' === ewpa_snippets_type( $snippet ) && empty( $snippet->active );
	?>
	<div class="ewpa-section" id="ewpa-snippet-activation" style="border-left: 4px solid #d63638; margin: 16px 0;">
		<div class="ewpa-section-header">
			<h2 style="margin: 0;">
				<span class="dashicons dashicons-shield-alt"></span>
				<?php esc_html_e( 'Confirm code snippet activation', 'enable-abilities-for-mcp' ); ?>
			</h2>
		</div>
		<p>
			<?php esc_html_e( 'An MCP client asked to activate this PHP snippet. Activating it runs this code on every request in its scope. Only approve code you have read and trust.', 'enable-abilities-for-mcp' ); ?>
		</p>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr><th><?php esc_html_e( 'Snippet', 'enable-abilities-for-mcp' ); ?></th><td><?php echo esc_html( $snippet->name ); ?> (ID <?php echo (int) $snippet->id; ?>)</td></tr>
				<tr><th><?php esc_html_e( 'Scope', 'enable-abilities-for-mcp' ); ?></th><td><code><?php echo esc_html( $snippet->scope ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Requested by', 'enable-abilities-for-mcp' ); ?></th><td><?php echo esc_html( $requester ? $requester->display_name : '#' . (int) $request['requested_by'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Requested', 'enable-abilities-for-mcp' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $request['requested_at'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Expires', 'enable-abilities-for-mcp' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $request['expires_at'] ) ); ?></td></tr>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Code', 'enable-abilities-for-mcp' ); ?></h3>
		<pre style="max-height: 420px; overflow: auto; background: #1d2327; color: #f0f0f1; padding: 12px; border-radius: 4px; white-space: pre;"><?php echo esc_html( (string) $snippet->code ); ?></pre>
		<?php if ( ! $code_matches ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The snippet code changed after this activation was requested. This request can only be rejected; request the activation again to review the current code.', 'enable-abilities-for-mcp' ); ?></p></div>
		<?php elseif ( is_wp_error( $valid ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $valid->get_error_message() ); ?></p></div>
		<?php elseif ( ! empty( $snippet->active ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'This snippet is already active.', 'enable-abilities-for-mcp' ); ?></p></div>
		<?php elseif ( ! $can_approve ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Only PHP snippets that are not in the trash can be activated this way.', 'enable-abilities-for-mcp' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
			<input type="hidden" name="action" value="ewpa_snippet_activation" />
			<input type="hidden" name="ewpa_snippet_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'ewpa_snippet_activation_' . $token ); ?>
			<?php if ( $can_approve ) : ?>
				<button type="submit" name="ewpa_snippet_decision" value="approve" class="button button-primary">
					<?php esc_html_e( 'Activate snippet', 'enable-abilities-for-mcp' ); ?>
				</button>
			<?php endif; ?>
			<button type="submit" name="ewpa_snippet_decision" value="reject" class="button" style="margin-left: 6px;">
				<?php esc_html_e( 'Reject request', 'enable-abilities-for-mcp' ); ?>
			</button>
		</form>
	</div>
	<?php
}
