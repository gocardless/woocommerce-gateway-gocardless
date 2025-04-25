<?php
/**
 * Wrapper for GoCardless API.
 *
 * @package WC_GoCardless
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper for GoCardless API.
 *
 * @since 2.4.0
 */
class WC_GoCardless_API {

	const BASE_URL         = 'https://api.gocardless.com/';
	const SANDBOX_BASE_URL = 'https://api-sandbox.gocardless.com/';
	const API_VERSION      = '2015-07-06';

	/**
	 * Supported countries.
	 *
	 * @see https://gocardless.com/guides/sepa/what-is-sepa/
	 *
	 * @since 2.4.0
	 *
	 * @var array
	 */
	public static $supported_countries = array(
		'AT',
		'AU',
		'BE',
		'BG',
		'CA',
		'CH',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GB',
		'GR',
		'HR',
		'HU',
		'IE',
		'IS',
		'IT',
		'LI',
		'LV',
		'LT',
		'LU',
		'MT',
		'MC',
		'NL',
		'NO',
		'NZ',
		'PL',
		'PT',
		'RO',
		'SE',
		'SM',
		'SK',
		'SI',
		'ES',
		'US',
	);

	/**
	 * Supported currencies.
	 *
	 * @see https://gocardless.com/faq/merchants/international-payments/
	 *
	 * @since 2.4.0
	 *
	 * @var array
	 */
	public static $supported_currencies = array(
		'GBP',
		'EUR',
		'SEK',
		'DKK',
		'AUD',
		'NZD',
		'CAD',
		'USD',
	);

	/**
	 * Check whether given country is supported by GoCardless.
	 *
	 * @since 2.4.0
	 *
	 * @param string $country Country code in ISO-3166-2.
	 *
	 * @return bool Returns true if supported
	 */
	public static function is_country_supported( $country = '' ) {
		return in_array( $country, self::$supported_countries, true );
	}

	/**
	 * Check whether given currency is supported by GoCardless.
	 *
	 * @since 2.4.0
	 *
	 * @param string $currency Currency code in ISO-4217.
	 *
	 * @return bool Returns true if supported
	 */
	public static function is_currency_supported( $currency = '' ) {
		return in_array( $currency, self::$supported_currencies, true );
	}

