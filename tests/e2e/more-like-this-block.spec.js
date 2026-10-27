const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );
const { STYLED, expectSupportsOnce } = require( './supports' );

const LIST = '.wp-block-undergrowth-more-like-this';
const PANEL = '.undergrowth-more-like-this-panel';
const BLOCK = '<!-- wp:undergrowth/more-like-this {"showExcerpt":true} /-->';

/**
 * Publishes the posts a list is drawn from. The test site's provider counts
 * the fixture words, so posts sharing "oca" are close and the ledger is not.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} block        The block's markup in the source post.
 * @return {Promise<Object>} Posts by name, as the REST API returns them.
 */
async function plantGarden( requestUtils, block = BLOCK ) {
	const posts = {};

	for ( const [ name, title, content, extra ] of [
		[ 'yacon', 'Yacon', 'Oca and yacon share a bed.', {} ],
		[ 'syrup', 'Syrup', 'Oca boiled down to syrup.', { excerpt: '' } ],
		[ 'ledger', 'Ledger', 'Ledger ledger ledger.', {} ],
		[ 'draft', 'Draft', 'Oca oca oca.', { status: 'draft' } ],
		[ 'source', 'Oca', 'Oca oca oca.', { excerpt: '' } ],
	] ) {
		posts[ name ] = await requestUtils.createPost( {
			title,
			content: `<!-- wp:paragraph --><p>${ content }</p><!-- /wp:paragraph -->${
				'source' === name ? block : ''
			}`,
			excerpt: 'A note the author wrote.',
			status: 'publish',
			...extra,
		} );
	}

	return posts;
}

/**
 * Finds the link to a post inside the list.
 *
 * @param {import('@playwright/test').Locator} list List.
 * @param {Object}                             post Post as the REST API returns it.
 * @return {import('@playwright/test').Locator} Link.
 */
function linkTo( list, post ) {
	return list.locator( `a[href="${ post.link }"]` );
}

// Selectors use classes and links, not text, because this spec also runs
// in Hebrew and in the en_XA pseudo-locale.
test.describe( 'More like this block', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await skipWelcomeGuide( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'shows readers close posts only, with titles, links and the author’s own excerpts', async ( {
		page,
		requestUtils,
	} ) => {
		const posts = await plantGarden( requestUtils );

		await page.goto( `/?p=${ posts.source.id }` );

		const list = page.locator( `nav${ LIST }` );
		await expect( list ).toBeVisible();
		await expect(
			page.getByRole( 'navigation', { name: 'More like this' } )
		).toBeVisible();
		await expect(
			list.getByRole( 'heading', { name: 'More like this' } )
		).toBeVisible();
		await expect( list.locator( 'li' ) ).toHaveCount( 2 );
		await expect( linkTo( list, posts.yacon ) ).toBeVisible();
		await expect( linkTo( list, posts.syrup ) ).toBeVisible();
		await expect( linkTo( list, posts.ledger ) ).toHaveCount( 0 );
		await expect( linkTo( list, posts.draft ) ).toHaveCount( 0 );
		await expect(
			list.locator( '.undergrowth-more-like-this-excerpt' )
		).toHaveCount( 1 );

		await expectNoA11yViolations( page, LIST );
	} );

	test( 'previews the list in the editor and hides a post from it by keyboard', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const posts = await plantGarden( requestUtils );

		await admin.editPost( posts.source.id );
		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="undergrowth/more-like-this"]' )
		);
		// Through the store, since the toggle's name is translated.
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar( 'edit-post/block' )
		);

		const preview = editor.canvas.locator( `${ LIST } nav${ LIST }` );
		await expect( preview.locator( 'li' ) ).toHaveCount( 2 );

		const panel = page.locator( PANEL );
		const toggle = panel.locator( '.components-panel__body-toggle' );
		if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
			await toggle.focus();
			await page.keyboard.press( 'Enter' );
		}

		const hide = panel.locator( '.undergrowth-more-like-this-hide' );
		await expect( hide ).toHaveCount( 2 );
		await expectNoA11yViolations( page, PANEL );
		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			LIST,
		] );

		await hide.first().focus();
		await page.keyboard.press( 'Enter' );

		await expect( hide ).toHaveCount( 1 );
		await expect(
			panel.locator( '.undergrowth-more-like-this-show' )
		).toHaveCount( 1 );
		await expect( preview.locator( 'li' ) ).toHaveCount( 1 );

		// Kept for good: readers no longer see it either.
		await page.goto( `/?p=${ posts.source.id }` );
		await expect( page.locator( `nav${ LIST } li` ) ).toHaveCount( 1 );
	} );

	test( 'shows its colour and spacing once in the editor, as on the front end', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const posts = await plantGarden(
			requestUtils,
			`<!-- wp:undergrowth/more-like-this ${ JSON.stringify( STYLED ) } /-->`
		);

		await expectSupportsOnce( {
			page,
			admin,
			editor,
			postId: posts.source.id,
			name: 'undergrowth/more-like-this',
			rendered: 'nav',
		} );
	} );

	test( 'shows readers nothing once every close post is composted', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const posts = await plantGarden( requestUtils );

		for ( const id of [ posts.yacon.id, posts.syrup.id ] ) {
			await admin.visitAdminPage( 'edit.php' );
			const compost = page.locator(
				`#post-${ id } a[href*="action=undergrowth_compost&"]`
			);
			await compost.focus();
			await page.keyboard.press( 'Enter' );
			await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
				/notice-success/
			);
		}

		await page.goto( `/?p=${ posts.source.id }` );
		await expect( page.locator( 'body' ) ).toBeVisible();
		await expect( page.locator( LIST ) ).toHaveCount( 0 );
	} );
} );
