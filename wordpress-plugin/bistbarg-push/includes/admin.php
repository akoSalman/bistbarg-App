<?php
/**
 * Admin panel: send notifications, history, settings.
 */

defined( 'ABSPATH' ) || exit;

function bbp_capability() {
	return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
}

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			'اعلان‌های اپلیکیشن',
			'اعلان‌های اپ',
			bbp_capability(),
			'bistbarg-push',
			'bbp_render_page',
			'dashicons-megaphone',
			56
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'toplevel_page_bistbarg-push' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'bbp-admin', BBP_URL . 'assets/admin.css', array(), BBP_VERSION );
		wp_enqueue_script( 'bbp-admin', BBP_URL . 'assets/admin.js', array( 'jquery' ), BBP_VERSION, true );
	}
);

add_action( 'admin_post_bbp_send', 'bbp_handle_send' );
add_action( 'admin_post_bbp_delete', 'bbp_handle_delete' );
add_action( 'admin_post_bbp_settings', 'bbp_handle_settings' );

function bbp_page_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'bistbarg-push' ), $args ), admin_url( 'admin.php' ) );
}

function bbp_handle_send() {
	if ( ! current_user_can( bbp_capability() ) ) {
		wp_die( 'دسترسی ندارید.' );
	}
	check_admin_referer( 'bbp_send' );

	$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$body     = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
	$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$image    = isset( $_POST['image'] ) ? esc_url_raw( wp_unslash( $_POST['image'] ) ) : '';
	$audience = isset( $_POST['audience'] ) ? sanitize_key( $_POST['audience'] ) : 'all';
	$expires  = isset( $_POST['expires'] ) ? absint( $_POST['expires'] ) : 7;
	$user_id  = 0;

	if ( '' === $title || '' === $body ) {
		wp_safe_redirect( bbp_page_url( array( 'bbp_msg' => 'empty' ) ) );
		exit;
	}

	if ( 'user' === $audience ) {
		$who  = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		if ( ! $user && ctype_digit( $who ) ) {
			$user = get_user_by( 'id', (int) $who );
		}
		if ( ! $user ) {
			wp_safe_redirect( bbp_page_url( array( 'bbp_msg' => 'nouser' ) ) );
			exit;
		}
		$user_id = $user->ID;
	}

	$id = bbp_add_notification(
		array(
			'title'        => $title,
			'body'         => $body,
			'url'          => $url,
			'image'        => $image,
			'audience'     => $audience,
			'user_id'      => $user_id,
			'expires_days' => $expires,
		)
	);

	wp_safe_redirect( bbp_page_url( array( 'bbp_msg' => $id ? 'sent' : 'error' ) ) );
	exit;
}

function bbp_handle_delete() {
	global $wpdb;
	if ( ! current_user_can( bbp_capability() ) ) {
		wp_die( 'دسترسی ندارید.' );
	}
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'bbp_delete_' . $id );
	$wpdb->delete( bbp_table( 'notifications' ), array( 'id' => $id ), array( '%d' ) );
	wp_safe_redirect( bbp_page_url( array( 'tab' => 'history', 'bbp_msg' => 'deleted' ) ) );
	exit;
}

