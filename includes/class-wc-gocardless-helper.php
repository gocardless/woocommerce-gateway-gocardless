<?php
/**
 * GoCardless helper utilities.
 *
 * @package WooCommerce_Gateway_GoCardless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for the GoCardless gateway plugin.
 */
class WC_GoCardless_Helper {

	/**
	 * Registered payment gateway instances from WooCommerce.
	 *
	 * @return array
	 */
	private static function get_payment_gateways_map() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		return WC()->payment_gateways->payment_gateways();
	}

	/**
	 * Bank pay (main) GoCardless gateway instance.
	 *
	 * @since 3.0.0
	 *
	 * @return WC_GoCardless_Gateway|WC_GoCardless_Gateway_Addons|bool
	 */
	public static function get_main_gateway() {
		$gateways = self::get_payment_gateways_map();

		return ! empty( $gateways['gocardless'] ) ? $gateways['gocardless'] : false;
	}

	/**
	 * PayTo GoCardless gateway instance when registered.
	 *
	 * @since 3.0.0
	 *
	 * @return WC_GoCardless_PayTo_Gateway|WC_GoCardless_PayTo_Gateway_Addons|bool
	 */
	public static function get_payto_gateway() {
		$gateways = self::get_payment_gateways_map();

		return ! empty( $gateways['gocardless_payto'] ) ? $gateways['gocardless_payto'] : false;
	}

	/**
	 * Whether the order uses a GoCardless payment method (Bank pay or PayTo).
	 *
	 * @since 3.0.0
	 *
	 * @param WC_Order|mixed $order Order object.
	 * @return bool
	 */
	public static function is_gocardless_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return in_array( $order->get_payment_method( 'edit' ), array( 'gocardless', 'gocardless_payto' ), true );
	}

	/**
	 * Resolve which GoCardless gateway should handle an order (Bank pay vs PayTo).
	 *
	 * @since 3.0.0
	 *
	 * @param WC_Order|mixed $order Order object.
	 * @return WC_GoCardless_Gateway|WC_GoCardless_Gateway_Addons|WC_GoCardless_PayTo_Gateway|WC_GoCardless_PayTo_Gateway_Addons|bool
	 */
	public static function get_gateway_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return self::get_main_gateway();
		}

		if ( 'gocardless_payto' !== $order->get_payment_method( 'edit' ) ) {
			return self::get_main_gateway();
		}

		$payto = self::get_payto_gateway();

		return $payto ? $payto : self::get_main_gateway();
	}


	/**
	 * Choose Bank pay or PayTo gateway for async webhook handling from the related order.
	 *
	 * @since 3.0.0
	 *
	 * @param array $payload Webhook payload (single event per scheduled action).
	 * @return WC_GoCardless_Gateway|WC_GoCardless_PayTo_Gateway|bool
	 */
	public static function resolve_gateway_for_webhook( array $payload ) {
		$main = self::get_main_gateway();
		if ( ! $main ) {
			return false;
		}

		// We will always have a single event per scheduled action.
		if ( empty( $payload['events'][0] ) ) {
			return $main;
		}

		$order = self::get_order_for_webhook_event( $main, $payload['events'][0] );
		if ( $order instanceof WC_Order ) {
			return self::get_gateway_for_order( $order );
		}

		return $main;
	}

	/**
	 * Resolve a WooCommerce order from a GoCardless webhook event when possible.
	 *
	 * Mandate and legacy subscription events are handled on the main gateway because they do not
	 * tie to a single order via payment/refund/billing_request meta in a reliable way.
	 *
	 * @since 3.0.0
	 *
	 * @param WC_GoCardless_Gateway $gateway Gateway instance (used for get_order_from_resource).
	 * @param array                 $event   Single event from the webhook payload.
	 * @return WC_Order|bool
	 */
	public static function get_order_for_webhook_event( $gateway, array $event ) {
		$resource_type = isset( $event['resource_type'] ) ? $event['resource_type'] : '';
		$links         = isset( $event['links'] ) && is_array( $event['links'] ) ? $event['links'] : array();

		switch ( $resource_type ) {
			case 'payments':
				if ( ! empty( $links['payment'] ) ) {
					return $gateway->get_order_from_resource( 'payment', 'id', $links['payment'] );
				}
				break;
			case 'refunds':
				if ( ! empty( $links['refund'] ) ) {
					return $gateway->get_order_from_resource( 'refund', 'id', $links['refund'] );
				}
				break;
			case 'billing_requests':
				if ( ! empty( $links['billing_request'] ) ) {
					return $gateway->get_order_from_resource( 'billing_request', 'id', $links['billing_request'] );
				}
				break;
		}

		return false;
	}

	/**
	 * GoCardless payment statuses eligible for temporary activation of a
	 * subscription/renewal order.
	 *
	 * Only merchant-approved, finite collection states belong here — states that
	 * the payment will leave on its own timeline (Bacs collection typically takes
	 * a few days). Approval-dependent states such as `pending_customer_approval`
	 * (e.g. Bacs dual-signature mandates) must NOT be included: those can remain
	 * unauthorised indefinitely, so activating a subscription before the initial
	 * payment is confirmed would let a shopper retain the active-subscriber
	 * entitlement without ever authorising collection.
	 *
	 * @since 3.0.3
	 *
	 * @param int $order_id Order ID, for filter context.
	 * @return string[] Payment statuses eligible for temporary activation.
	 */
	public static function get_temporary_activatable_payment_statuses( $order_id = 0 ) {
		/**
		 * Filter the GoCardless payment statuses eligible for temporary activation
		 * of a subscription/renewal order.
		 *
		 * @since 3.0.3
		 *
		 * @param string[] $statuses Payment statuses eligible for temporary activation.
		 * @param int      $order_id Order ID.
		 * @return string[] Payment statuses eligible for temporary activation.
		 */
		return (array) apply_filters(
			'woocommerce_gocardless_temporary_activatable_payment_statuses',
			array( 'pending_submission', 'submitted' ),
			$order_id
		);
	}

	/**
	 * Whether a GoCardless payment status is eligible for temporary activation of
	 * a subscription/renewal order.
	 *
	 * @since 3.0.3
	 *
	 * @param string $payment_status GoCardless payment status.
	 * @param int    $order_id       Order ID, for filter context.
	 * @return bool True when the status may temporarily activate a subscription order.
	 */
	public static function is_payment_status_temporary_activatable( $payment_status, $order_id = 0 ) {
		return in_array( $payment_status, self::get_temporary_activatable_payment_statuses( $order_id ), true );
	}

	/**
	 * Whether a temporarily activated order whose payment is still in a
	 * merchant-approved finite collection state (`pending_submission` /
	 * `submitted`) should be checked again rather than demoted.
	 *
	 * Keeps polling while the order is within the maximum temporary-activation
	 * age, falling back to the order creation date when the first-seen time is
	 * unknown. Once the maximum age is exceeded — or the age cannot be determined
	 * at all — returns false so the caller demotes the order and stops
	 * rescheduling: temporary activation can never persist unbounded.
	 *
	 * @since 3.0.3
	 *
	 * @param WC_Order $order Temporarily activated order.
	 * @return bool True to reschedule another check; false to demote the order.
	 */
	public static function should_reschedule_temporary_activation( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$activated_time = (int) $order->get_meta( '_gocardless_temporary_activated_time', true );

		// If the activated time is not set, use the order created time.
		if ( empty( $activated_time ) ) {
			$date_created = $order->get_date_created();

			/*
			 * `get_date_created()` is nullable (WC_DateTime|null). With neither a
			 * recorded activation time nor a creation date the age cannot be
			 * bounded, so demote rather than keep an unconfirmed order active
			 * indefinitely — the same fail-safe direction as an exceeded max age.
			 */
			if ( ! $date_created instanceof DateTimeInterface ) {
				return false;
			}

			$activated_time = $date_created->getTimestamp();
		}

		/**
		 * Filter the maximum age (in seconds) a subscription/renewal order may
		 * remain temporarily activated while its payment is still in-progress.
		 * After this cutoff the order is demoted to on-hold (which cascades the
		 * subscription to on-hold) and the scheduled check stops rescheduling.
		 *
		 * @since 3.0.3
		 *
		 * @param int $max_age  Maximum age in seconds. Default 14 days.
		 * @param int $order_id Order ID.
		 * @return int Maximum age in seconds.
		 */
		$max_age = (int) apply_filters( 'woocommerce_gocardless_temporary_activation_max_age', 14 * DAY_IN_SECONDS, $order->get_id() );

		// If the max age is 0 or less, do not reschedule.
		if ( $max_age <= 0 ) {
			return false;
		}

		return ( time() - $activated_time ) <= $max_age;
	}
}
