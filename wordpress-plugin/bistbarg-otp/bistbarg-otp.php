<?php
/**
 * Plugin Name:       ورود و ثبت‌نام با موبایل بیست‌برگ (کاوه‌نگار)
 * Description:       ثبت‌نام و ورود مشتریان با شمارهٔ موبایل و کد پیامکی، سپس تکمیل نام و استان و شهر. فرم پیش‌فرض ووکامرس غیرفعال می‌شود. دارای API برای اپلیکیشن.
 * Version:           2.2.0
 * Author:            Bistbarg
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       bistbarg-otp
 */

defined( 'ABSPATH' ) || exit;

define( 'BBOTP_VERSION', '2.2.0' );
define( 'BBOTP_DIR', plugin_dir_path( __FILE__ ) );

require_once BBOTP_DIR . 'iran-locations.php';

/* -------------------------------------------------------------------------
 *  صفحهٔ ورود اختصاصی و هدایت به آن
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'bbotp_ensure_login_page' );
add_action( 'admin_init', 'bbotp_ensure_login_page' );

/** یک صفحه با کد کوتاه فرم می‌سازد (یک بار) و شناسه‌اش را ذخیره می‌کند. */
function bbotp_ensure_login_page() {
	$pid = (int) get_option( 'bbotp_login_page_id' );
	if ( $pid && get_post( $pid ) && 'trash' !== get_post_status( $pid ) ) {
		return;
	}
	$existing = get_page_by_path( 'bistbarg-login' );
	if ( $existing ) {
		update_option( 'bbotp_login_page_id', $existing->ID );
		return;
	}
	$pid = wp_insert_post( array(
		'post_title'   => 'ورود | ثبت‌نام',
		'post_name'    => 'bistbarg-login',
		'post_content' => '[bistbarg_otp_login]',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	) );
	if ( $pid && ! is_wp_error( $pid ) ) {
		update_option( 'bbotp_login_page_id', (int) $pid );
	}
}

// در صفحهٔ ورود، اجزای قالب (سربرگ، فوتر، مسیر راهنما، نوار کناری) پنهان می‌شوند تا فقط فرم دیده شود.
add_filter( 'body_class', function ( $classes ) {
	$pid = (int) get_option( 'bbotp_login_page_id' );
	if ( $pid && is_page( $pid ) ) {
		$classes[] = 'bbotp-login-page';
	}
	return $classes;
} );

add_action( 'wp_head', function () {
	$pid = (int) get_option( 'bbotp_login_page_id' );
	if ( ! $pid || ! is_page( $pid ) ) {
		return;
	}
	echo '<style>
	body.bbotp-login-page{background:#f4efe3 !important}
	body.bbotp-login-page .whb-header, body.bbotp-login-page header.site-header, body.bbotp-login-page .site-header,
	body.bbotp-login-page .wd-header-banner, body.bbotp-login-page .main-page-title-wrapper, body.bbotp-login-page .page-title,
	body.bbotp-login-page .wd-page-title, body.bbotp-login-page .woocommerce-breadcrumb, body.bbotp-login-page .wd-breadcrumbs,
	body.bbotp-login-page .breadcrumbs, body.bbotp-login-page #main-page-title, body.bbotp-login-page .wd-footer-wrapper,
	body.bbotp-login-page footer, body.bbotp-login-page .site-footer, body.bbotp-login-page .footer-container,
	body.bbotp-login-page .widget-area, body.bbotp-login-page .sidebar, body.bbotp-login-page aside,
	body.bbotp-login-page .wd-tools-cart, body.bbotp-login-page .mobile-header, body.bbotp-login-page .wd-prefooter,
	body.bbotp-login-page .scrollToTop, body.bbotp-login-page .wd-sticky-nav, body.bbotp-login-page .whb-general-header{display:none !important}
	body.bbotp-login-page .content-area, body.bbotp-login-page #main, body.bbotp-login-page .site-content,
	body.bbotp-login-page .container, body.bbotp-login-page .row{width:100% !important;max-width:100% !important;margin:0 !important;float:none !important;padding-top:0 !important}
	body.bbotp-login-page .site-content, body.bbotp-login-page #main{display:flex;align-items:flex-start;justify-content:center;min-height:100vh;padding:28px 16px !important;box-sizing:border-box}
	body.bbotp-login-page .bbotp-box{margin-top:4vh}
	</style>';
}, 99 );

function bbotp_login_page_url() {
	$pid = (int) get_option( 'bbotp_login_page_id' );
	return $pid ? get_permalink( $pid ) : home_url( '/bistbarg-login/' );
}

// کاربر خارج‌شده که به صفحهٔ حساب می‌رود، به صفحهٔ ورود ما هدایت می‌شود.
add_action( 'template_redirect', function () {
	$s = bbotp_settings();
	if ( empty( $s['replace_wc'] ) || is_user_logged_in() ) {
		return;
	}
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	$pid = (int) get_option( 'bbotp_login_page_id' );
	if ( $pid && ! is_page( $pid ) ) {
		wp_safe_redirect( get_permalink( $pid ) );
		exit;
	}
} );

// فرم ثبت‌نام پیش‌فرض ووکامرس (ایمیل) را از همه‌جا بردار تا فقط فرم موبایلی بماند.
add_filter( 'pre_option_woocommerce_enable_myaccount_registration', function ( $v ) {
	$s = bbotp_settings();
	return empty( $s['replace_wc'] ) ? $v : 'no';
} );


/* -------------------------------------------------------------------------
 *  تنظیمات
 * ---------------------------------------------------------------------- */

function bbotp_defaults() {
	return array(
		'api_key'         => '',
		'template'        => 'verify',
		'code_length'     => 5,
		'code_ttl'        => 120,
		'resend_cooldown' => 60,
		'max_verify'      => 5,
		'ip_hourly_limit' => 15,
		'default_role'    => 'customer',
		'replace_wc'      => 1, // غیرفعال کردن فرم پیش‌فرض ووکامرس
	);
}

function bbotp_settings() {
	$saved = get_option( 'bbotp_settings', array() );
	$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), bbotp_defaults() );
	if ( '' === $s['api_key'] ) {
		$s['api_key'] = (string) get_option( 'kavenegar_api_key_custom', '' );
	}
	return $s;
}