function bbp_handle_settings() {
	if ( ! current_user_can( bbp_capability() ) ) {
		wp_die( 'دسترسی ندارید.' );
	}
	check_admin_referer( 'bbp_settings' );

	$settings = array(
		'order_enabled' => empty( $_POST['order_enabled'] ) ? 0 : 1,
		'orders'        => array(),
	);
	$posted = isset( $_POST['orders'] ) && is_array( $_POST['orders'] ) ? wp_unslash( $_POST['orders'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
	foreach ( $posted as $status => $t ) {
		$settings['orders'][ sanitize_key( $status ) ] = array(
			'on'    => empty( $t['on'] ) ? 0 : 1,
			'title' => sanitize_text_field( isset( $t['title'] ) ? $t['title'] : '' ),
			'body'  => sanitize_textarea_field( isset( $t['body'] ) ? $t['body'] : '' ),
		);
	}
	update_option( 'bbp_settings', $settings );
	wp_safe_redirect( bbp_page_url( array( 'tab' => 'settings', 'bbp_msg' => 'saved' ) ) );
	exit;
}

function bbp_stats() {
	global $wpdb;
	$devices = bbp_table( 'devices' );
	$since   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
	return array(
		'devices' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $devices WHERE last_seen > %s", $since ) ),
		'members' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $devices WHERE last_seen > %s AND user_id > 0", $since ) ),
		'sent'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . bbp_table( 'notifications' ) ),
	);
}

function bbp_render_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$msg      = isset( $_GET['bbp_msg'] ) ? sanitize_key( $_GET['bbp_msg'] ) : '';
	$messages = array(
		'sent'    => array( 'success', 'اعلان ثبت شد و در چند ثانیه تا چند دقیقهٔ آینده به گوشی کاربران می‌رسد.' ),
		'deleted' => array( 'success', 'اعلان حذف شد.' ),
		'saved'   => array( 'success', 'تنظیمات ذخیره شد.' ),
		'empty'   => array( 'error', 'عنوان و متن اعلان را وارد کنید.' ),
		'nouser'  => array( 'error', 'کاربری با این ایمیل یا نام کاربری پیدا نشد.' ),
		'error'   => array( 'error', 'ذخیرهٔ اعلان انجام نشد.' ),
	);
	if ( isset( $messages[ $msg ] ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
	}
}

function bbp_render_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'send';
	$tabs  = array(
		'send'     => 'ارسال اعلان',
		'history'  => 'تاریخچه',
		'settings' => 'اعلان‌های خودکار سفارش',
	);
	$stats = bbp_stats();
	?>
	<div class="wrap bbp-wrap">
		<h1 class="bbp-title"><span class="dashicons dashicons-megaphone"></span> اعلان‌های اپلیکیشن بیست‌برگ</h1>
		<?php bbp_render_notice(); ?>

		<div class="bbp-stats">
			<div class="bbp-stat"><strong><?php echo esc_html( number_format_i18n( $stats['devices'] ) ); ?></strong><span>گوشی فعال (۳۰ روز اخیر)</span></div>
			<div class="bbp-stat"><strong><?php echo esc_html( number_format_i18n( $stats['members'] ) ); ?></strong><span>کاربر عضو در اپ</span></div>
			<div class="bbp-stat"><strong><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></strong><span>اعلان ارسال‌شده</span></div>
		</div>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( bbp_page_url( array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="bbp-tab">
			<?php
			if ( 'history' === $tab ) {
				bbp_render_history();
			} elseif ( 'settings' === $tab ) {
				bbp_render_settings();
			} else {
				bbp_render_send();
			}
			?>
		</div>
	</div>
	<?php
}

function bbp_render_send() {
	?>
	<div class="bbp-send">
		<form class="bbp-card bbp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bbp_send">
			<?php wp_nonce_field( 'bbp_send' ); ?>

			<label class="bbp-field">
				<span>عنوان اعلان *</span>
				<input type="text" name="title" id="bbp-title" maxlength="100" required placeholder="مثلاً: تخفیف ویژهٔ آخر هفته 🎁">
			</label>

			<label class="bbp-field">
				<span>متن اعلان *</span>
				<textarea name="body" id="bbp-body" rows="4" maxlength="500" required placeholder="مثلاً: تا ۳۰٪ تخفیف روی همهٔ محصولات، فقط تا جمعه!"></textarea>
			</label>

			<label class="bbp-field">
				<span>لینک (با زدن روی اعلان باز می‌شود)</span>
				<input type="url" name="url" dir="ltr" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
				<small>خالی بگذارید تا صفحهٔ اصلی سایت باز شود. می‌توانید لینک یک محصول یا دسته را بگذارید.</small>
			</label>

			<div class="bbp-field">
				<span>تصویر (اختیاری)</span>
				<div class="bbp-image-row">
					<input type="url" name="image" id="bbp-image" dir="ltr" placeholder="https://...">
					<button type="button" class="button" id="bbp-pick-image">انتخاب از رسانه‌ها</button>
				</div>
			</div>

			<div class="bbp-field">
				<span>ارسال به</span>
				<div class="bbp-audience">
					<label><input type="radio" name="audience" value="all" checked> همهٔ کاربران اپ</label>
					<label><input type="radio" name="audience" value="members"> فقط مشتریان عضو (وارد شده)</label>
					<label><input type="radio" name="audience" value="user"> یک مشتری خاص</label>
				</div>
				<input type="text" name="user" id="bbp-user" class="bbp-hidden" placeholder="ایمیل یا نام کاربری مشتری">
			</div>

			<label class="bbp-field">
				<span>مدت اعتبار</span>
				<select name="expires">
					<option value="1">۱ روز</option>
					<option value="3">۳ روز</option>
					<option value="7" selected>۷ روز</option>
					<option value="30">۳۰ روز</option>
				</select>
				<small>گوشی‌هایی که در این مدت اینترنت نداشته باشند، بعد از آن اعلان را دریافت نمی‌کنند.</small>
			</label>

			<button type="submit" class="button button-primary bbp-submit">ارسال اعلان</button>
		</form>

		<div class="bbp-preview-wrap">
			<div class="bbp-phone">
				<div class="bbp-phone-bar"><?php echo esc_html( wp_date( 'H:i' ) ); ?></div>
				<div class="bbp-notif">
					<div class="bbp-notif-head"><span class="bbp-notif-icon"></span> Bistbarg · الان</div>
					<div class="bbp-notif-title" id="bbp-prev-title">عنوان اعلان</div>
					<div class="bbp-notif-body" id="bbp-prev-body">متن اعلان اینجا نمایش داده می‌شود.</div>
					<img class="bbp-notif-image bbp-hidden" id="bbp-prev-image" alt="">
				</div>
			</div>
			<p class="bbp-preview-note">پیش‌نمایش اعلان روی گوشی</p>
		</div>
	</div>
	<?php
}

function bbp_render_history() {
	global $wpdb;
	$table = bbp_table( 'notifications' );
	$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 100" );
	$aud   = array(
		'all'     => 'همهٔ کاربران',
		'members' => 'مشتریان عضو',
		'user'    => 'یک مشتری',
	);
	if ( ! $rows ) {
		echo '<div class="bbp-card bbp-empty">هنوز اعلانی ارسال نشده است.</div>';
		return;
	}
	?>
	<div class="bbp-card">
		<table class="widefat striped bbp-table">
			<thead>
				<tr>
					<th>اعلان</th>
					<th>گیرنده</th>
					<th>نوع</th>
					<th>دریافت شده</th>
					<th>تاریخ</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $r->title ); ?></strong>
						<div class="bbp-muted"><?php echo esc_html( wp_trim_words( $r->body, 18 ) ); ?></div>
					</td>
					<td>
						<?php
						echo esc_html( isset( $aud[ $r->audience ] ) ? $aud[ $r->audience ] : $r->audience );
						if ( 'user' === $r->audience && $r->user_id ) {
							$u = get_userdata( (int) $r->user_id );
							echo '<div class="bbp-muted">' . esc_html( $u ? $u->display_name : '#' . $r->user_id ) . '</div>';
						}
						?>
					</td>
					<td><?php echo 'order' === $r->source ? '<span class="bbp-badge bbp-badge-order">خودکار سفارش</span>' : '<span class="bbp-badge">دستی</span>'; ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $r->delivered ) ); ?> گوشی</td>
					<td><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $r->created_at . ' UTC' ) ) ); ?></td>
					<td>
						<a class="bbp-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bbp_delete&id=' . (int) $r->id ), 'bbp_delete_' . (int) $r->id ) ); ?>" onclick="return confirm('این اعلان حذف شود؟ گوشی‌هایی که هنوز آن را نگرفته‌اند دیگر دریافتش نمی‌کنند.');">حذف</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function bbp_render_settings() {
	$settings  = bbp_settings();
	$statuses  = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
	$defaults  = bbp_default_order_templates();
	?>
	<form class="bbp-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="bbp_settings">
		<?php wp_nonce_field( 'bbp_settings' ); ?>

		<label class="bbp-switch">
			<input type="checkbox" name="order_enabled" value="1" <?php checked( ! empty( $settings['order_enabled'] ) ); ?>>
			وقتی وضعیت سفارش مشتری تغییر می‌کند، خودکار برایش اعلان ارسال شود
		</label>
		<p class="bbp-muted">در متن‌ها می‌توانید از <code>{name}</code> (نام مشتری)، <code>{order}</code> (شمارهٔ سفارش) و <code>{status}</code> (وضعیت سفارش) استفاده کنید. با زدن روی اعلان، صفحهٔ همان سفارش باز می‌شود.</p>

		<?php if ( ! $statuses ) : ?>
			<p>ووکامرس فعال نیست.</p>
		<?php endif; ?>

		<div class="bbp-statuses">
		<?php
		foreach ( $statuses as $key => $label ) :
			$status = substr( $key, 3 ); // strip "wc-"
			$t      = isset( $settings['orders'][ $status ] ) ? $settings['orders'][ $status ]
				: ( isset( $defaults[ $status ] ) ? $defaults[ $status ] : array( 'on' => 0, 'title' => 'وضعیت سفارش شما: {status}', 'body' => '{name} عزیز، وضعیت سفارش #{order} به «{status}» تغییر کرد.' ) );
			$name   = 'orders[' . $status . ']';
			?>
			<div class="bbp-status">
				<label class="bbp-switch">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on]" value="1" <?php checked( ! empty( $t['on'] ) ); ?>>
					<strong><?php echo esc_html( $label ); ?></strong>
				</label>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $t['title'] ); ?>" placeholder="عنوان">
				<textarea name="<?php echo esc_attr( $name ); ?>[body]" rows="2" placeholder="متن"><?php echo esc_textarea( $t['body'] ); ?></textarea>
			</div>
		<?php endforeach; ?>
		</div>

		<button type="submit" class="button button-primary bbp-submit">ذخیرهٔ تنظیمات</button>
	</form>
	<?php
}
