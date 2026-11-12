<?php
/**
 * Upload links: get a local file into the media library without the model carrying it.
 *
 * Passing a file as base64 through an ability parameter makes the model emit the whole
 * file as text, which is slow, expensive and usually breaks on size limits. Instead,
 * ewpa/create-upload-link hands out a short-lived, single-use link. The client (or the
 * person, through a small browser page) sends the bytes straight to WordPress, and
 * ewpa/get-upload-status tells the model when the attachment exists.
 *
 * The link token is the only credential on the upload route, so:
 * - only its SHA-256 hash is stored, never the token itself;
 * - it expires after a few minutes and is bound to the user who created it, whose
 *   upload_files capability is checked both when the link is created and when it is used;
 * - it is consumed by a single DELETE, so two concurrent uploads cannot both succeed;
 * - files are limited to an image allowlist and a size cap, and checked by content.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EWPA_UPLOAD_LINK_PREFIX', 'ewpa_upload_link_' );
define( 'EWPA_UPLOAD_DONE_PREFIX', 'ewpa_upload_done_' );
define( 'EWPA_UPLOAD_QUERY_VAR', 'ewpa_upload' );

// ─── Settings ────────────────────────────────────────────────────────────────

/**
 * Seconds a new upload link stays valid.
 *
 * @return int Between 60 and 3600.
 */
function ewpa_upload_link_ttl(): int {
	/**
	 * Filters how long an upload link stays valid, in seconds.
	 *
	 * @param int $ttl Default 600 (10 minutes). Clamped to 60-3600.
	 */
	$ttl = (int) apply_filters( 'ewpa_upload_link_ttl', 10 * MINUTE_IN_SECONDS );

	return max( MINUTE_IN_SECONDS, min( HOUR_IN_SECONDS, $ttl ) );
}

/**
 * Maximum number of unexpired, unused links one user may hold at once.
 *
 * @return int At least 1.
 */
function ewpa_upload_link_max_active(): int {
	/**
	 * Filters how many pending upload links one user may hold at once.
	 *
	 * @param int $max Default 5.
	 */
	return max( 1, (int) apply_filters( 'ewpa_upload_link_max_active', 5 ) );
}

/**
 * Largest file an upload link accepts, in bytes.
 *
 * @return int
 */
function ewpa_upload_link_max_bytes(): int {
	/**
	 * Filters the largest file an upload link accepts, in bytes.
	 *
	 * The REST API reads the whole request body into memory, so the default stays well
	 * below a typical memory_limit even when the site allows much larger uploads.
	 *
	 * @param int $bytes Default: the site's upload limit (wp_max_upload_size()), capped at 20 MB.
	 */
	return max( 1, (int) apply_filters( 'ewpa_upload_link_max_bytes', min( (int) wp_max_upload_size(), 20 * MB_IN_BYTES ) ) );
}

/**
 * File types an upload link accepts: images only.
 *
 * @return array<string,string> Extension pattern => MIME type, as upload_mimes uses.
 */
function ewpa_upload_link_mimes(): array {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	);
}

// ─── Storage ─────────────────────────────────────────────────────────────────

/**
 * Hashes a link token. Only the hash is ever stored or returned as the upload ID.
 *
 * @param string $token Link token.
 * @return string 64 hex characters.
 */
function ewpa_upload_link_hash( string $token ): string {
	return hash( 'sha256', $token );
}

/**
 * Whether a string looks like a token or an upload ID (64 lowercase hex characters).
 *
 * @param mixed $value Value to check.
 * @return bool
 */
function ewpa_upload_link_is_hex64( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
}

/**
 * Reads one stored record straight from the options table.
 *
 * The object cache is bypassed on purpose: links are consumed with a direct DELETE,
 * and a cached copy must never make a used link look valid again.
 *
 * @param string $name Option name.
 * @return array|null The record, or null if missing or malformed.
 */
function ewpa_upload_link_read( string $name ): ?array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row as it is now; see the docblock.
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	if ( null === $raw ) {
		return null;
	}

	$record = maybe_unserialize( $raw );

	return is_array( $record ) && isset( $record['user_id'], $record['expires'] ) ? $record : null;
}

/**
 * Stores one record as a non-autoloaded option.
 *
 * @param string $name   Option name.
 * @param array  $record Record.
 * @return bool
 */
function ewpa_upload_link_write( string $name, array $record ): bool {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	return add_option( $name, $record, '', false );
}