/* -------------------------------------------------------------------------
 *  ابزارها
 * ---------------------------------------------------------------------- */

function bbotp_fa_to_en( $str ) {
	$fa = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
	$ar = array( '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
	$en = array( '0','1','2','3','4','5','6','7','8','9' );
	return str_replace( $ar, $en, str_replace( $fa, $en, (string) $str ) );
}

function bbotp_normalize_phone( $raw ) {
	$p = preg_replace( '/[^0-9+]/', '', bbotp_fa_to_en( $raw ) );
	if ( 0 === strpos( $p, '+98' ) ) {
		$p = '0' . substr( $p, 3 );
	} elseif ( 0 === strpos( $p, '0098' ) ) {
		$p = '0' . substr( $p, 4 );
	} elseif ( 0 === strpos( $p, '98' ) && 12 === strlen( $p ) ) {
		$p = '0' . substr( $p, 2 );
	} elseif ( 0 === strpos( $p, '9' ) && 10 === strlen( $p ) ) {
		$p = '0' . $p;
	}
	return preg_match( '/^09[0-9]{9}$/', $p ) ? $p : '';
}

function bbotp_key( $prefix, $value ) {
	return 'bbotp_' . $prefix . '_' . md5( $value );
}

function bbotp_hash_code( $phone, $code ) {
	return hash_hmac( 'sha256', $phone . '|' . $code, wp_salt( 'auth' ) );
}

function bbotp_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
}

/** آیا کاربر هنوز باید نام و استان و شهرش را تکمیل کند؟ */
function bbotp_needs_profile( $user_id ) {
	$first = get_user_meta( $user_id, 'first_name', true );
	$last  = get_user_meta( $user_id, 'last_name', true );
	$city  = get_user_meta( $user_id, 'billing_city', true );
	return ( '' === trim( (string) $first ) || '' === trim( (string) $last ) || '' === trim( (string) $city ) );
}

/* -------------------------------------------------------------------------
 *  کاوه‌نگار
 * ---------------------------------------------------------------------- */

