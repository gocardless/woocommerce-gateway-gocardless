/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '@woocommerce/settings';

/**
 * Register one GoCardless-derived payment method when Blocks exposes server data for it.
 *
 * @param {string} paymentMethodId Gateway ID (gocardless or gocardless_payto).
 * @param {string} defaultAriaLabel Fallback accessible label.
 */
export function registerGoCardlessPaymentMethod( paymentMethodId, defaultAriaLabel ) {
	const methodData = getPaymentMethodData( paymentMethodId, null );
	if ( ! methodData ) {
		return;
	}

	const {
		description,
		logo_url: logoUrl,
		title,
	} = methodData;

	const Content = () => {
		return decodeEntities( description || '' );
	};

	const Logo = () => {
		return (
			<img
				src={ logoUrl }
				alt={ decodeEntities( title ) }
				style={ { marginRight: '12px', marginBottom: '4px' } }
			/>
		);
	};

	const Label = ( props ) => {
		const { PaymentMethodLabel } = props.components;
		return (
			<PaymentMethodLabel
				text={ decodeEntities( title ) }
				icon={ <Logo /> }
			/>
		);
	};

	registerPaymentMethod( {
		name: paymentMethodId,
		label: <Label />,
		ariaLabel: decodeEntities( title ) || defaultAriaLabel,
		canMakePayment: ( { billingData, cartTotals } ) => {
			const currency = cartTotals?.currency_code;
			const supportedCountries =
				methodData?.supportedCountries || [];
			const supportedCurrencies =
				methodData?.supportedCurrencies || [];
			return (
				supportedCountries.includes( billingData?.country ) &&
				supportedCurrencies.includes( currency )
			);
		},
		content: <Content />,
		edit: <Content />,
		supports: {
			// Use `false` as fallback values in case server provided configuration is missing.
			showSavedCards: methodData.showSavedCards ?? false,
			showSaveOption: methodData.showSaveOption ?? false,
			features: methodData?.supports ?? [],
		},
	} );
}
