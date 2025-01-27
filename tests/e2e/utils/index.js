/**
 * External dependencies
 */
import { expect, Page } from '@playwright/test';

/**
 * Internal dependencies
 */
const { promisify } = require('util');
const execAsync = promisify(require('child_process').exec);
export const api = require('./api');
const {
	goCardlessConfig,
	customer,
	bankDetails,
	gbBankDetails,
	bankData,
} = require('../config');

/**
 * Save admin settings.
 *
 * @param {Page} page Playwright page object
 */
export async function saveSettings(page) {
	await page.waitForTimeout(1000);
	if (await page.getByRole('button', { name: 'Save changes' }).isEnabled()) {
		await page.getByRole('button', { name: 'Save changes' }).click();
		await expect(page.locator('.updated').last()).toContainText(
			'Your settings have been saved.'
		);
	}
}

/**
 * Visit product page in storefront.
 *
 * @param {Page}   page      Playwright page object
 * @param {number} productId Product ID to visit
 */
export async function visitProductPage(page, productId) {
	await page.goto(`/?p=${productId}`);
	await expect(page.locator('.product_title')).toBeVisible();
}

/**
 * Fill billing details on checkout page
 *
 * @param {Page}    page                   Playwright page object
 * @param {Object}  customerBillingDetails Customer billing details
 * @param {boolean} isBlock                Whether to use block checkout
 */
export async function fillBillingDetails(
	page,
	customerBillingDetails,
	isBlock = false
) {
	if (isBlock) {
		await blockFillBillingDetails(page, customerBillingDetails);
		return;
	}
	await page
		.locator('#billing_first_name')
		.fill(customerBillingDetails.firstname);
	await page
		.locator('#billing_last_name')
		.fill(customerBillingDetails.lastname);
	await page
		.locator('#billing_country')
		.selectOption(customerBillingDetails.country);
	await page
		.locator('#billing_address_1')
		.fill(customerBillingDetails.addressfirstline);
	await page
		.locator('#billing_address_2')
		.fill(customerBillingDetails.addresssecondline);
	await page.locator('#billing_city').fill(customerBillingDetails.city);
	if (
		customerBillingDetails.state &&
		(await page.locator('select#billing_state').isVisible())
	) {
		await page
			.locator('#billing_state')
			.selectOption(customerBillingDetails.state);
	}
	await page
		.locator('#billing_postcode')
		.fill(customerBillingDetails.postcode);
	await page.locator('#billing_phone').fill(customerBillingDetails.phone);
	await page.locator('#billing_email').fill(customerBillingDetails.email);
}

/**
 * Add product to cart
 *
 * @param {Page}   page Playwright page object
 * @param {string} slug Product slug
 */
export async function addToCart(page, slug) {
	await page.goto(`/product/${slug}/`);
	await page.locator('.single_add_to_cart_button').click();
	await expect(
		page.getByRole('link', { name: 'View cart' }).first()
	).toBeVisible();
}

/**
 * Fill Billing details on block checkout page
 *
 * @param {Page}   page            Playwright page object
 * @param {Object} customerDetails Customer billing details
 */
export async function blockFillBillingDetails(page, customerDetails) {
	const card = await page.locator('.wc-block-components-address-card');
	if (await card.isVisible()) {
		await card.locator('.wc-block-components-address-card__edit').click();
	}
	await page.locator('#email').fill(customerDetails.email);
	await page.locator('#billing-first_name').fill('');
	await page.locator('#billing-first_name').fill(customerDetails.firstname);
	await page.locator('#billing-first_name').blur();

	await page.locator('#billing-last_name').fill('');
	await page.locator('#billing-last_name').fill(customerDetails.lastname);
	await page.locator('#billing-last_name').blur();

	await page
		.locator('#billing-country')
		.selectOption(customerDetails.country);

	await page.locator('#billing-address_1').fill('');
	await page
		.locator('#billing-address_1')
		.fill(customerDetails.addressfirstline);
	await page.locator('#billing-address_1').blur();

	if (await page.locator('#billing-address_2').isVisible()) {
		await page
			.locator('#billing-address_2')
			.fill(customerDetails.addresssecondline);
	}

	await page.locator('#billing-city').fill('');
	await page.locator('#billing-city').fill(customerDetails.city);
	await page.locator('#billing-city').blur();

	if (
		customerDetails.state &&
		(await page.locator('select#billing-state').isVisible())
	) {
		await page
			.locator('select#billing-state')
			.selectOption(customerDetails.state);
	}

	await page.locator('#billing-postcode').fill('');
	await page.locator('#billing-postcode').fill(customerDetails.postcode);
	await page.locator('#billing-postcode').blur();
}

