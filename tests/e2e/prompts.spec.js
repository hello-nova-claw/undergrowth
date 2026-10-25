const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids and values, not text, because this spec also runs in
// Hebrew and in the en_XA pseudo-locale.
const WIDGET = '#undergrowth';
const STUDIO = [ 'edit.php', 'page=undergrowth-studio' ];
const QUESTIONS = '.undergrowth-questions';
const NOTICE = '#undergrowth-notice';

test.describe( 'Prompt studio', () => {
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

	test( 'the Dashboard points to the studio with no WCAG 2.2 AA violations', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		const link = page.locator(
			`${ WIDGET } .undergrowth-prompt a[href*="page=undergrowth-studio"]`
		);

		await expect( link ).toHaveCount( 1 );
		await expectNoA11yViolations( page, WIDGET );

		await link.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /page=undergrowth-studio/ );
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 1 );
	} );

	test( 'lists the new question with no WCAG 2.2 AA violations', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...STUDIO );

		const question = page.locator( `${ QUESTIONS } li` );

		await expect( question ).toHaveCount( 1 );
		await expect( question.locator( 'strong' ) ).toContainText( '?' );
		await expect( question.locator( 'button' ) ).toHaveCount( 3 );
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'saves by keyboard to the idea bank and moves focus to the notice', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...STUDIO );

		const save = page.locator( `${ QUESTIONS } button[value="save"]` );

		await save.focus();
		await expect( save ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( NOTICE ) ).toHaveClass( /notice-success/ );
		await expect( page.locator( NOTICE ) ).toBeFocused();
		// Core takes the result out of the address once it is shown.
		await expect( page ).not.toHaveURL( /undergrowth_notice/ );
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, '.wrap' );

		await admin.visitAdminPage(
			STUDIO[ 0 ],
			`${ STUDIO[ 1 ] }&view=saved`
		);
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 1 );
		await expect(
			page.locator( `${ QUESTIONS } a[href*="post.php"]` )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.wrap' );

		await page.locator( `${ QUESTIONS } button[value="remove"]` ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( NOTICE ) ).toBeFocused();
		await expect( page ).toHaveURL( /view=saved/ );
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 0 );
	} );

	test( 'undoes a dismissal by keyboard and brings the question back', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...STUDIO );
		await page.locator( `${ QUESTIONS } button[value="dismiss"]` ).focus();
		await page.keyboard.press( 'Space' );

		const notice = page.locator( NOTICE );
		const undo = notice.locator(
			'a[href*="action=undergrowth_restore_prompt"]'
		);

		await expect( notice ).toBeFocused();
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, NOTICE );

		// The Undo is the first stop after the notice.
		await page.keyboard.press( 'Tab' );
		await expect( undo ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( notice ).toBeFocused();
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 1 );
	} );

	test( 'keeps the question when there is nothing to replace it with', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...STUDIO );
		await page.locator( `${ QUESTIONS } button[value="replace"]` ).click();

		await expect( page.locator( NOTICE ) ).toHaveClass( /notice-warning/ );
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 1 );
	} );

	test( 'asks for more questions only once the cost is shown', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( ...STUDIO );
		await page.locator( `${ QUESTIONS } button[value="dismiss"]` ).click();

		const more = page.locator( '#undergrowth-more-prompts button' );

		await expect( page.locator( '#undergrowth-more-cost' ) ).toBeVisible();
		await expect( more ).toHaveAttribute(
			'aria-describedby',
			'undergrowth-more-cost'
		);
		await expectNoA11yViolations( page, '.wrap' );

		// A second reader's question gives Claude something new to ask about.
		const [ post ] = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { per_page: 1 },
		} );

		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/comments',
			data: {
				post: post.id,
				content: 'When do you lift them?',
				author_name: 'Another reader',
				author_email: 'another@example.com',
				status: 'approved',
			},
		} );

		await more.focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( NOTICE ) ).toHaveClass( /notice-success/ );
		await expect( page.locator( NOTICE ) ).toBeFocused();
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 1 );
	} );

	test( 'says so when there is nothing new to ask about', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...STUDIO );
		await page.locator( `${ QUESTIONS } button[value="save"]` ).click();
		await page.locator( '#undergrowth-more-prompts button' ).click();

		await expect( page.locator( NOTICE ) ).toHaveClass( /notice-info/ );
		await expect( page.locator( `${ QUESTIONS } li` ) ).toHaveCount( 0 );
	} );

	test( 'fits a phone screen without scrolling sideways', async ( {
		admin,
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 800 } );
		await admin.visitAdminPage( ...STUDIO );

		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );
