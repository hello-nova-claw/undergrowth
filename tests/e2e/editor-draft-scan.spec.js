const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

const SIDEBAR = '.undergrowth-links-sidebar';
const SCAN = `${ SIDEBAR } .undergrowth-scan`;

/**
 * Opens the Undergrowth sidebar from its toolbar button, by keyboard.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function openSidebar( page ) {
	const toggle = page.locator(
		'.interface-pinned-items button[aria-controls="undergrowth-links:undergrowth-links"]'
	);

	await toggle.focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( SIDEBAR ) ).toBeVisible();
}

// Selectors use classes and attributes, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Scan this draft', () => {
	let yacon;

	test.beforeAll( async ( { requestUtils } ) => {
		await skipWelcomeGuide( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		yacon = await requestUtils.createPost( {
			title: 'Yacon',
			content:
				'<!-- wp:paragraph --><p>Yacon syrup is sweet.</p><!-- /wp:paragraph -->',
			status: 'publish',
		} );
	} );

	test( 'scans only on a click, says where the text goes, and leaves no trace', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost( { title: 'Planting notes' } );
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'I keep yacon syrup by the door.' },
		} );
		await openSidebar( page );

		const scan = page.locator( `${ SCAN } .undergrowth-scan-button` );
		const destination = page.locator( `${ SCAN } .description` ).first();

		// The destination is named, and nothing has been sent yet.
		await expect( destination ).toContainText( 'e2e (word-counts)' );
		await expect( scan ).toHaveAttribute(
			'aria-describedby',
			( await destination.getAttribute( 'id' ) ) ?? ''
		);
		await expect(
			page.locator( `${ SCAN } .undergrowth-scan-results` )
		).toHaveCount( 0 );
		await expectNoA11yViolations( page, SIDEBAR );

		await scan.focus();
		await page.keyboard.press( 'Enter' );

		const results = page.locator( `${ SCAN } .undergrowth-scan-results` );
		const suggestion = results
			.locator( '.undergrowth-suggestion' )
			.filter( { has: page.locator( `a[href="${ yacon.link }"]` ) } );

		await expect( results ).toBeFocused();
		await expect( suggestion.locator( 'mark' ) ).toBeVisible();
		await expectNoA11yViolations( page, SIDEBAR );

		await suggestion.locator( '.undergrowth-accept' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			editor.canvas.locator( `p a[href="${ yacon.link }"]` )
		).toHaveCount( 1 );

		// Closed and opened again, the sidebar has forgotten the scan.
		await page
			.locator(
				'.interface-pinned-items button[aria-controls="undergrowth-links:undergrowth-links"]'
			)
			.click();
		await expect( page.locator( SIDEBAR ) ).toHaveCount( 0 );
		await openSidebar( page );
		await expect(
			page.locator( `${ SCAN } .undergrowth-scan-results` )
		).toHaveCount( 0 );

		// Nothing about the draft was stored.
		const draft = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);
		const stored = await requestUtils.rest( {
			path: `/undergrowth/v1/posts/${ draft }/links`,
		} );

		expect( stored.outgoing ).toEqual( [] );
		expect(
			(
				await requestUtils.rest( {
					path: `/undergrowth/v1/posts/${ yacon.id }/links`,
				} )
			).incoming
		).toEqual( [] );
	} );

	test( 'offers no scan for a published post', async ( { admin, page } ) => {
		await admin.editPost( yacon.id );
		await openSidebar( page );

		await expect(
			page.locator( `${ SIDEBAR } .undergrowth-links-loaded` )
		).toBeVisible();
		await expect( page.locator( SCAN ) ).toHaveCount( 0 );
	} );

	test( 'says why there is nothing to scan with', async ( {
		admin,
		context,
		baseURL,
		page,
	} ) => {
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'no-provider',
				url: baseURL,
			},
		] );
		await admin.createNewPost();
		await openSidebar( page );

		await expect( page.locator( SCAN ) ).toBeVisible();
		await expect(
			page.locator( `${ SCAN } .undergrowth-scan-button` )
		).toHaveCount( 0 );
		await expectNoA11yViolations( page, SIDEBAR );
	} );
} );
