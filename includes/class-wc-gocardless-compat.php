<?php
/**
 * GoCardless Compat
 *
 * @package WC_GoCardless_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility class for WooCommerce Custom Order Tables
 *
 * @since 2.4.20
 */
class WC_GoCardless_Compat {

	/**
	 * Helper function to get whether custom order tables are enabled or not.
	 *
	 * @return bool
	 */
	public static function is_cot_enabled() {
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}
}
