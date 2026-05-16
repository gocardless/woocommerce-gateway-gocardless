/* eslint-disable jest/expect-expect */
/**
 * External dependencies
 */
const { test, expect } = require('@playwright/test');

/**
 * Internal dependencies
 */
const {
	addToCart,
	connectWithGoCardless,
	goToCheckout,
	fillBillingDetails,
	placePayToOrder,
	placeOrder,
	enablePayToInSettings,
	validateGoCardlessPayment,
	runWpCliCommand,
} = require('../utils');
const { products, customer } = require('../config');

test.describe('PayTo checkout', () => {
	test.use({ storageState: process.env.CUSTOMERSTATE });

	let adminPage;
	const payToNotOfferedMessage =
		'PayTo is not offered (requires PayTo enabled and an active pay_to scheme on the creditor).';

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});
		await connectWithGoCardless(adminPage);
		await runWpCliCommand('wp option update woocommerce_currency "AUD"');
		await enablePayToInSettings(adminPage, true);
	});

	test.afterAll(async () => {
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
		await enablePayToInSettings(adminPage, false);
		await adminPage.close();
	});

	const auBilling = { ...customer.billing, ...customer.addresses.becs };
	const getPayToLocator = (isBlock) =>
		isBlock
			? 'label[for="radio-control-wc-payment-method-options-gocardless_payto"]'
			: 'ul.wc_payment_methods li.payment_method_gocardless_payto';

	const ensurePayToIsOffered = async (page, isBlock) => {
		const payToLocator = page.locator(getPayToLocator(isBlock)).first();
		await expect(payToLocator).toBeVisible();
	};

	[true, false].forEach((isBlock) => {
		const blockLabel = isBlock ? '[Block Checkout]' : '[Checkout]';

		test(`${blockLabel} PayTo hides when billing country is not Australia - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);

			const nonAu = {
				...customer.billing,
				country: 'US',
				countryName: 'United States',
				state: 'CA',
				stateName: 'California',
				postcode: '94107',
			};
			await fillBillingDetails(page, nonAu, isBlock);
			await expect(page.locator(getPayToLocator(isBlock)).first()).not.toBeVisible();
		});

		test(`${blockLabel} PayTo is available only for AUD currency - @foundational`, async ({
			page,
		}) => {
			await runWpCliCommand('wp option update woocommerce_currency "USD"');
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await expect(page.locator(getPayToLocator(isBlock)).first()).not.toBeVisible();

			await runWpCliCommand('wp option update woocommerce_currency "AUD"');
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);
		});

		test(`${blockLabel} Guest can complete checkout with PayTo when offered - @foundational`, async ({
			browser,
		}) => {
			const context = await browser.newContext({
				storageState: {},
			});
			const page = await context.newPage();
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(
				page,
				{ ...auBilling, email: `payto.${ Date.now() }@example.com` },
				isBlock
			);

			await ensurePayToIsOffered(page, isBlock);

			const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
			await validateGoCardlessPayment(adminPage, orderId, false, true);
			await context.close();
		});

		test(`${blockLabel} Guest can create account and checkout with PayTo - @foundational`, async ({
			browser,
		}) => {
			const context = await browser.newContext({
				storageState: {},
			});
			const page = await context.newPage();
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(
				page,
				{ ...auBilling, email: `payto.account.${ Date.now() }@example.com` },
				isBlock
			);
			await ensurePayToIsOffered(page, isBlock);

			if (isBlock) {
				await page
					.locator('.wc-block-checkout__create-account input[type="checkbox"]')
					.check();
				await page.waitForTimeout(2000);
			} else {
				await page.locator('#createaccount').check();
			}

			const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
			await validateGoCardlessPayment(adminPage, orderId, false, true);
			await context.close();
		});

		test(`${blockLabel} Customer can place order with PayTo - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);

			const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
			await validateGoCardlessPayment(adminPage, orderId, false, true);

			const customerId = await runWpCliCommand(
				'wp user meta get customer _gocardless_customer_id'
			);
			await expect(customerId).not.toBeFalsy();
		});

		test(`${blockLabel} Customer can save PayTo payment details for future checkout - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);

			const orderId = await placePayToOrder(page, { saveMethod: true, isBlock });
			await validateGoCardlessPayment(adminPage, orderId, false, true);

			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);

			await page.goto('/my-account/payment-methods/');
			await expect(
				page
					.locator('td.woocommerce-PaymentMethod', {
						hasText: 'PayTo Agreement - Cuscal Limited ending in 78',
					})
					.last()
			).toBeVisible();
		});

		test(`${blockLabel} Customer can place order with saved PayTo payment method - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);

			if (isBlock) {
				await page
					.locator('#radio-control-wc-payment-method-options-gocardless_payto')
					.check();
				const hasSavedMethod = await page
					.locator( 'label.wc-block-components-radio-control__option', {
						hasText: /PayTo Agreement - /
					})
					.first()
					.isVisible();
				if (!hasSavedMethod) {
					await placePayToOrder(page, { saveMethod: true, isBlock });
					await addToCart(page, products.simple);
					await goToCheckout(page, isBlock);
					await fillBillingDetails(page, auBilling, isBlock);
					await ensurePayToIsOffered(page, isBlock);
					await page
						.locator('#radio-control-wc-payment-method-options-gocardless_payto')
						.check();
				}
				await page
					.locator( 'label.wc-block-components-radio-control__option', {
						hasText: /PayTo Agreement - /
					})
					.first()
					.check();
			} else {
				await page.locator('input#payment_method_gocardless_payto').check();
				const hasSavedMethod = await page
					.locator(
						'li.woocommerce-SavedPaymentMethods-token input[name="wc-gocardless_payto-payment-token"]'
					)
					.first()
					.isVisible();
				if (!hasSavedMethod) {
					await placePayToOrder(page, { saveMethod: true, isBlock });
					await addToCart(page, products.simple);
					await goToCheckout(page, isBlock);
					await fillBillingDetails(page, auBilling, isBlock);
					await ensurePayToIsOffered(page, isBlock);
					await page.locator('input#payment_method_gocardless_payto').check();
				}
				await page
					.locator(
						'li.woocommerce-SavedPaymentMethods-token input[name="wc-gocardless_payto-payment-token"]'
					)
					.first()
					.check();
			}

			await page.waitForTimeout( 1000 );
			const orderId = await placeOrder(page, isBlock);
			await validateGoCardlessPayment(adminPage, orderId);
		});

		test(`${blockLabel} Guest can sign up to subscription using PayTo - @foundational`, async ({
			browser,
		}) => {
			const context = await browser.newContext({
				storageState: {},
			});
			const page = await context.newPage();
			await addToCart(page, products.subscription);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(
				page,
				{ ...auBilling, email: `payto.sub.${ Date.now() }@example.com` },
				isBlock
			);
			await ensurePayToIsOffered(page, isBlock);

			const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
			await validateGoCardlessPayment(adminPage, orderId, true);
			await adminPage
				.locator('.woocommerce_subscriptions_related_orders tr td a')
				.first()
				.click();
			await expect(
				await adminPage
					.locator('#order_status')
					.evaluate((el) => el.value)
			).toEqual('wc-active');
			await context.close();
		});

		test(`${blockLabel} Customer can sign up to subscription using PayTo - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.subscription);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, auBilling, isBlock);
			await ensurePayToIsOffered(page, isBlock);

			const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
			await validateGoCardlessPayment(adminPage, orderId, true);
			await adminPage
				.locator('.woocommerce_subscriptions_related_orders tr td a')
				.first()
				.click();
			await expect(
				await adminPage
					.locator('#order_status')
					.evaluate((el) => el.value)
			).toEqual('wc-active');
		});
	});

	test('Subscription renewal using PayTo payment gateway - @foundational', async ({
		page,
	}) => {
		await addToCart(page, products.subscription);
		await goToCheckout(page, true);
		await fillBillingDetails(page, auBilling, true);
		await ensurePayToIsOffered(page, true);

		const orderId = await placePayToOrder(page, {
			saveMethod: false,
			isBlock: true,
		});
		await validateGoCardlessPayment(adminPage, orderId, true);
		await adminPage
			.locator('.woocommerce_subscriptions_related_orders tr td a')
			.first()
			.click();
		await expect(
			await adminPage.locator('#order_status').evaluate((el) => el.value)
		).toEqual('wc-active');

		await adminPage
			.locator("select[name='wc_order_action']")
			.selectOption('wcs_process_renewal');
		await adminPage.on('dialog', (dialog) => dialog.accept());
		await adminPage.locator('#actions button.wc-reload').click();
		await expect(
			adminPage
				.locator(
					'.woocommerce_subscriptions_related_orders tr td mark.order-status'
				)
				.first()
		).toContainText('Processing');
	});
});