	/**
	 * API request wrapper to GoCardless Pro.
	 *
	 * @since 2.4.0
	 *
	 * @param string $endpoint API Endpoint.
	 * @param array  $args     Args.
	 *
	 * @return array|WP_Error Returns WP_Error for unexpected result or parsed
	 *                        array for succeed response
	 */
	protected static function _request( $endpoint, $args = array() ) {
		$settings = self::get_settings();

		$base_url = 'yes' === $settings['testmode'] ? self::SANDBOX_BASE_URL : self::BASE_URL;

		$url = $base_url . $endpoint;

		$defaults = array(
			'httpversion' => '1.1',
			'method'      => 'GET',
			'timeout'     => 30,
			'headers'     => array(
				'Authorization'      => 'Bearer ' . $settings['access_token'],
				'GoCardless-Version' => self::API_VERSION,
				'Content-Type'       => 'application/json',
			),
		);
		$args     = array_merge( $defaults, $args );

		if ( ! empty( $args['body'] ) ) {
			wc_gocardless()->log( sprintf( '%s - %s %s with params: \'%s\'', __METHOD__, $args['method'], $url, print_r( $args['body'], true ) ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		} else {
			wc_gocardless()->log( sprintf( '%s - %s %s', __METHOD__, $args['method'], $url ) );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			wc_gocardless()->log( sprintf( '%s - HTTP Request error: %s', __METHOD__, $resp->get_error_message() ) );
			return $resp;
		}

		$parsed_resp = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( is_null( $parsed_resp ) ) {
			wc_gocardless()->log( sprintf( '%s - Failed to decode JSON resp %s', __METHOD__, print_r( wp_remote_retrieve_body( $resp ), true ) ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return new WP_Error( 'error_json_decode', esc_html__( 'Error decoding JSON response', 'woocommerce-gateway-gocardless' ) );
		}

		if ( ! empty( $parsed_resp['error']['code'] ) && ! empty( $parsed_resp['error']['message'] ) ) {
			wc_gocardless()->log( sprintf( '%s - GoCardless responded with error: %s', __METHOD__, print_r( $parsed_resp['error'], true ) ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

			$message = $parsed_resp['error']['message'];

			/**
			 * Error Handling for enable Refund endpoint.
			 */
			if (
				'refunds' === $endpoint &&
				'POST' === $args['method'] &&
				'invalid_api_usage' === $parsed_resp['error']['type'] &&
				403 === $parsed_resp['error']['code'] &&
				'Forbidden request' === $message
			) {
				return new WP_Error( 'error_gocardless_create_refund', esc_html__( 'GoCardless refund endpoint is disabled by default. Please contact api@gocardless.com to enable it for you.', 'woocommerce-gateway-gocardless' ) );
			}

			$new_mandate = null;
			if ( ! empty( $parsed_resp['error']['errors'] ) && is_array( $parsed_resp['error']['errors'] ) ) {
				$message .= '. ' . esc_html__( 'Error details: ', 'woocommerce-gateway-gocardless' );
				$errors   = array();
				foreach ( $parsed_resp['error']['errors'] as $err ) {
					$err_item = '';

					if ( ! empty( $err['field'] ) ) {
						$err_item .= $err['field'] . ' - ';
					}

					if ( ! empty( $err['reason'] ) ) {
						$err_item .= $err['reason'] . ' - ';

						if ( 'mandate_replaced' === $err['reason'] && ! empty( $err['links']['new_mandate'] ) ) {
							$new_mandate = $err['links']['new_mandate'];
						}
					}

					if ( ! empty( $err['message'] ) ) {
						$err_item .= $err['message'];
					}

					$errors[] = $err_item;
				}

				if ( ! empty( $errors ) ) {
					$message .= implode( ', ', $errors );
				}
			}

			$err = new WP_Error( $parsed_resp['error']['code'], $message );
			if ( $new_mandate ) {
				wc_gocardless()->log( sprintf( '%s - Mandate has been replaced', __METHOD__ ) );
				$err->add_data( $new_mandate, 'new_mandate' );
			}

			return $err;
		}

		return $parsed_resp;
	}

	/**
	 * Get GoCardless settings.
	 *
	 * @since 2.4.0
	 *
	 * @return array Settings array
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'        => 'no',
			'access_token'   => '',
			'webhook_secret' => '',
			'testmode'       => 'no',
			'logging'        => 'no',
		);

		$settings = array_merge( $defaults, get_option( 'woocommerce_gocardless_settings', array() ) );

		return apply_filters( 'woocommerce_gocardless_settings', $settings );
	}

	/**
	 * Create redirect flow.
	 *
	 * @since 2.4.0
	 * @deprecated 2.7.0
	 *
	 * @param array $params Parameters to create redirect flow.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function create_redirect_flow( $params = array() ) {
		wc_deprecated_function( __FUNCTION__, '2.7.0' );

		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'redirect_flows' => $params ) ),
		);

		return self::_request( 'redirect_flows', $args );
	}

	/**
	 * Create billing request.
	 *
	 * @since 2.7.0
	 *
	 * @param array $params Parameters to create billing request.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function create_billing_request( $params = array() ) {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'billing_requests' => $params ) ),
		);

		return self::_request( 'billing_requests', $args );
	}

	/**
	 * Get billing request.
	 *
	 * @since 2.7.0
	 *
	 * @param string $billing_request_id Billing request ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_billing_request( $billing_request_id ) {
		return self::_request( 'billing_requests/' . $billing_request_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Create billing request flow.
	 *
	 * @since 2.7.0
	 *
	 * @param array $params Parameters to create billing request flow.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function create_billing_request_flow( $params = array() ) {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'billing_request_flows' => $params ) ),
		);

		return self::_request( 'billing_request_flows', $args );
	}

	/**
	 * Complete redirect flow.
	 *
	 * @since 2.4.0
	 * @deprecated 2.7.0
	 *
	 * @param string $redirect_flow_id ID from self::create_redirect_flow.
	 * @param array  $params           Parameters to complete redirect flow.
	 *
	 * @return array
	 */
	public static function complete_redirect_flow( $redirect_flow_id, $params = array() ) {
		wc_deprecated_function( __FUNCTION__, '2.7.0' );

		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'data' => $params ) ),
		);

		return self::_request( sprintf( 'redirect_flows/%s/actions/complete', $redirect_flow_id ), $args );
	}

	/**
	 * Get a single mandate from give mandate_id.
	 *
	 * @since 2.4.0
	 *
	 * @param string $mandate_id Mandate ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_mandate( $mandate_id ) {
		return self::_request( 'mandates/' . $mandate_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Updates a mandate.
	 *
	 * @since 2.4.0
	 *
	 * @param array $params Parameters to update mandate.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function update_mandate( $params = array() ) {
		$args = array(
			'method' => 'PUT',
			'body'   => wp_json_encode( array( 'mandates' => $params ) ),
		);

		return self::_request( 'mandates/' . $mandate_id, $args );
	}

	/**
	 * Create payment.
	 *
	 * @param array $params Parameters to create payment.
	 *
	 * @return array|WP_Error
	 */
	public static function create_payment( $params = array() ) {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'payments' => $params ) ),
		);

		return self::_request( 'payments', $args );
	}

