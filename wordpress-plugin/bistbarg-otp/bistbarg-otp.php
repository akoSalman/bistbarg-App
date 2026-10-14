<?php
/**
 * Plugin Name:       ورود با موبایل بیست‌برگ (کاوه‌نگار)
 * Description:       ثبت‌نام و ورود مشتریان با شمارهٔ موبایل و کد پیامکی (OTP) از طریق کاوه‌نگار. شامل فرم آماده و API برای اپلیکیشن.
 * Version:           1.0.0
 * Author:            Bistbarg
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       bistbarg-otp
 */

defined( 'ABSPATH' ) || exit;

define( 'BBOTP_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 *  تنظیمات
 * ---------------------------------------------------------------------- */

function bbotp_defaults() {
	return array(
		'api_key'        => '',
		'template'       => 'verify',
		'code_length'    => 5,
		'code_ttl'       => 120, // ثانیه؛ مدت اعتبار کد
		'resend_cooldown'=> 60,  // ثانیه؛ فاصلهٔ ارسال دوباره
		'max_verify'     => 5,   // حداکثر تلاش برای وارد کردن کد
		'ip_hourly_limit'=> 15,  // حداکثر درخواست کد از یک IP در ساعت
		'default_role'   => 'customer',
	);
}

function bbotp_settings() {
	$saved = get_option( 'bbotp_settings', array() );
	$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), bbotp_defaults() );
	// اگر کلید اینجا تنظیم نشده، از افزونهٔ پیامک فعلی استفاده کن.
	if ( '' === $s['api_key'] ) {
		$s['api_key'] = (string) get_option( 'kavenegar_api_key_custom', '' );
	}
	return $s;
}

/* -------------------------------------------------------------------------
 *  ابزارهای کمکی
 * ---------------------------------------------------------------------- */

/** تبدیل ارقام فارسی/عربی به انگلیسی و نرمال‌سازی شماره به شکل 09xxxxxxxxx */
function bbotp_normalize_phone( $raw ) {
	$fa = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
	$ar = array( '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
	$en = array( '0','1','2','3','4','5','6','7','8','9' );
	$p  = str_replace( $fa, $en, (string) $raw );
	$p  = str_replace( $ar, $en, $p );
	$p  = preg_replace( '/[^0-9+]/', '', $p );

	if ( 0 === strpos( $p, '+98' ) ) {
		$p = '0' . substr( $p, 3 );
	} elseif ( 0 === strpos( $p, '0098' ) ) {
		$p = '0' . substr( $p, 4 );
	} elseif ( 0 === strpos( $p, '98' ) && strlen( $p ) === 12 ) {
		$p = '0' . substr( $p, 2 );
	} elseif ( 0 === strpos( $p, '9' ) && strlen( $p ) === 10 ) {
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
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
	return $ip;
}

/* -------------------------------------------------------------------------
 *  ارسال کد از طریق کاوه‌نگار (VerifyLookup)
 * ---------------------------------------------------------------------- */

/** @return true|WP_Error */
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
	$code_http = wp_remote_retrieve_response_code( $res );
	$body      = json_decode( wp_remote_retrieve_body( $res ), true );
	$status    = isset( $body['return']['status'] ) ? (int) $body['return']['status'] : 0;
	if ( 200 === $code_http && 200 === $status ) {
		return true;
	}
	$msg = isset( $body['return']['message'] ) ? $body['return']['message'] : 'کد خطا ' . $code_http;
	return new WP_Error( 'sms', 'ارسال پیامک ناموفق بود: ' . $msg );
}

/* -------------------------------------------------------------------------
 *  مرحلهٔ ۱: درخواست کد
 * ---------------------------------------------------------------------- */

/** @return array|WP_Error  آرایهٔ {resend_in} یا خطا */
function bbotp_request_code( $raw_phone ) {
	$s     = bbotp_settings();
	$phone = bbotp_normalize_phone( $raw_phone );
	if ( '' === $phone ) {
		return new WP_Error( 'phone', 'شمارهٔ موبایل معتبر نیست.' );
	}

	// محدودیت بر اساس IP (جلوگیری از سوءاستفاده و خالی شدن اعتبار پیامک)
	$ip_key = bbotp_key( 'ip', bbotp_client_ip() );
	$ip_cnt = (int) get_transient( $ip_key );
	if ( $ip_cnt >= (int) $s['ip_hourly_limit'] ) {
		return new WP_Error( 'rate', 'تعداد درخواست‌ها زیاد است. لطفاً بعداً دوباره تلاش کنید.' );
	}

	// فاصلهٔ ارسال دوباره برای همین شماره
	$cd_key = bbotp_key( 'cd', $phone );
	if ( get_transient( $cd_key ) ) {
		return new WP_Error( 'cooldown', 'کد قبلی هنوز معتبر است. کمی صبر کنید.' );
	}

	$len  = max( 4, min( 8, (int) $s['code_length'] ) );
	$min  = (int) str_pad( '1', $len, '0' );          // 10000
	$max  = (int) str_pad( '', $len, '9' );           // 99999
	$code = (string) random_int( $min, $max );

	$sent = bbotp_send_code( $phone, $code );
	if ( is_wp_error( $sent ) ) {
		return $sent;
	}

	set_transient(
		bbotp_key( 'otp', $phone ),
		array( 'hash' => bbotp_hash_code( $phone, $code ), 'tries' => 0 ),
		(int) $s['code_ttl']
	);
	set_transient( $cd_key, 1, (int) $s['resend_cooldown'] );
	set_transient( $ip_key, $ip_cnt + 1, HOUR_IN_SECONDS );

	return array( 'resend_in' => (int) $s['resend_cooldown'], 'code_ttl' => (int) $s['code_ttl'] );
}

/* -------------------------------------------------------------------------
 *  مرحلهٔ ۲: بررسی کد و ورود/ثبت‌نام
 * ---------------------------------------------------------------------- */

/** @return array|WP_Error  آرایهٔ {user_id, is_new} یا خطا */
function bbotp_verify_code( $raw_phone, $raw_code ) {
	$s     = bbotp_settings();
	$phone = bbotp_normalize_phone( $raw_phone );
	$code  = preg_replace( '/[^0-9]/', '', str_replace(
		array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ),
		array( '0','1','2','3','4','5','6','7','8','9' ),
		(string) $raw_code
	) );
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
		return new WP_Error( 'locked', 'تعداد تلاش بیش از حد مجاز. دوباره درخواست کد دهید.' );
	}
	if ( ! hash_equals( $stored['hash'], bbotp_hash_code( $phone, $code ) ) ) {
		$stored['tries']++;
		set_transient( $otp_key, $stored, (int) $s['code_ttl'] );
		return new WP_Error( 'wrong', 'کد وارد شده درست نیست.' );
	}

	// کد درست است؛ دیگر لازم نیست.
	delete_transient( $otp_key );
	delete_transient( bbotp_key( 'cd', $phone ) );

	$user = bbotp_get_or_create_user( $phone, $is_new );
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	bbotp_login_user( $user->ID );
	return array( 'user_id' => $user->ID, 'is_new' => $is_new );
}

