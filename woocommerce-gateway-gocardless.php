<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Main plugin file
/**
 * Plugin Name:          WooCommerce GoCardless Gateway
 * Plugin URI:           https://www.woocommerce.com/products/gocardless/
 * Description:          Extends both WooCommerce and WooCommerce Subscriptions with the GoCardless Payment Gateway. A GoCardless merchant account is required.
 * Version:              2.9.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * PHP tested up to:     8.3
 * Author:               GoCardless
 * Author URI:           https://gocardless.com/
 * License:              GPL-3.0-or-later
 * License URI:          https://spdx.org/licenses/GPL-3.0-or-later.html
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.4
 * WC tested up to:      9.6
 *
 * Copyright: © 2023-2025 WooCommerce
 * Copyright: © 2025 GoCardless
 *
 * @package WC_GoCardless
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin main class.
 */
class WC_GoCardless {

	/**
	 * Plugin's version.
	 *
	 * @since 2.4.0
	 *
	 * @var string
	 */
	public $version = '2.9.0'; // WRCS: DEFINED_VERSION.

	/**
	 * Plugin's absolute path.
	 *
	 * @since 2.4.0
	 *
	 * @var string
	 */
	public $plugin_path;

	/**
	 * Plugin's URL.
	 *
	 * @since 2.4.0
	 *
	 * @var string
	 */
	public $plugin_url;

	/**
	 * Plugin's settings.
	 *
	 * @since 2.4.0
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Constructor
	 */
	public function __construct() {
		define( 'WC_GOCARDLESS_MAIN_FILE', __FILE__ );

		// Actions.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 11 );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'admin_notices', array( $this, 'environment_check' ) );

		$this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );
		$this->plugin_url  = untrailingslashit( plugins_url( '/', __FILE__ ) );

		require_once $this->plugin_path . '/includes/class-wc-gocardless-api.php';

