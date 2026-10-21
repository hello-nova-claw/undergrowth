const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids and digits, not words, because this spec also runs in
// Hebrew and in the en_XA pseudo-locale.
const WIDGET = '#undergrowth_meter';

/**
 * Sets the fixture cookie that makes the test site's stand-in for Jetpack
 * answer with made-up views.
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

test.describe( 'Threshold meter on the Dashboard', () => {
	test( 'without Jetpack shows no number and no WCAG 2.2 AA violations', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await expect( page.locator( WIDGET ) ).toHaveCount( 1 );
		await expect( page.locator( `${ WIDGET } .inside` ) ).not.toHaveText(
			/\d/
		);
		await expectNoA11yViolations( page, WIDGET );
	} );

	test( 'below the line shows the count against the line', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'meter-below' );
		await admin.visitAdminPage( 'index.php' );

		const status = page.locator( `${ WIDGET } strong` );

		await expect( status ).toContainText( '420' );
		await expect( status ).toContainText( /1.000/ );
		await expectNoA11yViolations( page, WIDGET );
	} );

	test( 'over the line shows the count', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'meter-crossed' );
		await admin.visitAdminPage( 'index.php' );

		await expect( page.locator( `${ WIDGET } strong` ) ).toContainText(
			/1.230/
		);
		await expectNoA11yViolations( page, WIDGET );
	} );

	test( 'a failed answer shows no number', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'meter-no-answer' );
		await admin.visitAdminPage( 'index.php' );

		await expect( page.locator( `${ WIDGET } .inside` ) ).not.toHaveText(
			/\d/
		);
		await expectNoA11yViolations( page, WIDGET );
	} );
} );
