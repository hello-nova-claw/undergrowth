const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids and values, not text, because this spec also runs in
// Hebrew and in the en_XA pseudo-locale.
const WIDGET = '#undergrowth';

test.describe( 'Prompt on the Dashboard', () => {
	test.beforeEach( async ( { requestUtils, context, baseURL } ) => {
		await requestUtils.deleteAllPosts();

		const post = await requestUtils.createPost( {
			title: 'Growing oca',
			content:
				'<!-- wp:paragraph --><p>I lift the tubers after the first frost.</p><!-- /wp:paragraph -->',
			status: 'publish',
		} );

		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/comments',
			data: {
				post: post.id,
				content: 'How do you store the tubers over winter?',
				author_name: 'A reader',
				author_email: 'reader@example.com',
				status: 'approved',
			},
		} );

		// The fixture supplies a made-up key; the test site answers for Claude.
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'prompt',
				url: baseURL,
			},
		] );
	} );

	test( 'shows one question with no WCAG 2.2 AA violations', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await expect( page.locator( WIDGET ) ).toHaveCount( 1 );
		await expect(
			page.locator( `${ WIDGET } .undergrowth-prompt strong` )
		).toContainText( '?' );
		await expectNoA11yViolations( page, WIDGET );
	} );

	test( 'snoozes by keyboard and moves focus to the notice', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		const snooze = page.locator( `${ WIDGET } button[value="snooze"]` );

		await snooze.focus();
		await expect( snooze ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);
		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
		// Core takes the result out of the address once it is shown.
		await expect( page ).not.toHaveURL( /undergrowth_notice/ );
		await expect( page.locator( WIDGET ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, '#undergrowth-notice' );
	} );

	test( 'dismisses for good', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'index.php' );

		const dismiss = page.locator( `${ WIDGET } button[value="dismiss"]` );

		await dismiss.focus();
		await page.keyboard.press( 'Space' );

		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);
		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
		await expect( page ).not.toHaveURL( /undergrowth_notice/ );

		await admin.visitAdminPage( 'index.php' );
		await expect( page.locator( WIDGET ) ).toHaveCount( 0 );
	} );

	test( 'undoes a dismissal by keyboard and brings the question back', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );
		await page.locator( `${ WIDGET } button[value="dismiss"]` ).focus();
		await page.keyboard.press( 'Enter' );

		const notice = page.locator( '#undergrowth-notice' );
		const undo = notice.locator(
			'a[href*="action=undergrowth_restore_prompt"]'
		);

		await expect( notice ).toBeFocused();
		await expectNoA11yViolations( page, '#undergrowth-notice' );

		// The Undo is the first stop after the notice.
		await page.keyboard.press( 'Tab' );
		await expect( undo ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( notice ).toBeFocused();
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( page.locator( WIDGET ) ).toHaveCount( 1 );
		await expect(
			page.locator( `${ WIDGET } button[value="dismiss"]` )
		).toBeVisible();
		await expectNoA11yViolations( page, '#undergrowth-notice' );
	} );

	test( 'fits a phone screen without scrolling sideways', async ( {
		admin,
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 800 } );
		await admin.visitAdminPage( 'index.php' );

		const widget = page.locator( WIDGET );

		await expect( widget ).toHaveCount( 1 );

		const overflow = await widget.evaluate(
			( box ) => box.scrollWidth - box.clientWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );
