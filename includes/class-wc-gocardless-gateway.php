<?php
/**
 * GoCardless gateway.
 *
 * @package WC_GoCardless_Gateway
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway class
 */
class WC_GoCardless_Gateway extends WC_Payment_Gateway {

	/**
	 * Notices to display.
	 *
	 * @since 2.4.0
	 *
	 * @var array
	 */
	private $_notices = array();

	/**
	 * Access token.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Webhook secret.
	 *
	 * @var string
	 */
	private $webhook_secret;

	/**
	 * Scheme.
	 *
	 * @var string
	 */
	private $scheme;

	/**
	 * Saved bank accounts.
	 *
	 * @var bool
	 */
	private $saved_bank_accounts;

	/**
	 * Is instant bank pay turned on.
	 *
	 * @var bool
	 */
	private $instant_bank_pay;

	/**
	 * Test mode.
	 *
	 * @var bool
	 */
	private $testmode;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'gocardless';
		$this->method_title       = __( 'Bank pay (open banking and direct debit via GoCardless)', 'woocommerce-gateway-gocardless' );
		$this->method_description = __( 'GoCardless takes bank payments using open banking and direct debit. Enabled in the UK, the Eurozone, Sweden, Denmark, Australia, New Zealand, Canada and United States.', 'woocommerce-gateway-gocardless' );
		$this->icon               = wc_gocardless()->plugin_url . '/images/gocardless.png';
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
			'subscription_amount_changes',
			'multiple_subscriptions',
			'pre-orders',
		);

		// Load saved settings.
		$this->load_settings();

		// Load the form fields.
		$this->init_form_fields();

		// Initialize settings.
		$this->init_settings();

		$this->view_transaction_url = $this->get_transaction_url_format();

		// Endpoint handler. Handling request such as webhook.
		add_action( 'woocommerce_api_wc_gateway_gocardless', array( $this, 'gocardless_endpoint_handler' ) );

		// Save admin options.
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Notices.
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );

		// Payment-token-API related hook.
		add_filter( 'woocommerce_payment_methods_list_item', array( $this, 'saved_payment_methods_list_item' ), 99, 2 );
		add_action( 'woocommerce_account_payment_methods_column_method', array( $this, 'saved_payment_methods_column_method' ) );

		// Order Pay (Receipt) page handling for complete billing request flow.
		add_filter( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Update the logged_in cookie in current request, after a guest user is created to avoid nonce inconsistencies.
		add_action( 'set_logged_in_cookie', array( $this, 'set_cookie_on_current_request' ) );
	}

	/**
	 * Check access token action (just retrieved or discarded) by user.
	 *
	 * @since 2.4.0
	 * @deprecated 2.8.0
	 * @todo Remove this method in future release.
	 */
	public function check_access_token() {
		wc_deprecated_function( __METHOD__, '2.8.0' );
	}

	/**
	 * Connect GoCardless account.
	 *
	 * @return bool Returns true if connected successfully
	 */
	public function connect_gocardless() {
		// Require the access token.
		if ( empty( $_GET['gocardless_access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in the calling function.
			return false;
		}

		// If we already have a token, ignore this request.
		$existing_access_token = $this->get_option( 'access_token', '' );
		if ( ! empty( $existing_access_token ) ) {
			return false;
		}
		$access_token = urldecode( sanitize_text_field( wp_unslash( $_GET['gocardless_access_token'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in the calling function.
		if ( empty( $access_token ) ) {
			return false;
		}
		$testmode = ( ! empty( $_GET['sandbox'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['sandbox'] ) ) ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in the calling function.

		$settings = get_option( 'woocommerce_gocardless_settings', array() );

		$settings['access_token'] = $access_token;
		$settings['testmode']     = $testmode;

		update_option( 'woocommerce_gocardless_settings', $settings );
		// Clear the available scheme transient.
		delete_transient( 'wc_gocardless_available_scheme_identifiers' );

		// Delete notice that informs merchant to connect with GoCardless.
		WC_Admin_Notices::remove_notice( 'gocardless_connect_prompt' );

		return true;
	}

	/**
	 * Disconnect GoCardless account.
	 *
	 * @return bool Returns true if disconnected successfully.
	 */
	public function disconnect_gocardless() {
		// If we don't have a token, ignore this request.
		$existing_access_token = $this->get_option( 'access_token', '' );
		if ( empty( $existing_access_token ) ) {
			return false;
		}

		$settings = get_option( 'woocommerce_gocardless_settings', array() );

		$settings['access_token'] = '';

		update_option( 'woocommerce_gocardless_settings', $settings );
		// Clear the available scheme transient.
		delete_transient( 'wc_gocardless_available_scheme_identifiers' );

		// Set the disconnect notice.
		return true;
	}

	/**
	 * Get connection HTML.
	 *
	 * @since 2.4.0
	 *
	 * @param mixed $key  Field's key.
	 * @param mixed $data Field's data.
	 *
	 * @return string Connection HTML.
	 */
	public function generate_connection_html( $key, $data ) {
		$access_token = $this->get_option( 'access_token', '' );
		$field_key    = $this->get_field_key( $key );

		$data['description'] = empty( $access_token ) ? $data['connect_description'] : $data['disconnect_description'];
		$data['action_link'] = empty( $access_token ) ? $this->get_connect_url() : $this->get_disconnect_url();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?>
			</th>
			<td class="forminp">
				<a href="<?php echo esc_url( $data['action_link'] ); ?>" class="button-primary">
					<?php if ( ! empty( $access_token ) ) : ?>
						<?php echo esc_html( $data['disconnect_button_label'] ); ?>
					<?php else : ?>
						<?php echo esc_html( $data['connect_button_label'] ); ?>
					<?php endif; ?>
				</a>

				<?php if ( ! empty( $access_token ) ) : ?>
					<span class="gocardless-connected"><span style="color: #00a32a">&#9679;</span>&nbsp;<?php esc_html_e( 'Connected', 'woocommerce-gateway-gocardless' ); ?></span>
				<?php endif; ?>

				<?php if ( empty( $access_token ) ) : ?>
					<p style="padding-top: 20px">
						<a href="<?php echo esc_url( $this->get_connect_url( array( 'sandbox' => true ) ) ); ?>">
							<?php echo esc_html( $data['use_sandbox_link_text'] ); ?>
						</a>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate Webhook Secret Input HTML.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 * @since  2.7.0
	 * @return string
	 */
	public function generate_webhook_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'css'         => '',
			'placeholder' => '',
			'desc_tip'    => false,
			'description' => '',
			'default'     => '',
		);

		$data = wp_parse_args( $data, $defaults );
		// translators: %s: Site title.
		$webhook_name     = sprintf( __( '%s - WooCommerce', 'woocommerce-gateway-gocardless' ), get_bloginfo( 'name' ) );
		$orders           = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_gocardless_webhook_events',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$webhook_received = ( ! empty( $orders ) );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div class="wcgc-webhook-secret-setup <?php echo esc_attr( $webhook_received ? 'connected' : '' ); ?>">
					<p class="wcgc-webhook-secret-actions">
						<a href="javascript:void(0);" class="open">
							<?php esc_html_e( 'Set up sync', 'woocommerce-gateway-gocardless' ); ?>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</a>
						<a href="javascript:void(0);" class="close">
							<?php esc_html_e( 'Hide settings', 'woocommerce-gateway-gocardless' ); ?>
							<span class="dashicons dashicons-arrow-up-alt2"></span>
						</a>
					</p>
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
						<p>
							<?php esc_html_e( 'Receive real-time notifications from GoCardless and automate your store. For example:', 'woocommerce-gateway-gocardless' ); ?>
							<ul>
								<li><?php esc_html_e( 'Detect payments and automatically change order statuses', 'woocommerce-gateway-gocardless' ); ?></li>
								<li><?php esc_html_e( 'Retry payments when a customer has insufficient funds', 'woocommerce-gateway-gocardless' ); ?></li>
								<li><?php esc_html_e( 'Suspend accounts when customers cancel mandates', 'woocommerce-gateway-gocardless' ); ?></li>
								<li><?php esc_html_e( 'Organize subscription payments directly into a customer’s records.', 'woocommerce-gateway-gocardless' ); ?></li>
							</ul>
						</p>
						<p>
							<?php
							// translators: %s: Link GC Dashboard for create webhook.
							echo wp_kses_post( sprintf( __( 'To enable these features, go to the GoCardless <a href="%s" target="_blank">Dashboard</a>, click on “create webhook endpoint” and paste these values:', 'woocommerce-gateway-gocardless' ), $this->get_create_webhook_url() ) );
							?>
						</p>

						<div class="wcgc-webhook-secret-details">
							<div class="wcgc-webhook-secret-field">
								<span class="field-label"><?php esc_html_e( 'Name', 'woocommerce-gateway-gocardless' ); ?></span>
								<input class="input-text regular-input" type="text" id="wcgc-webhook-name" value="<?php echo esc_attr( $webhook_name ); ?>" readonly/>
								<button type="button" class="button-secondary copy-to-clipboard"><?php esc_html_e( 'Copy', 'woocommerce-gateway-gocardless' ); ?></button>
							</div>
							<div class="wcgc-webhook-secret-field">
								<span class="field-label"><?php esc_html_e( 'URL', 'woocommerce-gateway-gocardless' ); ?></span>
								<input class="input-text regular-input" type="text" id="wcgc-webhook-url" value="<?php echo esc_url( $this->get_webhook_url() ); ?>" readonly/>
								<button type="button" class="button-secondary copy-to-clipboard"><?php esc_html_e( 'Copy', 'woocommerce-gateway-gocardless' ); ?></button>
							</div>
							<div class="wcgc-webhook-secret-field">
								<span class="field-label"><?php esc_html_e( 'Secret', 'woocommerce-gateway-gocardless' ); ?></span>
								<input class="input-text regular-input" type="text" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" value="<?php echo esc_attr( $this->get_option( $key, $data['default'] ) ); ?>" />
								<button type="button" class="button-secondary copy-to-clipboard"><?php esc_html_e( 'Copy', 'woocommerce-gateway-gocardless' ); ?></button>
								<button type="button" class="button-secondary regenerate-secret"><?php esc_html_e( 'Regenerate', 'woocommerce-gateway-gocardless' ); ?></button>
								<?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
							</div>
						</div>
					</fieldset>
				</div>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Get hidden field HTML.
	 *
	 * @since 2.4.0
	 *
	 * @param string $key Field's key.
	 *
	 * @return string Hidden field HTML.
	 */
	public function generate_hidden_html( $key ) {
		$field_key = $this->get_field_key( $key );

		return sprintf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( $field_key ),
			esc_attr( $this->get_option( $key ) )
		);
	}

	/**
	 * Get connect URL.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args Arguments to connect URL.
	 *
	 * @return string Connect URL.
	 */
	public function get_connect_url( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'base_url' => 'https://api.woocommerce.com/integrations/login/gocardless',
				'sandbox'  => false,
				'redirect' => '',
			)
		);

		if ( $args['sandbox'] ) {
			$args['base_url'] = 'https://api.woocommerce.com/integrations/login/gocardlesssandbox';
		}

		if ( empty( $args['redirect'] ) ) {
			$args['redirect'] = add_query_arg(
				array(
					'wc_gocardless_connect_nonce' => wp_create_nonce( 'wc_connect_gocardless' ),
					'sandbox'                     => $args['sandbox'] ? 'true' : 'false',
				),
				add_query_arg( 'action', 'wc_connect_gocardless', admin_url( 'admin-post.php' ) )
			);
		}
		$args['redirect'] = urlencode( $args['redirect'] ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Legacy code

		$base_url = $args['base_url'];
		unset( $args['base_url'] );

		return add_query_arg( $args, $base_url );
	}

	/**
	 * Get disconnect URL.
	 *
	 * @since 2.4.0
	 *
	 * @return string Disconnect URL
	 */
	public function get_disconnect_url() {
		$disconnect_url = add_query_arg( 'action', 'wc_disconnect_gocardless', admin_url( 'admin-post.php' ) );

		return wp_nonce_url( $disconnect_url, 'wc_disconnect_gocardless', 'wc_gocardless_disconnect_nonce' );
	}

	/**
	 * Add admin notice.
	 *
	 * @since 2.4.0
	 *
	 * @param string $slug         Slug.
	 * @param string $notice_class Notice class.
	 * @param string $message      Notice message.
	 */
	protected function _add_admin_notice( $slug, $notice_class, $message ) {
		$this->_notices[ $slug ] = array(
			'class'   => $notice_class,
			'message' => $message,
		);
	}

	/**
	 * Display any notices we've collected thus far (e.g. for connection, disconnection).
	 *
	 * @since 2.4.0
	 */
	public function display_admin_notices() {
		foreach ( (array) $this->_notices as $notice_key => $notice ) {
			echo '<div class="' . esc_attr( $notice_key ) . ' ' . esc_attr( $notice['class'] ) . '"><p>';
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
			echo '</p></div>';
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		// "woocommerce_gocardless_default_webhook_secret" is for backward compatibility only and will be removed in future.
		$default_webhook_secret = get_option( 'wc_gocardless_default_webhook_secret', get_option( 'woocommerce_gocardless_default_webhook_secret', false ) );
		if ( ! $default_webhook_secret ) {
			$default_webhook_secret = $this->generate_webhook_secret();
			update_option( 'wc_gocardless_default_webhook_secret', $default_webhook_secret );
		}

		$supported_schemes = $this->get_available_direct_debit_schemes();
		$supported_schemes = array_merge(
			array(
				'' => __( 'Automatically detected from the customer\'s bank account', 'woocommerce-gateway-gocardless' ),
			),
			$supported_schemes
		);

		$this->form_fields = array(
			'connection'          => array(
				'type'                    => 'connection',
				'title'                   => __( 'Connect', 'woocommerce-gateway-gocardless' ),
				'connect_button_label'    => __( 'Connect with GoCardless', 'woocommerce-gateway-gocardless' ),
				'connect_description'     => __( 'Only one store can be connected to your GoCardless account at a time. Connecting a store here will disconnect any other store already connected on that account.', 'woocommerce-gateway-gocardless' ),
				'disconnect_button_label' => __( 'Disconnect from GoCardless', 'woocommerce-gateway-gocardless' ),
				'disconnect_description'  => __( 'You just connected your GoCardless account to WooCommerce. You can start taking payments now.', 'woocommerce-gateway-gocardless' ),
				'use_sandbox_link_text'   => __( 'Not ready to accept live payments? Click here to connect using sandbox mode.', 'woocommerce-gateway-gocardless' ),
				'desc_tip'                => true,
			),
			'enabled'             => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-gateway-gocardless' ),
				'label'       => __( 'Enable Bank pay (open banking and direct debit via GoCardless)', 'woocommerce-gateway-gocardless' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'woocommerce-gateway-gocardless' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-gocardless' ),
				'default'     => __( 'Pay by bank', 'woocommerce-gateway-gocardless' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'woocommerce-gateway-gocardless' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-gocardless' ),
				'default'     => __( 'Pay securely via your bank account.', 'woocommerce-gateway-gocardless' ),
				'desc_tip'    => true,
			),
			'access_token'        => array(
				'type'    => 'hidden',
				'default' => '',
			),
			'webhook_secret'      => array(
				'title'   => __( 'GoCardless Sync', 'woocommerce-gateway-gocardless' ),
				'type'    => 'webhook_secret',
				'default' => $default_webhook_secret,
			),
			'instant_bank_pay'    => array(
				'title'       => __( 'Instant Bank Pay', 'woocommerce-gateway-gocardless' ),
				'label'       => __( 'Enable Instant Bank Pay', 'woocommerce-gateway-gocardless' ),
				'type'        => 'checkbox',
				'description' => __( 'Enables Instant Bank Payments in supported countries.', 'woocommerce-gateway-gocardless' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'saved_bank_accounts' => array(
				'title'       => __( 'Saved Bank Accounts', 'woocommerce-gateway-gocardless' ),
				'label'       => __( 'Enable Payment via Saved Bank Accounts', 'woocommerce-gateway-gocardless' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, users will be able to pay with a saved bank accounts during checkout. Bank account details are stored on GoCardless servers, not on your store.', 'woocommerce-gateway-gocardless' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'scheme'              => array(
				'title'       => __( 'Direct Debit Scheme', 'woocommerce-gateway-gocardless' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'options'     => $supported_schemes,
				'default'     => '',
				/* translators: Link to documentation */
				'description' => sprintf( __( 'The Direct Debit scheme of the mandate. See <a target="_blank" href="%s">this page</a> for  scheme and its supported countries. If ACH, Autogiro, BACS, BECS, BECS NZ, Betalingsservice, PAD or SEPA Core is specified, the payment pages will only allow the set-up of a mandate for the specified scheme. If auto detect is specified, failed validation may occur in case currency in the order is not supported by the scheme.', 'woocommerce-gateway-gocardless' ), 'https://developer.gocardless.com/api-reference#overview-supported-direct-debit-schemes' ),
			),
			'testmode'            => array(
				'type'    => 'hidden',
				'default' => 'no',
			),
			'logging'             => array(
				'title'       => __( 'Logging', 'woocommerce-gateway-gocardless' ),
				'label'       => __( 'Log debug messages', 'woocommerce-gateway-gocardless' ),
				'type'        => 'checkbox',
				'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-gocardless' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Load saved settings.
	 *
	 * @since 2.4.0
	 *
	 * @return void
	 */
	public function load_settings() {
		$this->title               = $this->get_option( 'title', __( 'Direct Debit', 'woocommerce-gateway-gocardless' ) );
		$this->description         = $this->get_option( 'description', '' );
		$this->enabled             = $this->get_option( 'enabled', 'no' );
		$this->access_token        = $this->get_option( 'access_token', '' );
		$this->webhook_secret      = $this->get_option( 'webhook_secret', '' );
		$this->instant_bank_pay    = $this->get_option( 'instant_bank_pay', 'no' ) === 'yes';
		$this->saved_bank_accounts = $this->get_option( 'saved_bank_accounts', 'yes' ) === 'yes';
		$this->scheme              = $this->get_option( 'scheme', '' );
		$this->testmode            = $this->get_option( 'testmode', 'yes' ) === 'yes';
	}

	/**
	 * Check if this gateway is enabled.
	 *
	 * @return bool Check if gateway is available.
	 */
	public function is_available() {
		// Subscription checks availability in checkout settings to display
		// available gateway that supports recurring payments.
		if ( is_admin() && $this->is_checkout_settings_page() ) {
			return parent::is_available();
		}

		if ( ! $this->access_token ) {
			return false;
		}

		// Check if currency is supported.
		$currency = $this->get_payment_currency();
		if ( ! WC_GoCardless_API::is_currency_supported( $currency ) ) {
			return false;
		}

		// Check customer country only for Front-end requests.
		if (
			WC()->customer &&
			( ! is_admin() || defined( 'DOING_AJAX' ) ) &&
			! ( isset( $_POST['action'] ) && 'wcs_import_request' === wc_clean( wp_unslash( $_POST['action'] ) ) ) //phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is already handled on the WooCommerce Subscriptions Importer and Exporter side.
		) {
			$country = WC()->customer->get_billing_country();

			if ( ! WC_GoCardless_API::is_country_supported( $country ) ) {
				return false;
			}
		}

		// Disable the option in add-payment-method page. Will enable this in
		// the future.
		//
		// Don't disable it for My Account Navigation.
		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() && 'woocommerce_account_navigation' !== current_action() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Gets the payment currency, either from current order or WC settings.
	 *
	 * @since 2.8.0
	 *
	 * @return string Three-letter currency code.
	 */
	protected function get_payment_currency() {
		$currency = get_woocommerce_currency();
		$order_id = $this->get_checkout_pay_page_order_id();

		// Get currency for the current order.
		if ( $order_id ) {
			$order    = wc_get_order( $order_id );
			$currency = $order->get_currency();
		}

		return $currency;
	}

	/**
	 * Returns the order_id if on the checkout pay page
	 *
	 * @since 2.8.0
	 * @return int Order ID.
	 */
	public function get_checkout_pay_page_order_id() {
		global $wp;

		return isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
	}


	/**
	 * Check if current admin page is checkout settings page.
	 *
	 * @since 2.4.2
	 *
	 * @return bool Returns true if in checkout settings page.
	 */
	private function is_checkout_settings_page() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( is_null( $screen ) ) {
			return false;
		}

		return (
			'woocommerce_page_wc-settings' === $screen->id
			&&
			! empty( $_GET['tab'] ) //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&&
			'checkout' === $_GET['tab'] //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Payment form on checkout page.
	 *
	 * @since 2.4.0
	 */
	public function payment_fields() {
		if ( $this->description ) {
			/**
			 * Filter description of the payment method.
			 * This description is displayed on the checkout page.
			 *
			 * @since 2.4.0
			 *
			 * @param string $description Description of the payment method.
			 * @return string Description of the payment method.
			 */
			echo wp_kses_post( wpautop( apply_filters( 'woocommerce_gocardless_description', $this->description ) ) );
		}

		$display_tokenization = (
			$this->supports( 'tokenization' )
			&& is_checkout()
			&& $this->saved_bank_accounts
		);

		if ( $display_tokenization ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
			$this->save_payment_method_checkbox();
		}
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			?>
			<div id="wc-gocardless-hidden-fields">
				<input name="wc-gocardless-billing-request-id" type="hidden" value="" />
			</div>
			<?php
		}
		?>
		<noscript>
			<?php esc_html_e( 'Since your browser does not support or has disabled JavaScript, you will not be able to use the GoCardless payment method. To proceed with your payment, please enable JavaScript in your current browser or switch to a browser that supports JavaScript.', 'woocommerce-gateway-gocardless' ); ?>
		</noscript>
		<?php
	}

	/**
	 * Checks whether user requsting checkout to use saved token.
	 *
	 * @since 2.4.0
	 *
	 * @return bool Returns true if processing payment with saved token / mandate
	 */
	protected function _is_processing_payment_with_saved_token() {
		return (
			isset( $_POST['wc-gocardless-payment-token'] ) //phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is already handled on the WooCommerce side.
			&&
			'new' !== sanitize_text_field( wp_unslash( $_POST['wc-gocardless-payment-token'] ) ) //phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is already handled on the WooCommerce side.
		);
	}

	/**
	 * Process payment with saved token.
	 *
	 * The stored token contains mandate ID that can be used to take payment
	 * from the customer.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array|WP_Error Returns array if succeed, otherwise WP_Error is returned.
	 */
	protected function _process_payment_with_saved_token( WC_Order $order ) {
		try {
			$token_id = isset( $_POST['wc-gocardless-payment-token'] ) ? wc_clean( wp_unslash( $_POST['wc-gocardless-payment-token'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is done in WooCommerce side.
			$token    = WC_Payment_Tokens::get( $token_id );
			if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
				throw new Exception( esc_html__( 'Invalid payment method. Please setup a new direct debit account.', 'woocommerce-gateway-gocardless' ) );
			}

			$mandate_id = $token->get_token();
			$mandate    = WC_GoCardless_API::get_mandate( $mandate_id );
			if ( is_wp_error( $mandate ) ) {
				throw new Exception( esc_html__( 'Failed to retrieve mandate.', 'woocommerce-gateway-gocardless' ) );
			}

			$order_id = wc_gocardless_get_order_prop( $order, 'id' );

			$this->update_order_resource( $order, 'mandate', $mandate['mandates'] );

			$this->_maybe_create_payment( $order_id, $mandate_id );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array(
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Process payment with billing request flow.
	 *
	 * @since 2.7.0
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array|bool Returns array if succeed, otherwise false is returned
	 */
	protected function _process_payment_with_billing_request_flow( WC_Order $order ) {
		return $this->create_billing_request_flow( $order );
	}

	/**
	 * Create a billing request and billing request flow to process the payment.
	 *
	 * @see https://developer.gocardless.com/api-reference/#billing-requests-billing-requests
	 * @see https://developer.gocardless.com/api-reference/#billing-requests-billing-request-flows
	 *
	 * @since 2.7.0
	 *
	 * @param WC_Order $order Order object.
	 * @return array Returns array with created billing request flow ID if succeed, otherwise array with error message is returned.
	 */
	public function create_billing_request_flow( WC_Order $order ) {
		wc_gocardless()->log( sprintf( '%s - Creating a billing request for order #%s', __METHOD__, $order->get_order_number() ) );

		$new_customer           = true;
		$billing_request_params = array(
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		/*
		 * Decide strategy for collecting the payment.
		 * - Mandate only:
		 *     - For countries and currencies where instant payment is not supported.
		 *     - Order total is zero (eg: Setup Trail subscription)
		 *     - For the change payment method request.
		 *     - For Pre-Orders with Pay upon release.
		 * - Instant Bank Payment Only (For one-off payment):
		 *     - IBP is Enabled in the settings &&
		 *     - For countries and currencies where instant payment is supported. (GB with GBP, DE with EUR) &&
		 *     - Order total is greater than zero &&
		 *     - Not a change payment method request &&
		 *     - Not a Pre-Orders with Pay upon release.
		 * - Mandate and Instant Bank Payment:
		 *     - Instant Bank Payment Only condition +
		 *     - Order contains subscription product.
		 *     - Customer wants to save the payment method.
		 */
		if (
			$this->supports_instant_payment( $order ) &&
			$order->get_total() > 0 &&
			! $this->is_change_payment_method_request() &&
			! $this->is_pre_orders_pay_upon_release( $order )
		) {
			$payment_request = array(
				'description' => $this->_get_description_from_order( $order ),
				'amount'      => absint( wc_format_decimal( ( (float) $order->get_total() * 100 ), wc_get_price_decimals() ) ),
				'currency'    => wc_gocardless_get_order_prop( $order, 'order_currency' ),
				'metadata'    => array(
					'order_id' => (string) $order->get_id(),
				),
			);

			$billing_request_params['payment_request'] = $payment_request;

			// If mandate is required, add mandate request.
			if ( $this->needs_mandate( $order ) ) {
				$mandate_request = array(
					'currency' => wc_gocardless_get_order_prop( $order, 'currency' ),
				);
				if ( ! empty( $this->scheme ) ) {
					$mandate_request['scheme'] = $this->scheme;
				}

				$billing_request_params['mandate_request'] = $mandate_request;
			}
		} else {
			// Mandate only.
			$mandate_request = array(
				'currency' => wc_gocardless_get_order_prop( $order, 'currency' ),
			);
			if ( ! empty( $this->scheme ) ) {
				$mandate_request['scheme'] = $this->scheme;
			}

			$billing_request_params['mandate_request'] = $mandate_request;
		}

		// Check if the customer has a GoCardless customer ID.
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
		 * Filter the billing request params.
		 * This filter can be used to modify the billing request params before
		 * creating the billing request.
		 *
		 * @since 2.7.0
		 *
		 * @param array $billing_request_params Billing request params.
		 * @return array Billing request params.
		 */
		$billing_request_params = apply_filters( 'woocommerce_gocardless_create_billing_request_params', $billing_request_params );
		$billing_request        = WC_GoCardless_API::create_billing_request( $billing_request_params );

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

		// Update the customer's ID in the WP user meta.
		if ( $new_customer && isset( $billing_request['billing_requests']['links'] ) && ! empty( $billing_request['billing_requests']['links']['customer'] ) ) {
			$customer_id = wc_clean( $billing_request['billing_requests']['links']['customer'] );
			update_user_meta( get_current_user_id(), '_gocardless_customer_id', $customer_id );
		}

		wc_gocardless()->log( sprintf( '%s - Billing request created: %s', __METHOD__, print_r( $billing_request, true ) ) );

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
		);

		/**
		 * Filter the billing request flow params.
		 * This filter can be used to modify the billing request flow params before
		 * creating the billing request flow.
		 *
		 * @since 2.7.0
		 *
		 * @param array $billing_request_flow_params Billing request flow params.
		 * @return array Billing request flow params.
		 */
		$billing_request_flow_params = apply_filters( 'woocommerce_gocardless_billing_request_flow_params_params', $billing_request_flow_params );
		$billing_request_flow        = WC_GoCardless_API::create_billing_request_flow( $billing_request_flow_params );
		if ( is_wp_error( $billing_request_flow ) ) {
			return array(
				'result'  => 'failure',
				'message' => $billing_request_flow->get_error_message(),
			);
		}
		if ( empty( $billing_request_flow['billing_request_flows']['id'] ) ) {
			return array(
				'result'  => 'failure',
				'message' => esc_html__( 'Error processing checkout. Please try again.', 'woocommerce-gateway-gocardless' ),
			);
		}
		$this->update_order_resource( $order, 'billing_request_flow', $billing_request_flow['billing_request_flows'] );

		wc_gocardless()->log( sprintf( '%s - Billing request flow created: %s', __METHOD__, print_r( $billing_request_flow, true ) ) );

		$response = array(
			'result'                  => 'success',
			'redirect'                => $order->get_checkout_payment_url(),
			'billing_request_flow_id' => $billing_request_flow['billing_request_flows']['id'],
		);

		// Update nonce if the customer is created.
		if ( did_action( 'woocommerce_created_customer' ) > 0 ) {
			$response['billing_request_nonce'] = wp_create_nonce( 'wc_gocardless_complete_billing_request_flow' );
		}

		return $response;
	}

	/**
	 * Process payment for pay page.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Returns array with redirect url succeed, otherwise array with error message is returned.
	 */
	public function process_payment_for_pay_page( WC_Order $order ) {
		$billing_request_id = isset( $_POST['wc-gocardless-billing-request-id'] ) ? sanitize_text_field( wp_unslash( $_POST['wc-gocardless-billing-request-id'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is already handled on the WooCommerce side.
		if ( empty( $billing_request_id ) ) {
			wc_add_notice( esc_html__( 'Billing request ID is required.', 'woocommerce-gateway-gocardless' ), 'error' );
			return array(
				'result'  => 'failure',
				'message' => esc_html__( 'Billing request ID is required.', 'woocommerce-gateway-gocardless' ),
			);
		}

		$save_token = (
			! empty( $_POST['wc-gocardless-new-payment-method'] ) //phpcs:ignore WordPress.Security.NonceVerification -- Nonce verification is already handled on the WooCommerce side.
			&& $this->saved_bank_accounts
			&& get_current_user_id()
		);

		$response = $this->handle_billing_request_complete( $order, $billing_request_id, $save_token );
		if ( 'failure' === $response['result'] ) {
			wc_add_notice( $response['message'], 'error' );
		}
		return $response;
	}

	/**
	 * Check if the payment method supports instant payment.
	 *
	 * @since 2.7.0
	 *
	 * @param WC_Order $order Order object.
	 * @return bool Returns true if the payment method supports instant payment.
	 */
	private function supports_instant_payment( $order ) {
		$is_ibp_enabled = $this->get_option( 'instant_bank_pay', 'no' ) === 'yes';
		$is_supported   = false;

		// Only check for supported country and currency if IBP is enabled in payment method settings.
		if ( $is_ibp_enabled ) {
			$country           = $order->get_billing_country();
			$currency          = wc_gocardless_get_order_prop( $order, 'currency' );
			$supported_schemes = $this->get_available_payment_request_schemes();

			switch ( $currency ) {
				case 'EUR':
					$supported_countries = array( 'DE', 'FR' );
					$schemes             = array( 'sepa_credit_transfer', 'sepa_instant_credit_transfer' );
					if ( in_array( $country, $supported_countries, true ) && ! empty( array_intersect( $schemes, $supported_schemes ) ) ) {
						$is_supported = true;
					}
					break;
				case 'GBP':
					$supported_countries = array( 'GB' );
					if ( in_array( $country, $supported_countries, true ) && in_array( 'faster_payments', $supported_schemes, true ) ) {
						$is_supported = true;
					}
					break;
			}
		}

		/**
		 * Filter supports instant payment.
		 * This filter can be used to modify the instant payment support.
		 *
		 * @since 2.7.0
		 *
		 * @param bool     $is_supported Returns true if the payment method supports instant payment.
		 * @param WC_Order $order        Order object.
		 * @return bool Returns true if the payment method supports instant payment.
		 */
		return apply_filters( 'woocommerce_gocardless_supports_instant_payment', $is_supported, $order );
	}

	/**
	 * Check if the order needs mandate.
	 *
	 * The mandate is required for the following cases:
	 * - Order contains pre-orders product which needs to be paid upon release.
	 * - Order contains subscription product.
	 * - If Customer has checked save bank account.
	 *
	 * @since 2.7.0
	 *
	 * @param WC_Order $order Order object.
	 * @return bool Returns true if the order needs mandate.
	 */
	private function needs_mandate( $order ) {
		$needs_mandate = false;

		// Check if customer has checked save payment method.
		$save_token = (
			! empty( $_POST['wc-gocardless-new-payment-method'] ) //phpcs:ignore WordPress.Security.NonceVerification -- Nonce verification is already handled on the WooCommerce side.
			&& $this->saved_bank_accounts
			&& get_current_user_id()
		);

		if ( $save_token ) {
			return true;
		}

		// Pre-Orders product which needs to be paid upon release needs mandate.
		if (
			class_exists( 'WC_Pre_Orders_Order' ) &&
			$order &&
			WC_Pre_Orders_Order::order_contains_pre_order( $order ) &&
			WC_Pre_Orders_Order::order_requires_payment_tokenization( $order )
		) {
			return true;
		}

		// Subscription needs mandate.
		if ( class_exists( 'WC_Subscriptions' ) && ( wcs_order_contains_subscription( $order, 'any' ) || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) ) {
			return true;
		}

		return $needs_mandate;
	}

	/**
	 * Check if request is to change payment method.
	 *
	 * @since 2.7.0
	 *
	 * @return boolean
	 */
	private function is_change_payment_method_request() {
		return class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
	}

	/**
	 * Check if the order contains pre-orders product which needs to be paid upon release.
	 *
	 * @since 2.7.0
	 *
	 * @param WC_Order $order Order object.
	 * @return bool Returns true if the order contains pre-orders product which needs to be paid upon release.
	 */
	private function is_pre_orders_pay_upon_release( $order ) {
		if (
			class_exists( 'WC_Pre_Orders_Order' ) &&
			$order &&
			WC_Pre_Orders_Order::order_contains_pre_order( $order ) &&
			WC_Pre_Orders_Order::order_will_be_charged_upon_release( $order )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Set logged_in cookie with current request using new login session (to ensure consistent nonce).
	 * Only apply during the checkout process with the account creation.
	 *
	 * @param string $cookie Cookie value.
	 */
	public function set_cookie_on_current_request( $cookie ) {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT && did_action( 'woocommerce_created_customer' ) > 0 ) {
			if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
				return;
			}

			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		}
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array|bool Returns array if succeed, otherwise false is returned
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $this->_is_processing_payment_with_saved_token() ) {
			return $this->_process_payment_with_saved_token( $order );
		}

		if ( isset( $_GET['pay_for_order'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verification is already handled on the WooCommerce side.
			// Pay for order page.
			return $this->process_payment_for_pay_page( $order );
		}

		return $this->_process_payment_with_billing_request_flow( $order );
	}

	/**
	 * Process refund.
	 *
	 * Refund is disabled by default. Merchant needs to contact GoCardless to
	 * enable refund.
	 *
	 * @since 2.4.0
	 *
	 * @param  int    $order_id      Order ID.
	 * @param  float  $refund_amount Amount to refund.
	 * @param  string $reason        Reason to refund.
	 *
	 * @return WP_Error|boolean True or false based on success, or a WP_Error object.
	 */
	public function process_refund( $order_id, $refund_amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		wc_gocardless()->log( sprintf( '%s - Refunding order #%s', __METHOD__, $order->get_order_number() ) );

		$payment_id = $this->get_order_resource( $order_id, 'payment', 'id' );
		if ( ! $payment_id ) {
			// translators: %s: Order Number.
			return new WP_Error( 'missing_payment', sprintf( esc_html__( 'Unable to refund order #%s. Order does not have payment ID. Make sure payment has been created.', 'woocommerce-gateway-gocardless' ), $order->get_order_number() ) );
		}

		$amount_in_cents       = intval( 100 * $refund_amount );
		$total_amount_in_cents = intval( 100 * $order->get_total_refunded() );

		/**
		 * Filter the refund params.
		 * This filter can be used to modify the refund params before creating the refund.
		 *
		 * @since 2.4.0
		 * @param array $refund_params Refund params.
		 * @return array Refund params.
		 */
		$refund_params = apply_filters(
			'woocommerce_gocardless_refund_params',
			array(
				'amount'                    => $amount_in_cents,
				'total_amount_confirmation' => $total_amount_in_cents,
				'links'                     => array( 'payment' => $payment_id ),
				'metadata'                  => array(
					'order_id'    => (string) $order_id,
					'order_total' => (string) $order->get_total(),
					'reason'      => $reason,
				),
			)
		);

		$refund = WC_GoCardless_API::create_refund( $refund_params );
		if ( is_wp_error( $refund ) ) {
			/* translators: Error message */
			$order->add_order_note( sprintf( esc_html__( 'Unable to refund via GoCardless: %s', 'woocommerce-gateway-gocardless' ), $refund->get_error_message() ) );
			return $refund;
		}

		if ( empty( $refund['refunds']['id'] ) ) {
			$order->add_order_note( esc_html__( 'Unable to refund via GoCardless. GoCardless returns unexpected refund response.', 'woocommerce-gateway-gocardless' ) );
			return new WP_Error( 'unexpected_refund_response', esc_html__( 'Unexpected refund response from GoCardless.', 'woocommerce-gateway-gocardless' ) );
		}

		$this->update_order_resource( $order, 'refund', $refund['refunds'] );

		$refund_id = $refund['refunds']['id'];
		$reference = $refund['refunds']['reference'] ?? '';
		$currency  = $refund['refunds']['currency'] ?? $order->get_currency();

		$order->add_order_note(
			sprintf(
				/* translators: Refund amount, refund ID, reference and (reason) */
				esc_html__( 'Refunded %1$s with refund ID %2$s and reference %3$s (%4$s)', 'woocommerce-gateway-gocardless' ),
				wc_price( $refund['refunds']['amount'] * 0.01, array( 'currency' => $currency ) ),
				esc_html( $refund_id ),
				esc_html( $reference ),
				$reason ? $reason : esc_html__( 'No reason provided', 'woocommerce-gateway-gocardless' )
			)
		);

		return true;
	}

	/**
	 * Get success redirect URL.
	 *
	 * @since 2.4.0
	 * @deprecated 2.7.0
	 *
	 * @param WC_Order|int $order Order object or ID.
	 *
	 * @return string Success redirect URL.
	 */
	public function get_success_redirect_url( $order ) {
		wc_deprecated_function( __METHOD__, '2.7.0' );

		$order    = wc_get_order( $order );
		$order_id = wc_gocardless_get_order_prop( $order, 'id' );
		$params   = array(
			'request'  => 'redirect_flow',
			'order_id' => $order_id,
		);

		$save_token = (
			! empty( $_POST['wc-gocardless-new-payment-method'] ) //phpcs:ignore WordPress.Security.NonceVerification -- Nonce verification is already handled on the WooCommerce side.
			&& $this->saved_bank_accounts
			&& get_current_user_id()
		);

		if ( $save_token ) {
			$params['save_customer_token'] = 'true';
		}

		$url = add_query_arg( $params, WC()->api_request_url( __CLASS__, true ) );

		return $url;
	}

	/**
	 * Get webhook URL.
	 *
	 * @since 2.4.0
	 *
	 * @return string Webhook URL
	 */
	public function get_webhook_url() {
		return add_query_arg( array( 'request' => 'webhook' ), WC()->api_request_url( 'WC_Gateway_GoCardless', true ) );
	}

	/**
	 * Get create webhook URL via GoCardless dashboard.
	 *
	 * @since 2.4.0
	 *
	 * @return string Dashboard URL
	 */
	public function get_create_webhook_url() {
		return sprintf( 'https://%s.gocardless.com/developers/webhook-endpoints/create', $this->testmode ? 'manage-sandbox' : 'manage' );
	}

	/**
	 * Handler for GoCardless endpoint.
	 *
	 * @since 2.4.0
	 */
	public function gocardless_endpoint_handler() {
		try {
			if ( empty( $_GET['request'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
				throw new Exception( esc_html__( 'Missing request type.', 'woocommerce-gateway-gocardless' ) );
			}

			switch ( $_GET['request'] ) { //phpcs:ignore WordPress.Security.NonceVerification
				case 'webhook':
					$this->_handle_webhook();
					break;
				default:
					throw new Exception( esc_html__( 'Unknown request type.', 'woocommerce-gateway-gocardless' ) );
					break; //phpcs:ignore Squiz.PHP.NonExecutableCode.Unreachable
			}
		} catch ( Exception $e ) {
			header( 'HTTP/1.1 400 Bad request' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler after mandate is created.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $mandate_id Mandate ID.
	 *
	 * @throws \Exception Exception.
	 */
	protected function _after_mandate_created( $order_id, $mandate_id ) {
		$order = wc_get_order( $order_id );
		wc_gocardless()->log( sprintf( '%s - Mandate created', __METHOD__ ) );
		wc_gocardless()->log( sprintf( '%s - Creating GoCardless payment for order %s', __METHOD__, $order->get_order_number() ) );

		$this->_maybe_create_payment( $order_id, $mandate_id );

		wc_gocardless()->log( sprintf( '%s - Payment created', __METHOD__ ) );
	}

	/**
	 * Maybe create the payment of a given order_id and mandate_id.
	 *
	 * @since 2.4.0
	 *
	 * @throws \Exception Exception.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $mandate_id Mandate ID.
	 * @param float  $amount     Amount to charge.
	 *
	 * @return void
	 */
	protected function _maybe_create_payment( $order_id, $mandate_id, $amount = null ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Exception( esc_html__( 'Invalid order.', 'woocommerce-gateway-gocardless' ) );
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}
		// Save it here in case we need to call this method again if mandate
		// is replaced.
		$original_amount = $amount;

		// Amount in pence (GBP), cents (EUR/AUD/NZD/CAD/USD), øre (DKK/SEK).
		$amount = absint( wc_format_decimal( ( (float) $amount * 100 ), wc_get_price_decimals() ) );

		// Maybe free products or pre-order.
		if ( ! $amount ) {
			$order->payment_complete();

			if ( function_exists( 'wc_empty_cart' ) ) {
				wc_empty_cart();
			}

			return;
		}

		// If switching payment method for subscriptions.
		if ( ! $order->needs_payment() ) {
			return;
		}

		/**
		 * Filter the payment params.
		 * This filter can be used to modify the payment params before creating the payment.
		 *
		 * @since 2.4.0
		 * @param array $payment_params Payment params.
		 * @return array Payment params.
		 */
		$payment_params = apply_filters(
			'woocommerce_gocardless_create_payment_params',
			array(
				'amount'            => $amount,
				'description'       => $this->_get_description_from_order( $order ),
				'currency'          => wc_gocardless_get_order_prop( $order, 'order_currency' ),
				'links'             => array(
					'mandate' => $mandate_id,
				),
				'metadata'          => array(
					'order_id' => (string) $order_id,
				),
				'retry_if_possible' => true,
			)
		);

		$payment = WC_GoCardless_API::create_payment( $payment_params );
		if ( is_wp_error( $payment ) ) {
			if ( $this->is_mandate_replaced( $payment ) ) {
				$new_mandate = $payment->get_error_data( 'new_mandate' );

				$this->_update_mandate( $mandate_id, $new_mandate );

				// Retry again.
				wc_gocardless()->log( sprintf( '%s - Retry create payment with new mandate', __METHOD__ ) );
				return $this->_maybe_create_payment( $order_id, $new_mandate, $original_amount );
			} else {
				/* translators: Error message */
				throw new Exception( sprintf( esc_html__( 'Unable to create payment: %s.', 'woocommerce-gateway-gocardless' ), esc_html( $payment->get_error_message() ) ) );
			}
		}

		if ( empty( $payment['payments']['id'] ) ) {
			throw new Exception( esc_html__( 'Unexpected payment response from GoCardless.', 'woocommerce-gateway-gocardless' ) );
		}

		// Handle payment creation.
		$this->handle_payment_creation( $order_id, $payment, true );
	}

	/**
	 * Handle payment creation for a given order.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $payment  Payment response from GoCardless.
	 * @param bool  $is_dd    Is Direct Debit (mandate) payment? Default is false.
	 * @return void
	 */
	private function handle_payment_creation( $order_id, $payment, $is_dd = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Exception( esc_html__( 'Invalid order.', 'woocommerce-gateway-gocardless' ) );
		}

		// Update order resource.
		$this->update_order_resource( $order, 'payment', $payment['payments'] );

		// Set payment status based on the payment status.
		switch ( $payment['payments']['status'] ) {
			case 'paid_out':
			case 'confirmed':
				$status = 'processing';
				break;
			case 'failed':
				$status = 'failed';
				break;
			case 'cancelled':
				$status = 'cancelled';
				break;
			default:
				/**
				 * For Subscriptions, set the order status to processing.
				 *
				 * Direct debit can take around a week, so would be useless for
				 * membership sites.
				 */
				$temp_activated = false;
				if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id ) ) {
					/**
					 * Filter the order status for subscription payment.
					 * This filter can be used to change the initial order status for subscription payment.
					 * By default, the order status is set to processing.
					 *
					 * @since 2.4.4
					 *
					 * @param string $status   Order status.
					 * @param int    $order_id Order ID.
					 * @return string Order status.
					 */
					$status         = apply_filters( 'woocommerce_gocardless_create_payment_subscription_order_status', 'processing', $order_id );
					$temp_activated = true;
				} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order_id ) ) {
					/**
					 * Filter the order status for subscription renewal payment.
					 * This filter can be used to change the initial order status for subscription renewal payment.
					 * By default, the order status is set to processing.
					 *
					 * @since 2.4.4
					 *
					 * @param string $status   Order status.
					 * @param int    $order_id Order ID.
					 * @return string Order status.
					 */
					$status         = apply_filters( 'woocommerce_gocardless_create_payment_subscription_renewal_order_status', 'processing', $order_id );
					$temp_activated = true;
				} else {
					/**
					 * Filter the order status for payment.
					 * This filter can be used to change the initial order status for payment.
					 * By default, the order status is set to on-hold.
					 *
					 * @since 2.4.4
					 *
					 * @param string $status   Order status.
					 * @param int    $order_id Order ID.
					 * @return string Order status.
					 */
					$status = apply_filters( 'woocommerce_gocardless_create_payment_order_status', 'on-hold', $order_id );
				}

				// Mark Subscription order temporary activated to check payment status later on.
				if ( true === $temp_activated ) {
					$this->_mark_order_temporary_activated( $order_id, $payment );
				}
				break;
		}

		$payment_id = $payment['payments']['id'];
		$mandate_id = $payment['payments']['links']['mandate'] ?? '';
		if ( $is_dd ) {
			$order_note = sprintf(
				/* translators: mandate ID, Payment ID, Status */
				__( 'GoCardless payment created with mandate %1$s, payment ID %2$s, and status "%3$s"', 'woocommerce-gateway-gocardless' ),
				'<a href="' . esc_url( sprintf( $this->get_mandate_url_format(), $mandate_id ) ) . '" target="_blank">' . esc_html( $mandate_id ) . '</a>',
				'<a href="' . esc_url( sprintf( $this->get_transaction_url_format(), $payment_id ) ) . '" target="_blank">' . esc_html( $payment_id ) . '</a>',
				esc_html( $payment['payments']['status'] )
			);
		} else {
			$order_note = sprintf(
				/* translators: Payment ID, Status */
				__( 'GoCardless payment created with ID %1$s and status "%2$s"', 'woocommerce-gateway-gocardless' ),
				'<a href="' . esc_url( sprintf( $this->get_transaction_url_format(), $payment_id ) ) . '" target="_blank">' . esc_html( $payment_id ) . '</a>',
				esc_html( $payment['payments']['status'] )
			);
		}

		$order->add_order_note( wp_kses_post( $order_note ) );

		/**
		 * Compatibility with Order Status Control.
		 */
		if ( 'processing' === $status ) {
			$order->payment_complete( $payment['payments']['id'] );
		} else {
			$order->update_status( $status );
		}

		// Reduce stock levels.
		if ( ! in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			wc_reduce_stock_levels( $order_id );
		}

		if ( function_exists( 'wc_empty_cart' ) ) {
			wc_empty_cart();
		}
	}

	/**
	 * Checks whether a response specifies if mandate has been replaced.
	 *
	 * @since 2.4.5
	 * @version 2.4.5
	 *
	 * @param array|WP_Error $resp Response from create payments.
	 *
	 * @return bool Returns true if response specifies mandate has been replaced.
	 */
	protected function is_mandate_replaced( $resp ) {
		return (
			is_wp_error( $resp )
			&&
			422 === (int) $resp->get_error_code()
			&&
			false !== strstr( $resp->get_error_message(), 'mandate_replaced' )
			&&
			$resp->get_error_data( 'new_mandate' )
		);
	}
	/**
	 * Handle redirect flow from GoCardless.
	 *
	 * @since 2.4.0
	 *
	 * @return void
	 */
	protected function _handle_webhook() {
		$this->load_settings();

		$raw_payload = file_get_contents( 'php://input' );
		$signature   = ! empty( $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ) ) : '';
		$secret      = wp_specialchars_decode( $this->webhook_secret, ENT_QUOTES );

		$calc_signature = hash_hmac( 'sha256', $raw_payload, $secret );

		try {
			if ( $signature !== $calc_signature ) {
				header( 'HTTP/1.1 498 Invalid signature' );
				throw new Exception( esc_html__( 'Invalid signature.', 'woocommerce-gateway-gocardless' ) );
			}

			$payload = json_decode( $raw_payload, true );
			if ( empty( $payload['events'] ) ) {
				header( 'HTTP/1.1 400 Bad request' );
				throw new Exception( esc_html__( 'Missing events in payload.', 'woocommerce-gateway-gocardless' ) );
			}

			$args = array( $payload );

			// Process the webhook payload asynchronously.
			WC()->queue()->schedule_single(
				WC()->call_function( 'time' ) + 1,
				'woocommerce_gocardless_process_webhook_payload_async',
				$args,
				'woocommerce-gocardless-webhook'
			);

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Process webhook payload.
	 *
	 * @since 2.4.0
	 * @version 2.4.5
	 *
	 * @param array $payload Payload.
	 */
	public function process_webhook_payload( array $payload ) {
		foreach ( $payload['events'] as $event ) {
			switch ( $event['resource_type'] ) {
				case 'mandates':
					$this->_process_mandate_event( $event );
					break;
				case 'payments':
					$this->_process_payment_event( $event );
					break;
				case 'refunds':
					$this->_process_refund_event( $event );
					break;
				case 'billing_requests':
					$this->_process_billing_request_event( $event );
					break;
				case 'subscriptions':
					// Since 2.4.0, subscriptions on the GoCardless side is not
					// used anymore. This handler helps to process subscriptions
					// created before 2.4.0.
					$this->_process_subscription_event( $event );
					break;
				default:
					wc_gocardless()->log( sprintf( '%s - Unhandled webhook event %s', __METHOD__, $event['resource_type'] ) );
			}
		}
	}

	/**
	 * Process mandate event from webhook.
	 *
	 * @since 2.4.5
	 * @version 2.4.5
	 *
	 * @param array $event Event payload.
	 */
	protected function _process_mandate_event( array $event ) {
		wc_gocardless()->log( sprintf( '%1$s - Handling mandate event with action "%2$s"', __METHOD__, $event['action'] ) );

		switch ( $event['action'] ) {
			case 'replaced':
				$this->_process_mandate_event_replaced( $event );
				break;
			case 'cancelled':
			case 'failed':
			case 'expired':
			case 'blocked':
				if ( empty( $event['links']['mandate'] ) ) {
					wc_gocardless()->log( sprintf( '%s - Mandate ID is missing from event payload', __METHOD__ ) );
					break;
				}
				// Remove saved payment methods if mandate is no longer active.
				$this->_remove_saved_payment_methods( $event['links']['mandate'] );
				break;
			default:
				// Only log other mandate events at this time.
				wc_gocardless()->log( sprintf( '%1$s - Unhandled mandate event with action "%2$s": %3$s', __METHOD__, $event['action'], print_r( $event, true ) ) );
		}
	}
	/**
	 * Process mandate event 'replaced' from webhook.
	 *
	 * @since 2.4.5
	 * @version 2.4.5
	 *
	 * @param array $event Event payload.
	 */
	protected function _process_mandate_event_replaced( array $event ) {
		$old_mandate = $event['links']['mandate'];
		$new_mandate = $event['links']['new_mandate'];

		$this->_update_mandate( $old_mandate, $new_mandate );
	}

	/**
	 * Remove saved payment methods by mandate ID.
	 *
	 * @since 2.7.0
	 * @param string $mandate_id Mandate ID.
	 * @return void
	 */
	protected function _remove_saved_payment_methods( $mandate_id ) {
		try {
			// Remove mandates that are stored as payment tokens.
			$token_ids = $this->_get_token_ids_by_mandate( $mandate_id );
			foreach ( $token_ids as $token_id ) {
				// Remove payment token.
				WC_Payment_Tokens::delete( absint( $token_id ) );
				wc_gocardless()->log(
					sprintf(
						'%1$s - The payment token with ID %2$s has been removed as the mandate is no longer active.',
						__METHOD__,
						$token_id
					)
				);
			}
		} catch ( Exception $e ) {
			wc_gocardless()->log( sprintf( '%1$s - Error when removing saved payment methods: %2$s', __METHOD__, $e->getMessage() ) );
		}
	}

	/**
	 * Update mandate with new mandate.
	 *
	 * @since 2.4.5
	 * @version 2.4.5
	 *
	 * @param string $old_mandate Old mandate to replace.
	 * @param string $new_mandate New mandate.
	 */
	protected function _update_mandate( $old_mandate, $new_mandate ) {
		if ( empty( $old_mandate ) || empty( $new_mandate ) ) {
			wc_gocardless()->log(
				sprintf( '%s - Old mandate or new mandate empty, skip updating payment tokens and orders', __METHOD__ )
			);
			return;
		}

		// Update mandates that stored as payment tokens.
		$token_ids = $this->_get_token_ids_by_mandate( $old_mandate );
		foreach ( $token_ids as $token_id ) {
			$token = WC_Payment_Tokens::get( absint( $token_id ) );

			$token->set_token( $new_mandate );
			$token->save();

			wc_gocardless()->log(
				sprintf(
					'%1$s - Updated payment token with ID %2$s from old mandate (%3$s) to new mandate (%4$s)',
					__METHOD__,
					$token_id,
					$old_mandate,
					$new_mandate
				)
			);
		}

		global $wpdb;

		$meta_table = $wpdb->postmeta;
		if ( WC_GoCardless_Compat::is_cot_enabled() ) {
			$meta_table = Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
		}

		// Update meta in posts (orders or subscriptions) that store mandates.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$meta_table} SET meta_value = %s " .
				"WHERE meta_key = '_gocardless_mandate' AND meta_value = %s",
				maybe_serialize( array( 'id' => $new_mandate ) ),
				maybe_serialize( array( 'id' => $old_mandate ) )
			)
		);
		// phpcs:enable
		wc_gocardless()->log(
			sprintf(
				'%1$s - Updated %2$s "_gocardless_mandate" metas from old mandate (%3$s) to new mandate (%4$s)',
				__METHOD__,
				$updated,
				$old_mandate,
				$new_mandate
			)
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$meta_table} SET meta_value = %s " .
				"WHERE meta_key = '_gocardless_mandate_id' AND meta_value = %s",
				$new_mandate,
				$old_mandate
			)
		);
		// phpcs:enable
		wc_gocardless()->log(
			sprintf(
				'%1$s - Updated %2$s "_gocardless_mandate_id" metas from old mandate (%3$s) to new mandate (%4$s)',
				__METHOD__,
				$updated,
				$old_mandate,
				$new_mandate
			)
		);
	}


	/**
	 * Process payment event from webhook.
	 *
	 * @since 2.5.4 Add logic to cancel subscriptions when orders cancel with mandate cancellation.
	 * @since 2.4.0
	 *
	 * @param array $event Event payload.
	 *
	 * @return bool|int On success, returns the ID of the inserted row, which
	 *                  validates to true
	 */
	protected function _process_payment_event( array $event ) {
		$order = $this->get_order_from_resource( 'payment', 'id', $event['links']['payment'] );
		if ( ! $order ) {
			wc_gocardless()->log( sprintf( '%s - Could not found order with payment ID "%s" with payload: %s', __METHOD__, $event['links']['payment'], print_r( $event, true ) ) );
			return false;
		}

		$order_id       = wc_gocardless_get_order_prop( $order, 'id' );
		$payment_method = wc_gocardless_get_order_prop( $order, 'payment_method' );
		if ( 'gocardless' !== $payment_method ) {
			wc_gocardless()->log( sprintf( '%s - Order #%s is not paid via GoCardless', __METHOD__, $order->get_order_number() ) );
			return false;
		}

		wc_gocardless()->log( sprintf( '%s - Handling payment event with action "%s" for order #%s', __METHOD__, $event['action'], $order->get_order_number() ) );
		$new_status = '';
		switch ( $event['action'] ) {
			case 'paid_out':
			case 'confirmed':
				$order->payment_complete( $event['links']['payment'] );
				break;
			case 'failed':
				$new_status = 'failed';
				// If payment failed, check if GoCardless will automatically retry the payment.
				if ( isset( $event['details']['will_attempt_retry'] ) && true === $event['details']['will_attempt_retry'] ) {
					// Set order status to on-hold to let GoCardless retry the payment and prevent retrying the payment from WooCommerce.
					/**
					 * Filter GoCardless Retry Payment order status.
					 *
					 * Allow other plugins to modify order status for GoCardless Retry Payment.
					 *
					 * @since 2.4.16
					 *
					 * @param string $status   Order Status.
					 * @param int    $order_id Order ID.
					 */
					$new_status = apply_filters( 'woocommerce_gocardless_retry_payment_order_status', 'on-hold', $order_id );
				}
				break;
			case 'cancelled':
				$new_status = 'cancelled';
				break;
			case 'charged_back':
			case 'chargeback_settled':
				$new_status = 'on-hold';
				break;
		}

		if ( ! empty( $new_status ) ) {
			$note = ! empty( $event['details']['description'] ) ? $event['details']['description'] : '';
			$order->update_status( $new_status, $note );
		}

		if ( in_array( $event['action'], array( 'confirmed', 'paid_out', 'failed', 'cancelled', 'charged_back', 'chargeback_settled' ), true ) ) {
			// Clear Existing scheduled GoCardless payment status check action.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'woocommerce_gocardless_check_subscription_payment_status', array( 'order_id' => $order_id ) );
			}
			$order->delete_meta_data( '_gocardless_temporary_activated' );
			$order->save_meta_data();
		}

		$payment = WC_GoCardless_API::get_payment( $event['links']['payment'] );
		if ( ! is_wp_error( $payment ) && ! empty( $payment['payments'] ) ) {
			$this->update_order_resource( $order, 'payment', $payment['payments'] );
		}

		$order->add_meta_data( '_gocardless_webhook_events', $event, false );
		$order->save_meta_data();

		// Cancel subscription when mandate cancelled for renewal.
		$subscriptions = function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ?
			wcs_get_subscriptions_for_renewal_order( $order ) :
			array();

		if ( 'mandate_cancelled' === $event['details']['cause'] && $subscriptions ) {
			foreach ( $subscriptions as $subscription ) {
				$subscription->cancel_order( $note );
			}
		}

		return true;
	}

	/**
	 * Process refund event from webhook.
	 *
	 * @since 2.4.0
	 *
	 * @param array $event Event payload.
	 *
	 * @return bool|int On success, returns the ID of the inserted row, which
	 *                  validates to true.
	 */
	protected function _process_refund_event( array $event ) {
		$order = $this->get_order_from_resource( 'refund', 'id', $event['links']['refund'] );
		if ( ! $order ) {
			wc_gocardless()->log( sprintf( '%s - Could not found order with refund ID "%s"', __METHOD__, $event['links']['refund'] ) );
			return false;
		}

		wc_gocardless()->log( sprintf( '%s - Handling refund event with action "%s" for order #%s', __METHOD__, $event['action'], $order->get_order_number() ) );

		$refund = WC_GoCardless_API::get_refund( $event['links']['refund'] );
		if ( ! is_wp_error( $refund ) && ! empty( $refund['refunds'] ) ) {
			$this->update_order_resource( $order, 'refund', $refund['refunds'] );
		}

		$order->add_meta_data( '_gocardless_webhook_events', $event, false );
		$order->save_meta_data();
		return true;
	}

	/**
	 * Process subscription event from webhook.
	 *
	 * Subscriptions created before 2.4.0 still use GoCardless subscriptions
	 * to handle payment scheduling. This handler will cancels the subscriptions
	 * at GoCardless then store the mandate to let WCS handles future payments.
	 *
	 * @since 2.4.0
	 *
	 * @param array $event Webhook event.
	 */
	protected function _process_subscription_event( array $event ) {
		$subscription_id = '';
		if ( ! empty( $event['links']['subscription'] ) ) {
			$subscription_id = $event['links']['subscription'];
		}
		if ( empty( $subscription_id ) ) {
			return;
		}

		global $wpdb;

		if ( WC_GoCardless_Compat::is_cot_enabled() ) {
			$meta_table = Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT order_id FROM {$meta_table} WHERE meta_key = '_gocardless_id' AND meta_value = %s ORDER BY order_id ASC LIMIT 1",
					$subscription_id
				)
			);
			// phpcs:enable
		} else {
			// Orders created before 2.4.0 store subscription ID in `_gocardless_id`.
			// The meta is not cloned to subscriptions and renewal orders because
			// it was saved during post-process_payment.
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_gocardless_id' AND meta_value=%s ORDER BY post_id ASC LIMIT 1", $subscription_id ) );
		}

		$subscriptions = array();
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order(
				$order_id,
				array(
					'subscription_status' => 'active',
				)
			);
		}
		if ( empty( $subscriptions ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		wc_gocardless()->log( sprintf( '%s - Handling subscription event with action "%s" for order #%s', __METHOD__, $event['action'], $order->get_order_number() ) );

		switch ( $event['action'] ) {
			case 'payment_created':
				$this->_maybe_update_subscriptions_with_mandate( $subscriptions, $subscription_id );
				break;
			case 'cancelled':
				// Only cancel WCS subscriptions when the order missing mandate.
				$mandate_in_order = $this->get_order_resource( $order_id, 'mandate', 'id' );
				if ( ! $mandate_in_order && class_exists( 'WC_Subscriptions_Manager' ) ) {
					WC_Subscriptions_Manager::cancel_subscriptions_for_order( $order_id );
				}
				break;
		}
	}

	/**
	 * Maybe update subscriptions created before 2.4.0 with mandate.
	 *
	 * @since 2.4.0
	 *
	 * @param array  $subscriptions              Array of subscription objects.
	 * @param string $gocardless_subscription_id GoCardless subscription ID.
	 */
	protected function _maybe_update_subscriptions_with_mandate( $subscriptions, $gocardless_subscription_id ) {
		// Cancel the GoCardless subscription.
		$gocardless_subscription = WC_GoCardless_API::cancel_subscription( $gocardless_subscription_id );

		if ( is_wp_error( $gocardless_subscription ) ) {
			wc_gocardless()->log( sprintf( '%s - Failed to cancel GoCardless subscription: %s', __METHOD__, $gocardless_subscription->get_error_message() ) );
			return;
		}

		if ( empty( $gocardless_subscription['subscriptions']['links']['mandate'] ) ) {
			wc_gocardless()->log( sprintf( '%s - Unexpected GoCardless subscription response. Missing mandate information.', __METHOD__ ) );
			return;
		}
		$mandate_id = $gocardless_subscription['subscriptions']['links']['mandate'];

		$mandate = WC_GoCardless_API::get_mandate( $mandate_id );
		if ( is_wp_error( $mandate ) ) {
			wc_gocardless()->log( sprintf( '%s - Failed to retrieve mandate.', __METHOD__ ) );
			return;
		}
		if ( empty( $mandate['mandates'] ) ) {
			wc_gocardless()->log( sprintf( '%s - Unexpected mandate response.', __METHOD__ ) );
		}

		foreach ( $subscriptions as $subscription ) {
			$this->update_order_resource( $subscription, 'mandate', $mandate['mandates'] );
		}

		$this->update_order_resource( $subscription->get_parent_id(), 'mandate', $mandate['mandates'] );
	}

	/**
	 * Process billing_request event from webhook.
	 *
	 * @since 2.7.0
	 *
	 * @param array $event Event payload.
	 */
	protected function _process_billing_request_event( array $event ) {
		wc_gocardless()->log( sprintf( '%1$s - Handling billing_request event with action "%2$s"', __METHOD__, $event['action'] ) );

		switch ( $event['action'] ) {
			case 'fulfilled':
				$this->_process_billing_request_event_fulfilled( $event );
				break;
			case 'cancelled':
				$this->_process_billing_request_event_cancelled( $event );
				break;
			default:
				// Only log other billing_request events at this time.
				wc_gocardless()->log( sprintf( '%1$s - Unhandled billing_request event with action "%2$s": %3$s', __METHOD__, $event['action'], print_r( $event, true ) ) );
		}
	}

	/**
	 * Process billing_request event 'fulfilled' from webhook.
	 * This event handling is backup for the case where payment is not created during the checkout process due to some reasons.
	 *
	 * @since 2.7.0
	 *
	 * @param array $event Event payload.
	 * @return bool
	 */
	protected function _process_billing_request_event_fulfilled( array $event ) {
		$billing_request_id = $event['links']['billing_request'];

		// Get order from billing_request ID.
		$order = $this->get_order_from_resource( 'billing_request', 'id', $event['links']['billing_request'] );
		if ( ! $order ) {
			wc_gocardless()->log( sprintf( '%s - Could not find order with billing_request ID "%s" with payload: %s', __METHOD__, $event['links']['payment'], print_r( $event, true ) ) );
			return false;
		}

		// Make sure the order payment method is GoCardless.
		$payment_method = wc_gocardless_get_order_prop( $order, 'payment_method' );
		if ( 'gocardless' !== $payment_method ) {
			wc_gocardless()->log( sprintf( '%s - Order #%s is not paid via GoCardless.', __METHOD__, $order->get_order_number() ) );
			return false;
		}

		$order_billing_request = $this->get_order_resource( $order->get_id(), 'billing_request' );
		$was_fulfilling        = ! empty( $order_billing_request ) && 'fulfilling' === $order_billing_request['status'];

		// Process billing request only for pending/cancelled orders or if billing request was not fulfilled during the checkout process.
		if ( ! in_array( $order->get_status(), array( 'pending', 'cancelled' ), true ) && ! $was_fulfilling ) {
			wc_gocardless()->log( sprintf( '%s - No action required for the Order #%s.', __METHOD__, $order->get_order_number() ) );
			return false;
		}

		// Get billing request.
		$billing_request = WC_GoCardless_API::get_billing_request( $billing_request_id );
		if ( is_wp_error( $billing_request ) ) {
			wc_gocardless()->log( sprintf( '%s - Failed to retrieve billing request.', __METHOD__ ) );
			return false;
		}
		if ( empty( $billing_request['billing_requests'] ) ) {
			wc_gocardless()->log( sprintf( '%s - Unexpected billing request response.', __METHOD__ ) );
			return false;
		}

		// Match order ID with billing request metadata.
		$update_needed   = false;
		$billing_request = $billing_request['billing_requests'];
		$order_id        = $billing_request['metadata']['order_id'];
		if ( $order->get_id() !== absint( $order_id ) ) {
			wc_gocardless()->log( sprintf( "%s - Order ID(%s) doesn't match with order ID(%s) of the billing request", __METHOD__, $order->get_id(), $order_id ) );
			return false;
		}

		// Check if payment is available and it is saved in the order.
		if ( ! empty( $billing_request['links']['payment_request_payment'] ) ) {
			$payment = $this->get_order_resource( $order_id, 'payment' );
			if ( empty( $payment ) ) {
				$payment = WC_GoCardless_API::get_payment( $billing_request['links']['payment_request_payment'] );
				if ( is_wp_error( $payment ) || empty( $payment['payments'] ) ) {
					wc_gocardless()->log( sprintf( '%s - Failed to retrieve payment.', __METHOD__ ) );
					return false;
				}

				if ( empty( $payment['payments']['id'] ) ) {
					wc_gocardless()->log( sprintf( '%s - Unexpected payment response from GoCardless.', __METHOD__ ) );
					return false;
				}

				// Compare amount with order total.
				$amount         = absint( wc_format_decimal( ( (float) $order->get_total() * 100 ), wc_get_price_decimals() ) );
				$payment_amount = $payment['payments']['amount'];
				if ( absint( $amount ) !== absint( $payment_amount ) ) {
					wc_gocardless()->log( sprintf( "%s - Order total(%s) doesn't match with payment amount(%s)", __METHOD__, $amount, $payment_amount ) );
					return false;
				}

				// Update order resource with payment data and update order status.
				$update_needed = true;
				$this->handle_payment_creation( $order_id, $payment );
			} else {
				wc_gocardless()->log( sprintf( '%s - Payment already created for billing request ID: %s', __METHOD__, $billing_request_id ) );
			}

			// Check if mandate is available and it is saved in the order.
			if ( ! empty( $billing_request['links']['mandate_request_mandate'] ) ) {
				$mandate = $this->get_order_resource( $order_id, 'mandate' );
				if ( ! empty( $mandate ) ) {
					wc_gocardless()->log( sprintf( '%s - Mandate already saved for billing request #%s', __METHOD__, $billing_request_id ) );
					return false;
				}

				$mandate_id = $billing_request['links']['mandate_request_mandate'];
				$mandate    = WC_GoCardless_API::get_mandate( $mandate_id );
				if ( is_wp_error( $mandate ) ) {
					wc_gocardless()->log( sprintf( '%s - Failed to retrieve mandate. #%s', __METHOD__, $mandate_id ) );
					return false;
				}

				$update_needed = true;
				$this->update_order_resource( $order, 'mandate', $mandate['mandates'] );

				// Save customer token if checked during checkout.
				if ( $order->get_meta( '_gocardless_save_customer_token', true ) && ! empty( $mandate['mandates'] ) ) {
					$this->_save_customer_token( $order->get_user_id(), $mandate['mandates'] );
				}

				// Update mandate in subscriptions if available.
				$subscriptions = array();
				if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
					$subscriptions = wcs_get_subscriptions_for_order( $order );
				} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
					$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
				}

				foreach ( $subscriptions as $subscription ) {
					$this->update_order_resource( wc_gocardless_get_order_prop( $subscription, 'id' ), 'mandate', $mandate['mandates'] );
				}
			}
		}

		if ( $update_needed ) {
			$this->update_order_resource( $order, 'billing_request', $billing_request );
		}

		return true;
	}

	/**
	 * Process billing_request event 'cancelled' from webhook.
	 * This event handling is for the cancel the order if payment or mandate data is not saved in the order and billing request is cancelled.
	 *
	 * @since 2.7.0
	 *
	 * @param array $event Event payload.
	 * @return bool
	 */
	protected function _process_billing_request_event_cancelled( array $event ) {
		$billing_request_id = $event['links']['billing_request'];

		// Get order from billing_request ID.
		$order = $this->get_order_from_resource( 'billing_request', 'id', $event['links']['billing_request'] );
		if ( ! $order ) {
			wc_gocardless()->log( sprintf( '%s - Could not find order with billing_request ID "%s" with payload: %s', __METHOD__, $event['links']['payment'], print_r( $event, true ) ) );
			return false;
		}

		// Make sure the order payment method is GoCardless.
		$payment_method = wc_gocardless_get_order_prop( $order, 'payment_method' );
		if ( 'gocardless' !== $payment_method ) {
			wc_gocardless()->log( sprintf( '%s - Order #%s is not paid via GoCardless.', __METHOD__, $order->get_order_number() ) );
			return false;
		}

		// Process billing request only for pending order.
		if ( 'pending' !== $order->get_status() ) {
			wc_gocardless()->log( sprintf( '%s - No action required for the Order #%s.', __METHOD__, $order->get_order_number() ) );
			return false;
		}

		// Get billing request.
		$billing_request = WC_GoCardless_API::get_billing_request( $billing_request_id );
		if ( is_wp_error( $billing_request ) ) {
			wc_gocardless()->log( sprintf( '%s - Failed to retrieve billing request.', __METHOD__ ) );
			return false;
		}
		if ( empty( $billing_request['billing_requests'] ) || 'cancelled' !== $billing_request['billing_requests']['status'] ) {
			wc_gocardless()->log( sprintf( '%s - Unexpected billing request response.', __METHOD__ ) );
			return false;
		}

		// Cancel the order if payment or mandate data is not saved in the order.
		$payment = $this->get_order_resource( $order->get_id(), 'payment' );
		$mandate = $this->get_order_resource( $order->get_id(), 'mandate' );
		if ( empty( $payment ) && empty( $mandate ) ) {
			$order->update_status( 'cancelled', __( 'Billing request cancelled.', 'woocommerce-gateway-gocardless' ) );
			$this->update_order_resource( $order, 'billing_request', $billing_request );
		}

		return true;
	}

	/**
	 * Update GoCardless resource in order meta.
	 *
	 * @since 2.4.0
	 *
	 * @param int|WC_Order $order         Order or Order ID.
	 * @param string       $resource_type GoCardless resource type ('payment', 'refund' etc)
	 *                                    in singular noun.
	 * @param array        $resource_data Resource data.
	 */
	public function update_order_resource( $order, $resource_type, $resource_data = array() ) {
		// get order if id passed.
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( empty( $order ) || empty( $resource_data ) || ! is_array( $resource_data ) ) {
			return;
		}

		switch ( $resource_type ) {
			case 'billing_request':
				$order->update_meta_data( '_gocardless_billing_request', $resource_data );
				$order->update_meta_data( '_gocardless_billing_request_id', $resource_data['id'] );
				$order->save_meta_data();
				break;

			case 'billing_request_flow':
				$order->update_meta_data( '_gocardless_billing_request_flow', $resource_data );
				$order->update_meta_data( '_gocardless_billing_request_flow_id', $resource_data['id'] );
				$order->save_meta_data();
				break;

			case 'mandate':
				// Don't save other mandate information as it subject to changes
				// over time. The same mandate can be used by more than one order,
				// so it'd be too much to sync all orders if webhook pushes
				// mandate events.
				$order->update_meta_data( '_gocardless_mandate', array( 'id' => $resource_data['id'] ) );
				$order->update_meta_data( '_gocardless_mandate_id', $resource_data['id'] );
				$order->save_meta_data();
				break;
			case 'payment':
				$order->update_meta_data( '_gocardless_payment', $resource_data );
				$order->update_meta_data( '_gocardless_payment_id', $resource_data['id'] );
				$order->update_meta_data( '_gocardless_payment_status', $resource_data['status'] );
				$order->save_meta_data();
				break;
			case 'refund':
				$order->update_meta_data( '_gocardless_refund', $resource_data );
				$order->update_meta_data( '_gocardless_refund_id', $resource_data['id'] );
				$order->save_meta_data();
				break;
		}
	}

	/**
	 * Get GoCardless resource from order meta.
	 *
	 * @since 2.4.0
	 *
	 * @param int         $order_id      Order ID.
	 * @param string      $resource_type GoCardless resource type ('mandate', 'payment', etc)
	 *                                   in singular noun. See self::update_order_resource.
	 * @param null|string $key           Key in resource array (e.g. 'id', 'status', etc).
	 *                                   If null then resource array is returned.
	 *
	 * @return mixed See WC_Data::get_meta return value.
	 */
	public function get_order_resource( $order_id, $resource_type, $key = null ) {
		if ( is_null( $key ) ) {
			$meta_key = sprintf( '_gocardless_%s', $resource_type );
		} else {
			$meta_key = sprintf( '_gocardless_%s_%s', $resource_type, $key );
		}

		$order = wc_get_order( $order_id );
		if ( empty( $order ) ) {
			return false;
		}
		return $order->get_meta( $meta_key, true );
	}

	/**
	 * Get order from given resource value.
	 *
	 * @since 2.4.0
	 *
	 * @param string $resource_type GoCardless resource type ('payment', 'refund' etc)
	 *                              in singular noun. Mandate is excluded because
	 *                              one mandate can be used in more than one order.
	 * @param string $key           Meta Key in resource array (e.g. 'id', 'status', etc).
	 * @param string $value         Value of a given resource key.
	 *
	 * @return WC_Order|bool Order object or false
	 */
	public function get_order_from_resource( $resource_type, $key, $value ) {
		global $wpdb;

		$meta_key = sprintf( '_gocardless_%s_%s', $resource_type, $key );

		if ( WC_GoCardless_Compat::is_cot_enabled() ) {
			$orders_table = Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$meta_table   = Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT orders_table.id FROM {$orders_table} as orders_table INNER JOIN {$meta_table} as meta_table ON meta_table.order_id = orders_table.id WHERE meta_table.meta_key = %s AND meta_table.meta_value = %s",
					$meta_key,
					$value
				)
			);
			// phpcs:enable
		} else {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s", $meta_key, $value ) );
		}

		if ( ! $order_id ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Since metas from parent order is copied to subscription and renewal
		// orders, the query might return subscription instead of order.
		$order_id = wc_gocardless_get_order_prop( $order, 'id' );
		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
			$subscription = wcs_get_subscription( $order_id );
			$last_order   = is_callable( array( $subscription, 'get_last_order' ) )
				? $subscription->get_last_order()
				: null;

			$order = $last_order ? wc_get_order( $last_order ) : $order;
		}

		return $order;
	}

	/**
	 * Get description to be sent to GoCardless from order.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Order|int $order Order object or order ID.
	 *
	 * @return string Order items description
	 */
	protected function _get_description_from_order( $order ) {
		$order = wc_get_order( $order );
		if ( ! $order ) {
			return '';
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			/* translators: product item x qty to send to GoCardless */
			$items[] = sprintf( esc_html__( '%1$s × %2$s', 'woocommerce-gateway-gocardless' ), $item['name'], $item['qty'] );
		}

		// translators: %s: Order Number.
		$description = sprintf( esc_html__( 'Order #%s', 'woocommerce-gateway-gocardless' ), $order->get_order_number() );

		if ( ! empty( $items ) ) {
			$description .= ' (' . implode( ', ', $items ) . ')';
		}

		// Truncate description due to 100 character GoCardless API limit.
		$description = html_entity_decode( wc_trim_string( $description, 100 ), ENT_NOQUOTES, 'UTF-8' );

		// Deprecated hook because of gocardless typo. Since apply_filters_deprecated
		// was introduced in 4.6, we need to support older WP version with the
		// cost of no warning being thrown.
		if ( function_exists( 'apply_filters_deprecated' ) ) {
			$description = apply_filters_deprecated(
				'woocommerce_gocarldess_payment_description',
				array( $description, $order ),
				'2.4.2',
				'woocommerce_gocardless_payment_description'
			);
		} else {
			/**
			 * Filter the payment description sent to GoCardless.
			 *
			 * @deprecated 2.4.2 Use woocommerce_gocardless_payment_description instead.
			 *
			 * @since 2.4.0
			 *
			 * @param string  $description Description.
			 * @param WC_Order $order       Order object.
			 * @return string Description.
			 */
			$description = apply_filters( 'woocommerce_gocarldess_payment_description', $description, $order );
		}

		/**
		 * Filter the payment description sent to GoCardless.
		 *
		 * @since 2.4.2
		 *
		 * @param string  $description Description.
		 * @param WC_Order $order       Order object.
		 * @return string Description.
		 */
		return apply_filters( 'woocommerce_gocardless_payment_description', $description, $order );
	}

	/**
	 * Get transaction URL format.
	 *
	 * @since 2.4.0
	 *
	 * @return string URL format
	 */
	public function get_transaction_url_format() {
		return $this->testmode ? 'https://manage-sandbox.gocardless.com/payments/%s' : 'https://manage.gocardless.com/payments/%s';
	}

	/**
	 * Get mandate URL format.
	 *
	 * @since 2.7.0
	 *
	 * @return string URL format
	 */
	public function get_mandate_url_format() {
		return $this->testmode ? 'https://manage-sandbox.gocardless.com/mandates/%s' : 'https://manage.gocardless.com/mandates/%s';
	}

	/**
	 * Save customer token (mandate and basic bank account info).
	 *
	 * @see https://github.com/woocommerce/woocommerce/wiki/Payment-Token-API.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $customer_id Customer ID.
	 * @param array $mandate     Direct debit mandate.
	 *
	 * @return bool True if saved successfully.
	 */
	protected function _save_customer_token( $customer_id, $mandate ) {
		if ( ! $customer_id ) {
			return false;
		}

		if ( ! class_exists( 'WC_GoCardless_Payment_Token_Direct_Debit' ) ) {
			return false;
		}

		// Retrieves bank account associated with the given mandate. This will
		// be used as saved payment method in checkout page so buyer has context
		// of their saved bank account.
		$bank_account_id = $mandate['links']['customer_bank_account'];
		$bank_account    = WC_GoCardless_API::get_customer_bank_account( $bank_account_id );
		if ( is_wp_error( $bank_account ) || empty( $bank_account['customer_bank_accounts'] ) ) {
			return false;
		}
		$bank_account = $bank_account['customer_bank_accounts'];

		$token = new WC_GoCardless_Payment_Token_Direct_Debit();

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
	 * Alter saved payment method item for direct debit method.
	 *
	 * @since 2.4.0
	 *
	 * @param array            $item          Item of payment method.
	 * @param WC_Payment_Token $payment_token The payment token associated with
	 *                                        this method entry.
	 *
	 * @return array Filtered item for direct debit.
	 */
	public function saved_payment_methods_list_item( $item, $payment_token ) {
		if ( 'direct_debit' !== strtolower( $payment_token->get_type() ) ) {
			return $item;
		}

		$item['method']['display_name'] = $payment_token->get_display_name();
		$item['method']['brand']        = esc_html( $payment_token->get_bank_name() );
		$item['method']['last4']        = esc_html( $payment_token->get_account_number_ending() );
		$item['expires']                = '';

		return $item;
	}

	/**
	 * Alter display of method column in customer's saved payment methods.
	 *
	 * @since 2.4.0
	 *
	 * @param array $method Item of payment method.
	 */
	public function saved_payment_methods_column_method( $method ) {
		if ( ! empty( $method['method']['gateway'] ) && 'gocardless' === $method['method']['gateway'] ) {
			echo esc_html( $method['method']['display_name'] );
		} else {
			echo esc_html( $this->_get_default_column_method_display( $method ) );
		}
	}

	/**
	 * This is needed because defalut template of payment methods will run the
	 * callback to other saved methods.
	 *
	 * @since 2.4.0
	 *
	 * @param array $method Item of payment method.
	 */
	protected function _get_default_column_method_display( $method ) {
		if ( ! empty( $method['method']['last4'] ) ) {
			/* translators: Credit card type label and last 4 numbers */
			return sprintf( esc_html__( '%1$s ending in %2$s', 'woocommerce-gateway-gocardless' ), esc_html( wc_get_credit_card_type_label( $method['method']['brand'] ) ), esc_html( $method['method']['last4'] ) );
		}
		return esc_html( wc_get_credit_card_type_label( $method['method']['brand'] ) );
	}

	/**
	 * Get all payment token IDs by mandate ID.
	 *
	 * @since 2.4.5
	 * @version 2.4.5
	 *
	 * @param string $mandate_id GoCardless mandate.
	 *
	 * @return array List of token IDs.
	 */
	protected function _get_token_ids_by_mandate( $mandate_id ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token = %s",
				$mandate_id
			)
		);
	}

	/**
	 * Mark Order temporary paid if order contains subscriptions or renewal.
	 *
	 * @param int   $order_id   Order ID.
	 * @param array $payment    Payment array.
	 */
	protected function _mark_order_temporary_activated( $order_id, $payment ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$order->update_meta_data( '_gocardless_temporary_activated', true );
		$order->save_meta_data();
		if ( function_exists( 'as_schedule_single_action' ) && ! empty( $payment['payments'] ) && ! empty( $payment['payments']['charge_date'] ) ) {
			$charge_date = $payment['payments']['charge_date'];
			as_schedule_single_action(
				strtotime( $charge_date . ' +1 day' ),
				'woocommerce_gocardless_check_subscription_payment_status',
				array( 'order_id' => $order_id )
			);
		}
	}

	/**
	 * Enqueue scripts on order page for payment dropin.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		// Only load scripts on checkout page, if access token is available and gateway is enabled.
		if ( ! is_checkout() || ! $this->access_token || 'yes' !== $this->enabled ) {
			return;
		}

		$asset_path   = wc_gocardless()->plugin_path . '/build/wc-gocardless-checkout.asset.php';
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
			'gocardless-dropin',
			'https://pay.gocardless.com/billing/static/dropin/v2/initialise.js',
			array(),
			$version,
			true
		);

		wp_enqueue_script(
			'wc-gocardless-checkout-js',
			wc_gocardless()->plugin_url . '/build/wc-gocardless-checkout.js',
			$dependencies,
			$version,
			true
		);

		wp_localize_script(
			'wc-gocardless-checkout-js',
			'wc_gocardless_checkout_params',
			array(
				'is_test'                        => $this->testmode,
				'ajax_url'                       => WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'create_billing_request_nonce'   => wp_create_nonce( 'wc_gocardless_create_billing_request_flow' ),
				'complete_billing_request_nonce' => wp_create_nonce( 'wc_gocardless_complete_billing_request_flow' ),
				'is_order_pay_page'              => is_checkout() && is_wc_endpoint_url( 'order-pay' ),
				'order_id'                       => absint( get_query_var( 'order-pay' ) ),
				'generic_error'                  => __( 'An error occurred while processing the payment.', 'woocommerce-gateway-gocardless' ),
			)
		);
	}

	/**
	 * Handle billing request complete.
	 * This is called when customer complete the billing request flow on the website.
	 * This will update the order with billing request and mandate information.
	 *
	 * @param WC_Order $order               Order object.
	 * @param string   $billing_request_id  Billing request ID.
	 * @param boolean  $save_customer_token Whether to save customer token.
	 * @return array Response array with 'result' and 'redirect' or 'message' keys.
	 */
	public function handle_billing_request_complete( $order, $billing_request_id, $save_customer_token = false ) {
		try {
			$order_id = $order->get_id();

			$order_billing_request = $this->get_order_resource( $order_id, 'billing_request' );
			if ( empty( $order_billing_request ) || $order_billing_request['id'] !== $billing_request_id ) {
				throw new Exception( esc_html__( 'Invalid billing request.', 'woocommerce-gateway-gocardless' ) );
			}

			$billing_requests = WC_GoCardless_API::get_billing_request( $billing_request_id );
			if ( is_wp_error( $billing_requests ) || empty( $billing_requests['billing_requests'] ) ) {
				throw new Exception( esc_html__( 'Failed to retrieve billing request.', 'woocommerce-gateway-gocardless' ) );
			}

			$billing_request = $billing_requests['billing_requests'];
			if ( ! in_array( $billing_request['status'], array( 'fulfilling', 'fulfilled' ), true ) ) {
				throw new Exception( esc_html__( 'Billing request is not fulfulling or fulfilled.', 'woocommerce-gateway-gocardless' ) );
			}

			$this->update_order_resource( $order, 'billing_request', $billing_request );
			$is_fulfilling = 'fulfilling' === $billing_request['status'];

			// Handle payment request if available.
			if ( ! empty( $billing_request['payment_request'] ) ) {
				if ( ! empty( $billing_request['links']['payment_request_payment'] ) ) {
					$payment = WC_GoCardless_API::get_payment( $billing_request['links']['payment_request_payment'] );
					if ( is_wp_error( $payment ) || empty( $payment['payments'] ) ) {
						throw new Exception( esc_html__( 'Failed to retrieve payment.', 'woocommerce-gateway-gocardless' ) );
					}

					if ( empty( $payment['payments']['id'] ) ) {
						throw new Exception( esc_html__( 'Unexpected payment response from GoCardless.', 'woocommerce-gateway-gocardless' ) );
					}

					$this->handle_payment_creation( $order_id, $payment );
				} elseif ( ! $is_fulfilling ) {
					// Throw error if payment is missing from billing request.
					throw new Exception( esc_html__( 'Payment is missing from billing request.', 'woocommerce-gateway-gocardless' ) );
				}
			}

			// Handle mandate request if available.
			if ( ! empty( $billing_request['mandate_request'] ) ) {
				if ( ! empty( $billing_request['links']['mandate_request_mandate'] ) ) {
					$mandate_id = $billing_request['links']['mandate_request_mandate'];
					$mandate    = WC_GoCardless_API::get_mandate( $mandate_id );

					if ( is_wp_error( $mandate ) ) {
						throw new Exception( esc_html__( 'Failed to retrieve mandate.', 'woocommerce-gateway-gocardless' ) );
					}

					$this->update_order_resource( $order, 'mandate', $mandate['mandates'] );

					// Save customer token if enabled.
					if ( $save_customer_token && ! is_wp_error( $mandate ) && ! empty( $mandate['mandates'] ) ) {
						$this->_save_customer_token( $order->get_user_id(), $mandate['mandates'] );
					}

					// Handle mandate only request.
					if ( empty( $billing_request['payment_request'] ) ) {
						wc_gocardless()->log( sprintf( '%s - Creating GoCardless mandate for order %s', __METHOD__, $order->get_order_number() ) );
						$this->_after_mandate_created( $order_id, $mandate_id );
					}
				} else {
					// Throw error if mandate is missing from billing request of mandate only request or billing request is already fulfilled.
					if ( ! $is_fulfilling || empty( $billing_request['payment_request'] ) ) {
						throw new Exception( esc_html__( 'Mandate is missing from billing request.', 'woocommerce-gateway-gocardless' ) );
					}

					if ( $save_customer_token ) {
						$order->update_meta_data( '_gocardless_save_customer_token', true );
						$order->save_meta_data();
					}
				}
			}

			/**
			 * Action hook to run after billing request fulfilled.
			 *
			 * @since 2.7.0
			 *
			 * @param array $billing_request Billing request.
			 */
			do_action( 'woocommerce_gocardless_after_billing_request_fulfilled', $billing_request );
			wc_gocardless()->log( sprintf( '%s - Billing request fulfilled', __METHOD__ ) );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			wc_gocardless()->log( sprintf( '%s - Error when handling billing request fulfilled: %s', __METHOD__, $e->getMessage() ) );

			$order           = wc_get_order( $order_id );
			$current_user_id = get_current_user_id();

			$error_message = __( 'We were unable to process your order.', 'woocommerce-gateway-gocardless' );

			// Only include the "Cancel order" URL for users that own the order.
			if ( $current_user_id && $order && $current_user_id === $order->get_customer_id() ) {
				/* translators: Link to retry cancel order */
				$error_message = sprintf( __( 'We were unable to process your order, <a href="%s">click here to try again</a>.', 'woocommerce-gateway-gocardless' ), esc_url( $order->get_cancel_order_url() ) );
			}

			$error_message = sprintf(
				'%1$s %2$s<br><br>%3$s',
				$error_message,
				__( 'If the problem still persists please contact us with the details below.', 'woocommerce-gateway-gocardless' ),
				$e->getMessage()
			);

			return array(
				'result'  => 'failure',
				'message' => wp_kses_post( $error_message ),
			);
		}
	}

	/**
	 * Return the gateway's icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon = $this->icon ? '<img style="max-width: 92px;" src="' . WC_HTTPS::force_https_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" />' : '';

		/**
		 * Filter the gateway icon. (This is WooCommerce core filter)
		 *
		 * @since 2.7.0
		 *
		 * @param string $icon Gateway icon.
		 * @param string $id   Gateway ID.
		 * @return string
		 */
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Get the direct debit schemes available to the merchant.
	 *
	 * @since 2.7.0
	 * @return array
	 */
	public function get_available_direct_debit_schemes() {
		$schemes_identifiers = $this->get_available_scheme_identifiers();
		if ( empty( $schemes_identifiers ) ) {
			return array();
		}

		// All supported schemes.
		$schemes = array(
			'ach'              => __( 'ACH', 'woocommerce-gateway-gocardless' ),
			'autogiro'         => __( 'Autogiro', 'woocommerce-gateway-gocardless' ),
			'bacs'             => __( 'BACS', 'woocommerce-gateway-gocardless' ),
			'becs'             => __( 'BECS', 'woocommerce-gateway-gocardless' ),
			'becs_nz'          => __( 'BECS NZ', 'woocommerce-gateway-gocardless' ),
			'betalingsservice' => __( 'Betalingsservice', 'woocommerce-gateway-gocardless' ),
			'pad'              => __( 'PAD (Pre-Authorized Debit)', 'woocommerce-gateway-gocardless' ),
			'sepa_core'        => __( 'SEPA Core', 'woocommerce-gateway-gocardless' ),
		);

		// Filter out only direct debit schemes.
		$supported_schemes = array();
		foreach ( $schemes_identifiers as $scheme ) {
			$key = 'sepa' === $scheme['scheme'] ? 'sepa_core' : $scheme['scheme'];
			if ( isset( $schemes[ $key ] ) ) {
				$supported_schemes[ $key ] = $schemes[ $key ];
			}
		}

		return $supported_schemes;
	}

	/**
	 * Get the payment request (Instant) schemes available to the merchant.
	 *
	 * @since 2.7.0
	 * @return array
	 */
	public function get_available_payment_request_schemes() {
		$schemes_identifiers = $this->get_available_scheme_identifiers();
		if ( empty( $schemes_identifiers ) ) {
			return array();
		}

		// All supported payment request schemes.
		$schemes = array(
			'faster_payments',
			'sepa_credit_transfer',
			'sepa_instant_credit_transfer',
		);

		// Filter out only payment request schemes.
		$supported_schemes = array();
		foreach ( $schemes_identifiers as $scheme ) {
			if ( in_array( $scheme['scheme'], $schemes, true ) ) {
				$supported_schemes[] = $scheme['scheme'];
			}
		}

		return $supported_schemes;
	}

	/**
	 * Get the available scheme identifiers to the merchant.
	 *
	 * @see https://hub.gocardless.com/s/article/Supporting-Bank-Debits-schemes-via-API?language=en_GB
	 *
	 * @since 2.7.0
	 *
	 * @return array
	 */
	public function get_available_scheme_identifiers() {
		$transient_key = 'wc_gocardless_available_scheme_identifiers';
		$schemes       = get_transient( $transient_key );
		if ( false !== $schemes ) {
			return $schemes;
		}

		if ( ! $this->access_token ) {
			return array();
		}

		$creditors = WC_GoCardless_API::get_creditors();
		if ( is_wp_error( $creditors ) || empty( $creditors['creditors'] ) ) {
			return array();
		}

		// As per GoCardless API docs, there should be only one creditor available.
		$creditor = $creditors['creditors'][0];
		if ( empty( $creditor['scheme_identifiers'] ) ) {
			return array();
		}

		// Filter out schemes that are not active.
		$schemes = array_filter(
			$creditor['scheme_identifiers'],
			function ( $scheme ) {
				return ( ! empty( $scheme['scheme'] ) && 'active' === $scheme['status'] );
			}
		);

		set_transient( $transient_key, $schemes, DAY_IN_SECONDS );

		return $schemes;
	}

	/**
	 * Generate webhook secret for GoCardless.
	 *
	 * @return string Webhook secret. Base64 encoded random bytes (48 chars).
	 */
	public function generate_webhook_secret() {
		if ( function_exists( 'random_bytes' ) ) {
			return base64_encode( random_bytes( 36 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} elseif ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return base64_encode( openssl_random_pseudo_bytes( 36 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Fallback if random_bytes and openssl_random_pseudo_bytes are not available.
		return base64_encode( wp_generate_password( 36, true, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
