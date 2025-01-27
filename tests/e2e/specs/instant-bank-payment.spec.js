/* eslint-disable jest/no-done-callback */
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
	placeGoCardlessOrder,
	validateGoCardlessPayment,
	runWpCliCommand,
	blockPlaceOrder,
	saveSettings,
} = require('../utils');
const { products, customer } = require('../config');

test.describe('Instant Bank Payment Tests', () => {
	// Set customer as logged-in user.
	let adminPage;
	test.use({ storageState: process.env.CUSTOMERSTATE });
	const supportedCountry = {
		country: 'GB',
		countryName: 'United Kingdom',
		city: 'London',
		postcode: 'WC2N 5DU',
	};

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		await connectWithGoCardless(adminPage);

		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_instant_bank_pay')
			.check();
		await saveSettings(adminPage);
	});

	test('Instant bank pay should be used for supported currencies and countries - @foundational', async ({
		page,
	}) => {
		// USD & US
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
		await addToCart(page, products.simple);
		await goToCheckout(page, true);
		await fillBillingDetails(page, customer.billing, true);
		await blockPlaceOrder(page);
		// Make sure Instant Bank Payment is not available for USA.
		const dropinIframe = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe.getByText('Instant bank pay').first()
		).not.toBeVisible();

		// GBP & GB
		await runWpCliCommand('wp option update woocommerce_currency "GBP"');
		await goToCheckout(page, true);
		await fillBillingDetails(
			page,
			{ ...customer.billing, ...supportedCountry },
			true
		);
		await blockPlaceOrder(page);

		// Make sure Instant Bank Payment is available.
		const dropinIframe1 = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe1.getByText('Instant bank pay').first()
		).toBeVisible();

		// EUR & DE
		await runWpCliCommand('wp option update woocommerce_currency "EUR"');
		await goToCheckout(page, true);
		await fillBillingDetails(
			page,
			{
				...customer.billing,
				country: 'DE',
				countryName: 'Germany',
				stateName: 'Berlin',
				city: 'Berlin',
				postcode: '10176',
				state: 'DE-BE',
			},
			true
		);

		await blockPlaceOrder(page);

		// Make sure Instant Bank Payment is available.
		const dropinIframe2 = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe2.getByText('Instant bank pay').first()
		).toBeVisible();
	});

	test('Merchant should be able to enable/disable Instant bank pay - @foundational', async ({
		page,
	}) => {
		// Disable Instant Bank Payment.
		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_instant_bank_pay')
			.uncheck();
		await saveSettings(adminPage);

		await runWpCliCommand('wp option update woocommerce_currency "GBP"');
		await addToCart(page, products.simple);
		await goToCheckout(page, true);
		await fillBillingDetails(
			page,
			{ ...customer.billing, ...supportedCountry },
			true
		);
		await blockPlaceOrder(page);

		// Make sure Instant Bank Payment is not available.
		const dropinIframe = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe.getByText('Instant bank pay').first()
		).not.toBeVisible();

		// Enable Instant Bank Payment.
		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_instant_bank_pay')
			.check();
		await saveSettings(adminPage);

		await goToCheckout(page, true);
		await fillBillingDetails(
			page,
			{ ...customer.billing, ...supportedCountry },
			true
		);
		await blockPlaceOrder(page);

		// Make sure Instant Bank Payment is available.
		const dropinIframe1 = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe1.getByText('Instant bank pay').first()
		).toBeVisible();
	});

	const checkouts = [true, false];

	checkouts.forEach((isBlock) => {
		const blockText = isBlock ? '[Block Checkout]' : '[Checkout]';

		test(`${blockText} Customer should be able to place order using IBP - @foundational`, async ({
			page,
		}) => {
			// GBP & GB
			await runWpCliCommand(
				'wp option update woocommerce_currency "GBP"'
			);
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(
				page,
				{ ...customer.billing, ...supportedCountry },
				isBlock
			);

			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: false,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);
		});

		// Covers critical flow: Add new payment method.
		test(`${blockText} Customer can save payment details for future checkout. - @foundational`, async ({
			page,
		}) => {
			// Remove saved payment methods.
			await page.goto('/my-account/payment-methods/');
			const savedMethods = await page.locator('tr.payment-method', {
				hasText: 'Barclays bank plc ending in 11',
			});
			const count = await savedMethods.count();

			for (let i = 0; i < count; i++) {
				await savedMethods.nth(0).locator('a.delete').click();
				await page.waitForTimeout(1000);
			}

			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(
				page,
				{
					...customer.billing,
					...supportedCountry,
				},
				isBlock
			);

			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: true,
				isBlock,
			});

			// Wait for 10 seconds, to allow the billing request to be fulfilled.
			await page.waitForTimeout(10000);
			await page.goto(`?billing_request_fulfilled_order_id=${orderId}`, { waitUntil: 'networkidle' });
			await validateGoCardlessPayment(adminPage, orderId);

			// Verify that the saved payment method is available.
			const nRetries = 5;
			for (let i = 0; i < nRetries; i++) {
				await page.goto('/my-account/payment-methods/');
				if ( await page
						.locator('td.woocommerce-PaymentMethod', {
							hasText: 'Barclays bank plc ending in 11',
						})
						.last()
						.isVisible()
				) {
					break;
				} else {
					await page.waitForTimeout(10000); // wait for webhook to be processed
				}
			}

			await page.goto('/my-account/payment-methods/');
			await expect(
				page
					.locator('td.woocommerce-PaymentMethod', {
						hasText: 'Barclays bank plc ending in 11',
					})
					.last()
			).toBeVisible();
		});
	});

	test('Customer can sign up to subscription using IBP and renewal using GoCardless DD - @foundational', async ({
		page,
	}) => {
		await addToCart(page, products.subscription);
		await goToCheckout(page, true);
		await fillBillingDetails(
			page,
			{ ...customer.billing, ...supportedCountry },
			true
		);

		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock: true,
		});

		// Wait for 10 seconds, to allow the billing request to be fulfilled.
		await page.waitForTimeout(10000);
		await page.goto(`?billing_request_fulfilled_order_id=${orderId}`, { waitUntil: 'networkidle' });


		// Verify that mandate saved.
		const nRetries = 5;
		for (let i = 0; i < nRetries; i++) {
			const metaData = await runWpCliCommand( `wp wc shop_order get ${orderId} --user=admin --field=meta_data` );
			console.log(metaData);
			if ( metaData?.includes( '_gocardless_mandate_id' ) ) {
				break;
			} else {
				await page.waitForTimeout(10000); // wait for webhook to be processed
			}
		}

		await validateGoCardlessPayment(adminPage, orderId);
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