/**
 * Place order in storefront.
 *
 * @param {Page}    page    Playwright page object
 * @param {boolean} isBlock Whether to use block checkout
 */
export async function placeOrder(page, isBlock = false) {
	if (isBlock) {
		await expect(
			page.locator(
				'button.wc-block-components-checkout-place-order-button'
			)
		).toBeEnabled();
		await page
			.locator('button.wc-block-components-checkout-place-order-button')
			.click();
	} else {
		await page.locator('#place_order').click();
	}

	await expect(
		page.getByRole('heading', { name: 'Order received' })
	).toBeVisible();
	const orderId = await page
		.locator('li.woocommerce-order-overview__order strong')
		.textContent();
	return orderId;
}

/**
 * Place order in storefront.
 *
 * @param {Page}   page    Playwright page object
 * @param {Object} options Whether to save payment method
 */
export async function placeGoCardlessOrder(page, options) {
	const { saveMethod = false, isBlock = false } = options;
	if (isBlock) {
		return blockPlaceGoCardlessOrder(page, options);
	}
	// Wait for overlay to disappear
	await page
		.locator('.blockUI.blockOverlay')
		.last()
		.waitFor({ state: 'detached' });

	await expect(
		page.locator('ul.wc_payment_methods li.payment_method_gocardless')
	).toBeVisible();

	const haveExistingPaymentMethods = await page
		.locator('li.woocommerce-SavedPaymentMethods-token')
		.first()
		.isVisible();
	if (haveExistingPaymentMethods) {
		await page.locator('#wc-gocardless-payment-token-new').check();
	}

	if (saveMethod) {
		await page.locator('#wc-gocardless-new-payment-method').check();
	}

	// Place order
	await page.locator('#place_order').click();

	// Handle GoCardless Payment
	await handleGoCardlessPayment(page, options);

	// verify order received page
	await expect(
		page.getByRole('heading', { name: 'Order received' })
	).toBeVisible();
	const orderId = await page
		.locator('li.woocommerce-order-overview__order strong')
		.textContent();
	return orderId;
}

export async function blockPlaceOrder(page, saveMethod = false) {
	const haveExistingPaymentMethods = await page
		.locator('input[name="radio-control-wc-payment-method-saved-tokens"]')
		.first()
		.isVisible();
	if (haveExistingPaymentMethods) {
		await page
			.locator('#radio-control-wc-payment-method-options-gocardless')
			.check();
	}

	if (saveMethod) {
		await page
			.locator(
				'.wc-block-components-payment-methods__save-card-info input[type="checkbox"]'
			)
			.first()
			.check();
	}

	// Place order
	await expect(
		page.locator('button.wc-block-components-checkout-place-order-button')
	).toBeEnabled();
	await page
		.locator('button.wc-block-components-checkout-place-order-button')
		.click();
}

/**
 * Place order in block checkout.
 *
 * @param {Page}   page    Playwright page object
 * @param {Object} options Options
 */
export async function blockPlaceGoCardlessOrder(page, options) {
	const { saveMethod = false } = options;
	await blockPlaceOrder(page, saveMethod);

	// Handle GoCardless redirect
	await handleGoCardlessPayment(page, options);

	// verify order received page
	await expect(
		page.getByRole('heading', { name: 'Order received' })
	).toBeVisible();
	const orderId = await page
		.locator('li.woocommerce-order-overview__order strong')
		.textContent();
	return orderId;
}

/**
 * Handle GoCardless payment.
 *
 * @param {Page}   page    Playwright page object
 * @param {Object} options Options
 */
