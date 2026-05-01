<?php

use PHPUnit\Framework\TestCase;

/**
 * The AddressTests class tests the functions associated with an address associated with an invoice.
 */
class OrderAdminTests extends TestCase {
	/**
	 * Set up our mocked WP functions. Rather than setting up a database we can mock the returns of core WordPress functions.
	 *
	 * @return void
	 */
	public function setUp() : void {
		\WP_Mock::setUp();
		$this->wc_gc_admin = new WC_GoCardless_Order_Admin();
	}
	/**
	 * Tear down WP Mock.
	 *
	 * @return void
	 */
	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_meta_box_added() {
		\WP_Mock::expectActionAdded( 'add_meta_boxes', array( $this->wc_gc_admin, 'add_webhook_events_meta_box' ), 11, 2 );

		$this->wc_gc_admin->add_meta_box();
		$this->assertEquals( true, true );
	}

	public function test_add_webhook_events_meta_box() {
		\WP_Mock::passthruFunction( 'absint', array( 'times' => 0 ) );

		// No order -- false
		$result = $this->wc_gc_admin->add_webhook_events_meta_box( null, null );
		$this->assertEquals( $result, false );

		$order_other_gateway = \Mockery::mock( 'WC_Order' );
		$order_other_gateway->shouldReceive( 'get_payment_method' )
			->with( 'edit' )
			->andReturn( 'some_other_method' );

		$order_gocardless = \Mockery::mock( 'WC_Order' );
		$order_gocardless->shouldReceive( 'get_payment_method' )
			->with( 'edit' )
			->andReturn( 'gocardless' );

		// Order exists with another payment method
		$result = $this->wc_gc_admin->add_webhook_events_meta_box( null, $order_other_gateway );
		$this->assertEquals( $result, false );

		// GoCardless order -- add_meta_box called; method has no explicit return (null)
		\WP_Mock::userFunction(
			'add_meta_box',
			array(
				'times' => 1,
				'args'  => array(
					'woocommerce-gocardless-webhook-events',
					__( 'GoCardless Webhook Events', 'woocommerce-gateway-gocardless' ),
					array( $this->wc_gc_admin, 'webhook_events_meta_box' ),
					'shop_order',
					'side',
				),
			)
		);
		$result = $this->wc_gc_admin->add_webhook_events_meta_box( null, $order_gocardless );
		$this->assertNull( $result );
	}

	public function add_order_actions() {
		\WP_Mock::expectActionAdded( 'woocommerce_order_actions', array( $this->wc_gc_admin, 'gocardless_actions' ), 10, 2 );
		\WP_Mock::expectActionAdded( 'woocommerce_order_action_gocardless_cancel_payment', array( $this, 'cancel_payment' ) );
		\WP_Mock::expectActionAdded( 'woocommerce_order_action_gocardless_retry_payment', array( $this, 'retry_payment' ) );

		$result = $this->wc_gc_admin->add_order_actions();

		$this->assertNull( $result );
	}

	public function test_get_cancel_payment_notice() {
		$error = \Mockery::mock( '\WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'Error Message' );

		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			function( $context ) {
				return $context instanceof \WP_Error;
			}
		);

		$payment = true;

		$result = $this->wc_gc_admin->get_cancel_payment_notice( 42, $error );
		$this->assertEquals(
			$result,
			'Failed to cancel GoCardless payment in order #42: Error Message'
		);

		$result = $this->wc_gc_admin->get_cancel_payment_notice( 42, $payment );
		$this->assertEquals(
			$result,
			'GoCardless payment in order #42 is cancelled.'
		);
	}

	public function test_get_retry_payment_notice() {
		$error = \Mockery::mock( '\WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'Error Message' );

		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			function( $context ) {
				return $context instanceof \WP_Error;
			}
		);

		$payment = true;

		$result = $this->wc_gc_admin->get_retry_payment_notice( 42, $error );
		$this->assertEquals(
			$result,
			'Failed to retry GoCardless payment in order #42: Error Message'
		);

		$result = $this->wc_gc_admin->get_retry_payment_notice( 42, $payment );
		$this->assertEquals(
			$result,
			'Retried GoCardless payment in order #42.'
		);
	}
}
