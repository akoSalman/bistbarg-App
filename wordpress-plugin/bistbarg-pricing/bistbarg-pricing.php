<?php
/**
 * Plugin Name:       قیمت دلاری مدیا
 * Description:       قیمت محصولات را به دلار وارد کنید؛ قیمت تومانی خودکار بر اساس نرخ دلار (خودکار از API یا دستی) محاسبه و با تغییر نرخ به‌روز می‌شود. دارای جدول قیمت با تصویر محصول.
 * Version:           1.4.1
 * Author:            Bistbarg
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       bistbarg-pricing
 */

defined( 'ABSPATH' ) || exit;

define( 'BBPRICE_VERSION', '1.4.1' );
define( 'BBPRICE_DEV_PHONE', '09031784732' );
define( 'BBPRICE_CRON', 'bbprice_update_event' );

/* -------------------------------------------------------------------------
 *  تنظیمات
 * ---------------------------------------------------------------------- */

function bbprice_defaults() {
	return array(
		'mode'        => 'manual',   // manual | auto
		'rate'        => 0,          // تومان به ازای هر دلار (قیمتی که محاسبه با آن انجام می‌شود)
		'store_unit'  => 'toman',    // واحد قیمت فروشگاه: toman | rial
		'round_to'    => 1000,       // گِرد کردن به نزدیک‌ترین (تومان)؛ 0 = بدون گِرد
		'margin'      => 0,          // درصد سود اضافه روی قیمت
		'source'      => 'tgju',     // tgju | custom
		'custom_url'  => '',
		'custom_path' => '',         // مسیر مقدار در JSON، مثل: current.price_dollar_rl.p
		'custom_unit' => 'rial',     // واحد مقدار API: rial | toman
		'interval'    => 'hourly',   // hourly | twicedaily | daily
		'updated_at'  => 0,
		'last_error'  => '',
	);
}

function bbprice_settings() {
	return wp_parse_args( get_option( 'bbprice_settings', array() ), bbprice_defaults() );
}

function bbprice_update_settings( $new ) {
	update_option( 'bbprice_settings', wp_parse_args( $new, bbprice_settings() ) );
}

/* -------------------------------------------------------------------------
 *  محاسبهٔ قیمت
 * ---------------------------------------------------------------------- */

/** قیمت تومانی/ریالی فروشگاه را از قیمت دلاری حساب می‌کند. */
function bbprice_convert( $usd ) {
	$s   = bbprice_settings();
	$usd = (float) $usd;
	$rate = (float) $s['rate'];
	if ( $usd <= 0 || $rate <= 0 ) {
		return 0;
	}
	$toman = $usd * $rate * ( 1 + (float) $s['margin'] / 100 );
	$step  = (int) $s['round_to'];
	if ( $step > 0 ) {
		$toman = round( $toman / $step ) * $step;
	} else {
		$toman = round( $toman );
	}
	return 'rial' === $s['store_unit'] ? $toman * 10 : $toman;
}

/** قیمت دلاری یک محصول/متغیر را به قیمت فروشگاه تبدیل و ذخیره می‌کند. */
function bbprice_apply_to_product( $product_id ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}
	$usd = $product->get_meta( '_bbprice_usd' );
	if ( '' === $usd || null === $usd ) {
		return; // این محصول قیمت دلاری ندارد؛ دست نمی‌زنیم.
	}
	$usd_sale = $product->get_meta( '_bbprice_usd_sale' );

	$regular = bbprice_convert( $usd );
	if ( $regular <= 0 ) {
		return;
	}
	$product->set_regular_price( (string) $regular );

	$sale = ( '' !== $usd_sale && null !== $usd_sale ) ? bbprice_convert( $usd_sale ) : 0;
	if ( $sale > 0 && $sale < $regular ) {
		$product->set_sale_price( (string) $sale );
		$product->set_price( (string) $sale );
	} else {
		$product->set_sale_price( '' );
		$product->set_price( (string) $regular );
	}
	$product->save();

	if ( $product->is_type( 'variation' ) && class_exists( 'WC_Product_Variable' ) ) {
		WC_Product_Variable::sync( $product->get_parent_id() );
	}
}

/** همهٔ محصولات و متغیرهای دارای قیمت دلاری را دوباره حساب می‌کند. */
function bbprice_recalculate_all() {
	$count = 0;
	foreach ( array( 'product', 'product_variation' ) as $type ) {
		$ids = get_posts( array(
			'post_type'      => $type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_bbprice_usd', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_compare'   => 'EXISTS',
		) );
		foreach ( $ids as $id ) {
			bbprice_apply_to_product( $id );
			$count++;
		}
	}
	return $count;
}

/* -------------------------------------------------------------------------
 *  گرفتن نرخ دلار از API
 * ---------------------------------------------------------------------- */