export async function handleGoCardlessPayment(page, options) {
	const { customerBilling = customer.billing, currency = 'USD' } = options;
	// Locate GoCardless iframe
	const dropinIframe = await page
		.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
		.first();

	await page.waitForTimeout(5000);
	await dropinIframe
		.getByTestId('loading-spinner')
		.waitFor({ state: 'detached' });
	await page.waitForTimeout(1000);

	if (await dropinIframe.getByText(/Instant bank pay/).isVisible()) {
		if (await dropinIframe.locator('#given_name').isVisible()) {
			await dropinIframe
				.locator('#given_name')
				.fill(customer.billing.firstname);
			await dropinIframe
				.locator('#family_name')
				.fill(customer.billing.lastname);
			await expect(
				dropinIframe.getByRole('button', { name: 'Continue' })
			).toBeVisible();
			await dropinIframe
				.getByRole('button', { name: 'Continue' })
				.click({ force: true });
		}
		// Select bank
		await dropinIframe
			.getByTestId('CONSENT_AUTHORISED_READ_REFUND_ACCOUNT_SANDBOX_BANK')
			.click();

		// Fill bank details
		let filledBankDetails = false;
		if ( await dropinIframe
			.getByTestId('branch_code').isVisible()
		) {
			await dropinIframe
				.getByTestId('branch_code')
				.fill(gbBankDetails.bankCode);
			filledBankDetails = true;
		}
		if ( await dropinIframe
			.getByTestId('account_number').isVisible()
		) {
			await dropinIframe
				.getByTestId('account_number')
				.fill(gbBankDetails.accountNumber);
			filledBankDetails = true;
		}
		if (filledBankDetails) {
			await expect(
				dropinIframe.getByRole('button', { name: 'Continue' })
			).toBeVisible();
			await dropinIframe
				.getByRole('button', { name: 'Continue' })
				.click({ force: true });
		}

		// Final Confirmation
		await expect(
			dropinIframe.getByTestId('billing-request.bank-confirm.header')
		).toBeVisible();
		if (
			await dropinIframe
				.getByTestId('billing-request.bank-confirm.default-cta-button')
				.isVisible()
		) {
			await dropinIframe
				.getByTestId('billing-request.bank-confirm.default-cta-button')
				.click();
		} else {
			await dropinIframe
				.getByTestId(
					'billing-request.bank-confirm.direct-debit-cta-button'
				)
				.click();
		}

		// Pay (Bank confirmation)
		await expect(
			dropinIframe.getByRole('button', { name: 'Continue to manual web login' })
		).toBeVisible();
		await dropinIframe
			.getByRole('button', { name: 'Continue to manual web login' })
			.click({ force: true });
		return;
	}

	// Fill GoCardless payment details
	await dropinIframe.locator('#currencySelector').selectOption(currency);
	await dropinIframe
		.locator('#country_code')
		.selectOption(customerBilling.country);
	await dropinIframe.locator('#given_name').fill(customerBilling.firstname);
	await dropinIframe.locator('#family_name').fill(customerBilling.lastname);
	await dropinIframe
		.locator('#address_line1')
		.fill(customerBilling.addressfirstline);
	await dropinIframe
		.locator('#address_line2')
		.fill(customerBilling.addresssecondline);
	await dropinIframe.locator('#city').fill(customerBilling.city);
	await dropinIframe.locator('#postal_code').fill(customerBilling.postcode);
	if (await dropinIframe.locator('#region').isVisible()) {
		await dropinIframe
			.locator('#region')
			.selectOption(customerBilling.state);
	}
	await expect(
		dropinIframe.getByRole('button', { name: 'Continue' })
	).toBeVisible();
	await dropinIframe
		.getByRole('button', { name: 'Continue' })
		.click({ force: true });

	// Fill bank details
	if (currency === 'USD') {
		await dropinIframe
			.locator('#accountHolderName')
			.fill(customerBilling.firstname + ' ' + customerBilling.lastname);
		await dropinIframe.locator('#bank_code').fill(bankDetails.bankCode);
		await dropinIframe
			.locator('#account_number')
			.fill(bankDetails.accountNumber);
		await dropinIframe
			.locator('select[name="account_type"]')
			.selectOption(bankDetails.accountType);
	} else {
		await dropinIframe
			.getByTestId('branch_code')
			.fill(gbBankDetails.bankCode);
		await dropinIframe
			.getByTestId('account_number')
			.fill(gbBankDetails.accountNumber);
	}
	await expect(
		dropinIframe.getByRole('button', { name: 'Continue' })
	).toBeVisible();
	await dropinIframe
		.getByRole('button', { name: 'Continue' })
		.click({ force: true });

	// Final Confirmation
	await dropinIframe
		.getByTestId('billing-request.bank-confirm.direct-debit-cta-button')
		.click();
}

