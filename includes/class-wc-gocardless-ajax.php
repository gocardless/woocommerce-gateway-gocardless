<?php
/**
 * WC_GoCardless_Ajax class.
 *
 * @package WC_GoCardless
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper for GoCardless API.
 * Handles ajax requests for GoCardless payment gateway.
 *
 * @since 2.7.0
 */
class WC_GoCardless_Ajax {

	/**
	 * GoCardless gateway instance.
	 *
	 * @var WC_Gateway_GoCardless
	 */
	protected $gateway;

	/**
	 * Class Initialization.
	 *
	 * @return void
	 */
	public function init() {
		// Ajax handler for billing request flow.
		add_action( 'wc_ajax_gocardless_create_billing_request_flow', array( $this, 'ajax_create_billing_request_flow' ) );
		add_action( 'wc_ajax_gocardless_complete_billing_request_flow', array( $this, 'ajax_complete_billing_request_flow' ) );
		add_action( 'wc_ajax_gocardless_regenerate_webhook_secret', array( $this, 'ajax_regenerate_webhook_secret' ) );
	}

	/**
	 * Returns an instantiated gateway.
	 *
	 * @since 2.7.0
	 * @return WC_Gateway_GoCardless
	 */
	protected function get_gateway() {
		if ( ! isset( $this->gateway ) ) {
			$gateways      = WC()->payment_gateways()->payment_gateways();
			$this->gateway = $gateways['gocardless'];
		}

		return $this->gateway;
	}


	/**
	 * Create a billing request and billing request flow to process the payment.
	 *
	 * This method creates a billing request and billing request flow to process the payment.
	 *
	 * @see https://developer.gocardless.com/api-reference/#billing-requests-billing-requests
	 * @see https://developer.gocardless.com/api-reference/#billing-requests-billing-request-flows
	 *
	 * @since 2.7.0
	 */
	public function ajax_create_billing_request_flow() {
		check_ajax_referer( 'wc_gocardless_create_billing_request_flow', 'security' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid order.', 'woocommerce-gateway-gocardless' ) ) );
		}

		$gateway              = $this->get_gateway();
		$billing_request_flow = $gateway->create_billing_request_flow( $order );
		if ( 'success' === $billing_request_flow['result'] ) {
			$billing_request_flow_id = $billing_request_flow['billing_request_flow_id'];
			wp_send_json_success( array( 'billing_request_flow_id' => $billing_request_flow_id ) );
		} else {
			$message = $billing_request_flow['message'] ?? esc_html__( 'Failed to create billing request flow.', 'woocommerce-gateway-gocardless' );
			wp_send_json_error( array( 'message' => $message ) );
		}
	}

	/**
	 * Complete the billing request flow.
	 *
	 * @since 2.7.0
	 */
	public function ajax_complete_billing_request_flow() {
		check_ajax_referer( 'wc_gocardless_complete_billing_request_flow', 'security' );
		$order_id           = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$billing_request_id = isset( $_POST['billing_request_id'] ) ? wc_clean( wp_unslash( $_POST['billing_request_id'] ) ) : '';

		// Maybe save customer token.
		$gateway             = $this->get_gateway();
		$save_bank_accounts  = 'yes' === $gateway->get_option( 'saved_bank_accounts', 'yes' );
		$save_customer_token = (
			! empty( $_POST['save_customer_token'] ) &&
			'yes' === wc_clean( wp_unslash( $_POST['save_customer_token'] ) ) &&
			get_current_user_id() &&
			$save_bank_accounts
		);

		if ( ! $order_id || ! $billing_request_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid request.', 'woocommerce-gateway-gocardless' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Order not found.', 'woocommerce-gateway-gocardless' ) ) );
		}

		wc_gocardless()->log( sprintf( '%s - GoCardless billing request fulfilled ajax call: "%s" and order ID %s', __METHOD__, $billing_request_id, $order_id ) );

		$response = $gateway->handle_billing_request_complete( $order, $billing_request_id, $save_customer_token );
		if ( 'success' === $response['result'] ) {
			wp_send_json_success( array( 'redirect_url' => $response['redirect'] ) );
		} else {
			$message = ! empty( $response['message'] ) ? $response['message'] : esc_html__( 'Failed to process billing request.', 'woocommerce-gateway-gocardless' );
			wp_send_json_error( array( 'message' => $message ) );
		}
	}

	/**
	 * Regenerate the webhook secret.
	 *
	 * @since 2.7.1
	 */
	public function ajax_regenerate_webhook_secret() {
		check_ajax_referer( 'wc_gocardless_regenerate_webhook_secret', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'woocommerce-gateway-gocardless' ) ) );
		}

		$gateway        = $this->get_gateway();
		$webhook_secret = $gateway->generate_webhook_secret();

		if ( $webhook_secret ) {
			// Update the webhook secret in the gateway settings.
			$gateway->update_option( 'webhook_secret', $webhook_secret );

			wp_send_json_success( array( 'webhook_secret' => $webhook_secret ) );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to regenerate webhook secret.', 'woocommerce-gateway-gocardless' ) ) );
		}
	}
}
