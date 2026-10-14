<?php
/**
 * Endpoint polled by the Android app:
 *   /wp-admin/admin-ajax.php?action=bistbarg_notifications&device=<uuid>&since=<last id>&v=<app version>
 *
 * admin-ajax is used (rather than the REST API) because it recognises the logged-in customer from
 * the app's WordPress cookies without a nonce, so personal notifications reach the right person.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_bistbarg_notifications', 'bbp_ajax_notifications' );
add_action( 'wp_ajax_nopriv_bistbarg_notifications', 'bbp_ajax_notifications' );

function bbp_ajax_notifications() {
	global $wpdb;
	nocache_headers();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only feed.
	$device  = isset( $_GET['device'] ) ? sanitize_text_field( wp_unslash( $_GET['device'] ) ) : '';
	$since   = isset( $_GET['since'] ) ? absint( $_GET['since'] ) : 0;
	$version = isset( $_GET['v'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['v'] ) ), 0, 20 ) : '';
	// phpcs:enable

	if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $device ) ) {
		wp_send_json_error( array( 'message' => 'invalid device' ), 400 );
	}

	$user_id = get_current_user_id();
	$now     = current_time( 'mysql', true );
	$devices = bbp_table( 'devices' );
	$table   = bbp_table( 'notifications' );

	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO $devices (device_id, user_id, app_version, last_seen, created_at) VALUES (%s, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), app_version = VALUES(app_version), last_seen = VALUES(last_seen)",
			$device,
			$user_id,
			$version,
			$now,
			$now
		)
	);

	$max_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $table" );

	// A new install starts from "now" instead of receiving old notifications.
	if ( 0 === $since ) {
		wp_send_json( array( 'cursor' => $max_id, 'notifications' => array() ) );
	}

	$where  = "id > %d AND (expires_at IS NULL OR expires_at > %s) AND (audience = 'all'";
	$params = array( $since, $now );
	if ( $user_id ) {
		$where   .= " OR audience = 'members' OR (audience = 'user' AND user_id = %d)";
		$params[] = $user_id;
	}
	$where .= ')';

	$limit = 20;
	$rows  = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, title, body, url, image FROM $table WHERE $where ORDER BY id ASC LIMIT $limit", $params ),
		ARRAY_A
	);

	$cursor = max( $since, $max_id );
	if ( count( $rows ) === $limit ) {
		$cursor = (int) end( $rows )['id']; // more to fetch next time
	}

	if ( $rows ) {
		$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$wpdb->query( "UPDATE $table SET delivered = delivered + 1 WHERE id IN ($ids)" );
	}

	wp_send_json(
		array(
			'cursor'        => $cursor,
			'notifications' => array_map(
				function ( $r ) {
					return array(
						'id'    => (int) $r['id'],
						'title' => $r['title'],
						'body'  => $r['body'],
						'url'   => $r['url'],
						'image' => $r['image'],
					);
				},
				$rows
			),
		)
	);
}
