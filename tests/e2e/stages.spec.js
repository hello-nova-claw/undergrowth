const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Growth stages tab', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'has no WCAG 2.2 AA violations before anything is published', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=stages'
		);

		await expect( page.locator( '.nav-tab-active' ) ).toHaveAttribute(
			'aria-current',
			'page'
		);
		await expect( page.locator( '.wp-list-table' ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'is reached from the suggestions tab by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		const tab = page.locator( '.nav-tab[href*="tab=stages"]' );

		await tab.focus();
		await expect( tab ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /tab=stages/ );
		await expect( page.locator( '.nav-tab-active' ) ).toHaveAttribute(
			'href',
			/tab=stages/
		);
	} );

	test.describe( 'with a post', () => {
		let oca;

		test.beforeEach( async ( { requestUtils, admin } ) => {
			oca = await requestUtils.createPost( {
				title: 'Oca',
				content: '<p>Oca grows here.</p>',
				status: 'publish',
			} );
			await admin.visitAdminPage(
				'edit.php',
				'page=undergrowth-links&tab=stages'
			);
		} );

		test( 'lists it with no WCAG 2.2 AA violations', async ( { page } ) => {
			await expect(
				page.locator( 'table.undergrowth-stages tbody tr' )
			).toHaveCount( 1 );
			await expect(
				page.locator( `#undergrowth-stage-${ oca.id }` )
			).toHaveValue( 'seedling' );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'pins and unpins a stage by keyboard', async ( { page } ) => {
			const select = page.locator( `#undergrowth-stage-${ oca.id }` );

			await select.focus();
			await expect( select ).toBeFocused();
			await page.keyboard.press( 'ArrowDown' );
			await page.keyboard.press( 'ArrowDown' );
			await page.keyboard.press( 'ArrowDown' );
			await expect( select ).toHaveValue( 'evergreen' );

			await page.keyboard.press( 'Tab' );
			const pin = page.locator(
				'form:has(input[value="undergrowth_pin"]) button'
			);
			await expect( pin ).toBeFocused();
			await page.keyboard.press( 'Enter' );

			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( page.locator( '.undergrowth-pinned' ) ).toHaveCount(
				1
			);
			await expectNoA11yViolations( page, '.wrap' );

			const unpin = page.locator(
				'form:has(input[value="undergrowth_unpin"]) button'
			);
			await unpin.focus();
			await expect( unpin ).toBeFocused();
			await page.keyboard.press( 'Enter' );

			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( page.locator( '.undergrowth-pinned' ) ).toHaveCount(
				0
			);
			await expect( select ).toHaveValue( 'seedling' );
		} );
	} );
} );
