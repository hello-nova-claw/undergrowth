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
		requestUtils,
	} ) => {
		// Only published posts have a stage to preview.
		const post = await requestUtils.createPost( {
			title: 'Oca',
			status: 'publish',
		} );
		await admin.editPost( post.id );
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

	test( 'explains in a Placeholder, not in post text, why a draft shows no badge', async ( {
		admin,
		editor,
		page,
	} ) => {
		// A draft is not in the garden yet, so readers would see no stage.
		await admin.createNewPost();
		await editor.insertBlock( { name: 'undergrowth/growth-stage' } );

		const note = editor.canvas.locator(
			`${ BADGE } .components-placeholder`
		);
		await expect( note ).toBeVisible();
		await expect( editor.canvas.locator( `${ BADGE } > p` ) ).toHaveCount(
			0
		);

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

		// The inserter does not always move focus to its search field, and
		// keys typed before it has focus go elsewhere, so focus it first.
		const search = page.getByRole( 'searchbox' );
		await expect( search ).toBeVisible();
		await search.focus();

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

		// A classic theme may drop the block library's styles, and with them
		// the rule that hides .screen-reader-text. The label still stays hidden.
		await page.evaluate( () =>
			document
				.querySelectorAll( 'link[rel="stylesheet"], style' )
				.forEach( ( sheet ) => sheet.remove() )
		);
		const label = await badge
			.locator( '.screen-reader-text' )
			.boundingBox();
		expect( label.width ).toBeLessThanOrEqual( 1 );
		expect( label.height ).toBeLessThanOrEqual( 1 );

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