/** @return float|WP_Error  نرخ به تومان */
function bbprice_fetch_rate() {
	$s = bbprice_settings();
	if ( 'custom' === $s['source'] ) {
		$url  = $s['custom_url'];
		$path = $s['custom_path'];
		$unit = $s['custom_unit'];
	} else {
		// منبع پیش‌فرض: tgju (قیمت دلار آزاد، به ریال)
		$url  = 'https://call.tgju.org/ajax.json';
		$path = 'current.price_dollar_rl.p';
		$unit = 'rial';
	}
	if ( '' === $url ) {
		return new WP_Error( 'url', 'آدرس API نرخ دلار تنظیم نشده است.' );
	}

	$res = wp_remote_get( $url, array(
		'timeout' => 15,
		'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0 BistbargPricing' ),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'net', 'خطا در ارتباط با سرور نرخ: ' . $res->get_error_message() );
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'json', 'پاسخ API قابل خواندن نیست.' );
	}

	$value = $data;
	foreach ( explode( '.', $path ) as $key ) {
		if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
			$value = $value[ $key ];
		} else {
			return new WP_Error( 'path', 'مقدار نرخ در پاسخ API پیدا نشد (مسیر: ' . esc_html( $path ) . ').' );
		}
	}
	// حذف جداکننده‌ها و تبدیل ارقام فارسی
	$num = (float) preg_replace( '/[^0-9.]/', '', str_replace(
		array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٬',',' ),
		array( '0','1','2','3','4','5','6','7','8','9','','' ),
		(string) $value
	) );
	if ( $num <= 0 ) {
		return new WP_Error( 'value', 'مقدار نرخ نامعتبر است.' );
	}
	$toman = 'rial' === $unit ? $num / 10 : $num; // خروجی همیشه تومان
	// محافظ: نرخ دلار را در بازهٔ منطقی (تومان) نگه می‌دارد تا خطای ۱۰برابری ریال/تومان رخ ندهد.
	$guard = 0;
	while ( $toman > 0 && $toman < 10000 && $guard++ < 4 ) {
		$toman *= 10;
	}
	$guard = 0;
	while ( $toman > 10000000 && $guard++ < 4 ) {
		$toman /= 10;
	}
	return $toman;
}

/** نرخ را می‌گیرد، ذخیره می‌کند و قیمت‌ها را به‌روز می‌کند. @return true|WP_Error */
function bbprice_refresh_rate_and_prices() {
	$rate = bbprice_fetch_rate();
	if ( is_wp_error( $rate ) ) {
		bbprice_update_settings( array( 'last_error' => $rate->get_error_message() ) );
		return $rate;
	}
	bbprice_update_settings( array( 'rate' => $rate, 'updated_at' => time(), 'last_error' => '' ) );
	bbprice_recalculate_all();
	bbprice_clear_caches();
	return true;
}

/** کش صفحات را پاک می‌کند تا قیمت جدید فوری دیده شود. */
function bbprice_clear_caches() {
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain(); // WP Rocket
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all(); // W3 Total Cache
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache(); // WP Super Cache
	}
}

function bbprice_interval_seconds() {
	switch ( bbprice_settings()['interval'] ) {
		case 'daily':      return DAY_IN_SECONDS;
		case 'twicedaily': return 12 * HOUR_IN_SECONDS;
		default:           return HOUR_IN_SECONDS;
	}
}

/**
 * هستهٔ به‌روزرسانی خودکار مستقل از WP-Cron:
 * اگر نرخ کهنه شده باشد، در پس‌زمینه (بدون معطل کردن بازدیدکننده) تازه می‌شود.
 */
function bbprice_maybe_auto_refresh( $blocking = false ) {
	$s = bbprice_settings();
	if ( 'auto' !== $s['mode'] ) {
		return;
	}
	if ( ( time() - (int) $s['updated_at'] ) < bbprice_interval_seconds() ) {
		return; // هنوز تازه است
	}
	if ( get_transient( 'bbprice_lock' ) ) {
		return; // یک درخواست دیگر در حال انجام است
	}
	set_transient( 'bbprice_lock', 1, 180 );

	if ( $blocking ) {
		bbprice_refresh_rate_and_prices();
		delete_transient( 'bbprice_lock' );
		return;
	}
	// اجرای غیرمسدودکننده از طریق لوپ‌بک تا بازدیدکننده منتظر نماند.
	$key = get_option( 'bbprice_cron_key' );
	if ( ! $key ) {
		$key = wp_generate_password( 24, false, false );
		update_option( 'bbprice_cron_key', $key );
	}
	wp_remote_get(
		add_query_arg(
			array( 'action' => 'bbprice_cron_run', 'key' => $key ),
			admin_url( 'admin-ajax.php' )
		),
		array( 'blocking' => false, 'timeout' => 0.01, 'sslverify' => false )
	);
}

// در هر بازدیدی که نرخ کهنه باشد، در پس‌زمینه تازه می‌شود (جایگزین مطمئن WP-Cron).
add_action( 'init', function () {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	bbprice_maybe_auto_refresh( is_admin() );
}, 99 );

// نقطهٔ اجرای پس‌زمینه (لوپ‌بک).
add_action( 'wp_ajax_nopriv_bbprice_cron_run', 'bbprice_cron_run_handler' );
add_action( 'wp_ajax_bbprice_cron_run', 'bbprice_cron_run_handler' );
function bbprice_cron_run_handler() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- محافظت با کلید مخفی.
	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! $key || ! hash_equals( (string) get_option( 'bbprice_cron_key' ), $key ) ) {
		wp_die( '', '', array( 'response' => 403 ) );
	}
	bbprice_refresh_rate_and_prices();
	delete_transient( 'bbprice_lock' );
	wp_die( 'ok', '', array( 'response' => 200 ) );
}