function bbotp_send_code( $phone, $code ) {
	$s = bbotp_settings();
	if ( '' === $s['api_key'] ) {
		return new WP_Error( 'no_api', 'کلید API کاوه‌نگار تنظیم نشده است.' );
	}
	$url = sprintf(
		'https://api.kavenegar.com/v1/%s/verify/lookup.json?receptor=%s&token=%s&template=%s',
		rawurlencode( $s['api_key'] ),
		rawurlencode( $phone ),
		rawurlencode( $code ),
		rawurlencode( $s['template'] )
	);
	$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'net', 'خطا در ارتباط با کاوه‌نگار: ' . $res->get_error_message() );
	}
	$body   = json_decode( wp_remote_retrieve_body( $res ), true );
	$status = isset( $body['return']['status'] ) ? (int) $body['return']['status'] : 0;
	if ( 200 === (int) wp_remote_retrieve_response_code( $res ) && 200 === $status ) {
		return true;
	}
	$msg = isset( $body['return']['message'] ) ? $body['return']['message'] : 'خطای نامشخص';
	return new WP_Error( 'sms', 'ارسال پیامک ناموفق بود: ' . $msg );
}

/* -------------------------------------------------------------------------
 *  مرحلهٔ ۱: درخواست کد
 * ---------------------------------------------------------------------- */

function bbotp_request_code( $raw_phone ) {
	$s     = bbotp_settings();
	$phone = bbotp_normalize_phone( $raw_phone );
	if ( '' === $phone ) {
		return new WP_Error( 'phone', 'شمارهٔ موبایل معتبر نیست.' );
	}
	$ip_key = bbotp_key( 'ip', bbotp_client_ip() );
	$ip_cnt = (int) get_transient( $ip_key );
	if ( $ip_cnt >= (int) $s['ip_hourly_limit'] ) {
		return new WP_Error( 'rate', 'تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.' );
	}
	if ( get_transient( bbotp_key( 'cd', $phone ) ) ) {
		return new WP_Error( 'cooldown', 'کد قبلی هنوز معتبر است. کمی صبر کنید.' );
	}

	$len  = max( 4, min( 8, (int) $s['code_length'] ) );
	$code = (string) random_int( (int) ( '1' . str_repeat( '0', $len - 1 ) ), (int) str_repeat( '9', $len ) );

	$sent = bbotp_send_code( $phone, $code );
	if ( is_wp_error( $sent ) ) {
		return $sent;
	}

	set_transient( bbotp_key( 'otp', $phone ), array( 'hash' => bbotp_hash_code( $phone, $code ), 'tries' => 0 ), (int) $s['code_ttl'] );
	set_transient( bbotp_key( 'cd', $phone ), 1, (int) $s['resend_cooldown'] );
	set_transient( $ip_key, $ip_cnt + 1, HOUR_IN_SECONDS );

	return array( 'resend_in' => (int) $s['resend_cooldown'], 'code_ttl' => (int) $s['code_ttl'] );
}

/* -------------------------------------------------------------------------
 *  مرحلهٔ ۲: بررسی کد و ورود/ثبت‌نام
 * ---------------------------------------------------------------------- */

function bbotp_verify_code( $raw_phone, $raw_code ) {
	$s     = bbotp_settings();
	$phone = bbotp_normalize_phone( $raw_phone );
	$code  = preg_replace( '/[^0-9]/', '', bbotp_fa_to_en( $raw_code ) );
	if ( '' === $phone ) {
		return new WP_Error( 'phone', 'شمارهٔ موبایل معتبر نیست.' );
	}

	$otp_key = bbotp_key( 'otp', $phone );
	$stored  = get_transient( $otp_key );
	if ( ! is_array( $stored ) ) {
		return new WP_Error( 'expired', 'کد منقضی شده است. دوباره درخواست دهید.' );
	}
	if ( (int) $stored['tries'] >= (int) $s['max_verify'] ) {
		delete_transient( $otp_key );
		return new WP_Error( 'locked', 'تعداد تلاش زیاد شد. دوباره درخواست کد دهید.' );
	}
	if ( ! hash_equals( $stored['hash'], bbotp_hash_code( $phone, $code ) ) ) {
		$stored['tries']++;
		set_transient( $otp_key, $stored, (int) $s['code_ttl'] );
		return new WP_Error( 'wrong', 'کد وارد شده درست نیست.' );
	}

	delete_transient( $otp_key );
	delete_transient( bbotp_key( 'cd', $phone ) );

	$user = bbotp_get_or_create_user( $phone, $is_new );
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	bbotp_login_user( $user->ID );

	return array(
		'user_id'       => $user->ID,
		'is_new'        => $is_new,
		'needs_profile' => bbotp_needs_profile( $user->ID ),
	);
}

