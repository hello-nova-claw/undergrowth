const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// The plugin's name is never translated, so it finds the section in Hebrew
// and in the en_XA pseudo-locale too.
test.describe( 'Privacy policy guide', () => {
	test( 'has a section for Undergrowth that opens by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'options-privacy.php', 'tab=policyguide' );

		const trigger = page.locator( '.privacy-settings-accordion-trigger', {
			hasText: 'Undergrowth',
		} );
		const panel = page.locator(
			`#${ await trigger.getAttribute( 'aria-controls' ) }`
		);

		await expect( panel ).toBeHidden();

		await trigger.focus();
		await page.keyboard.press( 'Enter' );

		await expect( trigger ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( panel ).toBeVisible();
		await expect( panel.locator( '.privacy-policy-tutorial' ) ).toHaveCount(
			2
		);
		await expect(
			panel.locator( 'p:not(.privacy-policy-tutorial)' )
		).toHaveCount( 6 );
	} );
} );
