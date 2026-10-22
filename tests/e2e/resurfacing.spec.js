const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

/**
 * Runs the monthly clustering pass, which ends by resurfacing composting
 * posts near new clusters.
 *
 * @param {Object} requestUtils Request utilities.
 */
async function runPass( requestUtils ) {
	const response = await requestUtils.request.get(
		'wp-admin/index.php?undergrowth_e2e_cluster=1'
	);

	expect( response.ok() ).toBe( true );
}

/**
 * Publishes posts that repeat one fixture word, so the e2e provider gives
 * them almost the same vector.
 *
 * @param {Object}   requestUtils Request utilities.
 * @param {string}   word         Fixture word.
 * @param {string[]} titles       Post titles.
 * @return {Promise<Object[]>} The posts.
 */
async function publish( requestUtils, word, titles ) {
	const posts = [];

	for ( const title of titles ) {
		posts.push(
			await requestUtils.createPost( {
				title,
				content: `<p>${ Array( 6 ).fill( word ).join( ' ' ) }</p>`,
				status: 'publish',
			} )
		);
	}

	return posts;
}

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Resurfacing', () => {
	let old;

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		[ old ] = await publish( requestUtils, 'oca', [ 'Winter tubers' ] );
		await publish( requestUtils, 'yacon', [
			'Sweet',
			'Sweeter',
			'Sweetest',
		] );

		// Composted through its own row action, as the author would.
		// esc_url() writes each & in the link as &#038;.
		const list = await requestUtils.request.get( 'wp-admin/edit.php' );
		const link = ( await list.text() ).match(
			new RegExp(
				`href="([^"]*action=undergrowth_compost&#038;post=${ old.id }&#038;[^"]*)"`
			)
		);

		expect( link ).not.toBeNull();
		expect(
			(
				await requestUtils.request.get(
					link[ 1 ].replaceAll( '&#038;', '&' )
				)
			).ok()
		).toBe( true );

		// The first pass is the previous cycle the next one is compared with.
		await runPass( requestUtils );
		await publish( requestUtils, 'oca', [
			'Planting',
			'Harvest',
			'Storing',
		] );
		await runPass( requestUtils );
	} );

	test( 'shows one signal for the composting post, dismissed by keyboard', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=map'
		);

		const signal = page.locator( '.undergrowth-resurfacing' );

		await expect( signal ).toHaveCount( 1 );
		await expect(
			signal.locator( `input[name="post"][value="${ old.id }"]` )
		).toHaveCount( 1 );
		await expect(
			signal.locator( 'a[href*="action=undergrowth_return&"]' )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.wrap' );

		const dismiss = signal.locator( 'button[type="submit"]' );

		await dismiss.focus();
		await expect( dismiss ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		const notice = page.locator( '#undergrowth-notice' );

		await expect( notice ).toBeFocused();
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( signal ).toHaveCount( 0 );
		await expectNoA11yViolations( page, '.wrap' );

		// The cluster is no longer new on the next pass, so nothing returns.
		await runPass( requestUtils );
		await page.reload();
		await expect( signal ).toHaveCount( 0 );
	} );

	test( 'returns the post to the garden from the signal by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=map'
		);

		const back = page.locator(
			'.undergrowth-resurfacing a[href*="action=undergrowth_return&"]'
		);

		await back.focus();
		await expect( back ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
		await expect( page.locator( '.undergrowth-resurfacing' ) ).toHaveCount(
			0
		);

		// Back in the garden, it is listed on Growth stages again.
		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=stages'
		);
		await expect(
			page.locator( `#undergrowth-stage-${ old.id }` )
		).toHaveCount( 1 );
	} );
} );