function bbotp_get_or_create_user( $phone, &$is_new ) {
	$is_new = false;
	foreach ( array( 'bbotp_phone', 'billing_phone' ) as $meta ) {
		$found = get_users( array(
			'meta_key'   => $meta,   // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $phone,  // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => 1,
			'fields'     => 'all',
		) );
		if ( $found ) {
			update_user_meta( $found[0]->ID, 'bbotp_phone', $phone );
			return $found[0];
		}
	}
	if ( $existing = get_user_by( 'login', $phone ) ) {
		update_user_meta( $existing->ID, 'bbotp_phone', $phone );
		return $existing;
	}

	$s   = bbotp_settings();
	$uid = wp_insert_user( array(
		'user_login'   => $phone,
		'user_pass'    => wp_generate_password( 24, true ),
		'role'         => $s['default_role'],
		'nickname'     => $phone,
		'display_name' => $phone,
	) );
	if ( is_wp_error( $uid ) ) {
		return $uid;
	}
	update_user_meta( $uid, 'bbotp_phone', $phone );
	update_user_meta( $uid, 'billing_phone', $phone );
	$is_new = true;
	do_action( 'bbotp_user_registered', $uid, $phone );
	return get_user_by( 'id', $uid );
}

function bbotp_login_user( $user_id ) {
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );
	$user = get_user_by( 'id', $user_id );
	if ( $user ) {
		do_action( 'wp_login', $user->user_login, $user );
	}
}

/* -------------------------------------------------------------------------
 *  مرحلهٔ ۳: تکمیل پروفایل (نام، نام خانوادگی، استان، شهر)
 * ---------------------------------------------------------------------- */

function bbotp_save_profile( $user_id, $data ) {
	if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
		return new WP_Error( 'auth', 'ابتدا باید وارد شوید.' );
	}
	$first    = sanitize_text_field( $data['first_name'] ?? '' );
	$last     = sanitize_text_field( $data['last_name'] ?? '' );
	$province = sanitize_text_field( $data['province'] ?? '' );
	$city     = sanitize_text_field( $data['city'] ?? '' );
	$address  = sanitize_textarea_field( $data['address'] ?? '' );

	if ( '' === $first || '' === $last ) {
		return new WP_Error( 'name', 'نام و نام خانوادگی را وارد کنید.' );
	}
	$locations = bbotp_iran_locations();
	if ( ! isset( $locations[ $province ] ) ) {
		return new WP_Error( 'province', 'استان را از فهرست انتخاب کنید.' );
	}
	if ( ! in_array( $city, $locations[ $province ], true ) ) {
		return new WP_Error( 'city', 'شهر را از فهرست استان انتخاب کنید.' );
	}

	wp_update_user( array(
		'ID'           => $user_id,
		'first_name'   => $first,
		'last_name'    => $last,
		'display_name' => trim( $first . ' ' . $last ),
	) );
	update_user_meta( $user_id, 'billing_first_name', $first );
	update_user_meta( $user_id, 'billing_last_name', $last );
	update_user_meta( $user_id, 'billing_state', $province );
	update_user_meta( $user_id, 'billing_city', $city );
	update_user_meta( $user_id, 'bbotp_province', $province );
	update_user_meta( $user_id, 'billing_country', 'IR' );
	if ( '' !== $address ) {
		update_user_meta( $user_id, 'billing_address_1', $address );
	}
	do_action( 'bbotp_profile_saved', $user_id );
	return array( 'ok' => true );
}

/* -------------------------------------------------------------------------
 *  REST API
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'bistbarg/v1', '/otp/request', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $r ) {
			$out = bbotp_request_code( $r->get_param( 'phone' ) );
			return is_wp_error( $out )
				? new WP_REST_Response( array( 'ok' => false, 'message' => $out->get_error_message() ), 400 )
				: new WP_REST_Response( array_merge( array( 'ok' => true ), $out ), 200 );
		},
	) );
	register_rest_route( 'bistbarg/v1', '/otp/verify', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $r ) {
			$out = bbotp_verify_code( $r->get_param( 'phone' ), $r->get_param( 'code' ) );
			return is_wp_error( $out )
				? new WP_REST_Response( array( 'ok' => false, 'message' => $out->get_error_message() ), 400 )
				: new WP_REST_Response( array( 'ok' => true, 'is_new' => $out['is_new'], 'needs_profile' => $out['needs_profile'] ), 200 );
		},
	) );
	register_rest_route( 'bistbarg/v1', '/otp/profile', array(
		'methods'             => 'POST',
		'permission_callback' => 'is_user_logged_in',
		'callback'            => function ( WP_REST_Request $r ) {
			$out = bbotp_save_profile( get_current_user_id(), $r->get_json_params() ?: $r->get_params() );
			return is_wp_error( $out )
				? new WP_REST_Response( array( 'ok' => false, 'message' => $out->get_error_message() ), 400 )
				: new WP_REST_Response( array( 'ok' => true ), 200 );
		},
	) );
	register_rest_route( 'bistbarg/v1', '/locations', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			return new WP_REST_Response( bbotp_iran_locations(), 200 );
		},
	) );
} );

/* -------------------------------------------------------------------------
 *  فرم آماده:  [bistbarg_otp_login]
 * ---------------------------------------------------------------------- */

