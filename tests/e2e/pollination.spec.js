const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

/**
 * Publishes a post whose body repeats fixture words, so the e2e provider
 * gives it a known vector. Titles hold no fixture word.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} title        Post title.
 * @param {Object} words        Number of times to repeat each fixture word.
 * @return {Promise<Object>} The post.
 */
async function publish( requestUtils, title, words ) {
	const content = Object.entries( words )
		.map( ( [ word, count ] ) => Array( count ).fill( word ).join( ' ' ) )
		.join( ' ' );

	return requestUtils.createPost( {
		title,
		content: `<p>${ content }</p>`,
		status: 'publish',
	} );
}

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Cross-pollination', () => {
	let bridge;

	test.beforeEach( async ( { requestUtils, admin } ) => {
		await requestUtils.deleteAllPosts();

		for ( const title of [ 'First', 'Second', 'Third' ] ) {
			await publish( requestUtils, title, { yacon: 6 } );
		}

		for ( const title of [ 'Roots', 'Old roots', 'Deep roots' ] ) {
			await publish( requestUtils, title, { oca: 6 } );
		}

		// Near both groups' middles.
		bridge = await publish( requestUtils, 'Both', { oca: 3, yacon: 3 } );

		const pass = await requestUtils.request.get(
			'wp-admin/index.php?undergrowth_e2e_cluster=1'
		);
		expect( pass.ok() ).toBe( true );

		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=map'
		);
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'shows the pair, its bridge and the bridge post idea, with no WCAG 2.2 AA violations', async ( {
		page,
	} ) => {
		const pairs = page.locator( '.undergrowth-pollination-pair' );

		await expect( pairs ).toHaveCount( 1 );
		await expect(
			pairs.locator( `a[href*="post=${ bridge.id }&"]` )
		).toHaveCount( 1 );
		// The bridge, and the line about a post connecting both groups.
		await expect( pairs.locator( 'ul li' ) ).toHaveCount( 2 );
		await expect( pairs.locator( 'h3' ) ).toHaveAttribute(
			'id',
			/^undergrowth-pollination-\d+-\d+$/
		);
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'dismisses a pair by keyboard, for good', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		const dismiss = page.locator(
			'.undergrowth-pollination-pair form button[type="submit"]'
		);

		await dismiss.focus();
		await page.keyboard.press( 'Enter' );

		const notice = page.locator( '#undergrowth-notice' );
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( notice ).toBeFocused();
		await expect(
			page.locator( '.undergrowth-pollination-pair' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.undergrowth-pollination-none' )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.wrap' );

		// Next month's pass finds the same groups again.
		const pass = await requestUtils.request.get(
			'wp-admin/index.php?undergrowth_e2e_cluster=1'
		);
		expect( pass.ok() ).toBe( true );

		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=map'
		);
		await expect(
			page.locator( '.undergrowth-pollination-pair' )
		).toHaveCount( 0 );
	} );

	test( 'sets the surprise scale on Settings by keyboard, with no WCAG 2.2 AA violations', async ( {
		page,
		admin,
	} ) => {
		await page.locator( '#undergrowth-pollination + p a' ).click();
		await expect( page ).toHaveURL(
			/options-general\.php\?page=undergrowth/
		);

		const cautious = page.locator( '#undergrowth_surprise_2' );
		const wild = page.locator( '#undergrowth_surprise_5' );

		await expect( cautious ).toBeChecked();
		await expectNoA11yViolations( page, '.wrap' );

		// Arrow keys move through a radio group, as in any form.
		await cautious.focus();
		for ( let i = 0; i < 3; i++ ) {
			await page.keyboard.press( 'ArrowDown' );
		}
		await expect( wild ).toBeChecked();

		await page.locator( '#submit' ).click();
		await expect( page.locator( '#undergrowth_surprise_5' ) ).toBeChecked();

		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=map'
		);
		await expect(
			page.locator( '#undergrowth-pollination + p strong' )
		).toHaveCount( 1 );

		// Back to the default for the next test.
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
		await page.locator( '#undergrowth_surprise_2' ).check();
		await page.locator( '#submit' ).click();
		await expect( page.locator( '#undergrowth_surprise_2' ) ).toBeChecked();
	} );
} );
