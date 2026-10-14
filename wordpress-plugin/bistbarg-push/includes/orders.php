<?php
/**
 * Automatic notification to the customer when their order status changes.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_order_status_changed', 'bbp_on_order_status_changed', 10, 4 );

function bbp_on_order_status_changed( $order_id, $from, $to, $order ) {
	$settings = bbp_settings();
	if ( empty( $settings['order_enabled'] ) || ! $order instanceof WC_Order ) {
		return;
	}
	$customer_id = (int) $order->get_customer_id();
	if ( ! $customer_id ) {
		return; // Guest orders can't be matched to an app user.
	}
	$template = isset( $settings['orders'][ $to ] ) ? $settings['orders'][ $to ] : null;
	if ( ! $template || empty( $template['on'] ) ) {
		return;
	}

	$name   = trim( $order->get_billing_first_name() );
	$tokens = array(
		'{name}'   => '' !== $name ? $name : 'مشتری',
		'{order}'  => $order->get_order_number(),
		'{status}' => wc_get_order_status_name( $to ),
	);

	bbp_add_notification(
		array(
			'title'        => strtr( $template['title'], $tokens ),
			'body'         => strtr( $template['body'], $tokens ),
			'url'          => $order->get_view_order_url(),
			'audience'     => 'user',
			'user_id'      => $customer_id,
			'source'       => 'order',
			'expires_days' => 14,
		)
	);
}