/** کاربر را با شماره پیدا می‌کند یا می‌سازد. */
function bbotp_get_or_create_user( $phone, &$is_new ) {
	$is_new = false;

	// ۱) بر اساس متای شماره
	$found = get_users( array(
		'meta_key'   => 'bbotp_phone', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value' => $phone,        // phpcs:ignore WordPress.DB.SlowDBQuery
		'number'     => 1,
		'fields'     => 'all',
	) );
	if ( $found ) {
		return $found[0];
	}
	// ۲) بر اساس شمارهٔ صورتحساب ووکامرس (مشتریان قدیمی)
	$found = get_users( array(
		'meta_key'   => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value' => $phone,          // phpcs:ignore WordPress.DB.SlowDBQuery
		'number'     => 1,
		'fields'     => 'all',
	) );
	if ( $found ) {
		update_user_meta( $found[0]->ID, 'bbotp_phone', $phone );
		return $found[0];
	}
	// ۳) بر اساس نام کاربری برابر شماره
	if ( $existing = get_user_by( 'login', $phone ) ) {
		update_user_meta( $existing->ID, 'bbotp_phone', $phone );
		return $existing;
	}

	// ۴) ساخت کاربر جدید
	$s   = bbotp_settings();
	$uid = wp_insert_user( array(
		'user_login' => $phone,
		'user_pass'  => wp_generate_password( 24, true ),
		'role'       => $s['default_role'],
		'nickname'   => $phone,
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
 *  REST API (برای اپلیکیشن و فرم)
 *    POST /wp-json/bistbarg/v1/otp/request  { phone }
 *    POST /wp-json/bistbarg/v1/otp/verify   { phone, code }
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'bistbarg/v1', '/otp/request', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$out = bbotp_request_code( $req->get_param( 'phone' ) );
			if ( is_wp_error( $out ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => $out->get_error_message() ), 400 );
			}
			return new WP_REST_Response( array_merge( array( 'ok' => true ), $out ), 200 );
		},
	) );
	register_rest_route( 'bistbarg/v1', '/otp/verify', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$out = bbotp_verify_code( $req->get_param( 'phone' ), $req->get_param( 'code' ) );
			if ( is_wp_error( $out ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => $out->get_error_message() ), 400 );
			}
			return new WP_REST_Response( array( 'ok' => true, 'is_new' => $out['is_new'] ), 200 );
		},
	) );
} );

