<?php
/**
 * GoCardless analytics reports handler.
 *
 * @package WooCommerce_Gateway_GoCardless
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage extending Analytics reports.
 *
 * @since 2.7.0
 */
class WC_GoCardless_Reports {
	/**
	 * Class Initialization.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_reports_scripts' ) );
		add_filter( 'woocommerce_rest_prepare_report_customers', array( $this, 'add_gocardless_customer_mandates' ) );
		// Export CSV columns.
		add_filter( 'woocommerce_report_customers_export_columns', array( $this, 'add_customer_export_column' ) );
		add_filter( 'woocommerce_report_customers_prepare_export_item', array( $this, 'prepare_export_customer_item' ), 10, 2 );
	}

	/**
	 * Enqueue scripts for analytics reports.
	 *
	 * @return void
	 */
	public function enqueue_reports_scripts() {
		// Enqueue scripts for analytics reports.
		$asset_path   = wc_gocardless()->plugin_path . '/build/customer-reports.asset.php';
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
		wp_enqueue_script(
			'customer-reports',
			wc_gocardless()->plugin_url . '/build/customer-reports.js',
			$dependencies,
			$version,
			true
		);
		$params = array(
			'mandate_url_format' => wc_gocardless()->gateway_instance() ? wc_gocardless()->gateway_instance()->get_mandate_url_format() : 'https://manage.gocardless.com/mandates/%s',
		);

		wp_localize_script( 'customer-reports', 'wc_gocardless_reports_params', $params );
	}

	/**
	 * Add GoCardless customer mandates to the customers report.
	 *
	 * @param object $response The customer response object.
	 * @return object $response with GoCardless customer mandates.
	 */
	public function add_gocardless_customer_mandates( $response ) {
		// Add GoCardless customer mandates to the customers report.
		$customer_id = $response->data['user_id'];
		if ( ! $customer_id ) {
			return $response;
		}

		$mandates                                = $this->get_customer_mandates( $customer_id );
		$response->data['direct_debit_mandates'] = $mandates;

		return $response;
	}

	/**
	 * Get customer mandate ids.
	 *
	 * @param int $customer_id The customer id.
	 * @return array $mandates Customer mandate ids.
	 */
	public function get_customer_mandates( $customer_id ) {
		// Get customer mandates.
		$mandates = array();
		$tokens   = WC_Payment_Tokens::get_customer_tokens( $customer_id, 'gocardless' );
		if ( empty( $tokens ) ) {
			return $mandates;
		}

		foreach ( $tokens as $token ) {
			$mandates[] = $token->get_token();
		}
		return $mandates;
	}

	/**
	 * Add GoCardless customer mandates column to the customers export.
	 *
	 * @param array $columns The customer export columns.
	 * @return array $columns The customer export columns with Direct Debit mandates.
	 */
	public function add_customer_export_column( $columns ) {
		$columns['direct_debit_mandates'] = __( 'Direct Debit Mandates', 'woocommerce-gateway-gocardless' );
		return $columns;
	}

	/**
	 * Prepare customer export item.
	 *
	 * @param array $export_item The customer export item.
	 * @param array $item        The customer item.
	 * @return array $item The customer export item with GoCardless mandates.
	 */
	public function prepare_export_customer_item( $export_item, $item ) {
		$mandates = array();
		if ( isset( $item['direct_debit_mandates'] ) ) {
			$mandates = $item['direct_debit_mandates'];
		}
		$export_item['direct_debit_mandates'] = implode( ', ', $mandates );
		return $export_item;
	}
}
