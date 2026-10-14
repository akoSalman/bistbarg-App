<?php
/**
 * Plugin Name:       Bistbarg App Notifications
 * Description:       ارسال اعلان به کاربران اپلیکیشن اندروید بیست‌برگ، به‌همراه اعلان خودکار تغییر وضعیت سفارش‌ها.
 * Version:           1.1.0
 * Author:            Bistbarg
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       bistbarg-push
 */

defined( 'ABSPATH' ) || exit;

define( 'BBP_VERSION', '1.1.0' );
define( 'BBP_FILE', __FILE__ );
define( 'BBP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BBP_URL', plugin_dir_url( __FILE__ ) );

require_once BBP_DIR . 'includes/db.php';
require_once BBP_DIR . 'includes/api.php';
require_once BBP_DIR . 'includes/orders.php';
require_once BBP_DIR . 'includes/socket.php';

if ( is_admin() ) {
	require_once BBP_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'bbp_install' );

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'bbp_db_version' ) !== BBP_VERSION ) {
			bbp_install();
		}
	}
);