/**
 * Deletes one record and reports whether this call is the one that removed it.
 *
 * This is how a link is consumed: when two uploads race, the database lets exactly
 * one DELETE affect the row, and only that request goes on to store the file.
 *
 * @param string $name Option name.
 * @return bool True only if this call deleted the row.
 */
function ewpa_upload_link_take( string $name ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The affected-row count is the lock; delete_option() does not expose it.
	$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
	wp_cache_delete( $name, 'options' );

	return 1 === $deleted;
}

/**
 * Lists stored records of one kind.
 *
 * @param string $prefix EWPA_UPLOAD_LINK_PREFIX or EWPA_UPLOAD_DONE_PREFIX.
 * @return array<string,array> Hash => record.
 */
function ewpa_upload_link_list( string $prefix ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small, short-lived set; see ewpa_upload_link_read().
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);

	$records = array();
	foreach ( (array) $rows as $row ) {
		$record = maybe_unserialize( $row->option_value );
		if ( is_array( $record ) && isset( $record['user_id'], $record['expires'] ) ) {
			$records[ substr( $row->option_name, strlen( $prefix ) ) ] = $record;
		}
	}

	return $records;
}

/**
 * Deletes expired links and expired upload results.
 *
 * @return void
 */
function ewpa_upload_link_gc(): void {
	$now = time();

	foreach ( array( EWPA_UPLOAD_LINK_PREFIX, EWPA_UPLOAD_DONE_PREFIX ) as $prefix ) {
		foreach ( ewpa_upload_link_list( $prefix ) as $hash => $record ) {
			if ( (int) $record['expires'] <= $now ) {
				ewpa_upload_link_take( $prefix . $hash );
			}
		}
	}
}

// ─── Link lifecycle ──────────────────────────────────────────────────────────

/**
 * Creates an upload link for a user.
 *
 * @param int   $user_id User the link belongs to.
 * @param array $args    Optional 'title' and 'alt_text' for the attachment.
 * @return array|WP_Error Link data, including the token, or an error.
 */
function ewpa_upload_link_create( int $user_id, array $args = array() ) {
	if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
		return new WP_Error( 'forbidden', 'The authenticated user is not allowed to upload files.', array( 'status' => 403 ) );
	}

	ewpa_upload_link_gc();

	$active = 0;
	foreach ( ewpa_upload_link_list( EWPA_UPLOAD_LINK_PREFIX ) as $record ) {
		if ( (int) $record['user_id'] === $user_id ) {
			++$active;
		}
	}

	$max = ewpa_upload_link_max_active();
	if ( $active >= $max ) {
		return new WP_Error(
			'too_many_links',
			sprintf( 'This user already has %d unused upload links. Use one or wait for them to expire.', $max ),
			array( 'status' => 429 )
		);
	}

	$token   = bin2hex( random_bytes( 32 ) );
	$hash    = ewpa_upload_link_hash( $token );
	$expires = time() + ewpa_upload_link_ttl();
	$record  = array(
		'user_id'  => $user_id,
		'expires'  => $expires,
		'title'    => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '',
		'alt_text' => isset( $args['alt_text'] ) ? sanitize_text_field( (string) $args['alt_text'] ) : '',
	);

	if ( ! ewpa_upload_link_write( EWPA_UPLOAD_LINK_PREFIX . $hash, $record ) ) {
		return new WP_Error( 'storage_failed', 'The upload link could not be stored.', array( 'status' => 500 ) );
	}

	$types = array();
	foreach ( array_keys( ewpa_upload_link_mimes() ) as $pattern ) {
		$types = array_merge( $types, explode( '|', $pattern ) );
	}

	return array(
		'upload_id'     => $hash,
		'upload_url'    => add_query_arg( 'filename', 'FILENAME', rest_url( 'ewpa/v1/upload/' . $token ) ),
		'page_url'      => add_query_arg( EWPA_UPLOAD_QUERY_VAR, $token, home_url( '/' ) ),
		'expires_at'    => gmdate( 'c', $expires ),
		'max_bytes'     => ewpa_upload_link_max_bytes(),
		'allowed_types' => $types,
		'instructions'  => 'Do not send the file through this conversation. If you can run commands and have the file, upload it with: curl -X PUT --data-binary @<file> "<upload_url with FILENAME replaced by the file name>". Otherwise give the user page_url to open in a browser and drop the file there. Then call ewpa/get-upload-status with upload_id.',
	);
}

/**
 * Returns a pending link for a token, or null if the token is unknown or expired.
 *
 * @param string $token Link token.
 * @return array|null
 */