/* -------------------------------------------------------------------------
 *  زمان‌بندی خودکار
 * ---------------------------------------------------------------------- */

add_action( BBPRICE_CRON, function () {
	if ( 'auto' === bbprice_settings()['mode'] ) {
		bbprice_refresh_rate_and_prices();
	}
} );

register_activation_hook( __FILE__, 'bbprice_schedule' );
add_action( 'admin_init', 'bbprice_schedule' );
function bbprice_schedule() {
	$interval = bbprice_settings()['interval'];
	$next     = wp_next_scheduled( BBPRICE_CRON );
	// اگر زمان‌بندی نیست یا بازه عوض شده، دوباره تنظیم کن.
	if ( ! $next ) {
		wp_schedule_event( time() + 60, $interval, BBPRICE_CRON );
	}
}

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( BBPRICE_CRON );
} );

/* -------------------------------------------------------------------------
 *  فیلدهای قیمت دلاری در صفحهٔ محصول
 * ---------------------------------------------------------------------- */

// محصول ساده
add_action( 'woocommerce_product_options_pricing', function () {
	echo '<div class="options_group bbprice-group">';
	woocommerce_wp_text_input( array(
		'id'          => '_bbprice_usd',
		'label'       => 'قیمت دلاری ($)',
		'desc_tip'    => true,
		'description' => 'قیمت تومانی خودکار بر اساس نرخ دلار محاسبه می‌شود.',
		'data_type'   => 'decimal',
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_bbprice_usd_sale',
		'label'       => 'قیمت حراج دلاری ($)',
		'desc_tip'    => true,
		'description' => 'اختیاری. اگر پر شود، قیمت حراج تومانی از آن حساب می‌شود.',
		'data_type'   => 'decimal',
	) );
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
	if ( isset( $_POST['_bbprice_usd'] ) ) {
		$product->update_meta_data( '_bbprice_usd', wc_clean( wp_unslash( $_POST['_bbprice_usd'] ) ) );
	}
	if ( isset( $_POST['_bbprice_usd_sale'] ) ) {
		$product->update_meta_data( '_bbprice_usd_sale', wc_clean( wp_unslash( $_POST['_bbprice_usd_sale'] ) ) );
	}
	// phpcs:enable
	$usd = $product->get_meta( '_bbprice_usd' );
	if ( '' !== $usd && null !== $usd ) {
		$regular = bbprice_convert( $usd );
		if ( $regular > 0 ) {
			$product->set_regular_price( (string) $regular );
			$usd_sale = $product->get_meta( '_bbprice_usd_sale' );
			$sale     = ( '' !== $usd_sale && null !== $usd_sale ) ? bbprice_convert( $usd_sale ) : 0;
			if ( $sale > 0 && $sale < $regular ) {
				$product->set_sale_price( (string) $sale );
				$product->set_price( (string) $sale );
			} else {
				$product->set_sale_price( '' );
				$product->set_price( (string) $regular );
			}
		}
	}
} );

// متغیرها (محصول متغیر)
add_action( 'woocommerce_variation_options_pricing', function ( $loop, $variation_data, $variation ) {
	woocommerce_wp_text_input( array(
		'id'            => '_bbprice_usd_' . $loop,
		'name'          => '_bbprice_usd_' . $loop,
		'value'         => get_post_meta( $variation->ID, '_bbprice_usd', true ),
		'label'         => 'قیمت دلاری ($)',
		'wrapper_class' => 'form-row form-row-first',
		'data_type'     => 'decimal',
	) );
	woocommerce_wp_text_input( array(
		'id'            => '_bbprice_usd_sale_' . $loop,
		'name'          => '_bbprice_usd_sale_' . $loop,
		'value'         => get_post_meta( $variation->ID, '_bbprice_usd_sale', true ),
		'label'         => 'حراج دلاری ($)',
		'wrapper_class' => 'form-row form-row-last',
		'data_type'     => 'decimal',
	) );
}, 10, 3 );

add_action( 'woocommerce_save_product_variation', function ( $variation_id, $loop ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
	if ( isset( $_POST[ '_bbprice_usd_' . $loop ] ) ) {
		update_post_meta( $variation_id, '_bbprice_usd', wc_clean( wp_unslash( $_POST[ '_bbprice_usd_' . $loop ] ) ) );
	}
	if ( isset( $_POST[ '_bbprice_usd_sale_' . $loop ] ) ) {
		update_post_meta( $variation_id, '_bbprice_usd_sale', wc_clean( wp_unslash( $_POST[ '_bbprice_usd_sale_' . $loop ] ) ) );
	}
	// phpcs:enable
	bbprice_apply_to_product( $variation_id );
}, 10, 2 );

