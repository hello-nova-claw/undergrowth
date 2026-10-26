const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

const PAGE = '.wp-block-group:has(.wp-block-undergrowth-garden-feed)';

/**
 * Starts a new page, picks the Garden page pattern from the editor's
 * starter patterns, publishes the page and opens it as a reader would.
 *
 * @param {Object} args              Test fixtures.
 * @param {Object} args.admin        Admin utilities.
 * @param {Object} args.page         Page.
 * @param {Object} args.requestUtils Request utilities.
 */
async function insertGardenPage( { admin, page, requestUtils } ) {
	await skipWelcomeGuide( requestUtils );
	await admin.createNewPost( { postType: 'page' } );

	// The pattern's title is translated, so it is read from the editor.
	const title = await page.evaluate(
		async () =>
			(
				await window.wp.data.resolveSelect( 'core' ).getBlockPatterns()
			).find( ( pattern ) => pattern.name === 'undergrowth/garden-page' )
				.title
	);

	// One choice in the new page's pattern picker inserts the whole page.
	const option = page
		.getByRole( 'dialog' )
		.getByRole( 'option', { name: title, exact: true } );
	await option.focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );

	const id = await page.evaluate( async () => {
		const editor = window.wp.data.dispatch( 'core/editor' );

		editor.editPost( { title: 'Garden', status: 'publish' } );
		await editor.savePost();

		return window.wp.data.select( 'core/editor' ).getCurrentPostId();
	} );

	await page.goto( `/?page_id=${ id }` );
}

test.describe( 'Garden page pattern', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();

		for ( const title of [ 'Oca', 'Yacon' ] ) {
			await requestUtils.createPost( {
				title,
				content: `<p>${ title } grows here.</p>`,
				status: 'publish',
			} );
		}
	} );

	test( 'inserts in one step and shows readers the garden', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await insertGardenPage( { admin, page, requestUtils } );

		const garden = page.locator( PAGE );
		await expect( garden.locator( 'h2' ) ).toHaveCount( 1 );
		await expect(
			garden.locator( '.wp-block-undergrowth-garden-count-number' )
		).toHaveText( '2' );
		await expect(
			garden.locator( '.wp-block-undergrowth-garden-filters' )
		).toBeVisible();
		await expect(
			garden.locator( '.wp-block-undergrowth-garden-feed-title' )
		).toHaveText( [ 'Yacon', 'Oca' ] );
		// The intro is a placeholder only the author sees.
		await expect( garden.locator( 'p:empty' ) ).toHaveCount( 1 );
		await expectNoA11yViolations( page, PAGE );
	} );

	test( 'works in a classic theme', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const themes = await requestUtils.rest( { path: '/wp/v2/themes' } );
		test.skip(
			! themes.some(
				( theme ) => theme.stylesheet === 'twentytwentyone'
			),
			'Twenty Twenty-One is not installed.'
		);

		await requestUtils.activateTheme( 'twentytwentyone' );

		try {
			await insertGardenPage( { admin, page, requestUtils } );

			const garden = page.locator( PAGE );
			await expect(
				garden.locator( '.wp-block-undergrowth-garden-feed-title' )
			).toHaveCount( 2 );
			await expectNoA11yViolations( page, PAGE );
		} finally {
			await requestUtils.activateTheme( 'twentytwentyfive' );
		}
	} );
} );
