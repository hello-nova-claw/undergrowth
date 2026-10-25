const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

/**
 * Publishes a post last edited two years ago and pins it Evergreen, so it is
 * worth a look. A post created with an old date keeps it as its last edit.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} title        Post title.
 * @return {Promise<Object>} The post, as the REST API returns it.
 */
async function staleEvergreen( requestUtils, title ) {
	const date = new Date( Date.now() - 2 * 365 * 24 * 3600 * 1000 );
	const post = await requestUtils.createPost( {
		title,
		content:
			'<!-- wp:paragraph --><p>Oca grows here.</p><!-- /wp:paragraph -->',
		status: 'publish',
		date: date.toISOString().slice( 0, 19 ),
	} );

	await requestUtils.rest( {
		method: 'POST',
		path: `/undergrowth/v1/posts/${ post.id }/stage`,
		data: { action: 'pin', stage: 'evergreen' },
	} );

	return post;
}

// Selectors use ids, classes and form attributes, not text, because this
// spec also runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Worth a look', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await skipWelcomeGuide( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'shows the note on the Growth stages tab and dismisses it by keyboard', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await staleEvergreen( requestUtils, 'Oca' );

		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=stages'
		);

		const row = page.locator( `#the-list tr:has(#cb-select-${ post.id })` );
		await expect( row.locator( '.undergrowth-tend' ) ).toBeVisible();
		await expectNoA11yViolations( page, '#the-list' );

		const dismiss = page.locator(
			`button[form="undergrowth-dismiss_tend-${ post.id }"]`
		);
		await dismiss.focus();
		await expect( dismiss ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);
		await expect( row.locator( '.undergrowth-tend' ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, '#undergrowth-notice' );
	} );

	test( 'shows the note in the editor panel, dismisses it, and never shows readers', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await staleEvergreen( requestUtils, 'Yacon' );

		await page.goto( `/?p=${ post.id }` );
		await expect( page.locator( 'body' ) ).toBeVisible();
		await expect( page.locator( '.undergrowth-tend' ) ).toHaveCount( 0 );

		await admin.editPost( post.id );
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar( 'edit-post/document' )
		);

		const panel = page.locator( '.undergrowth-editor-panel' );
		const toggle = panel.locator( '.components-panel__body-toggle' );
		if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
			await toggle.focus();
			await page.keyboard.press( 'Enter' );
		}

		const note = panel.locator( '.undergrowth-tend' );
		await expect( note ).toBeVisible();
		await expectNoA11yViolations( page, '.undergrowth-editor-panel' );

		const dismiss = panel.locator( '.undergrowth-dismiss-tend' );
		await dismiss.focus();
		await page.keyboard.press( 'Enter' );

		await expect( note ).toHaveCount( 0 );
		// The button is gone, so focus moves to the result.
		await expect( panel.locator( '.undergrowth-stage' ) ).toBeFocused();
	} );
} );