	/**
	 * Get a single payment from given payment_id.
	 *
	 * @param string $payment_id Payment ID.
	 *
	 * @return array
	 */
	public static function get_payment( $payment_id ) {
		return self::_request( 'payments/' . $payment_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Cancel payment.
	 *
	 * @param string $payment_id Payment ID.
	 *
	 * @return array
	 */
	public static function cancel_payment( $payment_id ) {
		$args = array(
			'method' => 'POST',
		);

		return self::_request( sprintf( 'payments/%s/actions/cancel', $payment_id ), $args );
	}

	/**
	 * Retries a failed payment if the underlying mandate is active.
	 *
	 * @param string $payment_id Payment ID.
	 * @param array  $args       Parameters to retry payment.
	 */
	public static function retry_payment( $payment_id, $args = array() ) {
		$args = array(
			'method' => 'POST',
		);

		return self::_request( sprintf( 'payments/%s/actions/retry', $payment_id ), $args );
	}

	/**
	 * Creates a subscription.
	 *
	 * @since 2.4.0
	 *
	 * @param array $params Parameters to create subscription.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function create_subscription( $params = array() ) {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'subscriptions' => $params ) ),
		);

		return self::_request( 'subscriptions', $args );
	}

	/**
	 * Get a single subscription from given subscription_id.
	 *
	 * @param string $subscription_id Subscription ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_subscription( $subscription_id ) {
		return self::_request( 'subscriptions/' . $subscription_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Cancel a subscription of a given subscription_id.
	 *
	 * @param string $subscription_id Subscriptino ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function cancel_subscription( $subscription_id ) {
		return self::_request(
			sprintf( 'subscriptions/%s/actions/cancel', $subscription_id ),
			array( 'method' => 'POST' )
		);
	}

	/**
	 * Get a single customer from given customer_id.
	 *
	 * @param string $customer_id Customer ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_customer( $customer_id ) {
		return self::_request( 'customers/' . $customer_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Update a single customer from given customer_id.
	 *
	 * @since 2.4.1
	 * @param string $customer_id Customer ID.
	 * @param array  $params Parameters to update customer.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function update_customer( $customer_id, $params = array() ) {
		$args = array(
			'method' => 'PUT',
			'body'   => wp_json_encode( array( 'customers' => $params ) ),
		);

		return self::_request( 'customers/' . $customer_id, $args );
	}

	/**
	 * Creates a new refund.
	 *
	 * @since 2.4.0
	 *
	 * @param array $params Parameters to create refund.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function create_refund( $params = array() ) {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array( 'refunds' => $params ) ),
		);

		return self::_request( 'refunds', $args );
	}

	/**
	 * Get a single refund from given refund_id.
	 *
	 * @param string $refund_id Refund ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_refund( $refund_id ) {
		return self::_request( 'refunds/' . $refund_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Get a single customer bank account.
	 *
	 * @param string $customer_bank_account_id Customer bank account ID.
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_customer_bank_account( $customer_bank_account_id ) {
		return self::_request( 'customer_bank_accounts/' . $customer_bank_account_id, array( 'method' => 'GET' ) );
	}

	/**
	 * Get supported countries by GoCardless.
	 *
	 * @return array
	 *
	 * @since 2.6.0
	 */
	public static function get_supported_countries() {
		return self::$supported_countries;
	}

	/**
	 * Get supported currencies by GoCardless.
	 *
	 * @return array
	 *
	 * @since 2.8.0
	 */
	public static function get_supported_currencies() {
		return self::$supported_currencies;
	}

	/**
	 * Get list of creditors.
	 *
	 * @since 2.7.0
	 *
	 * @return WP_Error|array See self::_request return value
	 */
	public static function get_creditors() {
		return self::_request( 'creditors', array( 'method' => 'GET' ) );
	}
}
