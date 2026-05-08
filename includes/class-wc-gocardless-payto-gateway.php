<?php
/**
 * GoCardless PayTo gateway.
 *
 * @package WC_GoCardless_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayTo payment gateway for Australian customers (AUD, scheme pay_to).
 *
 * Settings are stored in {@see WC_GoCardless_Gateway} options under payto_* keys.
 *
 * @class WC_GoCardless_PayTo_Gateway
 * @since x.x.x
 */
class WC_GoCardless_PayTo_Gateway extends WC_GoCardless_Gateway {

	/**
	 * Gateway ID (set at declaration so parent constructor sees it).
	 *
	 * @var string
	 */
	public $id = 'gocardless_payto';

	/**
	 * PayTo purpose code sent on billing requests (GoCardless API).
	 *
	 * @var string
	 */
	protected $purpose_code = 'retail';

	/**
	 * Whether BECS fallback is enabled when PayTo is unavailable for the payer's bank.
	 *
	 * @var bool
	 */
	protected $fallback_enabled = true;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->method_title       = __( 'PayTo (GoCardless)', 'woocommerce-gateway-gocardless' );
		$this->method_description = __(
			'Accept PayTo bank payments from customers in Australia.',
			'woocommerce-gateway-gocardless'
		);
		$this->icon               = wc_gocardless()->plugin_url . '/images/payto.png';

