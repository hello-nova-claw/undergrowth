const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, not text, because this spec also runs in Hebrew and in
// the en_XA pseudo-locale.
test.describe( 'Settings page', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
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

	test( 'reaches the key field and the save button by keyboard', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_openai_api_key' );
		const save = page.locator( '.wrap #submit' );

		await field.focus();
		await expect( field ).toBeFocused();

		await page.keyboard.press( 'Tab' );
		await expect( save ).toBeFocused();
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
} );