function ewpa_upload_link_pending( string $token ): ?array {
	if ( ! ewpa_upload_link_is_hex64( $token ) ) {
		return null;
	}

	$record = ewpa_upload_link_read( EWPA_UPLOAD_LINK_PREFIX . ewpa_upload_link_hash( $token ) );

	return ( $record && (int) $record['expires'] > time() ) ? $record : null;
}

/**
 * Stores an uploaded file for a link and consumes the link.
 *
 * On a validation failure the link stays usable until it expires, so the person can
 * retry with a valid file. Only a stored attachment consumes it.
 *
 * @param string $token    Link token.
 * @param string $tmp_file Path of the received bytes. Deleted unless it became the attachment.
 * @param string $filename Original file name, as sent by the client.
 * @return array|WP_Error Attachment data, or an error carrying an HTTP status.
 */
function ewpa_upload_link_receive( string $token, string $tmp_file, string $filename ) {
	$result = ewpa_upload_link_store( $token, $tmp_file, $filename );

	if ( is_wp_error( $result ) && file_exists( $tmp_file ) ) {
		wp_delete_file( $tmp_file );
	}

	return $result;
}

/**
 * Does the work of ewpa_upload_link_receive(), which owns temp-file cleanup.
 *
 * @param string $token    Link token.
 * @param string $tmp_file Path of the received bytes.
 * @param string $filename Original file name.
 * @return array|WP_Error
 */
function ewpa_upload_link_store( string $token, string $tmp_file, string $filename ) {
	$record = ewpa_upload_link_pending( $token );
	if ( ! $record ) {
		// Unknown, used and expired links look the same, so a guess learns nothing.
		return new WP_Error( 'invalid_link', 'This upload link is invalid, already used or expired. Ask for a new one.', array( 'status' => 404 ) );
	}

	$hash    = ewpa_upload_link_hash( $token );
	$user_id = (int) $record['user_id'];

	// The owner may have lost the capability, or the account, since the link was made.
	if ( ! get_userdata( $user_id ) || ! user_can( $user_id, 'upload_files' ) ) {
		ewpa_upload_link_take( EWPA_UPLOAD_LINK_PREFIX . $hash );
		return new WP_Error( 'forbidden', 'The user who created this link can no longer upload files.', array( 'status' => 403 ) );
	}

	$size = file_exists( $tmp_file ) ? (int) filesize( $tmp_file ) : 0;
	if ( $size <= 0 ) {
		return new WP_Error( 'empty_file', 'No file was received. Send the file as the request body.', array( 'status' => 400 ) );
	}

	$max = ewpa_upload_link_max_bytes();
	if ( $size > $max ) {
		return new WP_Error( 'file_too_large', sprintf( 'The file is %1$d bytes; the limit is %2$d bytes.', $size, $max ), array( 'status' => 413 ) );
	}

	$name = sanitize_file_name( $filename );
	$type = wp_check_filetype_and_ext( $tmp_file, $name, ewpa_upload_link_mimes() );
	if ( empty( $type['ext'] ) || empty( $type['type'] ) ) {
		return new WP_Error(
			'invalid_type',
			'Only JPEG, PNG, GIF, WebP and AVIF images are accepted, and the content must match the file extension.',
			array( 'status' => 415 )
		);
	}
	if ( ! empty( $type['proper_filename'] ) ) {
		$name = $type['proper_filename'];
	}

	if ( ! ewpa_upload_link_take( EWPA_UPLOAD_LINK_PREFIX . $hash ) ) {
		return new WP_Error( 'invalid_link', 'This upload link was used by another request.', array( 'status' => 409 ) );
	}

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	// The upload runs as the link's owner: authorship and the site's own MIME rules follow that user.
	$previous_user = get_current_user_id();
	wp_set_current_user( $user_id );

	$post_data = array();
	if ( '' !== $record['title'] ) {
		$post_data['post_title'] = $record['title'];
	}

	$attachment_id = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp_file,
		),
		0,
		null,
		$post_data
	);

	wp_set_current_user( $previous_user );

	if ( is_wp_error( $attachment_id ) ) {
		// Nothing was stored, so give the link back for a retry until it expires.
		ewpa_upload_link_write( EWPA_UPLOAD_LINK_PREFIX . $hash, $record );
		return new WP_Error( 'upload_failed', 'The file could not be stored: ' . $attachment_id->get_error_message(), array( 'status' => 500 ) );
	}

	if ( '' !== $record['alt_text'] ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $record['alt_text'] );
	}

	ewpa_upload_link_write(
		EWPA_UPLOAD_DONE_PREFIX . $hash,
		array(
			'user_id'       => $user_id,
			'attachment_id' => (int) $attachment_id,
			'expires'       => time() + HOUR_IN_SECONDS,
		)
	);

	// The upload route has no logged-in user, so record it against the link's owner.
	if ( function_exists( 'ewpa_log_activity' ) ) {
		ewpa_log_activity( $user_id, 'ewpa/upload-link-received' );
	}

	return ewpa_upload_link_attachment_data( (int) $attachment_id );
}

