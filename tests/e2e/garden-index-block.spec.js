const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

const INDEX = '.wp-block-undergrowth-garden-index';

/**
 * Publishes a post whose body repeats a fixture word, so the e2e provider
 * gives it a known vector. Titles hold no fixture word.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} title        Post title.
 * @param {string} word         Fixture word, or an empty string for none.
 * @return {Promise<Object>} The post.
 */
async function publish( requestUtils, title, word ) {
	return requestUtils.createPost( {
		title,
		content: `<p>${ Array( 6 ).fill( word ).join( ' ' ) }</p>`,
		status: 'publish',
	} );
}

// Selectors use classes and block names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale. Post titles stay as written.
test.describe( 'Garden index block', () => {
	let first;
	let page_;

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();
		await skipWelcomeGuide( requestUtils );

		// Three alike posts make one group; Bread is near none of them.
		first = await publish( requestUtils, 'First', 'yacon' );
		await publish( requestUtils, 'Second', 'yacon' );
		await publish( requestUtils, 'Third', 'yacon' );
		await publish( requestUtils, 'Bread', '' );

		const pass = await requestUtils.request.get(
			'wp-admin/index.php?undergrowth_e2e_cluster=1'
		);
		expect( pass.ok() ).toBe( true );

		page_ = await requestUtils.createPage( {
			title: 'Garden',
			content:
				'<!-- wp:undergrowth/garden-index {"fontSize":"small"} /-->',
			status: 'publish',
		} );
	} );

	test( 'shows readers each group, then the rest, and drops a composted post', async ( {
		admin,
		page,
	} ) => {
		await page.goto( `/?page_id=${ page_.id }` );

		const index = page.locator( INDEX );
		await expect( index ).toHaveClass( /has-small-font-size/ );
		await expect( index.locator( 'h2' ) ).toHaveCount( 2 );
		await expect(
			index.locator( 'ul' ).first().locator( 'li' )
		).toHaveCount( 3 );
		await expect(
			index.locator( 'ul' ).last().locator( 'li a' )
		).toHaveText( 'Bread' );
		await expect(
			index.locator( '.is-stage-seedling .screen-reader-text' ).first()
		).toBeAttached();
		await expectNoA11yViolations( page, INDEX );

		// Composted the way an author would, from the Posts screen.
		await admin.visitAdminPage( 'edit.php' );
		const compost = page.locator(
			`#post-${ first.id } a[href*="action=undergrowth_compost&"]`
		);
		await compost.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);

		await page.goto( `/?page_id=${ page_.id }` );
		await expect(
			page.locator( INDEX ).locator( 'ul' ).first().locator( 'li' )
		).toHaveCount( 2 );
		await expect(
			page.locator( `${ INDEX } a[href*="p=${ first.id }"]` )
		).toHaveCount( 0 );
	} );

	test( 'previews in the editor and filters by stage by keyboard', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.editPost( page_.id );

		const preview = editor.canvas.locator( `${ INDEX } ${ INDEX }` );
		await expect( preview.locator( 'li' ) ).toHaveCount( 4 );
		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			INDEX,
		] );

		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="undergrowth/garden-index"]' )
		);
		await editor.openDocumentSettingsSidebar();
		const stage = page.locator( '.block-editor-block-inspector select' );
		const evergreen = await stage
			.locator( 'option[value="evergreen"]' )
			.textContent();
		await stage.focus();
		// A closed select picks the option whose name is typed, in any locale.
		await page.keyboard.type( evergreen );
		await expect( stage ).toHaveValue( 'evergreen' );

		// No post is Evergreen, so the author is told why the list is empty.
		await expect(
			editor.canvas.locator( `${ INDEX } .components-placeholder` )
		).toBeVisible();
		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			INDEX,
		] );
		await expectNoA11yViolations( page, '.block-editor-block-inspector' );
	} );
} );
