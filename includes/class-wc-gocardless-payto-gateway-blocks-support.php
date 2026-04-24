<?php
/**
 * GoCardless PayTo payment method Blocks support.
 *
 * @package WC_GoCardless_Gateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayTo Blocks checkout integration.
 *
 * @since x.x.x
 */
final class WC_GoCardless_PayTo_Gateway_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method ID.
	 *
	 * @var string
	 */
	protected $name = 'gocardless_payto';

	/**
	 * Initialise from shared GoCardless settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_gocardless_settings', array() );
	}

	/**
	 * Active when PayTo is enabled and the store is connected to GoCardless.
	 *
	 * @return bool
	 */
	public function is_active() {
		return ! empty( $this->settings['access_token'] )
			&& isset( $this->settings['payto_enabled'] )
			&& 'yes' === $this->settings['payto_enabled'];
	}

	/**
	 * Script handles (shared bundle with main GoCardless Blocks integration).
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		return array( WC_GoCardless_Gateway_Blocks_Support::register_blocks_integration_script() );
	}

	/**
	 * Data for the PayTo payment method in Blocks.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$title       = isset( $this->settings['payto_title'] ) ? $this->settings['payto_title'] : __( 'PayTo', 'woocommerce-gateway-gocardless' );
		$description = isset( $this->settings['payto_description'] ) ? $this->settings['payto_description'] : '';

		return array(
			'title'               => $title,
			'description'         => $description,
			'supports'            => $this->get_supported_features(),
			'logo_url'            => wc_gocardless()->plugin_url . '/images/payto.png',
			'showSavedCards'      => $this->should_show_saved_bank_accounts(),
			'showSaveOption'      => $this->should_show_saved_bank_accounts(),
			'supportedCountries'  => array( 'AU' ),
			'supportedCurrencies' => array( 'AUD' ),
		);
	}

	/**
	 * Supported gateway features for Blocks.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		if ( empty( $payment_gateways['gocardless_payto'] ) ) {
			return array();
		}
		return $payment_gateways['gocardless_payto']->supports;
	}

	/**
	 * Whether saved PayTo agreements can be used at checkout.
	 *
	 * @return bool
	 */
	private function should_show_saved_bank_accounts() {
		return 'yes' === ( $this->settings['saved_bank_accounts'] ?? 'yes' );
	}
}
