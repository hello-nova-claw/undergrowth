const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

const DEV = [ 'edit.php', 'page=undergrowth-links&tab=dev' ];

// Selectors use ids, classes, names and values, not text, because this spec
// also runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Dev tools tab', () => {
	let oca;
	let yacon;

	test.beforeEach( async ( { requestUtils, context, baseURL } ) => {
		await requestUtils.deleteAllPosts();

		// The posts share fixture words, so each suggests a link to the other.
		oca = await requestUtils.createPost( {
			title: 'Oca',
			content: '<p>Oca grows here. I keep yacon nearby.</p>',
			status: 'publish',
		} );
		yacon = await requestUtils.createPost( {
			title: 'Yacon',
			content: '<p>Yacon syrup is sweet, and so is oca.</p>',
			status: 'publish',
		} );

		await context.addCookies( [
			{ name: 'undergrowth_e2e_dev_tools', value: '1', url: baseURL },
		] );
	} );

	test( 'is offered on a local site, with no WCAG 2.2 AA violations', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...DEV );

		await expect( page.locator( '.nav-tab-active' ) ).toHaveAttribute(
			'href',
			/tab=dev/
		);
		await expect( page.locator( '.wrap .notice-warning' ) ).toHaveCount(
			1
		);
		await expect( page.locator( 'input[name="action"]' ) ).toHaveCount( 2 );

		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'is not there without the cookie that keeps it', async ( {
		admin,
		page,
		context,
	} ) => {
		await context.clearCookies( { name: 'undergrowth_e2e_dev_tools' } );
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		await expect( page.locator( '.nav-tab[href*="tab=dev"]' ) ).toHaveCount(
			0
		);
	} );

	test( 'accepts every suggestion and undoes it, by keyboard alone', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( ...DEV );

		// Accept all.
		await page.locator( '#undergrowth-dev-per-post' ).focus();
		await page.keyboard.press( 'Tab' );
		await expect(
			page.locator( 'input[type="submit"].button-primary' )
		).toBeFocused();
		await page.keyboard.press( 'Enter' );

		// The notice takes focus once the page has loaded, which waits for
		// the posts the tool saved to be queued for reading.
		await page.waitForURL( /undergrowth_notice=dev_/ );
		await page.waitForLoadState( 'load' );
		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
		await expect( page.locator( '.wrap ul.ul-disc li' ) ).not.toHaveCount(
			0
		);
		await expectNoA11yViolations( page, '.wrap' );

		const linked = await requestUtils.rest( {
			path: `/wp/v2/posts/${ oca.id }`,
			params: { context: 'edit' },
		} );
		expect( linked.content.raw ).toContain( '<a href=' );

		// The undo asks first, on the page.
		await page.locator( 'a.button[href*="confirm=reset"]' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			page.locator( '#undergrowth-dev-forget-vectors' )
		).not.toBeChecked();
		await expectNoA11yViolations( page, '.wrap' );

		await page.locator( '#undergrowth-dev-forget-vectors' ).focus();
		await page.keyboard.press( 'Tab' );
		await expect(
			page.locator( 'input[type="submit"].button-primary' )
		).toBeFocused();
		await page.keyboard.press( 'Enter' );

		// The notice takes focus once the page has loaded, which waits for
		// the posts the tool saved to be queued for reading.
		await page.waitForURL( /undergrowth_notice=dev_/ );
		await page.waitForLoadState( 'load' );
		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();

		for ( const post of [ oca, yacon ] ) {
			const restored = await requestUtils.rest( {
				path: `/wp/v2/posts/${ post.id }`,
				params: { context: 'edit' },
			} );
			expect( restored.content.raw ).toBe( post.content.raw );
		}

		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'queues the monthly cycle', async ( { admin, page } ) => {
		await admin.visitAdminPage( ...DEV );

		await page.locator( '#undergrowth-dev-cycle' ).click();

		await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
			/notice-success/
		);
	} );
} );
