<?php
/**
 * GoCardless PayTo Payment Token
 *
 * @package WooCommerce_Gateway_GoCardless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce PayTo Payment Token.
 *
 * Representation of a saved PayTo agreement (mandate) for Australian bank payments.
 * Shares behaviour and meta with {@see WC_GoCardless_Payment_Token_Direct_Debit}; differs by token type and display label.
 *
 * @class WC_Payment_Token_GoCardless_PayTo
 * @since 3.0.0
 */
class WC_Payment_Token_GoCardless_PayTo extends WC_GoCardless_Payment_Token_Direct_Debit {

	/**
	 * Type of token.
	 *
	 * Stored in the database; WooCommerce resolves class `WC_Payment_Token_{type}`.
	 *
	 * @since 3.0.0
	 *
	 * @var string
	 */
	protected $type = 'GoCardless_PayTo';

	/**
	 * Get bank name for display (PayTo label instead of Direct Debit Mandate).
	 *
	 * @since 3.0.0
	 *
	 * @return string Bank name with PayTo prefix.
	 */
	public function get_bank_name() {
		return sprintf(
			/* translators: 1: bank name from GoCardless */
			__( 'PayTo Agreement - %1$s', 'woocommerce-gateway-gocardless' ),
			$this->get_meta( 'bank_name' )
		);
	}
}
