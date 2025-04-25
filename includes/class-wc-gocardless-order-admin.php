<?php
/**
 * Order admin handler.
 *
 * @package WooCommerce_Gateway_GoCardless
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage order admin UI for orders paid via GoCardless.
 *
 * @since 2.4.0
 */
class WC_GoCardless_Order_Admin {

	/**
	 * Initialization.
	 */
	public function init() {
		$this->add_meta_box();
		$this->add_order_actions();

		add_action( 'admin_notices', array( $this, 'display_connection_notices' ) );

		// GoCardless Connect/Disconnect actions.
		add_action( 'admin_post_wc_connect_gocardless', array( $this, 'connect_gocardless' ) );
		add_action( 'admin_post_wc_disconnect_gocardless', array( $this, 'disconnect_gocardless' ) );

		// Add GoCardless status column to the orders list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_gocardless_payment_status_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_gocardless_payment_status_column_content' ), 10, 2 );

		// HPOS - Add GoCardless status column to the orders list.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_gocardless_payment_status_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'add_gocardless_payment_status_column_content' ), 10, 2 );

		// Add payment status information to the order preview.
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( $this, 'add_payment_status_to_order_preview' ), 10, 2 );
	}

	/**
	 * Register webhook events meta box.
	 *
	 * @since 2.4.0
	 */
	public function add_meta_box() {
		add_action( 'add_meta_boxes', array( $this, 'add_webhook_events_meta_box' ), 11, 2 );
	}

	/**
	 * Add the webhook events meta box.
	 *
	 * @since 2.4.0
	 *
	 * @param string           $post_type Post Type.
	 * @param WP_Post|WC_Order $post      Order or Post object.
	 */
	public function add_webhook_events_meta_box( $post_type, $post ) {
		$order = ( $post instanceof WP_Post ) ? wc_get_order( $post->ID ) : $post;
		if ( ! $order ) {
			return false;
		}

		if ( 'gocardless' !== wc_gocardless_get_order_prop( $order, 'payment_method' ) ) {
			return false;
		}

		$screen = WC_GoCardless_Compat::is_cot_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box( 'woocommerce-gocardless-webhook-events', esc_html__( 'GoCardless Webhook Events', 'woocommerce-gateway-gocardless' ), array( $this, 'webhook_events_meta_box' ), $screen, 'side' );
	}

	/**
	 * Output the content for webhook events meta box.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_Post|WC_Order $post Order or Post object.
	 */
	public function webhook_events_meta_box( $post ) {
		$order = ( $post instanceof WP_Post ) ? wc_get_order( $post->ID ) : $post;
		if ( ! $order ) {
			return false;
		}

		$events = $order->get_meta( '_gocardless_webhook_events', false );

		// TODO(gedex): This will makes the list long for subscription payments,
		// paginate should be implemented.
		if ( ! empty( $events ) ) {
			echo wp_kses( wpautop( __( 'This list may contains duplicated events.', 'woocommerce-gateway-gocardless' ) ), array( 'p' => array() ) );
			echo '<ul class="order_notes">';
			foreach ( $events as $event_obj ) {
				$event_data = $event_obj->get_data();
				$event      = $event_data['value'];
				echo wp_kses_post( sprintf( '<li rel="%s">%s</li>', esc_attr( $event['id'] ), $this->get_formatted_event_item( $event ) ) );
			}
			echo '</ul>';
		} else {
			echo wp_kses( wpautop( __( 'No recent events.', 'woocommerce-gateway-gocardless' ) ), array( 'p' => array() ) );
		}
	}

	/**
	 * Get formatted event item.
	 *
	 * @since 2.4.0
	 *
	 * @param array $event Event array.
	 *
	 * @return string Formatted event item.
	 */
	public function get_formatted_event_item( $event ) {
		$resource_id = '';
		if ( ! empty( $event['links']['mandate'] ) ) {
			$resource_id = $event['links']['mandate'];
		} elseif ( ! empty( $event['links']['payment'] ) ) {
			$resource_id = $event['links']['payment'];
		} elseif ( ! empty( $event['links']['subscription'] ) ) {
			$resource_id = $event['links']['subscription'];
		} elseif ( ! empty( $event['links']['refund'] ) ) {
			$resource_id = $event['links']['refund'];
		}

		return sprintf(
			'
			<div class="note_content"><p><strong>%s%s</strong></p>%s</div>
			<p class="meta">
				<abbr class="exact-date">%s</abbr>
			</p>
			',
			$event['resource_type'] . ' ' . $event['action'],
			! empty( $resource_id ) ? ' → ' . $resource_id : '',
			! empty( $event['details']['description'] ) ? wpautop( $event['details']['description'] ) : '',
			/* translators: Logged on time */
			sprintf( esc_html__( 'Logged on %s', 'woocommerce-gateway-gocardless' ), $event['created_at'] )
		);
	}

	/**
	 * Add GoCardless specific order actions.
	 *
	 * @since 2.4.0
	 */
	public function add_order_actions() {
		add_action( 'woocommerce_order_actions', array( $this, 'gocardless_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_gocardless_cancel_payment', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_action_gocardless_retry_payment', array( $this, 'retry_payment' ) );
	}

	/**
	 * GoCardless order actions.
	 *
	 * @since 2.4.0
	 *
	 * @param array    $actions The actions available for the order.
	 * @param WC_Order $order   WC_Order object.
	 *
	 * @return array Order actions
	 */
	public function gocardless_actions( $actions, $order ) {
		if ( empty( $order ) ) {
			return $actions;
		}

		if ( 'gocardless' !== wc_gocardless_get_order_prop( $order, 'payment_method' ) ) {
			return $actions;
		}

		$gateway = wc_gocardless()->gateway_instance();
		if ( ! $gateway ) {
			return $actions;
		}

		$order_id = wc_gocardless_get_order_prop( $order, 'id' );

		// For payment with status pending_submission, merchant can cancel it.
		$payment_status = $gateway->get_order_resource( $order_id, 'payment', 'status' );
		switch ( $payment_status ) {
			case 'pending_submission':
				$actions['gocardless_cancel_payment'] = esc_html__( 'GoCardless &rsaquo; Cancel payment', 'woocommerce-gateway-gocardless' );
				// Display action text to "Cancel Payment & Subscription" for subscription and parent order.
				if (
					function_exists( 'wcs_is_subscription' ) &&
					(
						wcs_is_subscription( $order_id ) ||
						! empty( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) )
					)
				) {
					$actions['gocardless_cancel_payment'] = esc_html__( 'GoCardless &rsaquo; Cancel Payment & Subscription', 'woocommerce-gateway-gocardless' );
				}
				break;
			case 'failed':
				$actions['gocardless_retry_payment'] = esc_html__( 'GoCardless &rsaquo; Retry payment', 'woocommerce-gateway-gocardless' );
				break;
		}

		return $actions;
	}

	/**
	 * Order action to cancel payment.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Order $order WC_Order object.
	 */
	public function cancel_payment( $order ) {
		if ( ! $order ) {
			return;
		}

		if ( 'gocardless' !== wc_gocardless_get_order_prop( $order, 'payment_method' ) ) {
			return;
		}

		$gateway = wc_gocardless()->gateway_instance();
		if ( ! $gateway ) {
			return;
		}

		$order_id   = wc_gocardless_get_order_prop( $order, 'id' );
		$payment_id = $gateway->get_order_resource( $order_id, 'payment', 'id' );
		$payment    = WC_GoCardless_API::cancel_payment( $payment_id );

		if ( class_exists( 'WC_Admin_Notices' ) ) {
			WC_Admin_Notices::add_custom_notice( 'gocardless_cancel_payment', $this->get_cancel_payment_notice( $order->get_order_number(), $payment ) );
		}

		if ( ! is_wp_error( $payment ) ) {
			$order->update_status( 'cancelled', esc_html__( 'Cancelled GoCardless payment.', 'woocommerce-gateway-gocardless' ) );
		}
	}

	/**
	 * Get notice for cancel payment action.
	 *
	 * @since 2.4.0
	 *
	 * @param int            $order_number Order Number.
	 * @param WP_Error|array $payment      Payment array of WP_Error.
	 *
	 * @return string Notice message
	 * TODO:123
	 */
	public function get_cancel_payment_notice( $order_number, $payment ) {
		if ( is_wp_error( $payment ) ) {
			// translators: %1$s: Order Number, %2$s: Error message.
			return sprintf( esc_html__( 'Failed to cancel GoCardless payment in order #%1$s: %2$s', 'woocommerce-gateway-gocardless' ), $order_number, $payment->get_error_message() );
		}

		// translators: %s: Order Number.
		return sprintf( esc_html__( 'GoCardless payment in order #%s is cancelled.', 'woocommerce-gateway-gocardless' ), $order_number );
	}

	/**
	 * Order action to retry payment.
	 *
	 * @since 2.4.0
	 *
	 * @param WC_Order $order WC_Order object.
	 */
	public function retry_payment( $order ) {
		if ( ! $order ) {
			return;
		}

		if ( 'gocardless' !== wc_gocardless_get_order_prop( $order, 'payment_method' ) ) {
			return;
		}

		$gateway = wc_gocardless()->gateway_instance();
		if ( ! $gateway ) {
			return;
		}

		$order_id   = wc_gocardless_get_order_prop( $order, 'id' );
		$payment_id = $gateway->get_order_resource( $order_id, 'payment', 'id' );
		$payment    = WC_GoCardless_API::retry_payment( $payment_id );

		if ( class_exists( 'WC_Admin_Notices' ) ) {
			WC_Admin_Notices::add_custom_notice( 'gocardless_retry_payment', $this->get_retry_payment_notice( $order->get_order_number(), $payment ) );
		}

		if ( ! is_wp_error( $payment ) ) {
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
			$status = apply_filters( 'woocommerce_gocardless_retry_payment_order_status', 'on-hold', $order_id );
			$order->update_status( $status, esc_html__( 'Retried GoCardless payment.', 'woocommerce-gateway-gocardless' ) );
		}
	}

	/**
	 * Get notice for retry payment action.
	 *
	 * @since 2.4.0
	 *
	 * @param int            $order_number Order Number.
	 * @param WP_Error|array $payment      Payment array of WP_Error.
	 *
	 * @return string Notice message
	 */
	public function get_retry_payment_notice( $order_number, $payment ) {
		if ( is_wp_error( $payment ) ) {
			// translators: %1$s: Order Number, %2$s: Error message.
			return sprintf( esc_html__( 'Failed to retry GoCardless payment in order #%1$s: %2$s', 'woocommerce-gateway-gocardless' ), $order_number, $payment->get_error_message() );
		}

		// translators: %s: Order Number.
		return sprintf( esc_html__( 'Retried GoCardless payment in order #%s.', 'woocommerce-gateway-gocardless' ), $order_number );
	}

	/**
	 * Add GoCardless Payment Status column to the orders list.
	 *
	 * @since 2.7.0
	 *
	 * @param array $existing_columns Columns.
	 * @return array Columns with GoCardless Payment Status column.
	 */
	public function add_gocardless_payment_status_column( $existing_columns ) {
		$columns = array();

		foreach ( $existing_columns as $existing_column_key => $existing_column ) {
			$columns[ $existing_column_key ] = $existing_column;

			if ( 'order_status' === $existing_column_key ) {
				$columns['order_status']              = __( 'Order Status', 'woocommerce-gateway-gocardless' );
				$columns['gocardless_payment_status'] = __( 'GoCardless Payment Status', 'woocommerce-gateway-gocardless' );
			}
		}

		return $columns;
	}

	/**
	 * Add GoCardless Payment Status column content to the orders list.
	 *
	 * @since 2.7.0
	 *
	 * @param string       $column_name Column name.
	 * @param int|WC_Order $order_id    Order ID or WC_Order object.
	 * @return void
	 */
	public function add_gocardless_payment_status_column_content( $column_name, $order_id ) {
		if ( 'gocardless_payment_status' === $column_name ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				echo '-';
				return;
			}

			echo wp_kses_post( $this->get_gocardless_payment_status( $order->get_id(), true ) );
		}
	}

	/**
	 * Get the GoCardless payment status for an order.
	 *
	 * @since 2.7.0
	 * @param int  $order_id Order ID.
	 * @param bool $markup   Whether to return the status with markup.
	 * @return string GoCardless payment status.
	 */
	public function get_gocardless_payment_status( $order_id, $markup = false ) {
		$order   = wc_get_order( $order_id );
		$gateway = wc_gocardless()->gateway_instance();
		if ( ! $order || 'gocardless' !== $order->get_payment_method() || ! $gateway ) {
			return '-';
		}

		$statuses = array(
			'pending_submission'        => __( 'Pending submission', 'woocommerce-gateway-gocardless' ),
			'pending_customer_approval' => __( 'Pending customer approval', 'woocommerce-gateway-gocardless' ),
			'submitted'                 => __( 'Submitted', 'woocommerce-gateway-gocardless' ),
			'confirmed'                 => __( 'Confirmed', 'woocommerce-gateway-gocardless' ),
			'paid_out'                  => __( 'Paid out', 'woocommerce-gateway-gocardless' ),
			'cancelled'                 => __( 'Cancelled', 'woocommerce-gateway-gocardless' ),
			'customer_approval_denied'  => __( 'Customer approval denied', 'woocommerce-gateway-gocardless' ),
			'failed'                    => __( 'Failed', 'woocommerce-gateway-gocardless' ),
			'charged_back'              => __( 'Charged back', 'woocommerce-gateway-gocardless' ),
		);

		$payment_status = $gateway->get_order_resource( $order->get_id(), 'payment', 'status' );

		if ( empty( $payment_status ) ) {
			return '-';
		}

		$status = $statuses[ $payment_status ] ?? $payment_status;

		// Prepare the status with markup.
		if ( $markup ) {
			$class = 'order-status';
			switch ( $payment_status ) {
				case 'paid_out':
					$class .= ' status-completed';
					break;
				case 'confirmed':
					$class .= ' status-processing';
					break;
				case 'submitted':
					$class .= ' status-on-hold';
					break;
				case 'failed':
				case 'customer_approval_denied':
					$class .= ' status-failed';
					break;
				default:
					$class .= ' status-pending';
					break;
			}
			return '<mark class="' . esc_attr( $class ) . '"><span>' . esc_html( $status ) . '</span></mark>';
		}

		return $status;
	}

	/**
	 * Add payment status information to the order preview.
	 *
	 * @since 2.7.0
	 *
	 * @param array    $details Details to display.
	 * @param WC_Order $order   Order object.
	 *
	 * @return array
	 */
	public function add_payment_status_to_order_preview( $details, $order ) {
		if ( 'gocardless' !== $order->get_payment_method() ) {
			return $details;
		}

		$payment_status = $this->get_gocardless_payment_status( $order->get_id() );

		// return early if payment status is not set.
		if ( '-' === $payment_status ) {
			return $details;
		}

		if ( ! isset( $details['payment_via'] ) ) {
			$details['payment_via'] = '';
		}

		$details['payment_via'] .= sprintf(
			'<br><strong>%1$s</strong>%2$s',
			esc_html__( 'GoCardless payment status', 'woocommerce-gateway-gocardless' ),
			esc_html( $payment_status ),
		);

		return $details;
	}


	/**
	 * Disconnect GoCardless account.
	 *
	 * @since 2.8.0
	 */
	public function disconnect_gocardless() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You do not have permission to disconnect the GoCardless account.', 'woocommerce-gateway-gocardless' ) );
		}

		if ( ! isset( $_GET['wc_gocardless_disconnect_nonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['wc_gocardless_disconnect_nonce'] ) ), 'wc_disconnect_gocardless' ) ) {
			wp_die( esc_html__( 'Invalid disconnection request.', 'woocommerce-gateway-gocardless' ) );
		}

		$gateway = wc_gocardless()->gateway_instance();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'GoCardless gateway is not available.', 'woocommerce-gateway-gocardless' ) );
		}

		// Disconnect GoCardless account.
		$disconnected = $gateway->disconnect_gocardless();

		if ( $disconnected ) {
			$this->set_connection_notice( 'success', esc_html__( 'Disconnected from GoCardless successfully.', 'woocommerce-gateway-gocardless' ) );
			wc_gocardless()->log( sprintf( '%s - Disconnected from GoCardless', __METHOD__ ) );
		}

		wp_safe_redirect( wc_gocardless()->get_setting_url() );
		exit;
	}

	/**
	 * Connect GoCardless account.
	 *
	 * @since 2.8.0
	 */
	public function connect_gocardless() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'You do not have permission to connect a GoCardless account.', 'woocommerce-gateway-gocardless' ) );
		}

		if ( ! isset( $_GET['wc_gocardless_connect_nonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['wc_gocardless_connect_nonce'] ) ), 'wc_connect_gocardless' ) ) {
			wp_die( esc_html__( 'Invalid connection request.', 'woocommerce-gateway-gocardless' ) );
		}

		$gateway = wc_gocardless()->gateway_instance();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'GoCardless gateway is not available.', 'woocommerce-gateway-gocardless' ) );
		}

		// Connect GoCardless account.
		$connected = $gateway->connect_gocardless();

		if ( $connected ) {
			$this->set_connection_notice( 'success', esc_html__( 'Connected to GoCardless successfully.', 'woocommerce-gateway-gocardless' ) );
			wc_gocardless()->log( sprintf( '%s - Connected to GoCardless successfully', __METHOD__ ) );
		} else {
			$this->set_connection_notice( 'error', esc_html__( 'An error occurred while connecting with GoCardless.', 'woocommerce-gateway-gocardless' ) );
		}

		wp_safe_redirect( wc_gocardless()->get_setting_url() );
		exit;
	}

	/**
	 * Show notices for GoCardless connect/disconnect.
	 *
	 * @since 2.8.0
	 */
	public function display_connection_notices() {
		$notice = $this->get_connection_notice();
		if ( ! $notice ) {
			return;
		}

		if ( ! empty( $notice['message'] ) ) {
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Set connection notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 */
	public function set_connection_notice( $type, $message ) {
		set_transient(
			'wc_gocardless_connection_notice',
			array(
				'type'    => $type,
				'message' => $message,
			),
			30
		);
	}

	/**
	 * Get connection notice.
	 *
	 * @return array A Notice array with type and message.
	 */
	public function get_connection_notice() {
		$notice = get_transient( 'wc_gocardless_connection_notice' );
		delete_transient( 'wc_gocardless_connection_notice' );

		return $notice;
	}
}
