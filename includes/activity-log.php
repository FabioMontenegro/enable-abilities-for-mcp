<?php
/**
 * Activity log for Enable Abilities for MCP.
 *
 * Tracks per-user ability executions via MCP. Provides DB table management,
 * logging helpers, and a filter that wraps every public MCP ability with a
 * log entry on execution.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Table management ────────────────────────────────────────────────────────

/**
 * Returns the full prefixed table name for the activity log.
 */
function ewpa_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'ewpa_activity_log';
}

/**
 * Creates (or upgrades) the activity log table via dbDelta.
 * Safe to call on activation and on upgrade.
 */
function ewpa_create_activity_log_table(): void {
	global $wpdb;

	$table   = ewpa_log_table();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		ability varchar(100) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT 'success',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY created_at (created_at)
	) $charset;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Drops the activity log table. Called on plugin uninstall.
 */
function ewpa_drop_activity_log_table(): void {
	global $wpdb;
	$table = ewpa_log_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS $table" );
}

// ─── Logging ─────────────────────────────────────────────────────────────────

/**
 * Inserts a single activity log entry.
 *
 * @param int    $user_id The WordPress user ID.
 * @param string $ability The ability key (e.g. 'ewpa/get-posts').
 * @param string $status  'success' or 'error'.
 */
function ewpa_log_activity( int $user_id, string $ability, string $status = 'success' ): void {
	global $wpdb;

	$data    = array(
		'user_id'    => $user_id,
		'ability'    => $ability,
		'status'     => $status,
		'created_at' => current_time( 'mysql' ),
	);
	$formats = array( '%d', '%s', '%s', '%s' );

	$result = $wpdb->insert( ewpa_log_table(), $data, $formats );

	// If insert failed the table may not exist yet — create it and retry once.
	if ( false === $result ) {
		ewpa_create_activity_log_table();
		$wpdb->insert( ewpa_log_table(), $data, $formats );
	}
}

/**
 * Queries activity log entries.
 *
 * @param array $args {
 *   Optional. Query arguments.
 *
 *   @type int $user_id  Filter by user. 0 = all users.
 *   @type int $per_page Entries per page. Default 20.
 *   @type int $page     1-based page number. Default 1.
 *   @type int $days     Limit to last N days. 0 = no limit. Default 30.
 * }
 * @return array { logs: object[], total: int }
 */
function ewpa_get_activity_logs( array $args = array() ): array {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'user_id'  => 0,
			'per_page' => 20,
			'page'     => 1,
			'days'     => 30,
		)
	);

	$table      = ewpa_log_table();
	$where      = '1=1';
	$where_vals = array();

	if ( $args['user_id'] ) {
		$where       .= ' AND l.user_id = %d';
		$where_vals[] = (int) $args['user_id'];
	}

	if ( $args['days'] ) {
		$where       .= ' AND l.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
		$where_vals[] = (int) $args['days'];
	}

	// Total count.
	$count_sql = "SELECT COUNT(*) FROM $table l WHERE $where";
	$total     = $where_vals
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_vals ) )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $count_sql );

	// Paginated results joined with users table for login name.
	$offset    = ( max( 1, $args['page'] ) - 1 ) * $args['per_page'];
	$list_vals = array_merge( $where_vals, array( (int) $args['per_page'], $offset ) );
	$list_sql  = "SELECT l.*, u.user_login FROM $table l LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID WHERE $where ORDER BY l.created_at DESC LIMIT %d OFFSET %d";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$logs = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_vals ) );

	return array(
		'logs'  => $logs ? $logs : array(),
		'total' => $total,
	);
}

/**
 * Returns the distinct users who have log entries (for the filter dropdown).
 *
 * @return array Array of objects with user_id and user_login.
 */
function ewpa_get_log_users(): array {
	global $wpdb;
	$table = ewpa_log_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$results = $wpdb->get_results( "SELECT DISTINCT l.user_id, u.user_login FROM $table l LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID ORDER BY u.user_login ASC" );
	return $results ? $results : array();
}

/**
 * Clears activity log entries.
 *
 * @param int $user_id Clear only entries for this user. 0 = clear all.
 */
function ewpa_clear_activity_logs( int $user_id = 0 ): void {
	global $wpdb;
	$table = ewpa_log_table();

	if ( $user_id ) {
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}

// ─── Ability registration with logging ───────────────────────────────────────

/**
 * Registers an ability via wp_register_ability() and wraps its execute_callback
 * to insert an activity log entry on every execution.
 *
 * Use this instead of wp_register_ability() for all abilities you want logged.
 *
 * @param string $ability_key The ability key.
 * @param array  $args        Ability arguments (same as wp_register_ability).
 */
function ewpa_register_ability_with_log( string $ability_key, array $args ): void {
	if ( ! empty( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input ) use ( $ability_key, $original ) {
			$result = $original( $input );
			ewpa_log_activity( get_current_user_id(), $ability_key );
			return $result;
		};
	}

	wp_register_ability( $ability_key, $args );
}

// ─── AJAX: Clear logs ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_ewpa_clear_logs', 'ewpa_ajax_clear_logs' );

/**
 * AJAX handler to clear activity logs (all or per-user).
 */
function ewpa_ajax_clear_logs(): void {
	check_ajax_referer( 'ewpa_logs_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'enable-abilities-for-mcp' ) ) );
	}

	$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	ewpa_clear_activity_logs( $user_id );

	wp_send_json_success( array( 'message' => __( 'Logs cleared successfully.', 'enable-abilities-for-mcp' ) ) );
}
