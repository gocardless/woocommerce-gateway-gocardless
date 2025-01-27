/**
 * External dependencies
 */
const { test, expect } = require('@playwright/test');

/**
 * Internal dependencies
 */
const {
	addToCart,
	runWpCliCommand,
	connectWithGoCardless,
	disconnectFromGoCardless,
	goToCheckout,
	saveSettings,
	fillBillingDetails,
} = require('../utils');
const {
	paymentMethodTitle,
	paymentMethodDescription,
	products,
	customer,
} = require('../config');

test.describe('Admin Tests', () => {
	// Set admin as logged-in user.
	test.use({ storageState: process.env.ADMINSTATE });

	// Covers critical flow: Can activate the plugin without any error.
	test('Store admin can login and make sure extension is activated - @foundational', async ({
		page,
	}) => {
		await page.goto('/wp-admin/plugins.php');

		// Addon is active by default in the test environment, so we need to validate that it is activated.
		await expect(
			page.getByRole('link', {
				name: 'Deactivate WooCommerce GoCardless Gateway',
				exact: true,
			})
		).toBeVisible();
	});

	// Covers critical flow: Supported Country and Currency
	test('Should show required currency notice is non-supported currency is set on store - @foundational', async ({
		page,
	}) => {
		await runWpCliCommand('wp option update woocommerce_currency "INR"');
		await page.goto('/wp-admin/');

		await expect(page.locator('.error p')).toBeVisible();
		await expect(page.locator('.error p')).toContainText(
			'GoCardless requires that the WooCommerce currency is set to GBP, EUR, SEK, DKK, AUD, NZD, CAD or USD.'
		);

		await runWpCliCommand('wp option update woocommerce_currency "USD"');
		await page.goto('/wp-admin/');

		await expect(page.locator('.error p')).not.toBeVisible();
	});

	test('Store owner can see GoCardless payment gateway in payment methods list - @foundational', async ({
		page,
	}) => {
		await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout');
		const goCardless = await page.locator(
			'table.wc_gateways tr[data-gateway_id="gocardless"]'
		);
		await expect(goCardless).toBeVisible();
		await expect(goCardless.locator('td.name a')).toContainText(
			'Bank pay (open banking and direct debit via GoCardless)'
		);
	});

	// Covers critical flow: GoCardless Settings > Connect.
	test('Store owner can connect with GoCardless - @foundational', async ({
		page,
	}) => {
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await expect(
			page.getByRole('heading', {
				name: 'Bank pay (open banking and direct debit via GoCardless)',
			})
		).toBeVisible();

		// Connect with GoCardless
		await connectWithGoCardless(page);
	});

	/**
	 * Covers critical flow:
	 * - Setup and Configuration
	 * - GoCardless Settings > Enable / Disable payment gateway
	 * - GoCardless Settings > Title / Description
	 * - GoCardless Settings > Save Bank account
	 */
	test('Store owner can configure GoCardless payment gateway - @foundational', async ({
		page,
	}) => {
		// Make sure GoCardless is connected
		await connectWithGoCardless(page);

		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await expect(
			page.getByRole('heading', {
				name: 'Bank pay (open banking and direct debit via GoCardless)',
			})
		).toBeVisible();

		await page.locator('#woocommerce_gocardless_enabled').uncheck();
		await saveSettings(page);

		// Make sure GoCardless is not visible on traditional checkout page
		await addToCart(page, products.simple);
		await goToCheckout(page);
		await expect(
			page.locator('ul.wc_payment_methods li.payment_method_gocardless')
		).not.toBeVisible();

		// Make sure GoCardless is not visible on block checkout page
		await goToCheckout(page, true);
		await expect(
			page.locator(
				'label[for="radio-control-wc-payment-method-options-gocardless"]'
			)
		).not.toBeVisible();

		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await page.locator('#woocommerce_gocardless_enabled').check();
		await page
			.locator('#woocommerce_gocardless_title')
			.fill(paymentMethodTitle);
		await page
			.locator('#woocommerce_gocardless_description')
			.fill(paymentMethodDescription);
		await page
			.locator('#woocommerce_gocardless_saved_bank_accounts')
			.uncheck();
		await saveSettings(page);

		// Make sure GoCardless is visible on traditional checkout page
		await goToCheckout(page);
		await expect(
			page.locator('ul.wc_payment_methods li.payment_method_gocardless')
		).toBeVisible();
		await expect(
			page.locator(
				'li.payment_method_gocardless label[for="payment_method_gocardless"]'
			)
		).toContainText(paymentMethodTitle);
		await expect(
			page.locator('.payment_box.payment_method_gocardless p', {
				hasText: paymentMethodDescription,
			})
		).toBeVisible();
		await expect(
			page.locator('#wc-gocardless-new-payment-method')
		).not.toBeVisible();

		// Make sure GoCardless is visible on block checkout page
		await goToCheckout(page, true);
		await expect(
			page.locator(
				'label[for="radio-control-wc-payment-method-options-gocardless"]'
			)
		).toBeVisible();
		await expect(
			page.locator(
				'#radio-control-wc-payment-method-options-gocardless__label span'
			)
		).toContainText(paymentMethodTitle);
		const haveExistingPaymentMethods = await page
			.locator(
				'input[name="radio-control-wc-payment-method-saved-tokens"]'
			)
			.first()
			.isVisible();
		if (haveExistingPaymentMethods) {
			await page
				.locator('#radio-control-wc-payment-method-options-gocardless')
				.check();
		}
		await expect(
			page.locator(
				'.wc-block-components-radio-control-accordion-content',
				{
					hasText: paymentMethodDescription,
				}
			)
		).toBeVisible();
		await expect(
			page.locator(
				".wc-block-components-payment-methods__save-card-info input[type='checkbox']"
			)
		).not.toBeVisible();

		// Enable Save Bank Account.
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await page
			.locator('#woocommerce_gocardless_saved_bank_accounts')
			.check();
		await saveSettings(page);

		// Verify save bank account on traditional checkout page.
		await goToCheckout(page);
		await expect(
			page.locator('#wc-gocardless-new-payment-method')
		).toBeVisible();

		// Verify save bank account on block checkout page.
		await goToCheckout(page, true);
		if (
			await page
				.locator(
					'input[name="radio-control-wc-payment-method-saved-tokens"]'
				)
				.first()
				.isVisible()
		) {
			await page
				.locator('#radio-control-wc-payment-method-options-gocardless')
				.check();
		}
		await expect(
			page.locator(
				".wc-block-components-payment-methods__save-card-info input[type='checkbox']"
			)
		).toBeVisible();
	});

	test('Store owner can disconnect from GoCardless - @foundational', async ({
		page,
	}) => {
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await expect(
			page.getByRole('heading', { name: 'Bank pay (open banking and direct debit via GoCardless)' })
		).toBeVisible();

		// Disconnect from GoCardless
		await disconnectFromGoCardless(page);
	});

	// Covers critical flow: GoCardless Settings > Direct Debit Scheme
	test('Store owner can configure Direct Debit Scheme - @foundational', async ({
		page,
	}) => {
		// Make sure GoCardless is connected
		await connectWithGoCardless(page);

		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await page
			.locator('#woocommerce_gocardless_scheme')
			.selectOption('sepa_core');
		await page.locator('#woocommerce_gocardless_enabled').check();
		await saveSettings(page);

		await page.goto('/wp-admin/admin.php?page=wc-settings&tab=general');
		await page.locator('#woocommerce_currency').selectOption('EUR');
		await saveSettings(page);

		await addToCart(page, products.simple);
		await goToCheckout(page);
		await fillBillingDetails(page, customer.billing, false);
		// Wait for overlay to disappear
		await page
			.locator('.blockUI.blockOverlay')
			.last()
			.waitFor({ state: 'detached' });
		const haveExistingPaymentMethods = await page
			.locator('li.woocommerce-SavedPaymentMethods-token')
			.first()
			.isVisible();
		if (haveExistingPaymentMethods) {
			await page.locator('#wc-gocardless-payment-token-new').check();
		}

		// Place order
		await page.locator('#place_order').click();

		// Make sure Direct Debit Scheme is SEPA Core.
		const dropinIframe = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe.locator('span[aria-label="sepa"]').first()
		).toBeAttached();
		await goToCheckout(page);

		// Update Direct Debit Scheme to auto
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await page.locator('#woocommerce_gocardless_scheme').selectOption('');
		await saveSettings(page);
		await page.goto('/wp-admin/admin.php?page=wc-settings&tab=general');
		await page.locator('#woocommerce_currency').selectOption('USD');
		await saveSettings(page);

		await goToCheckout(page);
		await fillBillingDetails(page, customer.billing, false);
		// Wait for overlay to disappear
		await page
			.locator('.blockUI.blockOverlay')
			.last()
			.waitFor({ state: 'detached' });
		if (
			await page
				.locator('li.woocommerce-SavedPaymentMethods-token')
				.first()
				.isVisible()
		) {
			await page.locator('#wc-gocardless-payment-token-new').check();
		}

		// Place order
		await page.locator('#place_order').click();
		const dropinIframe1 = await page
			.frameLocator('iframe[name^="gocardless-dropin-iframe"]')
			.first();
		await expect(
			dropinIframe1.locator('span[aria-label="sepa"]').first()
		).not.toBeAttached();
		await goToCheckout(page);
	});
});
