const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

const BADGE = '.wp-block-undergrowth-growth-stage';

// Selectors use classes and block names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Growth stage block', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'previews the badge in the editor and keeps the editor accessible', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'undergrowth/growth-stage' } );

		// The preview comes from the server, inside the block's own wrapper.
		const preview = editor.canvas.locator(
			`${ BADGE } ${ BADGE }.is-stage-seedling`
		);
		await expect( preview ).toBeVisible();
		await expect( preview.locator( '.screen-reader-text' ) ).toHaveCount(
			1
		);
		await expect(
			preview.locator( '[aria-hidden="true"]' )
		).not.toBeEmpty();

		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			BADGE,
		] );
	} );

	test( 'is inserted from the inserter by keyboard', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();

		const toggle = page.locator(
			'.editor-document-tools__inserter-toggle'
		);
		await toggle.focus();
		await page.keyboard.press( 'Enter' );

		// The block's name is translated, so search by its slug keyword.
		await page.keyboard.type( 'undergrowth/growth-stage' );
		const option = page.locator(
			'.editor-block-list-item-undergrowth-growth-stage'
		);
		await expect( option ).toBeVisible();
		await option.focus();
		await page.keyboard.press( 'Enter' );

		await expect(
			editor.canvas.locator( `[data-type="undergrowth/growth-stage"]` )
		).toHaveCount( 1 );
	} );

	test( 'shows readers the stage and hides it once the post is composted', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Oca',
			content:
				'<!-- wp:undergrowth/growth-stage {"fontSize":"small"} /-->',
			status: 'publish',
		} );

		await page.goto( `/?p=${ post.id }` );

		const badge = page.locator( `${ BADGE }.is-stage-seedling` );
		await expect( badge ).toBeVisible();
		await expect( badge ).toHaveClass( /has-small-font-size/ );
		await expect( badge.locator( '.screen-reader-text' ) ).toHaveCount( 1 );
		await expectNoA11yViolations( page, BADGE );

		// Composted the way an author would, from the Posts screen.
		await admin.visitAdminPage( 'edit.php' );
		const compost = page.locator(
			`#post-${ post.id } a[href*="action=undergrowth_compost&"]`
		);
		await compost.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);

		await page.goto( `/?p=${ post.id }` );
		await expect( page.locator( 'body' ) ).toBeVisible();
		await expect( page.locator( BADGE ) ).toHaveCount( 0 );
	} );
} );
