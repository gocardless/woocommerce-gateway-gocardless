<?php
/**
 * GoCardless Addons.
 *
 * @package WC_GoCardless_Gateway
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_GoCardless_Gateway_Addons class.
 *
 * @extends WC_GoCardless_Gateway
 */
class WC_GoCardless_Gateway_Addons extends WC_GoCardless_Gateway {
	use WC_GoCardless_Gateway_Subscriptions_Pre_Orders_Trait;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		$this->register_hooks();
	}
}
