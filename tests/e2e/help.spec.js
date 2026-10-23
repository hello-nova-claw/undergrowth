const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, not words, because this spec also runs in Hebrew and in
// the en_XA pseudo-locale.
const TABS = [
	'undergrowth-overview',
	'undergrowth-suggestions',
	'undergrowth-stages',
	'undergrowth-map',
	'undergrowth-compost',
];

test.describe( 'Help tabs', () => {
	test( 'Posts > Undergrowth explains each tab, opened by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		const help = page.locator( '#contextual-help-link' );

		await help.focus();
		await page.keyboard.press( 'Enter' );
		await expect( help ).toHaveAttribute( 'aria-expanded', 'true' );

		for ( const id of TABS ) {
			const link = page.locator( `#tab-link-${ id } a` );

			await link.focus();
			await page.keyboard.press( 'Enter' );
			await expect( page.locator( `#tab-panel-${ id }` ) ).toBeVisible();
		}

		await expectNoA11yViolations( page, '#contextual-help-wrap' );
	} );

	test( 'the Posts list explains Compost', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php' );

		await page.locator( '#contextual-help-link' ).focus();
		await page.keyboard.press( 'Enter' );

		const link = page.locator( '#tab-link-undergrowth-compost a' );

		await link.focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			page.locator( '#tab-panel-undergrowth-compost' )
		).toBeVisible();
		await expectNoA11yViolations( page, '#contextual-help-wrap' );
	} );
} );
