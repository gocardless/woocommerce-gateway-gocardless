<?php
/**
 * Plugin name: GoCardless for WooCommerce Test plugin
 */

// Remove the https from the API request URL
add_filter( 'woocommerce_api_request_url', function ($url) {
	return str_replace( 'https://', 'http://', $url );
} );

// Simulate GoCardless webhook for testing.
add_action( 'woocommerce_thankyou_gocardless', 'test_wc_gocardless_simulate_webhook', 999 );
add_action( 'woocommerce_thankyou_gocardless_payto', 'test_wc_gocardless_simulate_webhook', 999 );

function test_wc_gocardless_simulate_webhook( $order_id ) {
	$webhook_body = test_wc_gocardless_get_webhook_body( $order_id );
	if ( ! $webhook_body ) {
		return false;
	}

	if ( class_exists( 'WC_GoCardless_API' ) ) {
		try{
			include_once __DIR__ . '/test-gocardless-api.php';
	
			// Activate the mandate.
			$order = wc_get_order( $order_id );
			if ( $order && 'test-pay_out@test.com' === $order->get_billing_email() ) {
				$mandate_id = $order->get_meta( '_gocardless_mandate_id' );
				$payment_id = $order->get_meta( '_gocardless_payment_id' );
		
				if ( ! empty( $mandate_id ) ) {
					WC_GoCardless_API_Test::activate_mandate( $mandate_id );
				}
		
				if ( ! empty( $payment_id ) ) {
					WC_GoCardless_API_Test::confirm_payment( $payment_id ); 
				}
			}
		} catch (\Exception $exception) {}
	}

	// Send the webhook.
	wp_remote_post(
		esc_url_raw( home_url( '/wc-api/WC_Gateway_GoCardless/?request=webhook', 'http' ) ),
		[ 
			'headers' => array(
				'Content-Type' => 'application/json',
				'Webhook-Signature' => test_wc_gocardless_get_webhook_signature( $webhook_body )
			),
			'body' => $webhook_body,
			'sslverify' => false
		]
	);

	// Trigger the action scheduler to run scheduled action.
	$queue = WC()->queue()->search(
		array(
			'status'   => 'pending',
			'per_page' => 10,
			'hook'     => 'woocommerce_gocardless_process_webhook_payload_async'
		)
	);

	try {
		if ( ! empty( $queue ) ) {
			foreach ( $queue as $action ) {
				$action->execute();
			}
		}
	} catch (\Exception $exception) {
	}

	// Trigger the action scheduler to run the queue.
	do_action( 'action_scheduler_run_queue' );
}

// Get the webhook data for the order.
function test_wc_gocardless_get_webhook_body( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}

	$mandate_id = $order->get_meta( '_gocardless_mandate_id' );
	$payment_id = $order->get_meta( '_gocardless_payment_id' );
	$search = array( 'ORDER_ID', 'MANDATE_ID', 'PAYMENT_ID', 'CURRENT_TIME' );
	$replace = array( $order_id, $mandate_id, $payment_id, current_time( 'c' ) );
	$webhook_body = '{
		"events": [
		  {
			"id": "EV01AY6QXSJCA1",
			"links": {
			  "mandate": "MANDATE_ID"
			},
			"action": "submitted",
			"details": {
			  "cause": "mandate_submitted",
			  "origin": "gocardless",
			  "description": "The mandate has been submitted to the banks."
			},
			"metadata": {},
			"created_at": "CURRENT_TIME",
			"resource_type": "mandates",
			"resource_metadata": {}
		  },
		  {
			"id": "EV01AY6QXT9QP9",
			"links": {
			  "mandate": "MANDATE_ID"
			},
			"action": "active",
			"details": {
			  "cause": "mandate_activated",
			  "origin": "gocardless",
			  "description": "The time window after submission for the banks to refuse a mandate has ended without any errors being received, so this mandate is now active."
			},
			"metadata": {},
			"created_at": "CURRENT_TIME",
			"resource_type": "mandates",
			"resource_metadata": {}
		  },
		  {
			"id": "EV01AY6QY0VWA2",
			"links": {
			  "payment": "PAYMENT_ID"
			},
			"action": "confirmed",
			"details": {
			  "cause": "payment_confirmed",
			  "origin": "gocardless",
			  "description": "Enough time has passed since the payment was submitted for the banks to return an error, so this payment is now confirmed."
			},
			"metadata": {},
			"created_at": "CURRENT_TIME",
			"resource_type": "payments",
			"resource_metadata": {
			  "order_id": "ORDER_ID"
			}
		  },
		  {
			"id": "EV01AY6QXV02B8",
			"links": {
			  "payment": "PAYMENT_ID"
			},
			"action": "submitted",
			"details": {
			  "cause": "payment_submitted",
			  "origin": "gocardless",
			  "description": "Payment submitted to the banks. As a result, it can no longer be cancelled.",
			  "bank_account_id": "BA000VXTRZ6YQV"
			},
			"metadata": {},
			"created_at": "CURRENT_TIME",
			"resource_type": "payments",
			"resource_metadata": {
			  "order_id": "ORDER_ID"
			}
		  }
		],
		"meta": {
		  "webhook_id": "WB002183BPD9BG"
		}
	}';
	return str_replace( $search, $replace, $webhook_body );
}

