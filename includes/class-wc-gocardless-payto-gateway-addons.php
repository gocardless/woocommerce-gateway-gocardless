<?php
/**
 * GoCardless PayTo gateway with Subscriptions and Pre-Orders support.
 *
 * @package WC_GoCardless_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayTo payment gateway for stores using WooCommerce Subscriptions and/or Pre-Orders.
 *
 * @class WC_GoCardless_PayTo_Gateway_Addons
 * @extends WC_GoCardless_PayTo_Gateway
 */
class WC_GoCardless_PayTo_Gateway_Addons extends WC_GoCardless_PayTo_Gateway {
	use WC_GoCardless_Gateway_Subscriptions_Pre_Orders_Trait;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->register_hooks();
	}
}
