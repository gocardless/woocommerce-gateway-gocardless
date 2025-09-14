<?php

use PHPUnit\Framework\TestCase;

/**
 * Test class for webhook race condition fix.
 * Tests the scenario where a paid_out event arrives after a failed event.
 */
class WebhookRaceConditionTests extends TestCase {
	/**
	 * Set up our mocked WP functions.
	 *
	 * @return void
	 */
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	/**
	 * Tear down WP Mock.
	 *
	 * @return void
	 */
	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	/**
	 * Test that paid_out event after failed event properly transitions order status.
	 */
	public function test_paid_out_after_failed_event() {
		// Mock the order object
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'failed' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '12345' );
		$order->shouldReceive( 'update_status' )->with( 'processing', 'Payment received after initial failure - updating status' )->once();
		$order->shouldReceive( 'payment_complete' )->with( 'payment_123' )->once();

		// Mock the gateway
		$gateway = \Mockery::mock( 'WC_GoCardless_Gateway' );
		$gateway->shouldReceive( 'get_order_from_resource' )->andReturn( $order );

		// Mock wc_gocardless_get_order_prop
		\WP_Mock::userFunction( 'wc_gocardless_get_order_prop' )
			->with( $order, 'id' )
			->andReturn( 123 );
		\WP_Mock::userFunction( 'wc_gocardless_get_order_prop' )
			->with( $order, 'payment_method' )
			->andReturn( 'gocardless' );

		// Mock wc_gocardless function
		$wc_gocardless_mock = \Mockery::mock();
		$wc_gocardless_mock->shouldReceive( 'log' )->andReturn( true );
		\WP_Mock::userFunction( 'wc_gocardless' )->andReturn( $wc_gocardless_mock );

		// Create the event payload
		$event = array(
			'action' => 'paid_out',
			'links'  => array(
				'payment' => 'payment_123',
			),
		);

		// Use reflection to access the protected method
		$reflection = new ReflectionClass( $gateway );
		$method = $reflection->getMethod( '_process_payment_event' );
		$method->setAccessible( true );

		// Execute the method
		$result = $method->invoke( $gateway, $event );

		// Assert the method returns true
		$this->assertTrue( $result );
	}

	/**
	 * Test that paid_out event after cancelled event properly transitions order status.
	 */
	public function test_paid_out_after_cancelled_event() {
		// Mock the order object
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '12345' );
		$order->shouldReceive( 'update_status' )->with( 'processing', 'Payment received after cancellation - updating status' )->once();
		$order->shouldReceive( 'payment_complete' )->with( 'payment_123' )->once();

		// Mock the gateway
		$gateway = \Mockery::mock( 'WC_GoCardless_Gateway' );
		$gateway->shouldReceive( 'get_order_from_resource' )->andReturn( $order );

		// Mock wc_gocardless_get_order_prop
		\WP_Mock::userFunction( 'wc_gocardless_get_order_prop' )
			->with( $order, 'id' )
			->andReturn( 123 );
		\WP_Mock::userFunction( 'wc_gocardless_get_order_prop' )
			->with( $order, 'payment_method' )
			->andReturn( 'gocardless' );

		// Mock wc_gocardless function
		$wc_gocardless_mock = \Mockery::mock();
		$wc_gocardless_mock->shouldReceive( 'log' )->andReturn( true );
		\WP_Mock::userFunction( 'wc_gocardless' )->andReturn( $wc_gocardless_mock );

		// Create the event payload
		$event = array(
			'action' => 'paid_out',
			'links'  => array(
				'payment' => 'payment_123',
			),
		);

		// Use reflection to access the protected method
		$reflection = new ReflectionClass( $gateway );
		$method = $reflection->getMethod( '_process_payment_event' );
		$method->setAccessible( true );

		// Execute the method
		$result = $method->invoke( $gateway, $event );

		// Assert the method returns true
		$this->assertTrue( $result );
	}

	/**
	 * Test that paid_out event with normal status doesn't trigger status change.
	 */
	public function test_paid_out_with_normal_status() {
		// Mock the order object
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '12345' );
		$order->shouldReceive( 'update_status' )->never();
		$order->shouldReceive( 'payment_complete' )->with( 'payment_123' )->once();

		// Mock the gateway
		$gateway = \Mockery::mock( 'WC_GoCardless_Gateway' );
		$gateway->shouldReceive( 'get_order_from_resource' )->andReturn( $order );

		// Mock wc_gocardless_get_order_prop
		\WP_Mock::userFunction( 'wc_gocardless_get_order_prop' )
			->with( $order, 'id' )
			->andReturn( 123 );
		\WP_Mock::userFunction( 'wc_gocardless_get_order_prop' )
			->with( $order, 'payment_method' )
			->andReturn( 'gocardless' );

		// Mock wc_gocardless function
		$wc_gocardless_mock = \Mockery::mock();
		$wc_gocardless_mock->shouldReceive( 'log' )->andReturn( true );
		\WP_Mock::userFunction( 'wc_gocardless' )->andReturn( $wc_gocardless_mock );

		// Create the event payload
		$event = array(
			'action' => 'paid_out',
			'links'  => array(
				'payment' => 'payment_123',
			),
		);

		// Use reflection to access the protected method
		$reflection = new ReflectionClass( $gateway );
		$method = $reflection->getMethod( '_process_payment_event' );
		$method->setAccessible( true );

		// Execute the method
		$result = $method->invoke( $gateway, $event );

		// Assert the method returns true
		$this->assertTrue( $result );
	}
}