/**
 * Describes a stored attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return array
 */
function ewpa_upload_link_attachment_data( int $attachment_id ): array {
	return array(
		'attachment_id' => $attachment_id,
		'url'           => (string) wp_get_attachment_url( $attachment_id ),
		'mime_type'     => (string) get_post_mime_type( $attachment_id ),
		'title'         => (string) get_the_title( $attachment_id ),
	);
}

/**
 * Reports what happened to an upload link.
 *
 * Links that do not exist, that belong to another user, or that expired unused all
 * answer 'expired', so the status ability cannot be used to probe other users' links.
 *
 * @param string $upload_id Upload ID returned by ewpa_upload_link_create().
 * @param int    $user_id   User asking.
 * @return array|WP_Error
 */
function ewpa_upload_link_status( string $upload_id, int $user_id ) {
	if ( ! ewpa_upload_link_is_hex64( $upload_id ) ) {
		return new WP_Error( 'invalid_upload_id', 'upload_id must be the 64-character value returned by ewpa/create-upload-link.', array( 'status' => 400 ) );
	}

	$now  = time();
	$done = ewpa_upload_link_read( EWPA_UPLOAD_DONE_PREFIX . $upload_id );
	if ( $done && (int) $done['user_id'] === $user_id && (int) $done['expires'] > $now && get_post( (int) $done['attachment_id'] ) ) {
		return array( 'status' => 'completed' ) + ewpa_upload_link_attachment_data( (int) $done['attachment_id'] );
	}

	$link = ewpa_upload_link_read( EWPA_UPLOAD_LINK_PREFIX . $upload_id );
	if ( $link && (int) $link['user_id'] === $user_id && (int) $link['expires'] > $now ) {
		return array(
			'status'     => 'pending',
			'expires_at' => gmdate( 'c', (int) $link['expires'] ),
			'message'    => 'The file has not arrived yet. Check again after the upload finishes.',
		);
	}

	return array(
		'status'  => 'expired',
		'message' => 'No pending or completed upload matches this upload_id. Create a new link.',
	);
}

// ─── REST endpoint ───────────────────────────────────────────────────────────

/**
 * Registers PUT /wp-json/ewpa/v1/upload/<token>.
 *
 * @return void
 */