function test_wc_gocardless_get_webhook_signature( $webhook_body ) {
	$settings = get_option( 'woocommerce_gocardless_settings', array() );
	$webhook_secret = isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '';

	$secret = wp_specialchars_decode( $webhook_secret, ENT_QUOTES );
	$signature = hash_hmac( 'sha256', $webhook_body, $secret );
	return $signature;
}

// Simulate GoCardless webhook for testing (Cancel mandate).
add_action( 'init', 'test_wc_gocardless_simulate_cancel_mandate_webhook' );
function test_wc_gocardless_simulate_cancel_mandate_webhook() {
	if ( ! isset( $_GET['cancel_mandate_order_id'] ) ) {
		return;
	}

	$order_id = absint( $_GET['cancel_mandate_order_id'] );

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}

	$mandate_id = $order->get_meta( '_gocardless_mandate_id' );
	if ( empty( $mandate_id ) ) {
		return false;
	}

	$search = array( 'MANDATE_ID', 'CURRENT_TIME' );
	$replace = array( $mandate_id, current_time( 'c' ) );
	$webhook_body = '{
		"events": [
		{
			"id": "EV01AY6QXSJCA1",
			"links": {
				"mandate": "MANDATE_ID"
			},
			"action": "cancelled",
			"details": {
			"cause": "mandate_cancelled",
			"origin": "gocardless",
			"description": "The mandate has been cancelled."
			},
			"metadata": {},
			"created_at": "CURRENT_TIME",
			"resource_type": "mandates",
			"resource_metadata": {}
		}
		],
		"meta": {
		"webhook_id": "WB002183BPD9BG"
		}
	}';
	$webhook_body = str_replace( $search, $replace, $webhook_body );

	// Send the webhook.
	wp_remote_post(
		esc_url_raw( home_url( '/wc-api/WC_Gateway_GoCardless/?request=webhook', 'http' ) ),
		[ 
			'headers' => array(
				'Content-Type' => 'application/json',
				'Webhook-Signature' => test_wc_gocardless_get_webhook_signature( $webhook_body )
			),
			'body' => $webhook_body,
			'sslverify' => false
		]
	);
}

