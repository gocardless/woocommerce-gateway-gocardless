/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME } from './constants';
import {
	completeBillingRequestFlow,
	getGoCardlessServerData,
} from './gocardless-utils';

const {
	description,
	logo_url: logoUrl,
	title,
	isTest,
	billingRequestNonce,
} = getGoCardlessServerData();

const Content = (props) => {
	const { eventRegistration, emitResponse, shouldSavePayment } = props;
	const { onCheckoutSuccess } = eventRegistration;

	// Handle the checkout success event.
	useEffect(() => {
		const unsubscribe = onCheckoutSuccess((data) => {
			let handler;
			// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars
			return new Promise((resolve, reject) => {
				const errorOptions = {
					type: 'error',
					messageContext: emitResponse.noticeContexts.PAYMENTS,
				};

				const { GoCardlessDropin } = window;
				if (!GoCardlessDropin) {
					resolve({
						...errorOptions,
						message: __(
							'GoCardless Drop-in is not available',
							'woocommerce-gateway-gocardless'
						),
					});
					return;
				}

				const billingRequestFlowId =
					data?.processingResponse?.paymentDetails
						?.billing_request_flow_id;
				const securityNonce = data?.processingResponse?.paymentDetails?.billing_request_nonce || billingRequestNonce;
				if (!billingRequestFlowId) {
					resolve({
						...errorOptions,
						message: __(
							'Invalid billing request flow ID',
							'woocommerce-gateway-gocardless'
						),
					});
					return;
				}

				const options = {
					billingRequestFlowID: billingRequestFlowId,
					environment: isTest ? 'sandbox' : 'live',
					// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars
					onSuccess: (billingRequest, billingRequestFlow) => {
						closeDropIn();
						if (
							billingRequest &&
							['fulfilling', 'fulfilled'].includes(
								billingRequest.status
							)
						) {
							const params = {
								security: securityNonce,
								order_id: data.orderId,
								billing_request_id: billingRequest.id,
								save_customer_token: shouldSavePayment
									? 'yes'
									: 'no',
							};

							completeBillingRequestFlow(params)
								.then((res) => {
									if (res && res.success) {
										resolve({
											type: 'success',
											redirectUrl: res.data.redirect_url,
										});
									} else {
										resolve({
											...errorOptions,
											message: res.data?.message,
										});
									}
								})
								.catch((error) => {
									resolve({
										...errorOptions,
										message: error.message,
									});
								});
						} else {
							resolve({
								...errorOptions,
								message: __(
									'Billing request is not fulfulling or fulfilled yet, please try again later.',
									'woocommerce-gateway-gocardless'
								),
							});
						}
					},
					// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars
					onExit: (error, metadata) => {
						if (error) {
							console.error(error); // eslint-disable-line no-console
						}
						window.location.href = data.redirectUrl;
					},
				};
				handler = GoCardlessDropin.create(options);
				handler.open();
				const closeDropIn = () => {
					handler?.exit();
				};
			});
		});
		return unsubscribe;
	}, [
		onCheckoutSuccess,
		shouldSavePayment,
		emitResponse.noticeContexts.PAYMENTS,
	]);

	return decodeEntities(description || '');
};

const Logo = () => {
	return (
		<img
			src={logoUrl}
			alt={decodeEntities(title)}
			style={{ marginRight: '12px', marginBottom: '4px' }}
		/>
	);
};

const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={decodeEntities(title)} icon={<Logo />} />;
};

registerPaymentMethod({
	name: PAYMENT_METHOD_NAME,
	label: <Label />,
	ariaLabel: __(
		'GoCardless payment method',
		'woocommerce-gateway-gocardless'
	),
	canMakePayment: ({ billingData, cartTotals }) => {
		// Check if the country and currency is supported.
		const currency = cartTotals?.currency_code;
		const supportedCountries =
			getGoCardlessServerData()?.supportedCountries || [];
		const supportedCurrencies =
			getGoCardlessServerData()?.supportedCurrencies || [];
		return (
			supportedCountries.includes(billingData?.country) &&
			supportedCurrencies.includes(currency)
		);
	},
	content: <Content />,
	edit: <Content />,
	supports: {
		// Use `false` as fallback values in case server provided configuration is missing.
		showSavedCards: getGoCardlessServerData().showSavedCards ?? false,
		showSaveOption: getGoCardlessServerData().showSaveOption ?? false,
		features: getGoCardlessServerData()?.supports ?? [],
	},
});
