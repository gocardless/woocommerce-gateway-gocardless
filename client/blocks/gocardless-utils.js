/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';

/**
 * GoCardless data comes from the server passed on a global object.
 */
export const getGoCardlessServerData = () => {
	const goCardlessServerData = getSetting('gocardless_data', null);
	if (!goCardlessServerData) {
		throw new Error(
			__(
				'GoCardless initialization data is not available',
				'woocommerce-gateway-gocardless'
			)
		);
	}
	return goCardlessServerData;
};
