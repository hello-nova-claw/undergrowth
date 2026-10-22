const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids and addresses, not words, because this spec also runs in
// Hebrew and in the en_XA pseudo-locale.
const NOTICE = '#undergrowth-first-run';

/**
 * Sets the fixture cookie for the test site.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {string}                                    baseURL Site address.
 * @param {string}                                    name    Fixture name.
 */
async function useFixture( context, baseURL, name ) {
	await context.addCookies( [
		{ name: 'undergrowth_e2e_fixture', value: name, url: baseURL },
	] );
}

test.describe( 'First run', () => {
	test( 'the notice shows on Plugins only, with no WCAG 2.2 AA violations', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'first-run' );
		await admin.visitAdminPage( 'plugins.php' );

		await expect( page.locator( NOTICE ) ).toHaveCount( 1 );
		await expectNoA11yViolations( page, NOTICE );

		await admin.visitAdminPage( 'index.php' );
		await expect( page.locator( NOTICE ) ).toHaveCount( 0 );
	} );

	test( 'with a provider there is no notice', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'plugins.php' );

		await expect( page.locator( '#the-list' ) ).toBeVisible();
		await expect( page.locator( NOTICE ) ).toHaveCount( 0 );
	} );

	test( 'Settings links on to suggestions after the first key is saved', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'first-key-saved' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const link = page.locator(
			'.notice-success a[href$="edit.php?page=undergrowth-links"]'
		);

		await expect( link ).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'by keyboard: activate, follow the notice, save a key, go on to suggestions', async ( {
		admin,
		page,
		context,
		baseURL,
		requestUtils,
	} ) => {
		// Without a provider, as on a new site, and with a fresh first run.
		await useFixture( context, baseURL, 'no-provider' );
		await requestUtils.rest( {
			method: 'DELETE',
			path: '/undergrowth-e2e/v1/first-run',
		} );
		await requestUtils.deactivatePlugin( 'undergrowth' );

		try {
			await admin.visitAdminPage( 'plugins.php' );

			const activate = page.locator( '#activate-undergrowth' );

			await activate.focus();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /plugins\.php\?activate=true/ );

			const settings = page.locator(
				`${ NOTICE } a[href$="options-general.php?page=undergrowth"]`
			);

			await expect( page.locator( NOTICE ) ).toHaveCount( 1 );
			await settings.focus();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /options-general\.php\?page=undergrowth/ );

			const field = page.locator( '#undergrowth_openai_api_key' );

			await field.focus();
			await page.keyboard.type( 'sk-e2e-0000000000wxyz' );
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /settings-updated=true/ );

			const onward = page.locator(
				'.notice-success a[href$="edit.php?page=undergrowth-links"]'
			);

			await onward.focus();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /edit\.php\?page=undergrowth-links/ );

			// With a key saved, the notice is gone for good.
			await admin.visitAdminPage( 'plugins.php' );
			await expect( page.locator( NOTICE ) ).toHaveCount( 0 );
		} finally {
			await requestUtils.activatePlugin( 'undergrowth' );
			await admin.visitAdminPage(
				'options-general.php',
				'page=undergrowth'
			);

			const remove = page.locator(
				'input[name="undergrowth_remove_openai_key"]'
			);

			if ( await remove.count() ) {
				await remove.check();
				await page.locator( '.wrap #submit' ).click();
			}
		}
	} );

	test( 'by keyboard, Dismiss for good removes the notice for good', async ( {
		admin,
		page,
		context,
		baseURL,
		requestUtils,
	} ) => {
		await useFixture( context, baseURL, 'no-provider' );
		await requestUtils.rest( {
			method: 'DELETE',
			path: '/undergrowth-e2e/v1/first-run',
		} );
		await requestUtils.deactivatePlugin( 'undergrowth' );

		try {
			await admin.visitAdminPage( 'plugins.php' );
			await page.locator( '#activate-undergrowth' ).focus();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /plugins\.php\?activate=true/ );

			// The second link in the notice, after the one to Settings.
			await page
				.locator(
					`${ NOTICE } a[href$="options-general.php?page=undergrowth"]`
				)
				.focus();
			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator(
					`${ NOTICE } a[href*="action=undergrowth_dismiss_first_run"]`
				)
			).toBeFocused();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /plugins\.php$/ );

			await expect( page.locator( NOTICE ) ).toHaveCount( 0 );
			await page.reload();
			await expect( page.locator( NOTICE ) ).toHaveCount( 0 );
		} finally {
			await requestUtils.activatePlugin( 'undergrowth' );
		}
	} );
} );
