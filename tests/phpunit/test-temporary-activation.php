<?php
/**
 * Tests for the temporary-activation decision logic that guards against the
 * Bacs dual-signature subscription persistence vuln.
 *
 * The vuln: an order whose payment is `pending_customer_approval`
 * (an approval-dependent state that may never progress) was treated like an
 * ordinary in-progress collection, activating the subscription and rescheduling
 * a daily check forever. These tests pin the corrected state machine:
 *   - `pending_customer_approval` is NOT eligible for temporary activation, so
 *     the order stays on-hold rather than activating a subscription.
 *   - Finite in-progress states (`pending_submission` / `submitted`) keep polling
 *     only while within a maximum age, then stop (bounded persistence).
 *
 * @package WooCommerce_Gateway_GoCardless
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Decision-logic tests for WC_GoCardless_Helper temporary activation guards.
 *
 * Filters are exercised through `WP_Mock::onFilter()`; unfiltered calls fall
 * through to the shipped defaults, so most tests assert real default behaviour.
 */
class TemporaryActivationTests extends TestCase {

	/**
	 * The shipped allowlist of temporarily activatable payment statuses.
	 *
	 * @var string[]
	 */
	const DEFAULT_STATUSES = array( 'pending_submission', 'submitted' );

	/**
	 * The shipped maximum temporary activation age.
	 */
	const DEFAULT_MAX_AGE = 14 * DAY_IN_SECONDS;

	/**
	 * Set up WP_Mock.
	 *
	 * @return void
	 */
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	/**
	 * Tear down WP_Mock.
	 *
	 * @return void
	 */
	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Allowlist: which payment statuses may temporarily activate an order.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The shipped allowlist contains only merchant-approved, finite collection
	 * states — notably NOT `pending_customer_approval`.
	 */
	public function test_default_activatable_statuses() {
		$this->assertSame(
			array( 'pending_submission', 'submitted' ),
			WC_GoCardless_Helper::get_temporary_activatable_payment_statuses( 123 )
		);
	}

	/**
	 * The core fix: `pending_customer_approval` must never be eligible for
	 * temporary activation of a subscription order.
	 */
	public function test_pending_customer_approval_is_not_activatable() {
		$this->assertFalse(
			WC_GoCardless_Helper::is_payment_status_temporary_activatable( 'pending_customer_approval', 123 )
		);
	}

	/**
	 * Merchant-approved finite collection states remain eligible (no regression).
	 */
	public function test_finite_states_are_activatable() {
		$this->assertTrue( WC_GoCardless_Helper::is_payment_status_temporary_activatable( 'pending_submission', 123 ) );
		$this->assertTrue( WC_GoCardless_Helper::is_payment_status_temporary_activatable( 'submitted', 123 ) );
	}

	/**
	 * Terminal and unknown statuses are not activatable.
	 */
	public function test_terminal_and_unknown_states_are_not_activatable() {
		foreach ( array( 'confirmed', 'paid_out', 'failed', 'cancelled', 'charged_back', 'customer_approval_denied', '' ) as $status ) {
			$this->assertFalse(
				WC_GoCardless_Helper::is_payment_status_temporary_activatable( $status, 123 ),
				"Status '{$status}' should not be temporarily activatable"
			);
		}
	}

	/**
	 * The allowlist filter receives the default allowlist and the order ID as
	 * context, and can narrow the allowlist — a store may opt out of temporary
	 * activation entirely.
	 */
	public function test_allowlist_filter_can_narrow_statuses() {
		\WP_Mock::onFilter( 'woocommerce_gocardless_temporary_activatable_payment_statuses' )
			->with( self::DEFAULT_STATUSES, 456 )
			->reply( array() );

		$this->assertFalse(
			WC_GoCardless_Helper::is_payment_status_temporary_activatable( 'submitted', 456 )
		);
	}