/**
 * Place order in block checkout.
 *
 * @param {Page}   page    Playwright page object
 * @param {Object} options Options
 * @param {string} scheme  Direct Debit Scheme
 */
export async function blockPlaceGoCardlessOrderSchemeWise(
	page,
	options,
	scheme = 'ACH'
) {
	const { saveMethod = false } = options;
	await blockPlaceOrder(page, saveMethod);

	// Handle GoCardless redirect
	await handleGoCardlessPaymentSchemeWise(page, options, scheme);

	// verify order received page
	await expect(
		page.getByRole('heading', { name: 'Order received' })
	).toBeVisible();
	const orderId = await page
		.locator('li.woocommerce-order-overview__order strong')
		.textContent();
	return orderId;
}

/**
 * Handle GoCardless payment Scheme wise.
 *
 * @param {Page}   page    Playwright page object
 * @param {Object} options Options
 * @param {string} scheme  Direct Debit Scheme
 */
export async function handleGoCardlessPaymentSchemeWise(
	page,
	options,
	scheme = 'ach'
) {
	const { customerBilling = customer.billing, currency = 'USD' } = options;
	// Locate GoCardless iframe
	const dropinIframe = await page
		.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
		.first();

	await page.waitForTimeout(5000);
	await dropinIframe
		.getByTestId('loading-spinner')
		.waitFor({ state: 'detached' });
	await page.waitForTimeout(1000);

	if (await dropinIframe.getByText(/Instant bank pay/).isVisible()) {
		return handleGoCardlessPayment(page, options);
	}

	// Fill GoCardless payment details
	const data = bankData[scheme] || bankDetails;
	await dropinIframe.locator('#currencySelector').selectOption(currency);

	await page.waitForTimeout(2500); // Add waiting time to avoid flakiness.
	if (scheme === 'sepa_core') {
		await dropinIframe
			.getByTestId('country-residence-selector')
			.selectOption('DK');
	} else {
		await dropinIframe
			.locator('#given_name')
			.fill(customerBilling.firstname);
		await dropinIframe
			.locator('#family_name')
			.fill(customerBilling.lastname);
	}

	if (scheme === 'betalingsservice') {
		dropinIframe.locator('#danish_identity_number').fill(data.idNumber);
	}
	await expect(
		dropinIframe.getByRole('button', { name: 'Continue' })
	).toBeVisible();
	await dropinIframe
		.getByRole('button', { name: 'Continue' })
		.click({ force: true });

	// Fill bank details
	switch (scheme) {
		case 'ach':
			await dropinIframe
				.locator('#accountHolderName')
				.fill(
					customerBilling.firstname + ' ' + customerBilling.lastname
				);
			await dropinIframe.locator('#bank_code').fill(data.bankCode);
			await dropinIframe
				.locator('#account_number')
				.fill(data.accountNumber);
			await dropinIframe
				.locator('select[name="account_type"]')
				.selectOption(data.accountType);
			break;

		case 'bacs':
		case 'becs':
		case 'autogiro':
			await dropinIframe.getByTestId('branch_code').fill(data.bankCode);
			await dropinIframe
				.getByTestId('account_number')
				.fill(data.accountNumber);
			break;

		case 'becs_nz':
			await dropinIframe.getByTestId('bank_code').fill(data.bankCode);
			await dropinIframe.getByTestId('branch_code').fill(data.branchCode);
			await dropinIframe
				.getByTestId('account_number')
				.fill(data.accountNumber);
			await dropinIframe
				.getByTestId('account_number_suffix')
				.fill(data.accountNumberSuffix);
			break;

		case 'pad':
			await dropinIframe.getByTestId('bank_code').fill(data.bankCode);
			await dropinIframe.getByTestId('branch_code').fill(data.branchCode);
			await dropinIframe
				.getByTestId('account_number')
				.fill(data.accountNumber);
			break;

		case 'betalingsservice':
		case 'sepa_core':
			await dropinIframe.getByTestId('bank_code').fill(data.bankCode);
			await dropinIframe
				.getByTestId('account_number')
				.fill(data.accountNumber);
			break;

		default:
			await dropinIframe
				.getByTestId('branch_code')
				.fill(gbBankDetails.bankCode);
			await dropinIframe
				.getByTestId('account_number')
				.fill(gbBankDetails.accountNumber);
			break;
	}

	await expect(
		dropinIframe.getByRole('button', { name: 'Continue' })
	).toBeVisible();
	await dropinIframe
		.getByRole('button', { name: 'Continue' })
		.click({ force: true });

	// Final Confirmation
	await dropinIframe
		.getByTestId('billing-request.bank-confirm.direct-debit-cta-button')
		.click();
}

