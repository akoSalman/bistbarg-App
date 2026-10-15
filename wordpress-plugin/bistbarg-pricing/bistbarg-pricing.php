<?php
/**
 * Plugin Name:       قیمت‌گذاری دلاری بیست‌برگ
 * Description:       قیمت محصولات را به دلار وارد کنید؛ قیمت تومانی خودکار بر اساس نرخ دلار (خودکار از API یا دستی) محاسبه و با تغییر نرخ به‌روز می‌شود.
 * Version:           1.0.0
 * Author:            Bistbarg
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       bistbarg-pricing
 */

defined( 'ABSPATH' ) || exit;

define( 'BBPRICE_VERSION', '1.0.0' );
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
	return 'rial' === $unit ? $num / 10 : $num; // خروجی همیشه تومان
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
	return true;
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
	add_submenu_page( 'woocommerce', 'قیمت‌گذاری دلاری', 'قیمت‌گذاری دلاری', 'manage_woocommerce', 'bistbarg-pricing', 'bbprice_admin_page' );
} );

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
	?>
	<div class="wrap" style="direction:rtl;text-align:right;max-width:820px">
		<h1>قیمت‌گذاری دلاری بیست‌برگ</h1>
		<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<div style="background:#fff;border:1px solid #e3dcc6;border-radius:12px;padding:18px;margin:16px 0">
			<strong style="font-size:16px">نرخ فعلی دلار:</strong>
			<span style="font-size:22px;color:#a9831c;font-weight:800"><?php echo esc_html( number_format( (float) $s['rate'] ) ); ?> تومان</span>
			<div style="color:#6b6352;margin-top:6px">آخرین به‌روزرسانی: <?php echo esc_html( $when ); ?><?php echo $s['last_error'] ? ' — <span style="color:#b3261e">خطا: ' . esc_html( $s['last_error'] ) . '</span>' : ''; ?></div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'bbprice_settings' ); ?>
			<table class="form-table">
				<tr><th>حالت نرخ دلار</th><td>
					<label><input type="radio" name="mode" value="manual" <?php checked( $s['mode'], 'manual' ); ?>> دستی (خودم وارد می‌کنم)</label>&nbsp;&nbsp;
					<label><input type="radio" name="mode" value="auto" <?php checked( $s['mode'], 'auto' ); ?>> خودکار (از API گرفته شود)</label>
				</td></tr>
				<tr><th>نرخ دلار دستی (تومان)</th><td>
					<input type="text" name="rate" value="<?php echo esc_attr( (int) $s['rate'] ); ?>" class="regular-text" dir="ltr">
					<p class="description">فقط در حالت «دستی» استفاده می‌شود. مثلاً 92000</p>
				</td></tr>
				<tr><th>واحد قیمت فروشگاه</th><td>
					<select name="store_unit">
						<option value="toman" <?php selected( $s['store_unit'], 'toman' ); ?>>تومان</option>
						<option value="rial" <?php selected( $s['store_unit'], 'rial' ); ?>>ریال</option>
					</select>
					<p class="description">واحدی که قیمت محصولات در ووکامرس با آن ذخیره می‌شود.</p>
				</td></tr>
				<tr><th>گِرد کردن قیمت (تومان)</th><td>
					<input type="number" name="round_to" value="<?php echo esc_attr( $s['round_to'] ); ?>" min="0" step="500">
					<p class="description">به نزدیک‌ترین این عدد گِرد می‌شود. 0 یعنی بدون گِرد کردن.</p>
				</td></tr>
				<tr><th>درصد سود</th><td>
					<input type="number" name="margin" value="<?php echo esc_attr( $s['margin'] ); ?>" step="0.5"> ٪
					<p class="description">اختیاری. روی قیمت دلاری اعمال می‌شود.</p>
				</td></tr>
				<tr><th>بازهٔ به‌روزرسانی خودکار</th><td>
					<select name="interval">
						<option value="hourly" <?php selected( $s['interval'], 'hourly' ); ?>>هر ساعت</option>
						<option value="twicedaily" <?php selected( $s['interval'], 'twicedaily' ); ?>>دو بار در روز</option>
						<option value="daily" <?php selected( $s['interval'], 'daily' ); ?>>روزی یک بار</option>
					</select>
				</td></tr>
			</table>

			<h2>منبع نرخ خودکار</h2>
			<table class="form-table">
				<tr><th>منبع</th><td>
					<label><input type="radio" name="source" value="tgju" <?php checked( $s['source'], 'tgju' ); ?>> دلار آزاد tgju (پیش‌فرض)</label>&nbsp;&nbsp;
					<label><input type="radio" name="source" value="custom" <?php checked( $s['source'], 'custom' ); ?>> آدرس دلخواه</label>
				</td></tr>
				<tr><th>آدرس API دلخواه</th><td><input type="text" name="custom_url" value="<?php echo esc_attr( $s['custom_url'] ); ?>" class="large-text" dir="ltr" placeholder="https://..."></td></tr>
				<tr><th>مسیر مقدار در JSON</th><td>
					<input type="text" name="custom_path" value="<?php echo esc_attr( $s['custom_path'] ); ?>" class="regular-text" dir="ltr" placeholder="current.price_dollar_rl.p">
					<p class="description">با نقطه جدا کنید. مثال بالا یعنی data[current][price_dollar_rl][p].</p>
				</td></tr>
				<tr><th>واحد مقدار API</th><td>
					<select name="custom_unit">
						<option value="rial" <?php selected( $s['custom_unit'], 'rial' ); ?>>ریال</option>
						<option value="toman" <?php selected( $s['custom_unit'], 'toman' ); ?>>تومان</option>
					</select>
				</td></tr>
			</table>

			<p>
				<button type="submit" name="bbprice_save" class="button button-primary">ذخیره و اعمال روی محصولات</button>
				<button type="submit" name="bbprice_fetch" class="button">گرفتن نرخ از API الان</button>
				<button type="submit" name="bbprice_recalc" class="button">محاسبهٔ دوبارهٔ همهٔ قیمت‌ها</button>
			</p>
		</form>

		<p style="color:#6b6352">راهنما: در صفحهٔ هر محصول، در بخش «عمومی» فیلد «قیمت دلاری ($)» را پر کنید. قیمت تومانی خودکار حساب می‌شود.</p>
	</div>
	<?php
}