	/**
	 * The allowlist filter is honoured when it widens the allowlist, and a scalar
	 * return is cast to an array rather than blowing up `in_array()`.
	 */
	public function test_allowlist_filter_result_is_cast_to_array() {
		\WP_Mock::onFilter( 'woocommerce_gocardless_temporary_activatable_payment_statuses' )
			->with( self::DEFAULT_STATUSES, 123 )
			->reply( 'confirmed' );

		$this->assertSame(
			array( 'confirmed' ),
			WC_GoCardless_Helper::get_temporary_activatable_payment_statuses( 123 )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reschedule window: how long an activated order may stay active.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Build a mocked WC_Order reporting a first-seen activation time and,
	 * optionally, a creation date.
	 *
	 * @param int|string $activated_time Value returned for the first-seen meta.
	 * @param int|false  $created_time   Timestamp for `get_date_created()`, or
	 *                                   `null` to have it return null. Omit
	 *                                   entirely when the method is never
	 *                                   expected to be called.
	 * @return \Mockery\MockInterface
	 */
	private function make_order( $activated_time, $created_time = false ) {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )
			->with( '_gocardless_temporary_activated_time', true )
			->andReturn( $activated_time );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );

		if ( false !== $created_time ) {
			$order->shouldReceive( 'get_date_created' )
				->andReturn( null === $created_time ? null : new \DateTime( '@' . $created_time ) );
		}

		return $order;
	}

	/**
	 * Scheduled check: a temporarily activated order keeps polling while within
	 * the maximum activation age.
	 */
	public function test_should_reschedule_within_max_age() {
		$order = $this->make_order( time() - DAY_IN_SECONDS );
		$this->assertTrue(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: once past the maximum age the order stops being
	 * rescheduled, so temporary activation can never persist unbounded.
	 */
	public function test_should_not_reschedule_past_max_age() {
		$order = $this->make_order( time() - ( 15 * DAY_IN_SECONDS ) );
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: the boundary is inclusive — an order exactly at the
	 * maximum age still gets one more check.
	 */
	public function test_should_reschedule_at_exactly_max_age() {
		$order = $this->make_order( time() - self::DEFAULT_MAX_AGE );
		$this->assertTrue(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: with no recorded first-seen time, the order's creation
	 * date stands in, so a recently created order keeps polling.
	 */
	public function test_missing_activated_time_falls_back_to_created_date() {
		$order = $this->make_order( '', time() - DAY_IN_SECONDS );
		$this->assertTrue(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: the creation-date fallback is still bounded — an order
	 * created before the cutoff is demoted even though the first-seen meta is
	 * missing. This closes the gap where an order with no recorded activation
	 * time would otherwise have polled forever.
	 */
	public function test_missing_activated_time_does_not_reschedule_old_order() {
		$order = $this->make_order( '', time() - ( 15 * DAY_IN_SECONDS ) );
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: `WC_Order::get_date_created()` is nullable, so an order
	 * with neither a recorded activation time nor a creation date must not fatal
	 * on `getTimestamp()`. With no way to bound the age, demote rather than keep
	 * the order temporarily active.
	 */
	public function test_missing_activated_time_and_null_created_date_does_not_reschedule() {
		$order = $this->make_order( '', null );
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: a zero meta value is treated as "not recorded" and falls
	 * back to the creation date rather than being read as the epoch (which would
	 * always look older than the cutoff).
	 */
	public function test_zero_activated_time_falls_back_to_created_date() {
		$order = $this->make_order( 0, time() - DAY_IN_SECONDS );
		$this->assertTrue(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: a max age of zero disables temporary activation outright —
	 * the order is demoted rather than polled.
	 */
	public function test_zero_max_age_does_not_reschedule() {
		\WP_Mock::onFilter( 'woocommerce_gocardless_temporary_activation_max_age' )
			->with( self::DEFAULT_MAX_AGE, 123 )
			->reply( 0 );

		$order = $this->make_order( time() );
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: a negative max age is treated like zero, not as a window
	 * extending into the past.
	 */
	public function test_negative_max_age_does_not_reschedule() {
		\WP_Mock::onFilter( 'woocommerce_gocardless_temporary_activation_max_age' )
			->with( self::DEFAULT_MAX_AGE, 123 )
			->reply( -1 );

		$order = $this->make_order( time() );
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: the max-age filter receives the order ID as context and a
	 * shortened window is honoured.
	 */
	public function test_max_age_filter_shortens_window() {
		\WP_Mock::onFilter( 'woocommerce_gocardless_temporary_activation_max_age' )
			->with( self::DEFAULT_MAX_AGE, 123 )
			->reply( DAY_IN_SECONDS );

		$order = $this->make_order( time() - ( 2 * DAY_IN_SECONDS ) );
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( $order )
		);
	}

	/**
	 * Scheduled check: a non-order argument does not reschedule (there is nothing
	 * to check), so the loop stops rather than continuing indefinitely.
	 */
	public function test_should_not_reschedule_without_valid_order() {
		$this->assertFalse(
			WC_GoCardless_Helper::should_reschedule_temporary_activation( null )
		);
	}
}
