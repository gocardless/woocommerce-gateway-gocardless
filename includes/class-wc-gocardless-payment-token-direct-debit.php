<?php
/**
 * GoCardless Direct Debit Payment Token
 *
 * @package WooCommerce_Gateway_GoCardless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Direct Debit Payment Token.
 *
 * Representation of a payment token for direct debit within the Bacs system.
 *
 * @class    WC_GoCardless_Payment_Token_Direct_Debit
 * @since    2.4.0
 */
class WC_GoCardless_Payment_Token_Direct_Debit extends WC_Payment_Token {
	/**
	 * Type of token.
	 *
	 * This should matches with suffix of class name.
	 *
	 * @since 2.4.0
	 *
	 * @var string
	 */
	protected $type = 'Direct_Debit';

	/**
	 * Validate direct debit payment tokens.
	 *
	 * @since 2.4.0
	 *
	 * @return boolean True if the passed data is valid
	 */
	public function validate() {
		if ( false === parent::validate() ) {
			return false;
		}

		if ( ! $this->get_scheme() ) {
			return false;
		}

		if ( ! $this->get_bank_name() ) {
			return false;
		}

		if ( ! $this->get_account_holder_name() ) {
			return false;
		}

		if ( ! $this->get_account_number_ending() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get display name for user.
	 *
	 * @since 2.4.0
	 *
	 * @param string $deprecated Context (deprecated).
	 *
	 * @return string
	 */
	public function get_display_name( $deprecated = '' ) {
		$display = sprintf(
			/* translators: 1: bank name 2: last two digits of account number */
			__( '%1$s ending in %2$s', 'woocommerce-gateway-gocardless' ),
			$this->get_bank_name(),
			$this->get_account_number_ending()
		);
		return $display;
	}

	/**
	 * Get direct debit scheme (autogiro, bacs, ...).
	 *
	 * @since 2.4.0
	 *
	 * @return string Scheme
	 */
	public function get_scheme() {
		return $this->get_meta( 'scheme' );
	}

	/**
	 * Set the direct debit scheme (mastercard, visa, ...).
	 *
	 * @since 2.4.0
	 *
	 * @param string $scheme Scheme (mastercard, visa, ...).
	 */
	public function set_scheme( $scheme ) {
		$this->add_meta_data( 'scheme', $scheme, true );
	}


	/**
	 * Get name of the account holder, as known by the bank.
	 *
	 * @since 2.4.0
	 *
	 * @return string Name of the account holder
	 */
	public function get_account_holder_name() {
		return $this->get_meta( 'account_holder_name' );
	}

	/**
	 * Set name of the account holder, as known by the bank.
	 *
	 * @since 2.4.0
	 *
	 * @param string $name Name of the account holder.
	 */
	public function set_account_holder_name( $name ) {
		$this->add_meta_data( 'account_holder_name', $name, true );
	}

	/**
	 * Get last two digits of account number.
	 *
	 * @since 2.4.0
	 *
	 * @return string Last two digits of account number
	 */
	public function get_account_number_ending() {
		return $this->get_meta( 'account_number_ending' );
	}

	/**
	 * Set the last two digits of account number.
	 *
	 * @since 2.4.0
	 *
	 * @param string $number Last two digits of account number.
	 */
	public function set_account_number_ending( $number ) {
		return $this->add_meta_data( 'account_number_ending', $number, true );
	}

	/**
	 * Get bank name.
	 *
	 * @since 2.4.0
	 *
	 * @return string Bank name
	 */
	public function get_bank_name() {
		$bank_name = sprintf(
			/* translators: 1: bank name */
			__( 'Direct Debit Mandate - %1$s', 'woocommerce-gateway-gocardless' ),
			$this->get_meta( 'bank_name' )
		);
		return $bank_name;
	}

	/**
	 * Set the bank name.
	 *
	 * @since 2.4.0
	 *
	 * @param string $name Bank name.
	 * @return string Last two digits of account number
	 */
	public function set_bank_name( $name ) {
		return $this->add_meta_data( 'bank_name', $name, true );
	}
}
