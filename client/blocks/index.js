/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME } from './constants';
import { getGoCardlessServerData } from './gocardless-utils';

const {
	description,
	logo_url: logoUrl,
	title,
} = getGoCardlessServerData();

const Content = (props) => {
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