/**
 * Run WP CLI command.
 *
 * @param {string} command
 */
export async function runWpCliCommand(command) {
	try {
		const { stdout, stderr } = await execAsync(
			`npm --silent run env run tests-cli -- ${command}`
		);
		if (stdout) {
			return stdout;
		}

		if (!stderr) {
			return true;
		}
		console.error(stderr);
		return false;
	} catch (error) {
		console.error(error);
		return false;
	}
}

/**
 * Connect with GoCardless.
 *
 * @param {Page} page Playwright page object
 */
export async function connectWithGoCardless(page) {
	await page.goto(
		'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
	);

	const isAlreadyConnected = await page
		.locator('.gocardless-connected')
		.isVisible();
	if (isAlreadyConnected) {
		return;
	}
	// Connect with GoCardless
	await page
		.getByRole('link', {
			name: 'Not ready to accept live payments? Click here to connect using sandbox mode.',
		})
		.click();
	await page.locator('#email').fill(goCardlessConfig.email);
	await page.locator('#password').fill(goCardlessConfig.password);
	await page.locator('#terms_and_conditions').check();
	await page.getByRole('button', { name: 'Connect Account' }).click();
	await page.locator('.redirect-button').click();

	// Verify that the account is connected.
	await expect(
		page.locator('.notice.notice-success.is-dismissible').first()
	).toContainText('Connected to GoCardless successfully.');
	await expect(page.locator('.gocardless-connected')).toBeVisible();
}

/**
 * Disconnect from GoCardless.
 *
 * @param {Page} page Playwright page object
 */
export async function disconnectFromGoCardless(page) {
	// Connect with GoCardless
	await page
		.getByRole('link', {
			name: 'Disconnect from GoCardless',
		})
		.click();

	// Verify that the account is disconnected.
	await expect(
		page.locator('.notice.notice-success.is-dismissible').first()
	).toContainText('Disconnected from GoCardless successfully.');
}

/**
 * Go to Checkout page.
 *
 * @param {Page}    page    Playwright page object
 * @param {boolean} isBlock Whether to use block checkout
 */
export async function goToCheckout(page, isBlock = false) {
	const slug = isBlock ? 'checkout' : 'shortcode-checkout';
	await page.goto(slug, { waitUntil: 'networkidle' });
}

/**
 * Validate GoCardless payment successful.
 *
 * @param {Page}   page    Playwright page object
 * @param {string} orderId Order ID
 */
export async function validateGoCardlessPayment(page, orderId) {
	const nRetries = 5;
	for (let i = 0; i < nRetries; i++) {
		await page.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		const orderStatus = await page
			.locator('#order_status')
			.evaluate((el) => el.value);
		if (orderStatus === 'wc-processing') {
			break;
		} else {
			await page.waitForTimeout(10000); // wait for webhook to be processed
		}
	}
	await expect(
		await page.locator('#order_status').evaluate((el) => el.value)
	).toEqual('wc-processing');
	await expect(
		page
			.locator(
				'#woocommerce-gocardless-webhook-events ul.order_notes li',
				{ hasText: 'payments confirmed' }
			)
			.first()
	).toBeVisible();
}

/**
 * Clear email Logs
 *
 * @param {Page} page Playwright page object
 */
