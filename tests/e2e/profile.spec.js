const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, not text, because this spec also runs in Hebrew and in
// the en_XA pseudo-locale.
test.describe( 'Discord user field on the profile screen', () => {
	const section = 'table.form-table:has( #undergrowth_discord_user_id )';

	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'profile.php' );
	} );

	test.afterEach( async ( { admin, page } ) => {
		await admin.visitAdminPage( 'profile.php' );
		await page.locator( '#undergrowth_discord_user_id' ).fill( '' );
		await page.locator( '#submit' ).click();
	} );

	test( 'has no WCAG 2.2 AA violations', async ( { page } ) => {
		await expectNoA11yViolations( page, section );
	} );

	test( 'describes what linking does alongside the field', async ( {
		page,
	} ) => {
		await expect(
			page.locator( '#undergrowth_discord_user_id' )
		).toHaveAttribute(
			'aria-describedby',
			'undergrowth-discord-user-description'
		);
		await expect(
			page.locator( '#undergrowth-discord-user-description' )
		).toBeVisible();
	} );

	test( 'saves a Discord user ID typed by keyboard', async ( { page } ) => {
		const field = page.locator( '#undergrowth_discord_user_id' );

		await field.focus();
		await page.keyboard.type( '223344556677889900' );
		await page.keyboard.press( 'Enter' );

		// Core shows this notice only after a save, and the page is then
		// loaded afresh, so the value read here comes from what was stored
		// rather than from what was typed.
		await expect( page.locator( '#message.updated' ) ).toBeVisible();
		await page.reload();
		await expect( field ).toHaveValue( '223344556677889900' );
	} );

	test( 'refuses a Discord user ID that is not a number', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_discord_user_id' );

		await field.fill( 'abc' );
		await page.locator( '#submit' ).click();

		await expect(
			page.locator( '.notice-error, .error' ).first()
		).toBeVisible();
		await expectNoA11yViolations( page, section );
	} );

	test( 'fits a phone screen without scrolling sideways', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 800 } );
		await page.reload();

		const width = await page
			.locator( section )
			.evaluate( ( table ) => table.scrollWidth );

		expect( width ).toBeLessThanOrEqual( 390 );
	} );
} );