		$this->settings = WC_GoCardless_API::get_settings();
		add_action( 'woocommerce_gocardless_check_subscription_payment_status', array( $this, 'gocardless_check_subscription_payment_status' ), 10, 1 );
		add_action( 'woocommerce_gocardless_process_webhook_payload_async', array( $this, 'process_webhook_payload_cron' ), 10, 1 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_feature_compatibility' ) );

		// Add support for WooCommerce Blocks.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'woocommerce_block_support' ) );

		// Allow redirects to GoCardless URLs.
		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ), 10, 2 );

		// Admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}

	/**
	 * Add GoCardless URLs to allowed redirect hosts.
	 *
	 * @since 2.6.3
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @param array $requested_host Requested host.
	 *
	 * @return array Modified allowed redirect hosts.
	 */
	public function allowed_redirect_hosts( $hosts, $requested_host ) {
		if ( ! $requested_host ) {
			return $hosts;
		}

		/*
		 * GoCardless uses a number of domains for payment pages so this allows
		 * redirects to any gocardless.com subdomain.
		 */
		if ( str_ends_with( $requested_host, '.gocardless.com' ) ) {
			$hosts[] = $requested_host;
		}

		return $hosts;
	}

	/**
	 * Process webhook payload.
	 *
	 * This function will be called by cron to process webhook events asynchronously.
	 *
	 * @param array $payload Webhook event payload.
	 *
	 * @return void
	 *
	 * @since 2.4.20
	 * @version 2.4.6
	 */
	public function process_webhook_payload_cron( array $payload ) {
		$gateway = $this->gateway_instance();
		if ( $gateway ) {
			$gateway->process_webhook_payload( $payload );
		}
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @since 1.0.0
	 * @version 2.4.6
	 *
	 * @param  array $links Plugin action links.
	 * @return array Plugin action links
	 */
	public function plugin_action_links( $links ) {
		if ( ! function_exists( 'WC' ) ) {
			return $links;
		}

		$setting_url = $this->get_setting_url();

		$plugin_links = array(
			'<a href="' . esc_url( $setting_url ) . '">' . esc_html__( 'Settings', 'woocommerce-gateway-gocardless' ) . '</a>',
			'<a href="https://support.woocommerce.com/">' . esc_html__( 'Support', 'woocommerce-gateway-gocardless' ) . '</a>',
			'<a href="https://docs.woocommerce.com/document/gocardless/">' . esc_html__( 'Docs', 'woocommerce-gateway-gocardless' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	/**
	 * Get setting URL.
	 *
	 * @since 2.3.8
	 *
	 * @return string Setting URL
	 */
	public function get_setting_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=gocardless' );
	}

	/**
	 * Checks whether gateway addons can be used.
	 *
	 * @since 2.4.0
	 *
	 * @return bool Returns true if gateway addons can be used
	 */
	public function can_use_gateway_addons() {
		return (
			( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) )
			||
			class_exists( 'WC_Pre_Orders_Order' )
		);
	}

	/**
	 * Init localisations and files.
	 */
	public function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Update payment method title to "Pay by bank".
		$this->update_payment_method_title();

		$this->_maybe_display_connect_notice();

		// Includes.
		require_once $this->plugin_path . '/includes/class-wc-gocardless-payment-token-direct-debit.php';
		require_once $this->plugin_path . '/includes/class-wc-gocardless-gateway.php';
		require_once $this->plugin_path . '/includes/class-wc-gocardless-privacy.php';
		require_once $this->plugin_path . '/includes/class-wc-gocardless-compat.php';

		if ( $this->can_use_gateway_addons() ) {
			include_once $this->plugin_path . '/includes/class-wc-gocardless-gateway-addons.php';
		}

		// Backwards compatibility.
		$this->register_class_aliases();

		// Ajax handler.
		require_once $this->plugin_path . '/includes/class-wc-gocardless-ajax.php';
		$ajax_handler = new WC_GoCardless_Ajax();
		$ajax_handler->init();

		// Customer Reports handler.
		require_once $this->plugin_path . '/includes/class-wc-gocardless-reports.php';
		$reports_handler = new WC_GoCardless_Reports();
		$reports_handler->init();

		// Localisation.
		load_plugin_textdomain( 'woocommerce-gateway-gocardless', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'init', array( $this, 'init_order_admin' ) );
	}

	/**
	 * Maybe display connect notice for merchants who have access token for
	 * legacy API and merchants that have the extension activated but never set
	 * API credentials before.
	 *
	 * @since 2.4.0
	 */
	protected function _maybe_display_connect_notice() {
		// "woocommerce_gocardless_version" is for backward compatibility only and will be removed in future.
		$current_version = get_option( 'wc_gocardless_version', get_option( 'woocommerce_gocardless_version' ) );

		if ( version_compare( $current_version, '2.4.0', '<' ) ) {
			$this->_display_connect_notice();
			$this->_migrate_old_settings();
		}

		update_option( 'wc_gocardless_version', $this->version );
	}

	/**
	 * Update payment method title to "Pay by bank".
	 * This update will only be applied to plugin update from version 2.6.4 or lower and current payment method title is "Direct Debit".
	 *
	 * @since 2.7.0
	 */
	public function update_payment_method_title() {
		// "woocommerce_gocardless_version" is for backward compatibility only and will be removed in future.
		$current_version = get_option( 'wc_gocardless_version', get_option( 'woocommerce_gocardless_version' ) );

		if ( $current_version && version_compare( $current_version, '2.6.4', '<=' ) ) {
			$settings = get_option( 'woocommerce_gocardless_settings', array() );
			if ( isset( $settings['title'] ) && 'Direct Debit' === $settings['title'] ) {
				$settings['title'] = sanitize_text_field( __( 'Pay by bank', 'woocommerce-gateway-gocardless' ) );
				update_option( 'woocommerce_gocardless_settings', $settings );
			}
		}
	}

	/**
	 * Display connect notice.
	 *
	 * @since 2.4.0
	 */
	protected function _display_connect_notice() {
		// For merchants that don't have access token yet.
		/* translators: Link to connect GoCardless account */
		$message = sprintf( __( 'GoCardless is almost ready. To get started, please <a href="%s">connect your GoCardless account</a>.', 'woocommerce-gateway-gocardless' ), $this->get_setting_url() );

		// For merchants that, maybe, have access token from legacy API.
		if ( ! empty( $this->settings['access_token'] ) ) {
			/* translators: Plugin's version (as in x.y.z) and settings URL */
			$message = sprintf( __( 'GoCardless %1$s requires new access token to work with the latest API. To upgrade your account with the latest API, please contact api@gocardless.com for assistance. Once upgraded, access token from legacy API can be renewed by clicking the connect button in <a href="%2$s">settings</a> page.', 'woocommerce-gateway-gocardless' ), $this->version, $this->get_setting_url() );
		}

		WC_Admin_Notices::add_custom_notice( 'gocardless_connect_prompt', $message );
	}

	/**
	 * Migrate old settings prior 2.4.0.
	 *
	 * @since 2.4.0
	 */
	protected function _migrate_old_settings() {
		$has_old_settings = (
			! empty( $this->settings['app_id'] ) &&
			! empty( $this->settings['app_secret'] ) &&
			! empty( $this->settings['merchant_id'] )
		);

		if ( $has_old_settings ) {
			// Backup the old settings in case merchant needs it.
			add_option( 'woocommerce_gocardless_settings_deprecated', $this->settings );

			unset(
				$this->settings['app_id'],
				$this->settings['app_secret'],
				$this->settings['merchant_id'],
				$this->settings['payment_action']
			);

			$this->settings['access_token'] = '';

			update_option( 'woocommerce_gocardless_settings', $this->settings );
		}
	}

	/**
	 * Init order admin.
	 *
	 * @since 2.4.0
	 */
	public function init_order_admin() {
		require_once $this->plugin_path . '/includes/class-wc-gocardless-order-admin.php';

		$order_admin = new WC_GoCardless_Order_Admin();
		$order_admin->init();
	}

	/**
	 * Register the gateway for use.
	 *
	 * @param array $methods Registered payment methods.
	 *
	 * @return array Payment methods
	 */
	public function register_gateway( $methods ) {
		if ( $this->can_use_gateway_addons() ) {
			$methods[] = 'WC_GoCardless_Gateway_Addons';
		} else {
			$methods[] = 'WC_GoCardless_Gateway';
		}

		return $methods;
	}

	/**
	 * Check environment and maybe show notice in admin if requirements are
	 * not satisified.
	 *
	 * @see https://gocardless.com/faq/merchants/international-payments/
	 *
	 * @since 2.4.6
	 * @version 2.4.6
	 */
	public function environment_check() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( ! in_array( get_woocommerce_currency(), array( 'GBP', 'EUR', 'SEK', 'DKK', 'AUD', 'NZD', 'CAD', 'USD' ), true ) ) {
			echo wp_kses_post(
				sprintf(
					'<div class="error"><p>%s</p></div>',
					sprintf(
						/* translators: Link to set required currency */
						__( 'GoCardless requires that the WooCommerce <a href="%s">currency</a> is set to GBP, EUR, SEK, DKK, AUD, NZD, CAD or USD.', 'woocommerce-gateway-gocardless' ),
						esc_url(
							add_query_arg(
								array(
									'page' => 'wc-settings',
									'tab'  => 'general',
								),
								admin_url( 'admin.php' )
							)
						)
					)
				)
			);
		}
	}

	/**
	 * Get GoCardless gateway instance.
	 *
	 * @since 2.4.0
	 *
	 * @return WC_GoCardless_Gateway|bool Returns gateway instance of false if not found
	 */
	public function gateway_instance() {
		$gateways = WC()->payment_gateways->payment_gateways();

		return ! empty( $gateways['gocardless'] ) ? $gateways['gocardless'] : false;
	}

	/**
	 * Log message.
	 *
	 * @since 2.3.7
	 *
	 * @param string $message Message to log.
	 *
	 * @return void
	 */
	public function log( $message ) {
		if ( 'yes' !== $this->settings['logging'] ) {
			return;
		}

		if ( empty( $this->logger ) ) {
			$this->logger = new WC_Logger();
		}

		$this->logger->add( 'woocommerce-gateway-gocardless', $message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
		}
	}

	/**
	 * Check GoCardless Payment status of temporarily activated subscriptions.
	 * If Payment is not confirmed or paid, update order status accordingly.
	 * (Subscription status will be automatically updated based order status)
	 *
	 * @param int $order_id Order ID.
	 */
	public function gocardless_check_subscription_payment_status( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_status   = $order->get_status();
		$temp_activated = $order->get_meta( '_gocardless_temporary_activated', true );
		$payment_id     = $order->get_meta( '_gocardless_payment_id', true );
		$payment_status = $order->get_meta( '_gocardless_payment_status', true );
		$end_statuses   = array( 'confirmed', 'paid_out', 'failed', 'cancelled', 'charged_back', 'customer_approval_denied' );

		$this->log( sprintf( '%s - GoCardless payment status check started. Order ID: %d', __METHOD__, $order_id ) );
		$this->log( sprintf( '%s - GoCardless payment ID: %s', __METHOD__, $payment_id ) );
		$this->log( sprintf( '%s - Current payment status in DB: %s', __METHOD__, $payment_status ) );

		// Process only temporary activated orders.
		if ( ! in_array( $order_status, array( 'processing', 'completed' ), true ) || ! $temp_activated ) {
			return;
		}

		// GoCardless payment status already updated by webhook.
		if ( in_array( $payment_status, $end_statuses, true ) ) {
			$this->remove_temporary_activated( $order );
			return;
		}

		try {
			$payment = WC_GoCardless_API::get_payment( $payment_id );
			if ( ! is_wp_error( $payment ) && ! empty( $payment['payments'] ) ) {
				$gocardless_status = $payment['payments']['status'];
				$this->log( sprintf( '%s - GoCardless payment status: %s', __METHOD__, $gocardless_status ) );

				if ( ! empty( $gocardless_status ) ) {
					$new_status = '';

					switch ( $gocardless_status ) {
						case 'paid_out':
						case 'confirmed':
							$order->payment_complete( $payment['payments']['id'] );
							break;
						case 'failed':
							$new_status = 'failed';
							break;
						case 'cancelled':
							$new_status = 'cancelled';
							break;
						case 'customer_approval_denied':
						case 'charged_back':
							$new_status = 'on-hold';
							break;
						case 'pending_submission':
						case 'submitted':
						case 'pending_customer_approval':
							// Payment is still in-progress, check again tomorrow.
							if ( function_exists( 'as_schedule_single_action' ) ) {
								as_schedule_single_action( strtotime( '+1 day' ), 'woocommerce_gocardless_check_subscription_payment_status', array( 'order_id' => $order_id ) );
							}
							$this->log( sprintf( '%s - GoCardless payment is still in-progress, will check again tomorrow', __METHOD__ ) );
							break;
					}

					if ( ! empty( $new_status ) ) {
						$note = esc_html__( 'GoCardless payment status check: ', 'woocommerce-gateway-gocardless' );
						$order->update_status( $new_status, $note );
					}

					if ( in_array( $gocardless_status, $end_statuses, true ) ) {
						// Remove temporary activated flag from the order.
						$this->remove_temporary_activated( $order );
					}

					$gateway = $this->gateway_instance();
					if ( $gateway ) {
						$gateway->update_order_resource( $order_id, 'payment', $payment['payments'] );
					}
				}
			}
		} catch ( Exception $e ) {
			$this->log( sprintf( '%s - Error when checking GoCardless payment status: %s', __METHOD__, $e->getMessage() ) );
		}
	}

	/**
	 * Declares compatibility with Woocommerce features.
	 *
	 *  List of features:
	 *  - custom_order_tables
	 *  - product_block_editor
	 *
	 * @since 2.6.2 Rename function
	 * @since 2.5.2
	 */
	public function declare_woocommerce_feature_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WC_GOCARDLESS_MAIN_FILE
			);

			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'product_block_editor',
				WC_GOCARDLESS_MAIN_FILE
			);
		}
	}

	/**
	 * Remove temporary activated flag from the order.
	 *
	 * @param WC_Order $order WC_Order object.
	 * @return void
	 */
	public function remove_temporary_activated( $order ) {
		if ( empty( $order ) ) {
			return;
		}

		$order->delete_meta_data( '_gocardless_temporary_activated' );
		$order->save_meta_data();
	}

	/**
	 * Add GoCardless payment method to WooCommerce Blocks.
	 *
	 * @return void
	 */
	public function woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once $this->plugin_path . '/includes/class-wc-gocardless-gateway-blocks-support.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new WC_GoCardless_Gateway_Blocks_Support() );
				}
			);
		}
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @since 2.7.0
	 * @param string $hook_suffix The current admin page.
	 */
	public function admin_scripts( $hook_suffix ) {
		if (
			'woocommerce_page_wc-settings' === $hook_suffix &&
			isset( $_GET['tab'] ) && 'checkout' === wc_clean( wp_unslash( $_GET['tab'] ) ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_GET['section'] ) && 'gocardless' === wc_clean( wp_unslash( $_GET['section'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			wp_enqueue_script( 'wc-gocardless-admin', $this->plugin_url . '/assets/js/admin.js', array( 'jquery' ), $this->version, true );

			wp_localize_script(
				'wc-gocardless-admin',
				'wc_gocardless_admin_params',
				array(
					'ajax_url'                        => WC_AJAX::get_endpoint( '%%endpoint%%' ),
					'regenerate_webhook_secret_nonce' => wp_create_nonce( 'wc_gocardless_regenerate_webhook_secret' ),
					'loading_text'                    => __( 'Generating...', 'woocommerce-gateway-gocardless' ),
					'generic_error'                   => __( 'An error occurred while generating the webhook secret.', 'woocommerce-gateway-gocardless' ),
					'copied_text'                     => __( 'Copied!', 'woocommerce-gateway-gocardless' ),
					'copy_error'                      => __( 'Copying to the clipboard failed.', 'woocommerce-gateway-gocardless' ),
				)
			);

			wp_enqueue_style( 'wc-gocardless-admin', $this->plugin_url . '/assets/css/admin.css', array(), $this->version, 'all' );
		}
	}

	/**
	 * Register class aliases for backwards compatibility.
	 * This will be removed in future.
	 *
	 * @since 2.7.2
	 */
	private function register_class_aliases() {
		$aliases = array(
			'WC_GoCardless_Gateway'                    => 'WC_Gateway_GoCardless',
			'WC_GoCardless_Gateway_Blocks_Support'     => 'WC_Gateway_GoCardless_Blocks_Support',
			'WC_GoCardless_Payment_Token_Direct_Debit' => 'WC_Payment_Token_Direct_Debit',
		);

		if ( $this->can_use_gateway_addons() ) {
			$aliases['WC_GoCardless_Gateway_Addons'] = 'WC_Gateway_GoCardless_Addons';
		}

		foreach ( $aliases as $new_class => $orig_class ) {
			class_alias( $new_class, $orig_class );
		}
	}
}

/**
 * Return instance of WC_GoCardless.
 *
 * @since 2.3.7
 *
 * @return WC_GoCardless
 */
function wc_gocardless() {
	static $instance;

	if ( ! isset( $instance ) ) {
		$instance = new WC_GoCardless();
	}

	return $instance;
}

/**
 * Get order property with compat check for WC 3.0.
 *
 * @since 2.4.2
 *
 * @param WC_Order $order Order object.
 * @param string   $prop  Order property.
 *
 * @return mixed Order property value.
 */
function wc_gocardless_get_order_prop( $order, $prop ) {
	$value = null;
	switch ( $prop ) {
		case 'order_currency':
			$getter = array( $order, 'get_currency' );
			$value  = is_callable( $getter ) ? call_user_func( $getter ) : $order->get_order_currency();
			break;
		case 'type':
		case 'order_type':
			$getter = array( $order, 'get_type' );
			$value  = is_callable( $getter ) ? call_user_func( $getter ) : $order->order_type;
			break;
		default:
			$getter = array( $order, 'get_' . $prop );
			$value  = is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
	}

	return $value;
}

wc_gocardless();