export async function clearEmailLogs(page) {
	await page.goto('/wp-admin/admin.php?page=email-log');
	const bulkAction = await page.locator('#bulk-action-selector-top');
	if (await bulkAction.isVisible()) {
		await page.locator('#cb-select-all-1').check();
		await bulkAction.selectOption('el-log-list-delete-all');
		await page.locator('#doaction').click();
		await expect(
			page.locator('#setting-error-deleted-email-logs p').first()
		).toContainText('email logs deleted');
	}
}

/**
 * Create Pre-Order Product.
 *
 * @param {Page}   page    Playwright page object
 * @param {Object} options Product options
 *
 * @return {number} Product ID
 */
export async function createPreOrderProduct(page, options = {}) {
	await page.goto('/wp-admin/post-new.php?post_type=product');
	const product = {
		regularPrice: '10',
		preOrderFee: '5',
		whenToCharge: 'upon_release',
		availabilityDate: getNextDay(),
		...options,
	};

	// Set product title.
	await page.locator('#title').fill('Pre-Order Product');
	await page.locator('#title').blur();
	await page.locator('#sample-permalink').waitFor();

	// Set product data.
	await page.locator('.wc-tabs > li > a', { hasText: 'General' }).click();
	await page.locator('#_regular_price').fill(product.regularPrice);

	// Enable Deposits.
	await page.locator('.wc-tabs > li > a', { hasText: 'Pre-orders' }).click();
	await page.locator('#_wc_pre_orders_enabled').check();
	await page
		.locator('#_wc_pre_orders_availability_datetime')
		.fill(product.availabilityDate);
	await page.locator('#_wc_pre_orders_fee').fill(product.preOrderFee);
	await page
		.locator('#_wc_pre_orders_when_to_charge')
		.selectOption(product.whenToCharge);

	await page.locator('#publish').waitFor();
	await page.locator('#publish').click();
	await expect(
		page.getByText('Product published. View Product')
	).toBeVisible();
	const productId = await page.locator('#post_ID').inputValue();
	return productId;
}

/**
 * Get next day date.
 */
function getNextDay() {
	const date = new Date();
	date.setDate(date.getDate() + 1);
	const year = date.getFullYear();
	const month = String(date.getMonth() + 1).padStart(2, '0');
	const day = String(date.getDate()).padStart(2, '0');
	const hours = String(date.getHours()).padStart(2, '0');
	const minutes = String(date.getMinutes()).padStart(2, '0');
	return `${year}-${month}-${day} ${hours}:${minutes}`;
}

/**
 * Complete the Pre-Order.
 *
 * @param {Page}   page    Playwright page object
 * @param {string} orderId Order ID
 */
export async function completePreOrder(page, orderId) {
	await page.goto(`/wp-admin/admin.php?page=wc_pre_orders`);
	await page
		.locator(
			`#the-list th.check-column input[name="order_id[]"][value="${orderId}"]`
		)
		.check();
	await page.locator('#bulk-action-selector-top').selectOption('complete');
	await page.locator('#doaction').click();
}

/**
 * Process refund.
 *
 * @param {Page}   page   Playwright page object.
 * @param {string} amount refund amount.
 */
export async function processRefund(page, amount) {
	await page.locator('.refund-items').click();
	await page.locator('.refund_order_item_qty').fill('1');
	if (await page.locator('#refund_amount').isEditable()) {
		await page.locator('#refund_amount').fill('');
	}
	await page.locator('.refund_line_total').fill('');
	await page.locator('.refund_line_total').fill(amount);
	await page.locator('.do-api-refund').click();
}

/**
 * Clears WooCommerce cart.
 *
 * @param {Object} page Playwright page object.
 */
export async function clearCart(page) {
	await page.goto('/cart');

	if (
		await page.locator('.wp-block-woocommerce-cart-items-block').isVisible()
	) {
		const removeBtns = await page.$$('.wc-block-cart-item__remove-link');

		for (const button of removeBtns) {
			await button.click();
			await page.waitForTimeout(1000);
		}
	}

	if (await page.locator('.woocommerce-cart-form').isVisible()) {
		const removeBtns = await page.$$('td.product-remove .remove');

		for (const button of removeBtns) {
			await button.click();
		}
	}
}