/* -------------------------------------------------------------------------
 *  صفحهٔ مدیریت
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_menu_page( 'قیمت دلاری مدیا', 'قیمت دلاری مدیا', 'manage_woocommerce', 'bistbarg-pricing', 'bbprice_admin_page', 'dashicons-money-alt', 55 );
	add_submenu_page( 'bistbarg-pricing', 'تنظیمات نرخ', 'تنظیمات نرخ', 'manage_woocommerce', 'bistbarg-pricing', 'bbprice_admin_page' );
	add_submenu_page( 'bistbarg-pricing', 'جدول قیمت', 'جدول قیمت', 'manage_woocommerce', 'bistbarg-pricing-table', 'bbprice_table_page' );
} );

/** کارت تماس با برنامه‌نویس (در پایین هر صفحه). */
function bbprice_dev_box() {
	$ph = BBPRICE_DEV_PHONE;
	echo '<div class="bbprice-dev">
		<div class="bbprice-dev-ic">👨‍💻</div>
		<div class="bbprice-dev-txt"><b>پشتیبانی و برنامه‌نویسی</b><span>برای سفارش افزونه و سایت تماس بگیرید</span></div>
		<a class="bbprice-dev-call" href="tel:' . esc_attr( $ph ) . '">📞 ' . esc_html( $ph ) . '</a>
	</div>
	<style>
		.bbprice-dev{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#1f1a10,#3a2f17);color:#fff;border-radius:18px;padding:16px 20px;margin:22px 0 8px;max-width:980px}
		.bbprice-dev-ic{font-size:30px}
		.bbprice-dev-txt{flex:1;display:flex;flex-direction:column}
		.bbprice-dev-txt b{color:#f2d879;font-size:15px}
		.bbprice-dev-txt span{color:#cfc6ad;font-size:12px}
		.bbprice-dev-call{background:#c7a742;color:#1f1a10 !important;border-radius:12px;padding:10px 18px;font-weight:800;text-decoration:none;font-size:16px;direction:ltr;white-space:nowrap}
		.bbprice-dev-call:hover{filter:brightness(1.06)}
	</style>';
}

function bbprice_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$notice = '';

	if ( isset( $_POST['bbprice_save'] ) && check_admin_referer( 'bbprice_settings' ) ) {
		$new = array(
			'mode'        => 'auto' === ( $_POST['mode'] ?? '' ) ? 'auto' : 'manual',
			'store_unit'  => 'rial' === ( $_POST['store_unit'] ?? '' ) ? 'rial' : 'toman',
			'round_to'    => max( 0, (int) ( $_POST['round_to'] ?? 1000 ) ),
			'margin'      => (float) ( $_POST['margin'] ?? 0 ),
			'source'      => 'custom' === ( $_POST['source'] ?? '' ) ? 'custom' : 'tgju',
			'custom_url'  => esc_url_raw( wp_unslash( $_POST['custom_url'] ?? '' ) ),
			'custom_path' => sanitize_text_field( wp_unslash( $_POST['custom_path'] ?? '' ) ),
			'custom_unit' => 'toman' === ( $_POST['custom_unit'] ?? '' ) ? 'toman' : 'rial',
			'interval'    => in_array( $_POST['interval'] ?? '', array( 'hourly', 'twicedaily', 'daily' ), true ) ? $_POST['interval'] : 'hourly',
		);
		// نرخ دستی فقط در حالت دستی قابل تغییر است.
		if ( 'manual' === $new['mode'] ) {
			$new['rate'] = (float) preg_replace( '/[^0-9.]/', '', str_replace( ',', '', (string) ( $_POST['rate'] ?? 0 ) ) );
		}
		bbprice_update_settings( $new );
		// بازهٔ زمان‌بندی را به‌روز کن.
		wp_clear_scheduled_hook( BBPRICE_CRON );
		wp_schedule_event( time() + 60, $new['interval'], BBPRICE_CRON );
		$count  = bbprice_recalculate_all();
		$notice = '<div class="notice notice-success"><p>تنظیمات ذخیره شد و قیمت ' . (int) $count . ' محصول به‌روز شد.</p></div>';
	}

	if ( isset( $_POST['bbprice_fetch'] ) && check_admin_referer( 'bbprice_settings' ) ) {
		$r = bbprice_refresh_rate_and_prices();
		$notice = is_wp_error( $r )
			? '<div class="notice notice-error"><p>گرفتن نرخ ناموفق بود: ' . esc_html( $r->get_error_message() ) . '</p></div>'
			: '<div class="notice notice-success"><p>نرخ دلار به‌روز شد و قیمت‌ها دوباره حساب شدند.</p></div>';
	}

	if ( isset( $_POST['bbprice_recalc'] ) && check_admin_referer( 'bbprice_settings' ) ) {
		$count  = bbprice_recalculate_all();
		$notice = '<div class="notice notice-success"><p>قیمت ' . (int) $count . ' محصول دوباره حساب شد.</p></div>';
	}

	$s    = bbprice_settings();
	$when = $s['updated_at'] ? wp_date( 'Y/m/d H:i', $s['updated_at'] ) : '—';
	$rate = (float) $s['rate'];
	?>
	<div class="wrap bbprice-wrap">
		<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="bbprice-head">
			<h1>💵 قیمت‌گذاری دلاری</h1>
			<span class="bbprice-ver">نسخهٔ <?php echo esc_html( BBPRICE_VERSION ); ?></span>
		</div>

		<div class="bbprice-hero">
			<div class="bbprice-hero-main">
				<div class="bbprice-hero-label">نرخ فعلی دلار</div>
				<div class="bbprice-hero-rate"><span id="bbprice-rate-num"><?php echo esc_html( number_format( $rate ) ); ?></span> <small>تومان</small></div>
				<div class="bbprice-hero-sub">
					<span class="bbprice-chip <?php echo 'auto' === $s['mode'] ? 'is-auto' : 'is-manual'; ?>"><?php echo 'auto' === $s['mode'] ? '⟳ خودکار' : '✎ دستی'; ?></span>
					آخرین به‌روزرسانی: <?php echo esc_html( $when ); ?>
				</div>
				<?php if ( $s['last_error'] ) : ?>
					<div class="bbprice-hero-err">⚠ <?php echo esc_html( $s['last_error'] ); ?></div>
				<?php endif; ?>
				<?php
				if ( 'auto' === $s['mode'] ) {
					$next   = wp_next_scheduled( BBPRICE_CRON );
					$cronok = $next ? 'بله (نوبت بعدی: ' . esc_html( wp_date( 'H:i', $next ) ) . ')' : 'زمان‌بندی نشده';
					$dis    = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? ' — WP-Cron خاموش است؛ کرون سرور لازم است' : '';
					echo '<div class="bbprice-hero-diag">⟳ به‌روزرسانی هر ' .
						esc_html( 'hourly' === $s['interval'] ? 'ساعت' : ( 'daily' === $s['interval'] ? 'روز' : '۱۲ ساعت' ) ) .
						' · زمان‌بندی: ' . $cronok . esc_html( $dis ) . '</div>';
				}
				?>
			</div>
			<div class="bbprice-calc">
				<div class="bbprice-calc-title">ماشین‌حساب زنده</div>
				<label>قیمت دلاری</label>
				<div class="bbprice-calc-row"><input type="number" id="bbprice-calc-usd" value="10" min="0" step="0.5"> <span>$</span></div>
				<div class="bbprice-calc-eq">=</div>
				<div class="bbprice-calc-out"><span id="bbprice-calc-out">—</span> <small><?php echo 'rial' === $s['store_unit'] ? 'ریال' : 'تومان'; ?></small></div>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'bbprice_settings' ); ?>
			<div class="bbprice-grid">

				<div class="bbprice-card">
					<h2>💲 نرخ دلار</h2>
					<div class="bbprice-seg">
						<label class="<?php echo checked( $s['mode'], 'manual', false ) ? 'on' : ''; ?>"><input type="radio" name="mode" value="manual" <?php checked( $s['mode'], 'manual' ); ?>>✎ دستی</label>
						<label class="<?php echo checked( $s['mode'], 'auto', false ) ? 'on' : ''; ?>"><input type="radio" name="mode" value="auto" <?php checked( $s['mode'], 'auto' ); ?>>⟳ خودکار</label>
					</div>
					<label class="bbprice-f">نرخ دستی (تومان)
						<input type="text" name="rate" id="bbprice-rate-input" value="<?php echo esc_attr( (int) $rate ); ?>" dir="ltr" placeholder="263900">
					</label>
					<p class="bbprice-hint">در حالت «دستی»، همین عدد ملاک محاسبه است.</p>
				</div>

				<div class="bbprice-card">
					<h2>🧮 تبدیل قیمت</h2>
					<label class="bbprice-f">واحد فروشگاه
						<select name="store_unit" id="bbprice-unit">
							<option value="toman" <?php selected( $s['store_unit'], 'toman' ); ?>>تومان</option>
							<option value="rial" <?php selected( $s['store_unit'], 'rial' ); ?>>ریال</option>
						</select>
					</label>
					<label class="bbprice-f">گِرد کردن (تومان)
						<input type="number" name="round_to" id="bbprice-round" value="<?php echo esc_attr( $s['round_to'] ); ?>" min="0" step="500">
					</label>
					<label class="bbprice-f">درصد سود (٪)
						<input type="number" name="margin" id="bbprice-margin" value="<?php echo esc_attr( $s['margin'] ); ?>" step="0.5">
					</label>
				</div>

				<div class="bbprice-card">
					<h2>⟳ به‌روزرسانی خودکار</h2>
					<label class="bbprice-f">بازهٔ زمانی
						<select name="interval">
							<option value="hourly" <?php selected( $s['interval'], 'hourly' ); ?>>هر ساعت</option>
							<option value="twicedaily" <?php selected( $s['interval'], 'twicedaily' ); ?>>دو بار در روز</option>
							<option value="daily" <?php selected( $s['interval'], 'daily' ); ?>>روزی یک بار</option>
						</select>
					</label>
					<label class="bbprice-f">منبع نرخ
						<select name="source" id="bbprice-source">
							<option value="tgju" <?php selected( $s['source'], 'tgju' ); ?>>دلار آزاد tgju (پیش‌فرض)</option>
							<option value="custom" <?php selected( $s['source'], 'custom' ); ?>>آدرس دلخواه</option>
						</select>
					</label>
				</div>

				<div class="bbprice-card bbprice-custom" id="bbprice-custom-card">
					<h2>🔗 API دلخواه</h2>
					<label class="bbprice-f">آدرس API
						<input type="text" name="custom_url" value="<?php echo esc_attr( $s['custom_url'] ); ?>" dir="ltr" placeholder="https://...">
					</label>
					<label class="bbprice-f">مسیر مقدار در JSON
						<input type="text" name="custom_path" value="<?php echo esc_attr( $s['custom_path'] ); ?>" dir="ltr" placeholder="current.price_dollar_rl.p">
					</label>
					<label class="bbprice-f">واحد مقدار API
						<select name="custom_unit">
							<option value="rial" <?php selected( $s['custom_unit'], 'rial' ); ?>>ریال</option>
							<option value="toman" <?php selected( $s['custom_unit'], 'toman' ); ?>>تومان</option>
						</select>
					</label>
				</div>
			</div>

			<div class="bbprice-actions">
				<button type="submit" name="bbprice_save" class="bbprice-btn primary">💾 ذخیره و اعمال روی محصولات</button>
				<button type="submit" name="bbprice_fetch" class="bbprice-btn">⟳ گرفتن نرخ از API الان</button>
				<button type="submit" name="bbprice_recalc" class="bbprice-btn ghost">🧮 محاسبهٔ دوبارهٔ همه</button>
			</div>
		</form>

		<p class="bbprice-foot">در صفحهٔ هر محصول، بخش «عمومی»، فیلد <b>«قیمت دلاری ($)»</b> را پر کنید؛ قیمت تومانی خودکار حساب می‌شود.</p>
		<?php bbprice_dev_box(); ?>
	</div>

	<style>
		.bbprice-wrap{direction:rtl;text-align:right;max-width:980px;font-family:inherit}
		.bbprice-wrap *{box-sizing:border-box}
		.bbprice-head{display:flex;align-items:center;gap:12px;margin:10px 0 18px}
		.bbprice-head h1{margin:0;font-size:24px;font-weight:800;color:#1f1a10}
		.bbprice-ver{background:#f0ead6;color:#8c6b12;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700}
		.bbprice-hero{display:grid;grid-template-columns:1.4fr 1fr;gap:18px;margin-bottom:20px}
		@media(max-width:782px){.bbprice-hero{grid-template-columns:1fr}}
		.bbprice-hero-main{background:linear-gradient(135deg,#1f1a10,#3a2f17);color:#fff;border-radius:20px;padding:24px 26px;position:relative;overflow:hidden}
		.bbprice-hero-main:after{content:"$";position:absolute;left:18px;bottom:-18px;font-size:120px;font-weight:900;color:rgba(199,167,66,.15)}
		.bbprice-hero-label{color:#e7d9a6;font-size:14px;margin-bottom:6px}
		.bbprice-hero-rate{font-size:42px;font-weight:900;color:#f2d879;line-height:1.1}
		.bbprice-hero-rate small{font-size:18px;color:#cbb971;font-weight:600}
		.bbprice-hero-sub{margin-top:12px;color:#cfc6ad;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
		.bbprice-chip{border-radius:20px;padding:3px 11px;font-weight:700;font-size:12px}
		.bbprice-chip.is-auto{background:#1e7e34;color:#fff}.bbprice-chip.is-manual{background:#c7a742;color:#1f1a10}
		.bbprice-hero-err{margin-top:10px;background:rgba(179,38,30,.2);color:#ffb4ae;border-radius:10px;padding:8px 12px;font-size:13px}
		.bbprice-hero-diag{margin-top:10px;color:#bcae82;font-size:12px}
		.bbprice-calc{background:#fff;border:1px solid #ece4cd;border-radius:20px;padding:20px}
		.bbprice-calc-title{font-weight:800;color:#8c6b12;margin-bottom:12px}
		.bbprice-calc label{display:block;font-size:13px;color:#6b6352;margin-bottom:6px}
		.bbprice-calc-row{display:flex;align-items:center;gap:8px}
		.bbprice-calc-row input{flex:1;border:1.5px solid #e3dcc6;border-radius:12px;padding:10px 12px;font-size:17px;text-align:center}
		.bbprice-calc-row span{font-size:20px;font-weight:800;color:#a9831c}
		.bbprice-calc-eq{text-align:center;color:#c7a742;font-size:22px;margin:6px 0}
		.bbprice-calc-out{text-align:center;font-size:26px;font-weight:900;color:#1f1a10}
		.bbprice-calc-out small{font-size:14px;color:#6b6352;font-weight:600}
		.bbprice-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
		@media(max-width:782px){.bbprice-grid{grid-template-columns:1fr}}
		.bbprice-card{background:#fff;border:1px solid #ece4cd;border-radius:18px;padding:20px 22px}
		.bbprice-card h2{margin:0 0 14px;font-size:16px;font-weight:800;color:#1f1a10}
		.bbprice-f{display:block;font-size:13px;font-weight:700;color:#3a3326;margin:12px 0 0}
		.bbprice-f input,.bbprice-f select{width:100%;margin-top:6px;border:1.5px solid #e3dcc6;border-radius:12px;padding:11px 13px;font-size:15px;background:#fcfbf6}
		.bbprice-f input:focus,.bbprice-f select:focus{outline:0;border-color:#a9831c;box-shadow:0 0 0 3px rgba(169,131,28,.15);background:#fff}
		.bbprice-hint{color:#8a8169;font-size:12px;margin:10px 0 0}
		.bbprice-seg{display:flex;gap:8px;background:#f4efe3;border-radius:14px;padding:5px}
		.bbprice-seg label{flex:1;text-align:center;padding:9px;border-radius:10px;cursor:pointer;font-weight:700;color:#6b6352;font-size:14px;user-select:none;transition:background .12s,color .12s}
		.bbprice-seg label:hover{color:#8c6b12}
		.bbprice-seg label.on{background:#fff;color:#8c6b12;box-shadow:0 2px 6px rgba(0,0,0,.08)}
		.bbprice-seg input{display:none}
		.bbprice-actions{display:flex;gap:10px;flex-wrap:wrap;margin:22px 0 10px}
		.bbprice-btn{border:0;border-radius:14px;padding:13px 22px;font-size:15px;font-weight:800;cursor:pointer;background:#f0ead6;color:#8c6b12}
		.bbprice-btn.primary{background:linear-gradient(135deg,#c7a742,#a9831c);color:#fff;box-shadow:0 8px 20px rgba(169,131,28,.3)}
		.bbprice-btn.ghost{background:#fff;border:1.5px solid #e3dcc6;color:#6b6352}
		.bbprice-btn:hover{filter:brightness(1.04)}
		.bbprice-foot{color:#6b6352;background:#fbf8ef;border:1px dashed #e3dcc6;border-radius:12px;padding:12px 16px;margin-top:8px}
	</style>
	<script>
	(function(){
		var unitSel=document.getElementById('bbprice-unit'),roundI=document.getElementById('bbprice-round'),marginI=document.getElementById('bbprice-margin');
		var modeInputs=document.getElementsByName('mode'),manualRate=document.getElementById('bbprice-rate-input'),rateNum=document.getElementById('bbprice-rate-num');
		var usd=document.getElementById('bbprice-calc-usd'),out=document.getElementById('bbprice-calc-out');
		var serverRate=<?php echo (float) $rate; ?>;
		function currentRate(){ // در حالت دستی از ورودی، وگرنه نرخ سرور
			var mode='manual';for(var i=0;i<modeInputs.length;i++)if(modeInputs[i].checked)mode=modeInputs[i].value;
			if(mode==='manual'){var v=parseFloat((manualRate.value||'').replace(/[^0-9.]/g,''));return v>0?v:serverRate;}
			return serverRate;
		}
		function calc(){
			var d=parseFloat(usd.value)||0,rate=currentRate(),margin=parseFloat(marginI.value)||0,step=parseInt(roundI.value)||0;
			var t=d*rate*(1+margin/100);
			if(step>0)t=Math.round(t/step)*step;else t=Math.round(t);
			if(unitSel.value==='rial')t=t*10;
			out.textContent=t>0?t.toLocaleString('fa-IR'):'—';
			if(rateNum)rateNum.textContent=Math.round(currentRate()).toLocaleString('fa-IR');
		}
		[usd,unitSel,roundI,marginI,manualRate].forEach(function(el){if(el){el.addEventListener('input',calc);el.addEventListener('change',calc);}});
		function syncSeg(){for(var i=0;i<modeInputs.length;i++){var lab=modeInputs[i].closest('label');if(lab)lab.classList.toggle('on',modeInputs[i].checked);}}
		for(var i=0;i<modeInputs.length;i++)modeInputs[i].addEventListener('change',function(){syncSeg();calc();});
		syncSeg();
		// نمایش/پنهان کارت API دلخواه
		var src=document.getElementById('bbprice-source'),card=document.getElementById('bbprice-custom-card');
		function toggleCustom(){card.style.display=(src.value==='custom')?'':'none';}
		if(src){src.addEventListener('change',toggleCustom);toggleCustom();}
		calc();
	})();
	</script>
	<?php
}


/* -------------------------------------------------------------------------
 *  جدول قیمت‌ها
 * ---------------------------------------------------------------------- */

function bbprice_price_label( $product ) {
	$s    = bbprice_settings();
	$unit = 'rial' === $s['store_unit'] ? 'ریال' : 'تومان';
	$price = $product->get_price();
	if ( '' === $price || null === $price ) {
		return '—';
	}
	return number_format( (float) $price ) . ' ' . $unit;
}

function bbprice_table_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$notice  = '';
	$per_page = 30;

	if ( isset( $_POST['bbprice_table_save'] ) && check_admin_referer( 'bbprice_table' ) ) {
		$usd = isset( $_POST['usd'] ) && is_array( $_POST['usd'] ) ? wp_unslash( $_POST['usd'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$n   = 0;
		foreach ( $usd as $id => $val ) {
			$id  = (int) $id;
			$val = preg_replace( '/[^0-9.]/', '', (string) $val );
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				continue;
			}
			$product->update_meta_data( '_bbprice_usd', $val );
			$product->save();
			bbprice_apply_to_product( $id );
			$n++;
		}
		$notice = '<div class="notice notice-success"><p>قیمت ' . (int) $n . ' محصول ذخیره و محاسبه شد.</p></div>';
	}

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
	$only   = isset( $_GET['only'] ) && '1' === $_GET['only']; // فقط دارای قیمت دلاری

	$args = array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		's'              => $search,
		'orderby'        => 'title',
		'order'          => 'ASC',
	);
	if ( $only ) {
		$args['meta_key']     = '_bbprice_usd'; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_compare'] = 'EXISTS';
	}
	$q = new WP_Query( $args );

	$base_url = admin_url( 'admin.php?page=bistbarg-pricing-table' );
	?>
	<div class="wrap bbprice-wrap">
		<div class="bbprice-head"><h1>📋 جدول قیمت دلاری</h1><span class="bbprice-ver">نرخ: <?php echo esc_html( number_format( (float) bbprice_settings()['rate'] ) ); ?> تومان</span></div>
		<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<form method="get" class="bbprice-toolbar">
			<input type="hidden" name="page" value="bistbarg-pricing-table">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجوی نام محصول…">
			<label class="bbprice-only"><input type="checkbox" name="only" value="1" <?php checked( $only ); ?> onchange="this.form.submit()"> فقط دارای قیمت دلاری</label>
			<button class="bbprice-btn">جستجو</button>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'bbprice_table' ); ?>
			<table class="bbprice-table">
				<thead><tr><th class="bbprice-imgcol">تصویر</th><th>نام محصول</th><th>قیمت دلاری ($)</th><th>قیمت فروشگاه</th></tr></thead>
				<tbody>
				<?php
				if ( ! $q->have_posts() ) {
					echo '<tr><td colspan="4" class="bbprice-empty">محصولی یافت نشد.</td></tr>';
				}
				while ( $q->have_posts() ) :
					$q->the_post();
					$product = wc_get_product( get_the_ID() );
					if ( ! $product ) {
						continue;
					}
					if ( $product->is_type( 'variable' ) ) :
						?>
						<tr class="bbprice-parent"><td colspan="4"><span class="bbprice-tag">متغیر</span> <?php echo esc_html( $product->get_name() ); ?> — <a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>" target="_blank">ویرایش متغیرها</a></td></tr>
						<?php
						foreach ( $product->get_children() as $vid ) :
							$v = wc_get_product( $vid );
							if ( ! $v ) { continue; }
							$attrs = implode( '، ', array_filter( $v->get_variation_attributes() ) );
							?>
							<tr>
								<td class="bbprice-imgcol"><?php echo $v->get_image( array( 46, 46 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="bbprice-name bbprice-sub">↳ <?php echo esc_html( $attrs ? $attrs : ( '#' . $vid ) ); ?></td>
								<td><input type="number" step="0.01" min="0" name="usd[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $v->get_meta( '_bbprice_usd' ) ); ?>" class="bbprice-usd"></td>
								<td class="bbprice-money"><?php echo esc_html( bbprice_price_label( $v ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td class="bbprice-imgcol"><?php echo $product->get_image( array( 46, 46 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="bbprice-name"><?php echo esc_html( $product->get_name() ); ?></td>
							<td><input type="number" step="0.01" min="0" name="usd[<?php echo (int) $product->get_id(); ?>]" value="<?php echo esc_attr( $product->get_meta( '_bbprice_usd' ) ); ?>" class="bbprice-usd"></td>
							<td class="bbprice-money"><?php echo esc_html( bbprice_price_label( $product ) ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endwhile; wp_reset_postdata(); ?>
				</tbody>
			</table>

			<div class="bbprice-actions">
				<button type="submit" name="bbprice_table_save" class="bbprice-btn primary">💾 ذخیرهٔ قیمت‌های این صفحه</button>
			</div>
		</form>

		<?php
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			$args_link = array_filter( array( 's' => $search, 'only' => $only ? '1' : '' ) );
			echo '<div class="bbprice-pager">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = add_query_arg( array_merge( $args_link, array( 'paged' => $i ) ), $base_url );
				echo '<a class="' . ( $i === $paged ? 'on' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
			}
			echo '</div>';
		}
		?>

		<style>
			.bbprice-toolbar{display:flex;gap:10px;align-items:center;margin:14px 0;flex-wrap:wrap}
			.bbprice-toolbar input[type=search]{border:1.5px solid #e3dcc6;border-radius:12px;padding:10px 14px;min-width:260px;font-size:14px}
			.bbprice-only{color:#6b6352;font-size:13px}
			.bbprice-table{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid #ece4cd;border-radius:16px;overflow:hidden}
			.bbprice-table th{background:#1f1a10;color:#f2d879;padding:13px 16px;text-align:right;font-size:14px}
			.bbprice-table td{padding:11px 16px;border-top:1px solid #f1ecdc;font-size:14px;color:#2a2518}
			.bbprice-table tr:hover td{background:#fcfaf1}
			.bbprice-parent td{background:#f6efd9 !important;font-weight:700;color:#8c6b12}
			.bbprice-tag{background:#c7a742;color:#1f1a10;border-radius:14px;padding:2px 10px;font-size:11px;font-weight:800}
			.bbprice-sub{color:#6b6352}
			.bbprice-usd{width:120px;border:1.5px solid #e3dcc6;border-radius:10px;padding:8px 10px;text-align:center;font-size:14px}
			.bbprice-usd:focus{outline:0;border-color:#a9831c;box-shadow:0 0 0 3px rgba(169,131,28,.15)}
			.bbprice-money{font-weight:700;color:#1f1a10;white-space:nowrap}
			.bbprice-empty{text-align:center;color:#8a8169;padding:30px}
			.bbprice-pager{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0}
			.bbprice-pager a{min-width:36px;text-align:center;padding:7px 10px;border:1px solid #e3dcc6;border-radius:10px;color:#6b6352;text-decoration:none}
			.bbprice-pager a.on{background:#a9831c;color:#fff;border-color:#a9831c}
			.bbprice-imgcol{width:58px}
			.bbprice-imgcol img{width:46px;height:46px;object-fit:cover;border-radius:10px;display:block;background:#f4efe3}
		</style>
		<?php bbprice_dev_box(); ?>
	</div>
	<?php
}
