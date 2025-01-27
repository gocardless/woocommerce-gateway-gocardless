<?php
/**
 * Wrapper for TEST GoCardless API.
 *
 * @package WC_GoCardless
 */

/**
 * Wrapper for TEST GoCardless API.
 *
 * @since 2.4.0
 */
class WC_GoCardless_API_Test extends WC_GoCardless_API {
	public static function get_data( $resource ) {
		return array(
			"data" => array(
				"links" => array(
					"resource" => $resource,
				),
			)
		);
	}

	public static function activate_mandate( $mandate_id ) {
		$args = array(
			'method' => 'POST',
			'body' => wp_json_encode( self::get_data( $mandate_id  ) ),
		);

		return self::_request( 'scenario_simulators/mandate_activated/actions/run', $args );
	}

    public static function confirm_payment( $payment_id ) {
		$args = array(
			'method' => 'POST',
			'body' => wp_json_encode( self::get_data( $payment_id  ) ),
		);

		return self::_request( 'scenario_simulators/payment_confirmed/actions/run', $args );
	}
}