/* -------------------------------------------------------------------------
 *  فرم آماده:  [bistbarg_otp_login]
 * ---------------------------------------------------------------------- */

add_shortcode( 'bistbarg_otp_login', 'bbotp_shortcode' );

function bbotp_shortcode( $atts ) {
	if ( is_user_logged_in() ) {
		$acc = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		return '<div class="bbotp-box"><p>شما وارد حساب خود شده‌اید.</p><a class="bbotp-btn" href="' . esc_url( $acc ) . '">حساب کاربری من</a></div>';
	}
	$redirect = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	$rest     = esc_url_raw( rest_url( 'bistbarg/v1/otp' ) );
	ob_start();
	?>
	<div class="bbotp-box" dir="rtl" data-rest="<?php echo esc_attr( $rest ); ?>" data-redirect="<?php echo esc_url( $redirect ); ?>">
		<div class="bbotp-step bbotp-step-phone">
			<label class="bbotp-label" for="bbotp-phone">شمارهٔ موبایل</label>
			<input id="bbotp-phone" class="bbotp-input" type="tel" inputmode="numeric" dir="ltr" placeholder="09123456789" autocomplete="tel">
			<button type="button" class="bbotp-btn bbotp-send">دریافت کد</button>
		</div>
		<div class="bbotp-step bbotp-step-code" style="display:none">
			<p class="bbotp-sent">کد به شمارهٔ <span class="bbotp-shown"></span> پیامک شد. <a href="#" class="bbotp-edit">ویرایش</a></p>
			<label class="bbotp-label" for="bbotp-code">کد تأیید</label>
			<input id="bbotp-code" class="bbotp-input bbotp-code" type="tel" inputmode="numeric" dir="ltr" maxlength="8" placeholder="- - - - -" autocomplete="one-time-code">
			<button type="button" class="bbotp-btn bbotp-verify">تأیید و ورود</button>
			<button type="button" class="bbotp-resend" disabled>ارسال دوباره (<span class="bbotp-timer">۶۰</span>)</button>
		</div>
		<p class="bbotp-msg" role="alert"></p>
	</div>
	<style>
		.bbotp-box{max-width:360px;margin:0 auto;font-family:inherit}
		.bbotp-label{display:block;margin:14px 0 6px;font-weight:600;color:#1f1a10}
		.bbotp-input{width:100%;box-sizing:border-box;padding:12px 14px;border:1px solid #d8d2bf;border-radius:12px;font-size:16px}
		.bbotp-code{letter-spacing:.4em;text-align:center;font-size:20px}
		.bbotp-btn{width:100%;margin-top:16px;padding:13px;border:0;border-radius:12px;background:#a9831c;color:#fff;font-size:16px;font-weight:700;cursor:pointer}
		.bbotp-btn:disabled{opacity:.6;cursor:default}
		.bbotp-resend{width:100%;margin-top:8px;background:none;border:0;color:#a9831c;cursor:pointer;font-size:14px}
		.bbotp-resend:disabled{color:#9a9384;cursor:default}
		.bbotp-sent{font-size:13px;color:#6b6352}
		.bbotp-edit{color:#a9831c}
		.bbotp-msg{margin-top:12px;color:#b3261e;font-size:14px;min-height:20px;text-align:center}
		.bbotp-msg.ok{color:#1e7e34}
	</style>
	<script>
	document.querySelectorAll('.bbotp-box[data-rest]').forEach(function(box){
		if(box.dataset.bbotpInit)return;box.dataset.bbotpInit='1';
		var rest=box.dataset.rest, redirect=box.dataset.redirect;
		var stepPhone=box.querySelector('.bbotp-step-phone'), stepCode=box.querySelector('.bbotp-step-code');
		var phone=box.querySelector('#bbotp-phone'), code=box.querySelector('#bbotp-code');
		var msg=box.querySelector('.bbotp-msg'), timerEl=box.querySelector('.bbotp-timer'), resend=box.querySelector('.bbotp-resend');
		var fa=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		function toFa(n){return String(n).replace(/[0-9]/g,function(d){return fa[+d];});}
		function say(t,ok){msg.textContent=t||'';msg.className='bbotp-msg'+(ok?' ok':'');}
		function busy(btn,on,label){btn.disabled=on;if(on){btn.dataset.t=btn.textContent;btn.textContent='لطفاً صبر کنید…';}else{btn.textContent=label||btn.dataset.t;}}
		var timer=null;
		function startTimer(sec){
			clearInterval(timer);resend.disabled=true;
			var left=sec;timerEl.textContent=toFa(left);
			timer=setInterval(function(){left--;if(left<=0){clearInterval(timer);resend.disabled=false;resend.innerHTML='ارسال دوباره';}else{timerEl.textContent=toFa(left);}},1000);
		}
		function post(path,data){
			return fetch(rest+path,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify(data)}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});});
		}
		function sendCode(btn){
			var p=(phone.value||'').trim();
			if(p.length<10){say('شمارهٔ موبایل را درست وارد کنید');return;}
			say('');busy(btn,true);
			post('/request',{phone:p}).then(function(res){
				busy(btn,false);
				if(res.ok&&res.j.ok){
					box.querySelector('.bbotp-shown').textContent=p;
					stepPhone.style.display='none';stepCode.style.display='';
					code.focus();startTimer(res.j.resend_in||60);say('');
				}else{say(res.j.message||'خطا در ارسال کد');}
			}).catch(function(){busy(btn,false);say('خطا در ارتباط با سرور');});
		}
		box.querySelector('.bbotp-send').addEventListener('click',function(){sendCode(this);});
		resend.addEventListener('click',function(){if(!resend.disabled)sendCode(box.querySelector('.bbotp-send'));});
		box.querySelector('.bbotp-edit').addEventListener('click',function(e){e.preventDefault();stepCode.style.display='none';stepPhone.style.display='';say('');});
		box.querySelector('.bbotp-verify').addEventListener('click',function(){
			var c=(code.value||'').trim();if(c.length<4){say('کد را کامل وارد کنید');return;}
			say('');busy(this,true);var self=this;
			post('/verify',{phone:phone.value.trim(),code:c}).then(function(res){
				if(res.ok&&res.j.ok){say(res.j.is_new?'ثبت‌نام انجام شد. در حال ورود…':'ورود موفق. در حال انتقال…',true);setTimeout(function(){window.location.href=redirect;},800);}
				else{busy(self,false);say(res.j.message||'کد نادرست است');}
			}).catch(function(){busy(self,false);say('خطا در ارتباط با سرور');});
		});
	});
	</script>
	<?php
	return ob_get_clean();
}

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
		$new = array(
			'api_key'         => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
			'template'        => sanitize_text_field( wp_unslash( $_POST['template'] ?? 'verify' ) ),
			'code_length'     => max( 4, min( 8, (int) ( $_POST['code_length'] ?? 5 ) ) ),
			'code_ttl'        => max( 30, (int) ( $_POST['code_ttl'] ?? 120 ) ),
			'resend_cooldown' => max( 30, (int) ( $_POST['resend_cooldown'] ?? 60 ) ),
			'max_verify'      => max( 3, (int) ( $_POST['max_verify'] ?? 5 ) ),
			'ip_hourly_limit' => max( 3, (int) ( $_POST['ip_hourly_limit'] ?? 15 ) ),
			'default_role'    => sanitize_key( $_POST['default_role'] ?? 'customer' ),
		);
		update_option( 'bbotp_settings', $new );
		echo '<div class="notice notice-success"><p>ذخیره شد.</p></div>';
	}
	$s = bbotp_settings();
	?>
	<div class="wrap" style="direction:rtl;text-align:right">
		<h1>ورود با موبایل بیست‌برگ</h1>
		<p>فرم ورود/ثبت‌نام را با این کد کوتاه در هر صفحه‌ای بگذارید: <code>[bistbarg_otp_login]</code></p>
		<form method="post">
			<?php wp_nonce_field( 'bbotp_settings' ); ?>
			<table class="form-table">
				<tr><th>کلید API کاوه‌نگار</th><td>
					<input type="text" name="api_key" value="<?php echo esc_attr( get_option( 'bbotp_settings' )['api_key'] ?? '' ); ?>" class="regular-text" dir="ltr" placeholder="اگر خالی بماند از افزونهٔ پیامک فعلی خوانده می‌شود">
				</td></tr>
				<tr><th>نام الگوی OTP</th><td>
					<input type="text" name="template" value="<?php echo esc_attr( $s['template'] ); ?>" class="regular-text" dir="ltr">
					<p class="description">در پنل کاوه‌نگار یک الگوی تأیید بسازید که متنش شامل <code>%token%</code> باشد، مثلاً: «کد ورود شما: %token%». نام همان الگو را اینجا بگذارید.</p>
				</td></tr>
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
