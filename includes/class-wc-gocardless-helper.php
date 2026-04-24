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
}
