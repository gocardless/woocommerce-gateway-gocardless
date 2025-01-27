<?php
/**
 * GoCardless Privacy
 *
 * @package WooCommerce_Gateway_GoCardless
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}

/**
 * GoCardless Privacy
 */
class WC_GoCardless_Privacy extends WC_Abstract_Privacy {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( __( 'Direct Debit (GoCardless)', 'woocommerce-gateway-gocardless' ) );

		$this->add_exporter( 'woocommerce-gateway-gocardless-order-data', __( 'WooCommerce GoCardless Order Data', 'woocommerce-gateway-gocardless' ), array( $this, 'order_data_exporter' ) );

		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			$this->add_exporter( 'woocommerce-gateway-gocardless-subscriptions-data', __( 'WooCommerce GoCardless Subscriptions Data', 'woocommerce-gateway-gocardless' ), array( $this, 'subscriptions_data_exporter' ) );
		}

		$this->add_eraser( 'woocommerce-gateway-gocardless-order-data', __( 'WooCommerce GoCardless Data', 'woocommerce-gateway-gocardless' ), array( $this, 'order_data_eraser' ) );
	}

	/**
	 * Returns a list of orders that are using one of GoCardless's payment methods.
	 *
	 * @param string $email_address Customer email.
	 * @param int    $page Page number.
	 *
	 * @return array WP_Post
	 */
	protected function get_gocardless_orders( $email_address, $page ) {
		$user = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.

		$order_query = array(
			'payment_method' => 'gocardless',
			'limit'          => 10,
			'page'           => $page,
		);

		if ( $user instanceof WP_User ) {
			$order_query['customer_id'] = (int) $user->ID;
		} else {
			$order_query['billing_email'] = $email_address;
		}

		return wc_get_orders( $order_query );
	}

	/**
	 * Gets the message of the privacy to display.
	 */
	public function get_privacy_message() {
		/* translators: Link to documentation */
		return wp_kses_post( wpautop( sprintf( __( 'By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>', 'woocommerce-gateway-gocardless' ), 'https://docs.woocommerce.com/document/privacy-payments/#woocommerce-gateway-gocardless' ) ) );
	}

	/**
	 * Handle exporting data for Orders.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();

		$orders = $this->get_gocardless_orders( $email_address, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => esc_attr__( 'Orders', 'woocommerce-gateway-gocardless' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => esc_attr__( 'Gocardless payment id', 'woocommerce-gateway-gocardless' ),
							'value' => $order->get_meta( '_gocardless_payment_id', true ),
						),
						array(
							'name'  => esc_attr__( 'Gocardless mandate id', 'woocommerce-gateway-gocardless' ),
							'value' => $order->get_meta( '_gocardless_mandate_id', true ),
						),
						array(
							'name'  => esc_attr__( 'Gocardless refund id', 'woocommerce-gateway-gocardless' ),
							'value' => $order->get_meta( '_gocardless_refund_id', true ),
						),
					),
				);
			}

			$done = 10 > count( $orders );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Handle exporting data for Subscriptions.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function subscriptions_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$page           = (int) $page;
		$data_to_export = array();

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_payment_method',
				'value'   => 'gocardless',
				'compare' => '=',
			),
			array(
				'key'     => '_billing_email',
				'value'   => $email_address,
				'compare' => '=',
			),
		);

		$subscription_query = array(
			'posts_per_page' => 10,
			'page'           => $page,
			'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		$subscriptions = wcs_get_subscriptions( $subscription_query );

		$done = true;

		if ( 0 < count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_subscriptions',
					'group_label' => esc_attr__( 'Subscriptions', 'woocommerce-gateway-gocardless' ),
					'item_id'     => 'subscription-' . $subscription->get_id(),
					'data'        => array(
						array(
							'name'  => esc_attr__( 'Gocardless payment id', 'woocommerce-gateway-gocardless' ),
							'value' => $subscription->get_meta( '_gocardless_payment_id', true ),
						),
						array(
							'name'  => esc_attr__( 'Gocardless mandate id', 'woocommerce-gateway-gocardless' ),
							'value' => $subscription->get_meta( '_gocardless_mandate_id', true ),
						),
						array(
							'name'  => esc_attr__( 'Gocardless refund id', 'woocommerce-gateway-gocardless' ),
							'value' => $subscription->get_meta( '_gocardless_refund_id', true ),
						),
					),
				);
			}

			$done = 10 > count( $subscriptions );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Finds and erases order data by email address.
	 *
	 * @since 3.4.0
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs
	 */
	public function order_data_eraser( $email_address, $page ) {
		$orders = $this->get_gocardless_orders( $email_address, (int) $page );

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		foreach ( (array) $orders as $order ) {
			$order = wc_get_order( $order->get_id() );

			list( $removed, $retained, $msgs ) = $this->maybe_handle_order( $order );
			$items_removed                    |= $removed;
			$items_retained                   |= $retained;
			$messages                          = array_merge( $messages, $msgs );

			list( $removed, $retained, $msgs ) = $this->maybe_handle_subscription( $order );
			$items_removed                    |= $removed;
			$items_retained                   |= $retained;
			$messages                          = array_merge( $messages, $msgs );
		}

		// Tell core if we have more orders to work on still.
		$done = count( $orders ) < 10;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Handle eraser of data tied to Subscriptions
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	protected function maybe_handle_subscription( $order ) {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return array( false, false, array() );
		}

		if ( ! wcs_order_contains_subscription( $order ) ) {
			return array( false, false, array() );
		}

		$subscription = current( wcs_get_subscriptions_for_order( $order->get_id() ) );

		$gocardless_mandate_id = $subscription->get_meta( '_gocardless_mandate_id', true );

		if ( empty( $gocardless_mandate_id ) ) {
			return array( false, false, array() );
		}

		if ( $subscription->has_status( apply_filters( 'wc_gocardless_privacy_eraser_subs_statuses', array( 'on-hold', 'active' ) ) ) ) {
			/* translators: Subscription order ID. */
			return array( false, true, array( sprintf( esc_html__( 'Order ID %d contains an active Subscription', 'woocommerce-gateway-gocardless' ), $order->get_id() ) ) );
		}

		$renewal_orders = WC_Subscriptions_Renewal_Order::get_renewal_orders( $order->get_id() );

		foreach ( $renewal_orders as $renewal_order_id ) {
			$renewal_order = wc_get_order( $renewal_order_id );
			if ( empty( $renewal_order ) ) {
				continue;
			}
			$renewal_order->delete_meta_data( '_gocardless_webhook_events' );
			$renewal_order->delete_meta_data( '_gocardless_redirect_flow' );
			$renewal_order->delete_meta_data( '_gocardless_redirect_flow_id' );
			$renewal_order->delete_meta_data( '_gocardless_billing_request' );
			$renewal_order->delete_meta_data( '_gocardless_billing_request_id' );
			$renewal_order->delete_meta_data( '_gocardless_billing_request_flow' );
			$renewal_order->delete_meta_data( '_gocardless_billing_request_flow_id' );
			$renewal_order->delete_meta_data( '_gocardless_mandate' );
			$renewal_order->delete_meta_data( '_gocardless_mandate_id' );
			$renewal_order->delete_meta_data( '_gocardless_payment' );
			$renewal_order->delete_meta_data( '_gocardless_payment_id' );
			$renewal_order->delete_meta_data( '_gocardless_payment_status' );
			$renewal_order->delete_meta_data( '_gocardless_refund' );
			$renewal_order->delete_meta_data( '_gocardless_refund_id' );
			$renewal_order->save_meta_data();
		}

		$subscription->delete_meta_data( '_gocardless_webhook_events' );
		$subscription->delete_meta_data( '_gocardless_redirect_flow' );
		$subscription->delete_meta_data( '_gocardless_redirect_flow_id' );
		$subscription->delete_meta_data( '_gocardless_billing_request' );
		$subscription->delete_meta_data( '_gocardless_billing_request_id' );
		$subscription->delete_meta_data( '_gocardless_billing_request_flow' );
		$subscription->delete_meta_data( '_gocardless_billing_request_flow_id' );
		$subscription->delete_meta_data( '_gocardless_mandate' );
		$subscription->delete_meta_data( '_gocardless_mandate_id' );
		$subscription->delete_meta_data( '_gocardless_payment' );
		$subscription->delete_meta_data( '_gocardless_payment_id' );
		$subscription->delete_meta_data( '_gocardless_payment_status' );
		$subscription->delete_meta_data( '_gocardless_refund' );
		$subscription->delete_meta_data( '_gocardless_refund_id' );
		$subscription->save_meta_data();

		return array( true, false, array( esc_attr__( 'GoCardless Subscriptions Data Erased.', 'woocommerce-gateway-gocardless' ) ) );
	}

	/**
	 * Handle eraser of data tied to Orders
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected function maybe_handle_order( $order ) {

		$gocardless1  = $order->get_meta( '_gocardless_webhook_events', true );
		$gocardless2  = $order->get_meta( '_gocardless_redirect_flow', true );
		$gocardless3  = $order->get_meta( '_gocardless_redirect_flow_id', true );
		$gocardless4  = $order->get_meta( '_gocardless_mandate', true );
		$gocardless5  = $order->get_meta( '_gocardless_mandate_id', true );
		$gocardless6  = $order->get_meta( '_gocardless_payment', true );
		$gocardless7  = $order->get_meta( '_gocardless_payment_id', true );
		$gocardless8  = $order->get_meta( '_gocardless_payment_status', true );
		$gocardless9  = $order->get_meta( '_gocardless_refund', true );
		$gocardless0  = $order->get_meta( '_gocardless_refund_id', true );
		$gocardless11 = $order->get_meta( '_gocardless_billing_request', true );
		$gocardless12 = $order->get_meta( '_gocardless_billing_request_id', true );
		$gocardless13 = $order->get_meta( '_gocardless_billing_request_flow', true );
		$gocardless14 = $order->get_meta( '_gocardless_billing_request_flow_id', true );

		if ( empty( $gocardless1 ) && empty( $gocardless2 ) && empty( $gocardless3 ) &&
			empty( $gocardless4 ) && empty( $gocardless5 ) && empty( $gocardless6 ) &&
			empty( $gocardless7 ) && empty( $gocardless8 ) && empty( $gocardless9 ) && empty( $gocardless0 ) && empty( $gocardless11 ) && empty( $gocardless12 ) && empty( $gocardless13 ) && empty( $gocardless14 ) ) {
			return array( false, false, array() );
		}

		$order->delete_meta_data( '_gocardless_webhook_events' );
		$order->delete_meta_data( '_gocardless_redirect_flow' );
		$order->delete_meta_data( '_gocardless_redirect_flow_id' );
		$order->delete_meta_data( '_gocardless_billing_request' );
		$order->delete_meta_data( '_gocardless_billing_request_id' );
		$order->delete_meta_data( '_gocardless_billing_request_flow' );
		$order->delete_meta_data( '_gocardless_billing_request_flow_id' );
		$order->delete_meta_data( '_gocardless_mandate' );
		$order->delete_meta_data( '_gocardless_mandate_id' );
		$order->delete_meta_data( '_gocardless_payment' );
		$order->delete_meta_data( '_gocardless_payment_id' );
		$order->delete_meta_data( '_gocardless_payment_status' );
		$order->delete_meta_data( '_gocardless_refund' );
		$order->delete_meta_data( '_gocardless_refund_id' );
		$order->save_meta_data();

		return array( true, false, array( esc_attr__( 'GoCardless Order Data Erased.', 'woocommerce-gateway-gocardless' ) ) );
	}
}

new WC_GoCardless_Privacy();
