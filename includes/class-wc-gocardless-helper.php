<?php
/**
 * GoCardless helper utilities.
 *
 * @package WooCommerce_Gateway_GoCardless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for the GoCardless gateway plugin.
 */
class WC_GoCardless_Helper {

	/**
	 * Registered payment gateway instances from WooCommerce.
	 *
	 * @return array
	 */
	private static function get_payment_gateways_map() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		return WC()->payment_gateways->payment_gateways();
	}

	/**
	 * Bank pay (main) GoCardless gateway instance.
	 *
	 * @since x.x.x
	 *
	 * @return WC_GoCardless_Gateway|WC_GoCardless_Gateway_Addons|bool
	 */
	public static function get_main_gateway() {
		$gateways = self::get_payment_gateways_map();

		return ! empty( $gateways['gocardless'] ) ? $gateways['gocardless'] : false;
	}

	/**
	 * PayTo GoCardless gateway instance when registered.
	 *
	 * @since x.x.x
	 *
	 * @return WC_GoCardless_PayTo_Gateway|WC_GoCardless_PayTo_Gateway_Addons|bool
	 */
	public static function get_payto_gateway() {
		$gateways = self::get_payment_gateways_map();

		return ! empty( $gateways['gocardless_payto'] ) ? $gateways['gocardless_payto'] : false;
	}

	/**
	 * Whether the order uses a GoCardless payment method (Bank pay or PayTo).
	 *
	 * @since x.x.x
	 *
	 * @param WC_Order|mixed $order Order object.
	 * @return bool
	 */
	public static function is_gocardless_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return in_array( $order->get_payment_method( 'edit' ), array( 'gocardless', 'gocardless_payto' ), true );
	}

	/**
	 * Resolve which GoCardless gateway should handle an order (Bank pay vs PayTo).
	 *
	 * @since x.x.x
	 *
	 * @param WC_Order|mixed $order Order object.
	 * @return WC_GoCardless_Gateway|WC_GoCardless_Gateway_Addons|WC_GoCardless_PayTo_Gateway|WC_GoCardless_PayTo_Gateway_Addons|bool
	 */
	public static function get_gateway_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return self::get_main_gateway();
		}

		if ( 'gocardless_payto' !== $order->get_payment_method( 'edit' ) ) {
			return self::get_main_gateway();
		}

		$payto = self::get_payto_gateway();

		return $payto ? $payto : self::get_main_gateway();
	}


	/**
	 * Choose Bank pay or PayTo gateway for async webhook handling from the related order.
	 *
	 * @since x.x.x
	 *
	 * @param array $payload Webhook payload (single event per scheduled action).
	 * @return WC_GoCardless_Gateway|WC_GoCardless_PayTo_Gateway|bool
	 */
	public static function resolve_gateway_for_webhook( array $payload ) {
		$main = self::get_main_gateway();
		if ( ! $main ) {
			return false;
		}

		// We will always have a single event per scheduled action.
		if ( empty( $payload['events'][0] ) ) {
			return $main;
		}

		$order = self::get_order_for_webhook_event( $main, $payload['events'][0] );
		if ( $order instanceof WC_Order ) {
			return self::get_gateway_for_order( $order );
		}

		return $main;
	}

	/**
	 * Resolve a WooCommerce order from a GoCardless webhook event when possible.
	 *
	 * Mandate and legacy subscription events are handled on the main gateway because they do not
	 * tie to a single order via payment/refund/billing_request meta in a reliable way.
	 *
	 * @since x.x.x
	 *
	 * @param WC_GoCardless_Gateway $gateway Gateway instance (used for get_order_from_resource).
	 * @param array                 $event   Single event from the webhook payload.
	 * @return WC_Order|bool
	 */
	public static function get_order_for_webhook_event( $gateway, array $event ) {
		$resource_type = isset( $event['resource_type'] ) ? $event['resource_type'] : '';
		$links         = isset( $event['links'] ) && is_array( $event['links'] ) ? $event['links'] : array();

		switch ( $resource_type ) {
			case 'payments':
				if ( ! empty( $links['payment'] ) ) {
					return $gateway->get_order_from_resource( 'payment', 'id', $links['payment'] );
				}
				break;
			case 'refunds':
				if ( ! empty( $links['refund'] ) ) {
					return $gateway->get_order_from_resource( 'refund', 'id', $links['refund'] );
				}
				break;
			case 'billing_requests':
				if ( ! empty( $links['billing_request'] ) ) {
					return $gateway->get_order_from_resource( 'billing_request', 'id', $links['billing_request'] );
				}
				break;
		}

		return false;
	}
}
