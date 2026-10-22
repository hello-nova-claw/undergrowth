const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Compost and return', () => {
	let oca;
	let yacon;

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		// The posts share fixture words, so each is suggested a link to the other.
		oca = await requestUtils.createPost( {
			title: 'Oca',
			content: '<p>Oca grows here. I keep yacon nearby.</p>',
			status: 'publish',
		} );
		yacon = await requestUtils.createPost( {
			title: 'Yacon',
			content: '<p>Yacon syrup is sweet.</p>',
			status: 'publish',
		} );
	} );

	/**
	 * Returns the row action link for a post.
	 *
	 * @param {import('@playwright/test').Page} page   Page under test.
	 * @param {string}                          action 'compost' or 'return'.
	 * @param {number}                          id     Post ID.
	 * @return {import('@playwright/test').Locator} The link.
	 */
	function rowAction( page, action, id ) {
		return page.locator(
			`#post-${ id } a[href*="action=undergrowth_${ action }&"]`
		);
	}

	test( 'composts a post and returns it by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );
		await expect(
			page.locator( 'table.undergrowth-links tbody tr' )
		).toHaveCount( 2 );

		await admin.visitAdminPage( 'edit.php' );

		// The post's stage sits with it on the Posts list.
		const stage = page.locator(
			`#post-${ oca.id } .column-undergrowth_stage`
		);
		await expect( stage ).not.toBeEmpty();
		await expectNoA11yViolations(
			page,
			`#post-${ oca.id } .column-undergrowth_stage`
		);

		// Core shows row actions when focus moves into the row.
		const compost = rowAction( page, 'compost', oca.id );
		await compost.focus();
		await expect( compost ).toBeFocused();
		await expect( compost ).toBeVisible();
		await page.keyboard.press( 'Enter' );

		const notice = page.locator( '#undergrowth-notice' );
		await expect( notice ).toBeFocused();
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( rowAction( page, 'compost', oca.id ) ).toHaveCount( 0 );
		await expect( rowAction( page, 'return', oca.id ) ).toHaveCount( 1 );
		await expect(
			page.locator( `#post-${ oca.id } .post-state` )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '#undergrowth-notice' );
		// Only the row actions: axe flags core's title link beside any post
		// state, such as Draft, as a link in a block of text.
		await expectNoA11yViolations( page, `#post-${ oca.id } .row-actions` );

		// Suggestions both from and to the composting post are gone.
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );
		await expect( page.locator( 'table.undergrowth-links' ) ).toHaveCount(
			0
		);
		await expectNoA11yViolations( page, '.wrap' );

		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=stages'
		);
		await expect(
			page.locator( 'table.undergrowth-stages tbody tr' )
		).toHaveCount( 1 );
		await expect(
			page.locator( `#undergrowth-stage-${ oca.id }` )
		).toHaveCount( 0 );
		await expect(
			page.locator( `#undergrowth-stage-${ yacon.id }` )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.wrap' );

		await admin.visitAdminPage( 'edit.php' );

		const back = rowAction( page, 'return', oca.id );
		await back.focus();
		await expect( back ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( notice ).toBeFocused();
		await expect( rowAction( page, 'compost', oca.id ) ).toHaveCount( 1 );
		await expect(
			page.locator( `#post-${ oca.id } .post-state` )
		).toHaveCount( 0 );

		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );
		await expect(
			page.locator( 'table.undergrowth-links tbody tr' )
		).toHaveCount( 2 );
	} );

	test( 'composts with the bulk action and lists the post under Composting', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php' );

		const box = page.locator( `#cb-select-${ oca.id }` );
		await box.focus();
		await page.keyboard.press( 'Space' );
		await expect( box ).toBeChecked();

		const bulk = page.locator( '#bulk-action-selector-top' );
		await bulk.focus();
		await bulk.selectOption( 'undergrowth_compost' );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#doaction' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();

		const view = page.locator( '.subsubsub .undergrowth_composting a' );
		await view.focus();
		await page.keyboard.press( 'Enter' );

		await expect( view ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( `#post-${ oca.id }` ) ).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.subsubsub' );
	} );
} );
