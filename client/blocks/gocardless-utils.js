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

/**
 * Call the server to complete a billing request flow.
 *
 * @param {Object} data Data to send to the server.
 * @return {Promise<Object>} Response from the server.
 */
export const completeBillingRequestFlow = (data) => {
	const { wcAjaxUrl } = getGoCardlessServerData();
	const formData = new FormData();
	for (const key in data) {
		formData.append(key, data[key]);
	}
	const ajaxUrl = wcAjaxUrl.replace(
		'%%endpoint%%',
		'gocardless_complete_billing_request_flow'
	);

	return fetch(ajaxUrl, {
		method: 'POST',
		body: formData,
	}).then((response) => response.json());
};
