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
