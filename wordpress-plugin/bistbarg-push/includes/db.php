<?php
/**
 * Storage: notifications and the app installs (devices) that fetch them.
 */

defined( 'ABSPATH' ) || exit;

function bbp_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'bistbarg_' . $name;
}

function bbp_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . bbp_table( 'notifications' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		title varchar(190) NOT NULL,
		body text NOT NULL,
		url varchar(500) NOT NULL DEFAULT '',
		image varchar(500) NOT NULL DEFAULT '',
		audience varchar(20) NOT NULL DEFAULT 'all',
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		source varchar(20) NOT NULL DEFAULT 'manual',
		delivered int(10) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		expires_at datetime NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . bbp_table( 'devices' ) . " (
		device_id varchar(64) NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		app_version varchar(20) NOT NULL DEFAULT '',
		last_seen datetime NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (device_id),
		KEY user_id (user_id),
		KEY last_seen (last_seen)
		) $charset;"
	);

	update_option( 'bbp_db_version', BBP_VERSION );
	add_option( 'bbp_settings', bbp_default_settings() );
}

/** Default texts for automatic order notifications. Placeholders: {name} {order} {status} */
function bbp_default_order_templates() {
	return array(
		'processing' => array(
			'on'    => 1,
			'title' => 'سفارش شما ثبت شد ✅',
			'body'  => '{name} عزیز، سفارش #{order} دریافت شد و در حال آماده‌سازی است.',
		),
		'on-hold'    => array(
			'on'    => 1,
			'title' => 'سفارش شما در انتظار بررسی است',
			'body'  => '{name} عزیز، سفارش #{order} ثبت شد و به‌زودی بررسی می‌شود.',
		),
		'completed'  => array(
			'on'    => 1,
			'title' => 'سفارش شما تکمیل شد 🎉',
			'body'  => '{name} عزیز، سفارش #{order} تکمیل شد. از خرید شما سپاسگزاریم.',
		),
		'cancelled'  => array(
			'on'    => 1,
			'title' => 'سفارش شما لغو شد',
			'body'  => '{name} عزیز، سفارش #{order} لغو شد. برای اطلاعات بیشتر با ما در تماس باشید.',
		),
		'refunded'   => array(
			'on'    => 1,
			'title' => 'مبلغ سفارش بازگردانده شد',
			'body'  => '{name} عزیز، مبلغ سفارش #{order} به شما بازگردانده شد.',
		),
	);
}

function bbp_default_settings() {
	return array(
		'order_enabled'   => 1,
		'orders'          => bbp_default_order_templates(),
		'socket_enabled'  => 0,
		'socket_url'      => bbp_socket_default_url(),
		'socket_internal' => 'http://127.0.0.1:8090',
		'socket_secret'   => '',
	);
}

function bbp_settings() {
	$saved    = get_option( 'bbp_settings', array() );
	$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), bbp_default_settings() );
	if ( strlen( $settings['socket_secret'] ) < 32 ) {
		// Shared with the socket server; generated once.
		$settings['socket_secret'] = wp_generate_password( 48, false, false );
		update_option( 'bbp_settings', $settings );
	}
	return $settings;
}

/**
 * Queues a notification for the app.
 *
 * @param array $args title, body, url, image, audience (all|members|user), user_id, source, expires_days.
 * @return int Notification id (0 on failure).
 */
function bbp_add_notification( $args ) {
	global $wpdb;
	$d = wp_parse_args(
		$args,
		array(
			'title'        => '',
			'body'         => '',
			'url'          => '',
			'image'        => '',
			'audience'     => 'all',
			'user_id'      => 0,
			'source'       => 'manual',
			'expires_days' => 7,
		)
	);

	$expires = (int) $d['expires_days'] > 0
		? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * (int) $d['expires_days'] )
		: null;

	$ok = $wpdb->insert(
		bbp_table( 'notifications' ),
		array(
			'title'      => mb_substr( (string) $d['title'], 0, 190 ),
			'body'       => (string) $d['body'],
			'url'        => mb_substr( (string) $d['url'], 0, 500 ),
			'image'      => mb_substr( (string) $d['image'], 0, 500 ),
			'audience'   => in_array( $d['audience'], array( 'all', 'members', 'user' ), true ) ? $d['audience'] : 'all',
			'user_id'    => (int) $d['user_id'],
			'source'     => sanitize_key( $d['source'] ),
			'created_at' => current_time( 'mysql', true ),
			'expires_at' => $expires,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
	);

	if ( ! $ok ) {
		return 0;
	}
	$id = (int) $wpdb->insert_id;

	/**
	 * Fires after a notification is queued (used for real-time socket delivery).
	 *
	 * @param int    $id       Notification id.
	 * @param string $audience all|members|user.
	 * @param int    $user_id  Target user for the "user" audience.
	 */
	do_action( 'bbp_notification_added', $id, $d['audience'], (int) $d['user_id'] );
	return $id;
}