		$this->setup_hooks();
	}

	/**
	 * Setup hooks for the PayTo gateway.
	 *
	 * @return void
	 */
	protected function setup_hooks() {
		// Payment-token-API related hook.
		add_filter( 'woocommerce_payment_methods_list_item', array( $this, 'saved_payment_methods_list_item' ), 99, 2 );
	}

	/**
	 * Shared option key with the main GoCardless gateway (payto_* keys).
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public function get_option_key() {
		return 'woocommerce_gocardless_settings';
	}

	/**
	 * Load settings from shared option and PayTo-specific keys.
	 *
	 * @return void
	 */
	public function load_settings() {
		$this->init_settings();
		$s = $this->settings;

		$this->title       = ! empty( $s['payto_title'] )
			? $s['payto_title']
			: __( 'PayTo', 'woocommerce-gateway-gocardless' );
		$this->description = $s['payto_description'] ?? '';
		$this->enabled     = $s['payto_enabled'] ?? 'no';

		$this->access_token   = $s['access_token'] ?? '';
		$this->webhook_secret = $s['webhook_secret'] ?? '';
		$this->testmode       = ( $s['testmode'] ?? 'yes' ) === 'yes';

		$this->instant_bank_pay    = false;
		$this->saved_bank_accounts = ( $s['saved_bank_accounts'] ?? 'yes' ) === 'yes';
		$this->scheme              = 'pay_to';
		$this->fallback_enabled    = ( $s['fallback_enabled'] ?? 'yes' ) === 'yes';
	}

	/**
	 * Initialise settings from the shared option array.
	 *
	 * @return void
	 */
	public function init_settings() {
		$this->settings = get_option( $this->get_option_key(), array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	/**
	 * Map logical option keys to storage keys in the shared option.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Option key.
	 * @param mixed  $empty_value Default when empty.
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		$map = array(
			'enabled'     => 'payto_enabled',
			'title'       => 'payto_title',
			'description' => 'payto_description',
		);

		if ( empty( $this->settings ) ) {
			$this->init_settings();
		}

		$storage_key = isset( $map[ $key ] ) ? $map[ $key ] : $key;

		if ( ! array_key_exists( $storage_key, $this->settings ) ) {
			$default = $this->get_default_for_payto_option( $storage_key );
			return null !== $default ? $default : $empty_value;
		}

		$value = $this->settings[ $storage_key ];
		if ( '' === $value && null !== $empty_value ) {
			return $empty_value;
		}

		return $value;
	}

	/**
	 * Defaults for PayTo keys when missing from the database.
	 *
	 * @since x.x.x
	 *
	 * @param string $storage_key Key in woocommerce_gocardless_settings.
	 * @return mixed|null Null if no default.
	 */
	protected function get_default_for_payto_option( $storage_key ) {
		$defaults = array(
			'payto_title'   => __( 'PayTo', 'woocommerce-gateway-gocardless' ),
			'payto_enabled' => 'no',
		);

		return isset( $defaults[ $storage_key ] ) ? $defaults[ $storage_key ] : null;
	}

	/**
	 * No separate settings page; PayTo is configured under the main GoCardless gateway.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	/**
	 * Prevent saving a duplicate settings row for this gateway ID.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		return false;
	}

	/**
	 * No admin UI for this gateway.
	 *
	 * @return void
	 */
	public function admin_options() {
		echo '<p>' . esc_html__(
			'PayTo is configured in the GoCardless payment gateway settings.',
			'woocommerce-gateway-gocardless'
		) . '</p>';
	}

	/**
	 * Whether the merchant has an active PayTo scheme on their creditor.
	 *
	 * @return bool
	 */
	protected function merchant_supports_payto() {
		foreach ( $this->get_available_scheme_identifiers() as $scheme_row ) {
			if ( ! empty( $scheme_row['scheme'] ) && 'pay_to' === $scheme_row['scheme']
				&& ! empty( $scheme_row['status'] ) && 'active' === $scheme_row['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * PayTo is only available for Australia, AUD, and when PayTo is enabled and active on the account.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( is_admin() && $this->is_checkout_settings_page() ) {
			return parent::is_available();
		}

		if ( 'yes' !== $this->get_option( 'enabled', 'no' ) ) {
			return false;
		}

		if ( ! $this->access_token ) {
			return false;
		}

		if ( 'AUD' !== $this->get_payment_currency() ) {
			return false;
		}

		if (
			WC()->customer &&
			( ! is_admin() || defined( 'DOING_AJAX' ) ) &&
			! ( isset( $_POST['action'] ) && 'wcs_import_request' === wc_clean( wp_unslash( $_POST['action'] ) ) ) //phpcs:ignore WordPress.Security.NonceVerification.Missing
		) {
			if ( 'AU' !== WC()->customer->get_billing_country() ) {
				return false;
			}
		}

		if ( ! $this->merchant_supports_payto() ) {
			return false;
		}

		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() && 'woocommerce_account_navigation' !== current_action() ) {
			return false;
		}

		return WC_Payment_Gateway::is_available();
	}

	/**
	 * Create billing request with PayTo fields (purpose_code, fallback_enabled, scheme pay_to, AUD).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function create_billing_request_flow( WC_Order $order ) {
		wc_gocardless()->log( sprintf( '%s - Creating a PayTo billing request for order #%s', __METHOD__, $order->get_order_number() ) );

		$new_customer           = true;
		$billing_request_params = array(
			'metadata'         => array(
				'order_id' => (string) $order->get_id(),
			),
			'purpose_code'     => $this->purpose_code,
			'fallback_enabled' => $this->fallback_enabled,
		);

		$description = $this->_get_description_from_order( $order );

		/**
		 * Filter the default max amount per payment for PayTo in .
		 *
		 * @since x.x.x
		 *
		 * @param int      $max_amount_per_payment Max amount per payment. Default 100 AUD.
		 * @param WC_Order $order                  Order.
		 * @return int Max amount per payment.
		 */
		$default_max_amount_per_payment = apply_filters( 'woocommerce_gocardless_payto_default_max_amount_per_payment', 100, $order ); // TODO: Check default value with GoCardless.

		// Convert to cents and ensure minimum 1000 cents.
		$default_max_amount_per_payment = max( 1000, absint( $default_max_amount_per_payment * 100 ) );
		$order_amount                   = absint( wc_format_decimal( ( (float) $order->get_total() * 100 ), wc_get_price_decimals() ) );
		$max_amount_per_payment         = max( $default_max_amount_per_payment, $order_amount );
		$constraints                    = array(
			'start_date'             => date( 'Y-m-d' ), // TODO: Check timezone.
			'max_amount_per_payment' => $max_amount_per_payment,
		);

		if (
			$order->get_total() > 0 &&
			! $this->is_change_payment_method_request() &&
			! $this->is_pre_orders_pay_upon_release( $order )
		) {
			$payment_request = array(
				'description' => $description,
				'amount'      => absint( wc_format_decimal( ( (float) $order->get_total() * 100 ), wc_get_price_decimals() ) ),
				'currency'    => wc_gocardless_get_order_prop( $order, 'currency' ),
				'scheme'      => 'pay_to',
				'metadata'    => array(
					'order_id' => (string) $order->get_id(),
				),
			);

			$billing_request_params['payment_request'] = $payment_request;

			if ( $this->needs_mandate( $order ) ) {
				$billing_request_params['mandate_request'] = array(
					'currency'    => wc_gocardless_get_order_prop( $order, 'currency' ),
					'scheme'      => 'pay_to',
					'description' => $description,
					'constraints' => $constraints,
				);
			}
		} else {
			$billing_request_params['mandate_request'] = array(
				'currency'    => wc_gocardless_get_order_prop( $order, 'currency' ),
				'scheme'      => 'pay_to',
				'description' => $description,
				'constraints' => $constraints,
			);
		}

		$customer_id = get_user_meta( get_current_user_id(), '_gocardless_customer_id', true );

		if ( ! empty( $customer_id ) ) {
			$customer = WC_GoCardless_API::get_customer( $customer_id );
			if ( ! is_wp_error( $customer ) && ! empty( $customer['customers']['id'] ) ) {
				$new_customer                    = false;
				$billing_request_params['links'] = array(
					'customer' => $customer['customers']['id'],
				);
			}
		}

		/**
		 * Filter PayTo billing request params.
		 *
		 * @since x.x.x
		 *
		 * @param array    $billing_request_params Params.
		 * @param WC_Order $order                  Order.
		 */
		$billing_request_params = apply_filters(
			'woocommerce_gocardless_payto_create_billing_request_params',
			$billing_request_params,
			$order
		);

		$billing_request = WC_GoCardless_API::create_billing_request( $billing_request_params );

		if ( is_wp_error( $billing_request ) ) {
			return array(
				'result'  => 'failure',
				'message' => $billing_request->get_error_message(),
			);
		}

		if ( empty( $billing_request['billing_requests']['id'] ) ) {
			return array(
				'result'  => 'failure',
				'message' => esc_html__( 'Error processing checkout. Please try again.', 'woocommerce-gateway-gocardless' ),
			);
		}

		$billing_request_id = $billing_request['billing_requests']['id'];
		$this->update_order_resource( $order, 'billing_request', $billing_request['billing_requests'] );

		if ( $new_customer && isset( $billing_request['billing_requests']['links'] ) && ! empty( $billing_request['billing_requests']['links']['customer'] ) ) {
			$new_customer_id = wc_clean( $billing_request['billing_requests']['links']['customer'] );
			update_user_meta( get_current_user_id(), '_gocardless_customer_id', $new_customer_id );
		}

		wc_gocardless()->log( sprintf( '%s - PayTo billing request created: %s', __METHOD__, print_r( $billing_request, true ) ) );

		// TODO: REMOVE PRE-FILLED CUSTOMER and ADD COLLECT CUSTOMER DETAILS API here.
		$billing_request_flow_params = array(
			'prefilled_customer' => array(
				'given_name'    => $order->get_billing_first_name(),
				'family_name'   => $order->get_billing_last_name(),
				'email'         => $order->get_billing_email(),
				'company_name'  => $order->get_billing_company(),
				'address_line1' => $order->get_billing_address_1(),
				'address_line2' => $order->get_billing_address_2(),
				'country_code'  => $order->get_billing_country(),
				'city'          => $order->get_billing_city(),
				'postal_code'   => $order->get_billing_postcode(),
			),
			'links'              => array( 'billing_request' => $billing_request_id ),
			'redirect_uri'       => $this->get_success_redirect_url( $order ),
			'exit_uri'           => $order->get_checkout_payment_url(),
		);

		/**
		 * Filter PayTo billing request flow params before creating the flow.
		 *
		 * @since x.x.x
		 *
		 * @param array    $billing_request_flow_params Billing request flow params.
		 * @param WC_Order $order                       Order.
		 * @return array Billing request flow params.
		 */
		$billing_request_flow_params = apply_filters(
			'woocommerce_gocardless_payto_billing_request_flow_params',
			$billing_request_flow_params,
			$order
		);

		$billing_request_flow = WC_GoCardless_API::create_billing_request_flow( $billing_request_flow_params );
		if ( is_wp_error( $billing_request_flow ) ) {
			return array(
				'result'  => 'failure',
				'message' => $billing_request_flow->get_error_message(),
			);
		}
		if ( empty( $billing_request_flow['billing_request_flows']['id'] ) || empty( $billing_request_flow['billing_request_flows']['authorisation_url'] ) ) {
			return array(
				'result'  => 'failure',
				'message' => esc_html__( 'Error processing checkout. Please try again.', 'woocommerce-gateway-gocardless' ),
			);
		}
		$this->update_order_resource( $order, 'billing_request_flow', $billing_request_flow['billing_request_flows'] );

		wc_gocardless()->log( sprintf( '%s - PayTo billing request flow created: %s', __METHOD__, print_r( $billing_request_flow, true ) ) );

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $billing_request_flow['billing_request_flows']['authorisation_url'] ),
		);
	}

	/**
	 * Store mandate as PayTo token.
	 *
	 * @param int   $customer_id Customer ID.
	 * @param array $mandate     Mandate data.
	 * @return bool
	 */
	protected function _save_customer_token( $customer_id, $mandate ) {
		if ( ! $customer_id ) {
			return false;
		}

		if ( ! class_exists( 'WC_Payment_Token_GoCardless_PayTo' ) ) {
			return false;
		}

		$bank_account_id = $mandate['links']['customer_bank_account'];
		$bank_account    = WC_GoCardless_API::get_customer_bank_account( $bank_account_id );
		if ( is_wp_error( $bank_account ) || empty( $bank_account['customer_bank_accounts'] ) ) {
			return false;
		}
		$bank_account = $bank_account['customer_bank_accounts'];

		$token = new WC_Payment_Token_GoCardless_PayTo();

		// Set basic info required by token API.
		$token->set_token( $mandate['id'] );
		$token->set_gateway_id( $this->id );
		$token->set_user_id( $customer_id );

		// Save bank account info for display purpose.
		$token->set_scheme( $mandate['scheme'] );
		$token->set_account_holder_name( $bank_account['account_holder_name'] );
		$token->set_account_number_ending( $bank_account['account_number_ending'] );
		$token->set_bank_name( $bank_account['bank_name'] );

		return $token->save();
	}

	/**
	 * Format saved PayTo methods in My Account.
	 *
	 * @param array $item          Item.
	 * @param mixed $payment_token Token.
	 * @return array
	 */
	public function saved_payment_methods_list_item( $item, $payment_token ) {
		if ( 'gocardless_payto' !== strtolower( $payment_token->get_type() ) ) {
			return $item;
		}

		$item['method']['display_name'] = $payment_token->get_display_name();
		$item['method']['brand']        = esc_html( $payment_token->get_bank_name() );
		$item['method']['last4']        = esc_html( $payment_token->get_account_number_ending() );
		$item['expires']                = '';

		return $item;
	}

	/**
	 * Return the gateway's icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon = $this->icon ? '<img style="max-width: 60px;" src="' . WC_HTTPS::force_https_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" />' : '';

		/**
		 * Filter the gateway icon. (This is WooCommerce core filter)
		 *
		 * @since x.x.x
		 *
		 * @param string $icon Gateway icon.
		 * @param string $id   Gateway ID.
		 * @return string
		 */
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}
}
