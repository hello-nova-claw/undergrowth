const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

const LIST = '.wp-block-undergrowth-linked-from';

/**
 * Accepts the suggestion for a post to link to Yacon, by keyboard, as the
 * author would on Posts > Undergrowth.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin  Admin utils.
 * @param {import('@playwright/test').Page}                      page   Page.
 * @param {string}                                               source Title of the post the link sits in.
 */
async function acceptToYacon( admin, page, source = 'Oca' ) {
	await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

	const row = page.locator( 'table.undergrowth-links tbody tr' ).filter( {
		has: page.locator( '.column-source a', { hasText: source } ),
	} );
	const accept = row
		.getByRole( 'button', {
			name: new RegExp( `${ source }[\\s\\S]*Yacon` ),
		} )
		.and( row.locator( '[form^="undergrowth-accept-"]' ) );

	await accept.focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
		/notice-success/
	);
}

// Selectors use classes and block names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale. Post titles stay as written.
test.describe( 'Linked from block', () => {
	let oca;
	let yacon;

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		oca = await requestUtils.createPost( {
			title: 'Oca',
			content: '<p>Oca grows here. I keep yacon nearby.</p>',
			status: 'publish',
		} );
		yacon = await requestUtils.createPost( {
			title: 'Yacon',
			content:
				'<!-- wp:paragraph --><p>Yacon syrup is sweet.</p><!-- /wp:paragraph --><!-- wp:undergrowth/linked-from {"fontSize":"small"} /-->',
			status: 'publish',
		} );
	} );

	test( 'explains in a Placeholder why nothing is listed before a link is accepted', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.editPost( yacon.id );

		const note = editor.canvas.locator(
			`${ LIST } .components-placeholder`
		);
		await expect( note ).toBeVisible();
		await expect( editor.canvas.locator( `${ LIST } ul` ) ).toHaveCount(
			0
		);

		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			LIST,
		] );
	} );

	test( 'lists an accepted link in the editor, with settings set by keyboard', async ( {
		admin,
		editor,
		page,
	} ) => {
		await acceptToYacon( admin, page );
		await admin.editPost( yacon.id );

		const preview = editor.canvas.locator( `${ LIST } ${ LIST }` );
		await expect( preview.locator( 'li a' ) ).toHaveText( 'Oca' );
		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			LIST,
		] );

		// The heading is the author's own words, typed in the sidebar.
		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="undergrowth/linked-from"]' )
		);
		await editor.openDocumentSettingsSidebar();
		const heading = page.locator(
			'.block-editor-block-inspector input[type="text"]'
		);
		await heading.focus();
		await page.keyboard.type( 'Paths here' );

		await expect( preview.locator( 'h2' ) ).toHaveText( 'Paths here' );
		await expectNoA11yViolations( page, '.block-editor-block-inspector' );
	} );

	test( 'shows readers the linking posts, and drops one once composted', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.createPost( {
			title: 'Mashua',
			content: '<p>Mashua grows here. I keep yacon nearby.</p>',
			status: 'publish',
			date: '2020-01-01T10:00:00',
		} );
		await acceptToYacon( admin, page );
		await acceptToYacon( admin, page, 'Mashua' );

		await page.goto( `/?p=${ yacon.id }` );

		// Newest first, kept far enough apart to tap at a small size.
		const list = page.locator( LIST );
		await expect( list ).toHaveClass( /has-small-font-size/ );
		await expect( list.locator( 'h2' ) ).toHaveCount( 1 );
		await expect( list.locator( 'li a' ) ).toHaveText( [
			'Oca',
			'Mashua',
		] );
		await expect( list.locator( 'li a' ).first() ).toHaveAttribute(
			'href',
			new RegExp( `p=${ oca.id }|/oca/?$` )
		);
		await expectNoA11yViolations( page, LIST );

		// Composted the way an author would, from the Posts screen.
		await admin.visitAdminPage( 'edit.php' );
		const compost = page.locator(
			`#post-${ oca.id } a[href*="action=undergrowth_compost&"]`
		);
		await compost.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);

		await page.goto( `/?p=${ yacon.id }` );
		await expect( page.locator( `${ LIST } li a` ) ).toHaveText( [
			'Mashua',
		] );
	} );
} );
