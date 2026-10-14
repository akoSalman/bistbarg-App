<?php
/**
 * Real-time delivery through the Bistbarg socket server (see socket-server/ in the app repository).
 *
 * - The app asks WordPress for a signed token (admin-ajax, with its login cookies) and connects to
 *   the socket server with it.
 * - When a notification is queued, WordPress tells the socket server which audience to ping.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'bbp_notification_added', 'bbp_socket_publish', 10, 3 );
add_action( 'wp_ajax_bistbarg_socket', 'bbp_ajax_socket' );
add_action( 'wp_ajax_nopriv_bistbarg_socket', 'bbp_ajax_socket' );

function bbp_socket_default_url() {
	return preg_replace( '#^http#', 'ws', home_url( '/bistbarg-socket/' ) );
}

function bbp_b64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/** Signed token: base64url(json) . "." . base64url(hmac_sha256). Valid for 24 hours. */
function bbp_socket_token( $user_id, $device ) {
	$settings = bbp_settings();
	$body     = bbp_b64url(
		wp_json_encode(
			array(
				'u' => (int) $user_id,
				'd' => (string) $device,
				'e' => time() + DAY_IN_SECONDS,
			)
		)
	);
	return $body . '.' . bbp_b64url( hash_hmac( 'sha256', $body, $settings['socket_secret'], true ) );
}

function bbp_ajax_socket() {
	nocache_headers();
	$settings = bbp_settings();
	if ( empty( $settings['socket_enabled'] ) ) {
		wp_send_json( array( 'enabled' => false ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- issues a token for the caller's own session.
	$device = isset( $_GET['device'] ) ? sanitize_text_field( wp_unslash( $_GET['device'] ) ) : '';
	if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $device ) ) {
		wp_send_json_error( array( 'message' => 'invalid device' ), 400 );
	}
	wp_send_json(
		array(
			'enabled' => true,
			'url'     => $settings['socket_url'],
			'token'   => bbp_socket_token( get_current_user_id(), $device ),
		)
	);
}

function bbp_socket_request( $method, $path, $body = null, $timeout = 3 ) {
	$settings = bbp_settings();
	$args     = array(
		'method'  => $method,
		'timeout' => $timeout,
		'headers' => array(
			'X-Bistbarg-Secret' => $settings['socket_secret'],
			'Content-Type'      => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$response = wp_remote_request( untrailingslashit( $settings['socket_internal'] ) . $path, $args );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

/** Pings the phones that should receive notification $id. */
function bbp_socket_publish( $id, $audience, $user_id ) {
	$settings = bbp_settings();
	if ( empty( $settings['socket_enabled'] ) ) {
		return;
	}
	bbp_socket_request(
		'POST',
		'/publish',
		array(
			'id'       => (int) $id,
			'audience' => $audience,
			'user_id'  => (int) $user_id,
		)
	);
}

/** Connected-phones info from the socket server, or null if it can't be reached. */
function bbp_socket_health() {
	$settings = bbp_settings();
	if ( empty( $settings['socket_enabled'] ) ) {
		return null;
	}
	return bbp_socket_request( 'GET', '/health', null, 2 );
}
