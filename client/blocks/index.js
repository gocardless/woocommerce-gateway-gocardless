/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME, PAYTO_PAYMENT_METHOD_NAME } from './constants';
import { registerGoCardlessPaymentMethod } from './register-methods';

registerGoCardlessPaymentMethod(
	PAYMENT_METHOD_NAME,
	__( 'GoCardless payment method', 'woocommerce-gateway-gocardless' )
);

registerGoCardlessPaymentMethod(
	PAYTO_PAYMENT_METHOD_NAME,
	__( 'PayTo (GoCardless) payment method', 'woocommerce-gateway-gocardless' )
);
