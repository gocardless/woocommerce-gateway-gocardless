<?php
/**
 * GoCardless payment method Blocks Support
 *
 * @package WC_GoCardless_Gateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GoCardless payment method integration
 *
 * @since 2.6.0
 */
final class WC_GoCardless_Gateway_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Name of the payment method.
	 *
	 * @var string
	 */
	protected $name = 'gocardless';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_gocardless_settings', array() );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && ! empty( $this->settings['access_token'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$asset_path   = wc_gocardless()->plugin_path . '/build/index.asset.php';
		$version      = wc_gocardless()->version;
		$dependencies = array();
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}

		// Register GoCardless Drop-in script.
		wp_register_script(
			'gocardless-dropin',
			'https://pay.gocardless.com/billing/static/dropin/v2/initialise.js',
			array(),
			$version,
			true
		);

		wp_register_script(
			'wc-gocardless-blocks-integration',
			wc_gocardless()->plugin_url . '/build/index.js',
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations(
			'wc-gocardless-blocks-integration',
			'woocommerce-gateway-gocardless'
		);

		return array( 'gocardless-dropin', 'wc-gocardless-blocks-integration' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'               => $this->get_setting( 'title' ),
			'description'         => $this->get_setting( 'description' ),
			'supports'            => $this->get_supported_features(),
			'logo_url'            => wc_gocardless()->plugin_url . '/images/gocardless.png',
			'showSavedCards'      => $this->should_show_saved_bank_accounts(),
			'showSaveOption'      => $this->should_show_saved_bank_accounts(),
			'supportedCountries'  => WC_GoCardless_API::get_supported_countries(),
			'supportedCurrencies' => WC_GoCardless_API::get_supported_currencies(),
			'isTest'              => 'yes' === $this->settings['testmode'],
			'wcAjaxUrl'           => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'billingRequestNonce' => wp_create_nonce( 'wc_gocardless_complete_billing_request_flow' ),
		);
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		return $payment_gateways[ $this->name ]->supports;
	}

	/**
	 * Determine if store allows bank accounts to be saved during checkout.
	 *
	 * @return bool True if merchant allows shopper to save bank accounts during checkout.
	 */
	private function should_show_saved_bank_accounts() {
		return isset( $this->settings['saved_bank_accounts'] ) ? 'yes' === $this->settings['saved_bank_accounts'] : false;
	}
}