// Simulate GoCardless webhook for billing request fulfilled.
add_action( 'init', 'test_wc_gocardless_simulate_billing_request_fulfilled_webhook', 1000 );
function test_wc_gocardless_simulate_billing_request_fulfilled_webhook() {
	if ( ! isset( $_GET['billing_request_fulfilled_order_id'] ) ) {
		return;
	}

	$order_id = absint( $_GET['billing_request_fulfilled_order_id'] );
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}

	$billing_request_id = $order->get_meta( '_gocardless_billing_request_id' );
	$mandate_id         = $order->get_meta( '_gocardless_mandate_id' );
	$payment_id         = $order->get_meta( '_gocardless_payment_id' );
	if ( empty( $billing_request_id ) ) {
		return false;
	}

	$search = array( 'BILLING_REQUEST_ID', 'CURRENT_TIME', 'ORDER_ID', 'MANDATE_ID', 'PAYMENT_ID' );
	$replace = array( $billing_request_id, current_time( 'c' ), $order_id, $mandate_id, $payment_id );
	$webhook_body = '{
		"events": [
		{
			"id": "EV01V2XMAFP81Y",
			"created_at": "CURRENT_TIME",
			"resource_type": "billing_requests",
			"action": "fulfilled",
			"metadata": {},
			"details": {
				"origin": "gocardless",
				"cause": "billing_request_fulfilled",
				"description": "This billing request has been fulfilled, and the resources have been created."
			},
			"links": {
				"billing_request": "BILLING_REQUEST_ID"
				"mandate_request_mandate": "MANDATE_ID",
				"payment_request_payment": "PAYMENT_ID",
			},
			"resource_metadata": {
				"order_id": "ORDER_ID"
			}
		}
		],
		"meta": {
			"webhook_id": "WB003BEE5G1WBM"
		}
	}';
	$webhook_body = str_replace( $search, $replace, $webhook_body );

	// Send the webhook.
	wp_remote_post(
		esc_url_raw( home_url( '/wc-api/WC_Gateway_GoCardless/?request=webhook', 'http' ) ),
		[ 
			'headers' => array(
				'Content-Type' => 'application/json',
				'Webhook-Signature' => test_wc_gocardless_get_webhook_signature( $webhook_body )
			),
			'body' => $webhook_body,
			'sslverify' => false
		]
	);
}

/**
 * Registers a REST API endpoint to update the access token.
 * This is used in E2E tests to update the access token.
 *
 * Route: POST /wp-json/gocardless-e2e/v1/update-access-token
 * Body:  { "access_token": "1234567890" }
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'gocardless-e2e/v1', '/update-access-token', array(
		'methods'             => 'POST',
		'callback'            => 'gocardless_e2e_update_access_token_callback',
		'permission_callback' => '__return_true',
		'args'                => array(
			'access_token' => array(
				'type'     => 'string',
				'required' => true,
			),
		),
	) );

	register_rest_route( 'gocardless-e2e/v1', '/billing-request-status', array(
		'methods'             => 'POST',
		'callback'            => 'gocardless_e2e_billing_request_status_callback',
		'permission_callback' => '__return_true',
		'args'                => array(
			'order_id' => array(
				'type'     => 'string',
				'required' => true,
			),
		),
	) );
} );

/**
 * Gets the billing request status.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response The response object.
 */
function gocardless_e2e_billing_request_status_callback( $request ) {
	global $wpdb;
	$order_id = $request->get_param( 'order_id' );

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_REST_Response( array(
			'error' => __( 'Order not found.', 'gocardless-e2e' )
		), 404 );
	}

	$billing_request_id = $order->get_meta( '_gocardless_billing_request_id' );

	if ( empty( $billing_request_id ) ) {
		return new WP_REST_Response( array(
			'error' => __( 'Billing request not found.', 'gocardless-e2e' )
		), 404 );
	}

	$billing_requests = WC_GoCardless_API::get_billing_request( $billing_request_id );

	$status = '';
	if ( is_wp_error( $billing_requests ) || empty( $billing_requests['billing_requests'] ) ) {
		$status = 'error';
	}

	$billing_request = $billing_requests['billing_requests'];
	$status = $billing_request['status'] ?? '';

	return rest_ensure_response( array(
		'success' => true,
		'status' => $status,
	) );
}

/**
 * Updates the access token.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response The response object.
 */
function gocardless_e2e_update_access_token_callback( $request ) {
	$access_token = $request->get_param( 'access_token' );
	if ( empty( $access_token ) ) {
		return new WP_REST_Response( array(
			'error' => __( 'Access token is required.', 'gocardless-e2e' )
		), 400 );
	}

	$settings = get_option( 'woocommerce_gocardless_settings', array() );
	$settings['access_token'] = sanitize_text_field( $access_token );
	update_option( 'woocommerce_gocardless_settings', $settings );

	// Delete the available scheme transient, to request the available schemes again, to test the flow.
	delete_transient( 'wc_gocardless_available_scheme_identifiers' );

	return rest_ensure_response( array(
		'success' => true,
		'message' => __( 'Access token updated.', 'gocardless-e2e' ),
	) );
}
