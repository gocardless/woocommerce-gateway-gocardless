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
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );

			// Allow store managers to manually set GoCardless as the payment method on a subscription.
			add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
			add_action( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
		}

		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_payment_for_released_pre_order' ) );
		}
	}

	/**
	 * Update GoCardless resource in order meta.
	 *
	 * Overrides here so that GoCardless related meta updated in subscriptions
	 * and renewal orders.
	 *
	 * @since 2.4.0
	 *
	 * @param int|WC_Order $order         Order or Order ID.
	 * @param string       $resource_type GoCardless resource type ('payment', 'refund' etc)
	 *                                    in singular noun.
	 * @param array        $resource_data Resource data.
	 */
	public function update_order_resource( $order, $resource_type, $resource_data = array() ) {
		parent::update_order_resource( $order, $resource_type, $resource_data );

		// Also store it on the subscriptions being purchased or paid for in the order.
		$subscriptions = array();
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		}

		foreach ( $subscriptions as $subscription ) {
			parent::update_order_resource( wc_gocardless_get_order_prop( $subscription, 'id' ), $resource_type, $resource_data );
		}
	}

	/**
	 * Process payment for subscription.
	 *
	 * @since 2.4.0
	 *
	 * @throws \Exception Exception.
	 *
	 * @param float    $amount_to_charge Amount to charge.
	 * @param WC_Order $order            The most recent order that relates to
	 *                                   current subscription, can be renewal
	 *                                   order or initial order.
	 */
	protected function _process_subscription_payment( $amount_to_charge, $order ) {
		$order_id   = wc_gocardless_get_order_prop( $order, 'id' );
		$mandate_id = $this->get_order_resource( $order_id, 'mandate', 'id' );

		wc_gocardless()->log( sprintf( '%s - Creating subscription payment for order #%s', __METHOD__, $order->get_order_number() ) );

		add_filter( 'woocommerce_gocardless_payment_description', array( $this, 'payment_description_for_subscription' ) );

		$this->_maybe_create_payment( $order_id, $mandate_id, $amount_to_charge );

		remove_filter( 'woocommerce_gocardless_payment_description', array( $this, 'payment_description_for_subscription' ) );
	}

	/**
	 * Payment description for subscription payment.
	 *
	 * @since 2.4.0
	 *
	 * @param string $description Description.
	 *
	 * @return string Description.
	 */
	public function payment_description_for_subscription( $description ) {
		/* translators: Wrap Description with "Subscription payment from:" */
		$description = sprintf( esc_html__( 'Subscription payment from: %s', 'woocommerce-gateway-gocardless' ), $description );
		return $description;
	}

	/**
	 * Process payment when a subscription payment is due.
	 *
	 * @since 2.4.0
	 *
	 * @param float    $amount_to_charge Amount to charge.
	 * @param WC_Order $order            The most recent order that relates to
	 *                                   current subscription, can be renewal
	 *                                   order or initial order.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $order ) {
		try {
			$this->_process_subscription_payment( $amount_to_charge, $order );
		} catch ( Exception $e ) {
			/* translators: Error message */
			$order->update_status( 'failed', sprintf( esc_html__( 'Failed to create payment via GoCardless: %s', 'woocommerce-gateway-gocardless' ), $e->getMessage() ) );
		}
	}

	/**
	 * Update mandate for a subscription, after using GoCardless to complete a
	 * payment, to make up for an automatic renewal payment which previously
	 * failed.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Subscription $subscription  The subscription for which the failing
	 *                                       payment method relates.
	 * @param WC_Order        $renewal_order The order which recorded the successful
	 *                                       payment (to make up for the failed
	 *                                       automatic payment).
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {
		$renewal_order_id = wc_gocardless_get_order_prop( $renewal_order, 'id' );
		$this->update_order_resource( $subscription, 'mandate', $this->get_order_resource( $renewal_order_id, 'mandate' ) );
	}

	/**
	 * Checks whether given order_id is a pre-order.
	 *
	 * @since 2.4.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return boolean Returns true if pre-order.
	 */
	protected function _is_pre_order( $order_id ) {
		return ( class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Order::order_contains_pre_order( $order_id ) );
	}

	/**
	 * Checks whether pre-order charges upon release.
	 *
	 * @since 2.4.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool Returns true if pre-order charges upon release
	 */
	protected function _is_pre_order_charges_upon_release( $order_id ) {
		return (
			$this->_is_pre_order( $order_id )
			&&
			WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id )
		);
	}

	/**
	 * Process pre-order.
	 *
	 * This only process pre-order without taking payment.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array Checkout response.
	 */
	protected function _process_pre_order( WC_Order $order ) {
		$order_id = wc_gocardless_get_order_prop( $order, 'id' );

		wc_reduce_stock_levels( $order_id );

		if ( function_exists( 'wc_empty_cart' ) ) {
			wc_empty_cart();
		}

		WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * For checkout with saved token, if the order is pre-order that charges
	 * upon release and needs tokenization then save the token in order for
	 * taking payment later.
	 *
	 * @since 2.4.0
	 *
	 * @throws \Exception Exception.
	 *
	 * @param WC_Order $order Order object.
	 */
	protected function _process_payment_with_saved_token( WC_Order $order ) {
		$order_id = wc_gocardless_get_order_prop( $order, 'id' );
		if ( $this->_is_pre_order_charges_upon_release( $order_id ) ) {
			//phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verification is already handled on the WooCommerce side.
			if ( isset( $_POST['wc-gocardless-payment-token'] ) ) {
				$token_id = wc_clean( wp_unslash( $_POST['wc-gocardless-payment-token'] ) );
			} else {
				$token_id = false;
			}
			//phpcs:enable WordPress.Security.NonceVerification.Missing -- Nonce verification is already handled on the WooCommerce side.
			$token = WC_Payment_Tokens::get( $token_id );
			if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
				throw new Exception( esc_html__( 'Invalid payment method. Please setup a new direct debit account.', 'woocommerce-gateway-gocardless' ) );
			}

			$mandate_id = $token->get_token();
			$mandate    = WC_GoCardless_API::get_mandate( $mandate_id );
			if ( is_wp_error( $mandate ) ) {
				throw new Exception( esc_html__( 'Failed to retrieve mandate.', 'woocommerce-gateway-gocardless' ) );
			}
			$this->update_order_resource( $order, 'mandate', $mandate['mandates'] );

			return $this->_process_pre_order( $order );
		}

		return parent::_process_payment_with_saved_token( $order );
	}

	/**
	 * For checkout with redirect flow, and right after mandate is created,
	 * if the order being processed is pre-order that charges upon release,
	 * don't take payment immediately.
	 *
	 * @since 2.4.0
	 *
	 * @throws \Exception Exception.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $mandate_id Mandate ID.
	 */
	protected function _after_mandate_created( $order_id, $mandate_id ) {
		if ( $this->_is_pre_order_charges_upon_release( $order_id ) ) {
			return $this->_process_pre_order( wc_get_order( $order_id ) );
		}
		return parent::_after_mandate_created( $order_id, $mandate_id );
	}

	/**
	 * Process payment when pre-order is released.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Order $order Order object.
	 */
	public function process_payment_for_released_pre_order( $order ) {
		$order_id   = wc_gocardless_get_order_prop( $order, 'id' );
		$mandate_id = $this->get_order_resource( $order_id, 'mandate', 'id' );

		wc_gocardless()->log( sprintf( '%s - Creating payment for pre-order #%s', __METHOD__, $order->get_order_number() ) );

		try {
			$this->_maybe_create_payment( $order_id, $mandate_id );
		} catch ( Exception $e ) {
			$order->update_status( 'failed' );
			/* translators: placeholder is error message from GoCardless */
			$order->add_order_note( sprintf( esc_html__( 'Unable to create payment: %s', 'woocommerce-gateway-gocardless' ), $e->getMessage() ) );
		}
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
	 *
	 * @param array           $payment_meta associative array of meta data required for automatic payments.
	 * @param WC_Subscription $subscription An instance of a subscription object.
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$subscription_id = $subscription->get_id();

		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				'_gocardless_mandate_id' => array(
					'value' => $subscription->get_meta( '_gocardless_mandate_id', true ),
					'label' => esc_html__( 'GoCardless Mandate ID', 'woocommerce-gateway-gocardless' ),
				),
			),
		);

		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
	 *
	 * @param string $payment_method_id The ID of the payment method to validate.
	 * @param array  $payment_meta      Associative array of meta data required for automatic payments.
	 *
	 * @throws Exception Validation Error.
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
		if ( $this->id === $payment_method_id ) {
			if ( ! isset( $payment_meta['post_meta']['_gocardless_mandate_id']['value'] ) || empty( $payment_meta['post_meta']['_gocardless_mandate_id']['value'] ) ) {
				throw new Exception( esc_html__( 'A "GoCardless Mandate ID" value is required.', 'woocommerce-gateway-gocardless' ) );
			}

			if ( 0 !== strpos( $payment_meta['post_meta']['_gocardless_mandate_id']['value'], 'MD' ) ) {
				throw new Exception( esc_html__( 'Invalid GoCardless Mandate ID. A valid "GoCardless Mandate ID" must begin with "MD".', 'woocommerce-gateway-gocardless' ) );
			}
		}
	}
}
