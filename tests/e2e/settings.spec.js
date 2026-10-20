const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, not text, because this spec also runs in Hebrew and in
// the en_XA pseudo-locale.
test.describe( 'Settings page', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
	} );

	// A key left behind by a failed test would put the remove checkbox in the
	// tab order and break the keyboard test on the next run.
	test.afterEach( async ( { admin, page } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const remove = page.locator(
			'input[name="undergrowth_remove_openai_key"], input[name="undergrowth_remove_anthropic_key"]'
		);

		if ( ( await remove.count() ) > 0 ) {
			for ( const checkbox of await remove.all() ) {
				await checkbox.check();
			}
			await page.locator( '.wrap #submit' ).click();
		}
	} );

	test( 'has no WCAG 2.2 AA violations', async ( { page } ) => {
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'describes what is sent alongside the key field', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_openai_api_key' );

		await expect( field ).toHaveAttribute(
			'aria-describedby',
			'undergrowth-openai-notice'
		);
		await expect(
			page.locator( '#undergrowth-openai-notice' )
		).toBeVisible();
	} );

	test( 'describes what is sent to Anthropic alongside its key field', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_anthropic_api_key' );

		await expect( field ).toHaveAttribute(
			'aria-describedby',
			'undergrowth-anthropic-notice'
		);
		await expect(
			page.locator( '#undergrowth-anthropic-notice' )
		).toBeVisible();
	} );

	// en_XA pads strings with long unbroken runs, as some real languages do
	// with compound words. The last-error notice adds the longest of them.
	test( 'fits a phone screen without scrolling sideways', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'last-error',
				url: baseURL,
			},
		] );
		await page.setViewportSize( { width: 390, height: 800 } );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const width = await page
			.locator( '.wrap' )
			.evaluate( ( wrap ) => wrap.scrollWidth );

		expect( width ).toBeLessThanOrEqual( 390 );
	} );

	test( 'reaches both key fields and the save button by keyboard', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_openai_api_key' );

		await field.focus();
		await expect( field ).toBeFocused();

		await page.keyboard.press( 'Tab' );
		await expect(
			page.locator( '#undergrowth_anthropic_api_key' )
		).toBeFocused();

		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '.wrap #submit' ) ).toBeFocused();
	} );

	test( 'shows the key field empty after saving a key', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_openai_api_key' );

		await field.fill( 'sk-e2e-0000000000wxyz' );
		await page.locator( '.wrap #submit' ).click();

		await expect( field ).toHaveValue( '' );
		await expect( page.locator( '.wrap' ) ).toContainText( 'wxyz' );
		await expect( page.locator( '.wrap' ) ).not.toContainText(
			'sk-e2e-0000000000wxyz'
		);

		await page
			.locator( 'input[name="undergrowth_remove_openai_key"]' )
			.check();
		await page.locator( '.wrap #submit' ).click();
		await expect(
			page.locator( 'input[name="undergrowth_remove_openai_key"]' )
		).toHaveCount( 0 );
	} );

	test( 'shows the Anthropic key field empty after saving a key', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_anthropic_api_key' );
		const remove = page.locator(
			'input[name="undergrowth_remove_anthropic_key"]'
		);

		await field.fill( 'sk-ant-e2e-00000000qrst' );
		await page.locator( '.wrap #submit' ).click();

		await expect( field ).toHaveValue( '' );
		await expect( page.locator( '.wrap' ) ).toContainText( 'qrst' );
		await expect( page.locator( '.wrap' ) ).not.toContainText(
			'sk-ant-e2e-00000000qrst'
		);
		await expect(
			page.locator( 'input[name="undergrowth_remove_openai_key"]' )
		).toHaveCount( 0 );

		await remove.check();
		await page.locator( '.wrap #submit' ).click();
		await expect( remove ).toHaveCount( 0 );
	} );

	test( 'has no WCAG 2.2 AA violations with a failing Anthropic key', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'prompt-last-error',
				url: baseURL,
			},
		] );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		await expectNoA11yViolations( page, '.wrap' );
	} );
} );