function ewpa_upload_link_register_route(): void {
	register_rest_route(
		'ewpa/v1',
		'/upload/(?P<token>[a-f0-9]{64})',
		array(
			'methods'             => array( 'PUT', 'POST' ),
			'callback'            => 'ewpa_upload_link_rest_receive',
			// The link token in the URL is the credential; the callback validates it.
			'permission_callback' => '__return_true',
			'args'                => array(
				'filename' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ewpa_upload_link_register_route' );

/**
 * Handles an upload: the request body is the file.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ewpa_upload_link_rest_receive( WP_REST_Request $request ) {
	$token = (string) $request['token'];

	// Reject early so an invalid link never writes a temp file.
	if ( ! ewpa_upload_link_pending( $token ) ) {
		return new WP_Error( 'invalid_link', 'This upload link is invalid, already used or expired. Ask for a new one.', array( 'status' => 404 ) );
	}

	$body = $request->get_body();
	$max  = ewpa_upload_link_max_bytes();
	if ( strlen( $body ) > $max ) {
		return new WP_Error( 'file_too_large', sprintf( 'The file is %1$d bytes; the limit is %2$d bytes.', strlen( $body ), $max ), array( 'status' => 413 ) );
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$filename = (string) $request['filename'];
	$tmp_file = wp_tempnam( $filename );
	if ( ! $tmp_file || false === file_put_contents( $tmp_file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file handed to media_handle_sideload().
		return new WP_Error( 'storage_failed', 'The file could not be written to a temporary location.', array( 'status' => 500 ) );
	}
	unset( $body );

	$result = ewpa_upload_link_receive( $token, $tmp_file, $filename );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( $result, 201 );
}

// ─── Browser page ────────────────────────────────────────────────────────────

/**
 * Serves the upload page at ?ewpa_upload=<token>, for clients that cannot send files.
 *
 * @return void
 */
function ewpa_upload_link_maybe_serve_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The link token is the credential; it is validated below.
	if ( ! isset( $_GET[ EWPA_UPLOAD_QUERY_VAR ] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
	$token = sanitize_text_field( wp_unslash( $_GET[ EWPA_UPLOAD_QUERY_VAR ] ) );
	$valid = null !== ewpa_upload_link_pending( $token );

	nocache_headers();
	status_header( $valid ? 200 : 404 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Frame-Options: DENY' );

	$nonce      = bin2hex( random_bytes( 16 ) );
	$rest       = rest_url( 'ewpa/v1/upload/' . $token );
	$rest_parts = wp_parse_url( $rest );
	$origin     = $rest_parts['scheme'] . '://' . $rest_parts['host'] . ( isset( $rest_parts['port'] ) ? ':' . $rest_parts['port'] : '' );
	header( "Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; img-src blob:; connect-src $origin; base-uri 'none'; form-action 'none'; frame-ancestors 'none'" );

	ewpa_upload_link_render_page( $valid, $rest, $nonce );
	exit;
}
add_action( 'template_redirect', 'ewpa_upload_link_maybe_serve_page', 0 );

/**
 * Prints the upload page.
 *
 * @param bool   $valid Whether the link can still be used.
 * @param string $rest  Upload endpoint URL.
 * @param string $nonce CSP nonce.
 * @return void
 */
function ewpa_upload_link_render_page( bool $valid, string $rest, string $nonce ): void {
	$site = get_bloginfo( 'name' );
	$max  = ewpa_upload_link_max_bytes();
	$i18n = array(
		'uploading' => __( 'Uploading…', 'enable-abilities-for-mcp' ),
		'done'      => __( 'Uploaded. You can close this page and go back to your AI assistant.', 'enable-abilities-for-mcp' ),
		'tooLarge'  => __( 'This file is larger than the upload limit.', 'enable-abilities-for-mcp' ),
		'failed'    => __( 'The upload failed:', 'enable-abilities-for-mcp' ),
	);
	?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Upload an image to %s', 'enable-abilities-for-mcp' ), $site ) ); ?></title>
<style nonce="<?php echo esc_attr( $nonce ); ?>">
:root{--ink:#1d2327;--muted:#50575e;--line:#c3c4c7;--bg:#f6f7f7;--card:#fff;--accent:#2271b1;--ok:#00a32a;--err:#d63638}
@media (prefers-color-scheme:dark){:root{--ink:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--bg:#101517;--card:#1d2327;--accent:#72aee6;--ok:#68de7c;--err:#ff8085}}
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{width:100%;max-width:440px;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:28px}
h1{font-size:1.2rem;margin:0 0 4px}
p{margin:0 0 16px;color:var(--muted)}
label{display:block;border:2px dashed var(--line);border-radius:8px;padding:32px 16px;text-align:center;cursor:pointer}
label:hover,label.over{border-color:var(--accent)}
label:focus-within{outline:2px solid var(--accent);outline-offset:2px}
input{position:absolute;opacity:0;width:1px;height:1px}
img{display:block;max-width:100%;max-height:220px;margin:16px auto 0;border-radius:4px}
#status{margin:16px 0 0;font-weight:600;color:var(--ink)}
#status.ok{color:var(--ok)}#status.err{color:var(--err)}
</style>
</head>
<body>
<main>
	<?php if ( ! $valid ) : ?>
	<h1><?php esc_html_e( 'This upload link has expired', 'enable-abilities-for-mcp' ); ?></h1>
	<p><?php esc_html_e( 'Links work once and only for a few minutes. Ask your AI assistant for a new one.', 'enable-abilities-for-mcp' ); ?></p>
	<?php else : ?>
	<h1><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Upload an image to %s', 'enable-abilities-for-mcp' ), $site ) ); ?></h1>
	<p><?php echo esc_html( sprintf( /* translators: %s: maximum file size, e.g. "8 MB" */ __( 'JPEG, PNG, GIF, WebP or AVIF, up to %s. The image goes straight to the media library.', 'enable-abilities-for-mcp' ), size_format( $max ) ) ); ?></p>
	<label id="drop"><?php esc_html_e( 'Drop an image here or click to choose one', 'enable-abilities-for-mcp' ); ?>
		<input id="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif">
	</label>
	<img id="preview" alt="" hidden>
	<p id="status" role="status" aria-live="polite"></p>
	<script nonce="<?php echo esc_attr( $nonce ); ?>">
	(function () {
		var endpoint = <?php echo wp_json_encode( $rest ); ?>;
		var maxBytes = <?php echo (int) $max; ?>;
		var t = <?php echo wp_json_encode( $i18n ); ?>;
		var drop = document.getElementById('drop'), input = document.getElementById('file');
		var statusEl = document.getElementById('status'), preview = document.getElementById('preview');
		var busy = false;
		function say(text, cls) { statusEl.textContent = text; statusEl.className = cls || ''; }
		function send(file) {
			if (!file || busy) { return; }
			if (file.size > maxBytes) { say(t.tooLarge, 'err'); return; }
			busy = true;
			preview.src = URL.createObjectURL(file); preview.hidden = false;
			say(t.uploading);
			fetch(endpoint + (endpoint.indexOf('?') < 0 ? '?' : '&') + 'filename=' + encodeURIComponent(file.name), {
				method: 'PUT', body: file, credentials: 'omit',
				headers: { 'Content-Type': file.type || 'application/octet-stream' }
			}).then(function (r) {
				return r.json().then(function (data) { return { ok: r.ok, data: data }; });
			}).then(function (res) {
				if (res.ok) { say(t.done, 'ok'); drop.hidden = true; return; }
				busy = false; say(t.failed + ' ' + (res.data && res.data.message ? res.data.message : ''), 'err');
			}).catch(function (e) { busy = false; say(t.failed + ' ' + e.message, 'err'); });
		}
		input.addEventListener('change', function () { send(input.files[0]); });
		['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
		['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
		drop.addEventListener('drop', function (e) { send(e.dataTransfer.files[0]); });
	}());
	</script>
	<?php endif; ?>
</main>
</body>
</html>
	<?php
}

// ─── Abilities ───────────────────────────────────────────────────────────────

/**
 * Registers ewpa/create-upload-link and ewpa/get-upload-status.
 *
 * @return void
 */
function ewpa_register_upload_link_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	if ( ewpa_is_ability_enabled( 'ewpa/create-upload-link' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/create-upload-link',
			array(
				'label'               => __( 'Create Upload Link', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Creates a temporary, single-use link to upload an image from the user\'s computer straight to the media library, so the file never passes through the conversation. Use this instead of sending base64. If you can run commands and have the file, send it with an HTTP PUT to upload_url; otherwise give the user page_url to open in a browser. Then call ewpa/get-upload-status with upload_id to get the attachment ID.', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'    => array(
							'type'        => 'string',
							'description' => 'Title for the attachment (optional; defaults to the file name)',
						),
						'alt_text' => array(
							'type'        => 'string',
							'description' => 'Image alt text (optional)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'upload_id'     => array( 'type' => 'string' ),
						'upload_url'    => array( 'type' => 'string' ),
						'page_url'      => array( 'type' => 'string' ),
						'expires_at'    => array( 'type' => 'string' ),
						'max_bytes'     => array( 'type' => 'integer' ),
						'allowed_types' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'instructions'  => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'execute_callback'    => function ( $input ) {
					return ewpa_upload_link_create( get_current_user_id(), is_array( $input ) ? $input : array() );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	if ( ewpa_is_ability_enabled( 'ewpa/get-upload-status' ) ) {
		ewpa_register_ability_with_log(
			'ewpa/get-upload-status',
			array(
				'label'               => __( 'Get Upload Status', 'enable-abilities-for-mcp' ),
				'description'         => __( 'Reports whether the file for an upload link has arrived. Returns status "pending", "completed" (with the attachment ID and URL) or "expired".', 'enable-abilities-for-mcp' ),
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'upload_id' ),
					'properties' => array(
						'upload_id' => array(
							'type'        => 'string',
							'description' => 'The upload_id returned by ewpa/create-upload-link (required)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status'        => array(
							'type' => 'string',
							'enum' => array( 'pending', 'completed', 'expired' ),
						),
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'mime_type'     => array( 'type' => 'string' ),
						'title'         => array( 'type' => 'string' ),
						'expires_at'    => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'execute_callback'    => function ( $input ) {
					return ewpa_upload_link_status( (string) ( $input['upload_id'] ?? '' ), get_current_user_id() );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
add_action( 'wp_abilities_api_init', 'ewpa_register_upload_link_abilities' );