add_shortcode( 'bistbarg_otp_login', 'bbotp_shortcode' );

function bbotp_shortcode( $atts = array() ) {
	if ( is_user_logged_in() && ! bbotp_needs_profile( get_current_user_id() ) ) {
		$acc = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		return '<div class="bbotp-box" dir="rtl"><p style="text-align:center">شما وارد حساب خود شده‌اید.</p><a class="bbotp-btn" href="' . esc_url( $acc ) . '">حساب کاربری من</a></div>';
	}
	$redirect     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	$rest         = esc_url_raw( rest_url( 'bistbarg/v1' ) );
	$locations    = wp_json_encode( bbotp_iran_locations(), JSON_UNESCAPED_UNICODE );
	$start_profile = ( is_user_logged_in() && bbotp_needs_profile( get_current_user_id() ) ) ? '1' : '';
	ob_start();
	?>
	<div class="bbotp-box" dir="rtl" data-rest="<?php echo esc_attr( $rest ); ?>" data-redirect="<?php echo esc_url( $redirect ); ?>" data-profile="<?php echo esc_attr( $start_profile ); ?>">
		<?php if ( function_exists( 'get_custom_logo' ) && has_custom_logo() ) : ?>
			<div class="bbotp-logo"><?php echo get_custom_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
		<!-- مرحلهٔ ۱: شماره -->
		<div class="bbotp-step" data-step="phone">
			<h3 class="bbotp-h">ورود | ثبت‌نام</h3>
			<label class="bbotp-label" for="bbotp-phone">شمارهٔ موبایل</label>
			<input id="bbotp-phone" class="bbotp-input" type="tel" inputmode="numeric" dir="ltr" placeholder="09123456789" autocomplete="tel">
			<button type="button" class="bbotp-btn" data-act="send">دریافت کد</button>
		</div>
		<!-- مرحلهٔ ۲: کد -->
		<div class="bbotp-step" data-step="code" style="display:none">
			<h3 class="bbotp-h">کد تأیید</h3>
			<p class="bbotp-sent">کد به <span class="bbotp-shown" dir="ltr"></span> پیامک شد. <a href="#" data-act="edit">ویرایش شماره</a></p>
			<input id="bbotp-code" class="bbotp-input bbotp-code" type="tel" inputmode="numeric" dir="ltr" maxlength="8" placeholder="- - - - -" autocomplete="one-time-code">
			<button type="button" class="bbotp-btn" data-act="verify">تأیید</button>
			<button type="button" class="bbotp-resend" data-act="resend" disabled>ارسال دوباره (<span class="bbotp-timer">۶۰</span>)</button>
		</div>
		<!-- مرحلهٔ ۳: پروفایل -->
		<div class="bbotp-step" data-step="profile" style="display:none">
			<h3 class="bbotp-h">تکمیل اطلاعات</h3>
			<div class="bbotp-row">
				<div><label class="bbotp-label" for="bbotp-first">نام</label><input id="bbotp-first" class="bbotp-input" type="text" autocomplete="given-name"></div>
				<div><label class="bbotp-label" for="bbotp-last">نام خانوادگی</label><input id="bbotp-last" class="bbotp-input" type="text" autocomplete="family-name"></div>
			</div>
			<div class="bbotp-row">
				<div><label class="bbotp-label" for="bbotp-province">استان</label><select id="bbotp-province" class="bbotp-input"></select></div>
				<div><label class="bbotp-label" for="bbotp-city">شهر</label><select id="bbotp-city" class="bbotp-input" disabled></select></div>
			</div>
			<label class="bbotp-label" for="bbotp-address">نشانی (اختیاری)</label>
			<textarea id="bbotp-address" class="bbotp-input" rows="2"></textarea>
			<button type="button" class="bbotp-btn" data-act="save">ثبت و ورود</button>
		</div>
		<p class="bbotp-msg" role="alert"></p>
	</div>
	<style>
		.bbotp-box{max-width:380px;margin:0 auto;font-family:inherit;text-align:right;background:#fff;border-radius:22px;padding:28px 24px 26px;box-shadow:0 18px 50px rgba(31,26,16,.12);border:1px solid #f0ead6}
		.bbotp-logo{text-align:center;margin-bottom:6px}
		.bbotp-logo img{max-height:70px;width:auto;display:inline-block}
		.bbotp-h{margin:4px 0 18px;font-size:21px;font-weight:800;color:#1f1a10;text-align:center}
		.bbotp-label{display:block;margin:12px 0 7px;font-weight:700;color:#3a3326;font-size:14px}
		.bbotp-input{width:100%;box-sizing:border-box;padding:13px 15px;border:1.5px solid #e3dcc6;border-radius:14px;font-size:16px;background:#fcfbf6;transition:border-color .15s,box-shadow .15s;font-family:inherit}
		.bbotp-input:focus{outline:0;border-color:#a9831c;box-shadow:0 0 0 3px rgba(169,131,28,.15);background:#fff}
		select.bbotp-input{height:48px}
		.bbotp-code{letter-spacing:.5em;text-align:center;font-size:22px;font-weight:700}
		.bbotp-row{display:flex;gap:10px}.bbotp-row>div{flex:1}
		.bbotp-btn{display:block;width:100%;margin-top:20px;padding:14px;border:0;border-radius:14px;background:linear-gradient(135deg,#c7a742,#a9831c);color:#fff;font-size:16px;font-weight:800;cursor:pointer;text-align:center;text-decoration:none;box-shadow:0 8px 20px rgba(169,131,28,.28);transition:transform .08s,box-shadow .15s}
		.bbotp-btn:hover{box-shadow:0 10px 26px rgba(169,131,28,.38)}
		.bbotp-btn:active{transform:translateY(1px)}
		.bbotp-btn:disabled{opacity:.6;cursor:default;box-shadow:none}
		.bbotp-resend{width:100%;margin-top:10px;background:none;border:0;color:#a9831c;cursor:pointer;font-size:14px;font-weight:600}
		.bbotp-resend:disabled{color:#b3ad9c;cursor:default}
		.bbotp-sent{font-size:13px;color:#6b6352;text-align:center;line-height:1.9}.bbotp-sent a{color:#a9831c;font-weight:700}
		.bbotp-msg{margin-top:14px;color:#b3261e;font-size:14px;min-height:20px;text-align:center;line-height:1.7}
		.bbotp-msg.ok{color:#1e7e34}
	</style>
	<script>
	(function(){
	var DATA=<?php echo $locations; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON. ?>;
	document.querySelectorAll('.bbotp-box[data-rest]').forEach(function(box){
		if(box.dataset.bbotpInit)return;box.dataset.bbotpInit='1';
		var rest=box.dataset.rest,redirect=box.dataset.redirect;
		var steps={};box.querySelectorAll('.bbotp-step').forEach(function(s){steps[s.dataset.step]=s;});
		var msg=box.querySelector('.bbotp-msg');
		var phone=box.querySelector('#bbotp-phone'),code=box.querySelector('#bbotp-code');
		var prov=box.querySelector('#bbotp-province'),city=box.querySelector('#bbotp-city');
		var resend=box.querySelector('[data-act=resend]'),timerEl=box.querySelector('.bbotp-timer');
		var fa=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		function toFa(n){return String(n).replace(/[0-9]/g,function(d){return fa[+d];});}
		function say(t,ok){msg.textContent=t||'';msg.className='bbotp-msg'+(ok?' ok':'');}
		function show(name){for(var k in steps)steps[k].style.display=(k===name?'':'none');say('');}
		function busy(btn,on){if(on){btn.dataset.t=btn.textContent;btn.disabled=true;btn.textContent='لطفاً صبر کنید…';}else{btn.disabled=false;btn.textContent=btn.dataset.t;}}
		function post(path,data){return fetch(rest+path,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify(data)}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});});}
		var timer=null;
		function startTimer(sec){clearInterval(timer);resend.disabled=true;var l=sec;timerEl.textContent=toFa(l);timer=setInterval(function(){l--;if(l<=0){clearInterval(timer);resend.disabled=false;resend.innerHTML='ارسال دوباره';}else timerEl.textContent=toFa(l);},1000);}

		// پر کردن استان‌ها
		prov.innerHTML='<option value="">انتخاب استان</option>';
		Object.keys(DATA).forEach(function(p){var o=document.createElement('option');o.value=p;o.textContent=p;prov.appendChild(o);});
		prov.addEventListener('change',function(){
			city.innerHTML='';var list=DATA[prov.value]||[];
			if(!list.length){city.disabled=true;city.innerHTML='<option value="">اول استان را انتخاب کنید</option>';return;}
			city.disabled=false;city.innerHTML='<option value="">انتخاب شهر</option>';
			list.forEach(function(c){var o=document.createElement('option');o.value=c;o.textContent=c;city.appendChild(o);});
		});

		function sendCode(btn){var p=(phone.value||'').trim();if(p.replace(/\D/g,'').length<10){say('شمارهٔ موبایل را درست وارد کنید');return;}busy(btn,true);
			post('/otp/request',{phone:p}).then(function(r){busy(btn,false);if(r.ok&&r.j.ok){box.querySelector('.bbotp-shown').textContent=p;show('code');code.focus();startTimer(r.j.resend_in||60);}else say(r.j.message||'خطا در ارسال کد');}).catch(function(){busy(btn,false);say('خطا در ارتباط با سرور');});}

		box.addEventListener('click',function(e){
			var act=e.target.getAttribute&&e.target.getAttribute('data-act');if(!act)return;e.preventDefault();var btn=e.target;
			if(act==='send')sendCode(btn);
			else if(act==='resend'){if(!resend.disabled)sendCode(box.querySelector('[data-act=send]')||btn);}
			else if(act==='edit')show('phone');
			else if(act==='verify'){var c=(code.value||'').trim();if(c.replace(/\D/g,'').length<4){say('کد را کامل وارد کنید');return;}busy(btn,true);
				post('/otp/verify',{phone:phone.value.trim(),code:c}).then(function(r){busy(btn,false);
					if(r.ok&&r.j.ok){if(r.j.needs_profile)show('profile');else{say('ورود موفق. در حال انتقال…',true);setTimeout(function(){location.href=redirect;},700);}}
					else say(r.j.message||'کد نادرست است');}).catch(function(){busy(btn,false);say('خطا در ارتباط با سرور');});}
			else if(act==='save'){
				var payload={first_name:box.querySelector('#bbotp-first').value.trim(),last_name:box.querySelector('#bbotp-last').value.trim(),province:prov.value,city:city.value,address:box.querySelector('#bbotp-address').value.trim()};
				if(!payload.first_name||!payload.last_name){say('نام و نام خانوادگی را وارد کنید');return;}
				if(!payload.province||!payload.city){say('استان و شهر را انتخاب کنید');return;}
				busy(btn,true);
				post('/otp/profile',payload).then(function(r){busy(btn,false);if(r.ok&&r.j.ok){say('ثبت شد. خوش آمدید!',true);setTimeout(function(){location.href=redirect;},700);}else say(r.j.message||'ثبت اطلاعات ناموفق بود');}).catch(function(){busy(btn,false);say('خطا در ارتباط با سرور');});}
		});

		if(box.dataset.profile)show('profile');
	});
	})();
	</script>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 *  غیرفعال کردن فرم پیش‌فرض ووکامرس و نشاندن فرم ما به‌جای آن
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_before_customer_login_form', function () {
	$s = bbotp_settings();
	if ( empty( $s['replace_wc'] ) ) {
		return;
	}
	// فرم‌های پیش‌فرض ورود/ثبت‌نام را پنهان کن و فرم ما را نشان بده.
	echo '<style>#customer_login .u-column1, #customer_login .u-column2, .woocommerce-form-login, .woocommerce-form-register, #customer_login > h2 { display:none !important; } #customer_login{display:block !important}</style>';
	echo do_shortcode( '[bistbarg_otp_login]' );
}, 5 );

// اگر کاربر وارد شده ولی پروفایلش ناقص است، در صفحهٔ حساب، فرم تکمیل را نشان بده.
add_action( 'woocommerce_account_dashboard', function () {
	if ( is_user_logged_in() && bbotp_needs_profile( get_current_user_id() ) ) {
		echo do_shortcode( '[bistbarg_otp_login]' );
	}
}, 1 );

/* -------------------------------------------------------------------------
 *  صفحهٔ تنظیمات
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page( 'ورود با موبایل', 'ورود با موبایل', 'manage_options', 'bistbarg-otp', 'bbotp_settings_page' );
} );

function bbotp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['bbotp_save'] ) && check_admin_referer( 'bbotp_settings' ) ) {
		update_option( 'bbotp_settings', array(
			'api_key'         => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
			'template'        => sanitize_text_field( wp_unslash( $_POST['template'] ?? 'verify' ) ),
			'code_length'     => max( 4, min( 8, (int) ( $_POST['code_length'] ?? 5 ) ) ),
			'code_ttl'        => max( 30, (int) ( $_POST['code_ttl'] ?? 120 ) ),
			'resend_cooldown' => max( 30, (int) ( $_POST['resend_cooldown'] ?? 60 ) ),
			'max_verify'      => max( 3, (int) ( $_POST['max_verify'] ?? 5 ) ),
			'ip_hourly_limit' => max( 3, (int) ( $_POST['ip_hourly_limit'] ?? 15 ) ),
			'default_role'    => sanitize_key( $_POST['default_role'] ?? 'customer' ),
			'replace_wc'      => empty( $_POST['replace_wc'] ) ? 0 : 1,
		) );
		echo '<div class="notice notice-success"><p>ذخیره شد.</p></div>';
	}
	$raw = wp_parse_args( get_option( 'bbotp_settings', array() ), bbotp_defaults() );
	$s   = bbotp_settings();
	?>
	<div class="wrap" style="direction:rtl;text-align:right">
		<h1>ورود و ثبت‌نام با موبایل</h1>
		<p>فرم را با این کد کوتاه در هر صفحه‌ای بگذارید: <code>[bistbarg_otp_login]</code></p>
		<form method="post">
			<?php wp_nonce_field( 'bbotp_settings' ); ?>
			<table class="form-table">
				<tr><th>کلید API کاوه‌نگار</th><td><input type="text" name="api_key" value="<?php echo esc_attr( $raw['api_key'] ); ?>" class="regular-text" dir="ltr" placeholder="خالی = استفاده از افزونهٔ پیامک فعلی"></td></tr>
				<tr><th>نام الگوی OTP</th><td><input type="text" name="template" value="<?php echo esc_attr( $s['template'] ); ?>" class="regular-text" dir="ltr">
					<p class="description">در پنل کاوه‌نگار یک الگوی تأیید بسازید که متنش شامل <code>%token%</code> باشد (مثلاً «کد ورود شما: %token%») و نامش را اینجا بگذارید.</p></td></tr>
				<tr><th>غیرفعال کردن فرم پیش‌فرض ووکامرس</th><td><label><input type="checkbox" name="replace_wc" value="1" <?php checked( $s['replace_wc'], 1 ); ?>> در صفحهٔ «حساب کاربری من» به‌جای فرم ایمیل/رمز، همین فرم موبایلی نشان داده شود.</label></td></tr>
				<tr><th>تعداد ارقام کد</th><td><input type="number" name="code_length" value="<?php echo esc_attr( $s['code_length'] ); ?>" min="4" max="8"></td></tr>
				<tr><th>مدت اعتبار کد (ثانیه)</th><td><input type="number" name="code_ttl" value="<?php echo esc_attr( $s['code_ttl'] ); ?>" min="30"></td></tr>
				<tr><th>فاصلهٔ ارسال دوباره (ثانیه)</th><td><input type="number" name="resend_cooldown" value="<?php echo esc_attr( $s['resend_cooldown'] ); ?>" min="30"></td></tr>
				<tr><th>حداکثر تلاش برای کد</th><td><input type="number" name="max_verify" value="<?php echo esc_attr( $s['max_verify'] ); ?>" min="3"></td></tr>
				<tr><th>حداکثر درخواست از یک IP در ساعت</th><td><input type="number" name="ip_hourly_limit" value="<?php echo esc_attr( $s['ip_hourly_limit'] ); ?>" min="3"></td></tr>
			</table>
			<?php submit_button( 'ذخیره', 'primary', 'bbotp_save' ); ?>
		</form>
	</div>
	<?php
}
