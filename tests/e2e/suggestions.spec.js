const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
/**
 * Finds a row's Accept or Veto button by role, by the titles in its name,
 * which stay as written in every locale, and by its action.
 *
 * @param {import('@playwright/test').Locator} row    Table row.
 * @param {string}                             action 'accept' or 'veto'.
 * @param {RegExp}                             name   Titles the name holds.
 * @return {import('@playwright/test').Locator} The button.
 */
function actionButton( row, action, name = /Oca[\s\S]*Yacon|Yacon[\s\S]*Oca/ ) {
	return row
		.getByRole( 'button', { name } )
		.and( row.locator( `[form^="undergrowth-${ action }-"]` ) );
}

test.describe( 'Suggestions on Posts > Undergrowth', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	// A failed test must never leave suggestions switched off for the next.
	test.afterEach( async ( { admin, page } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const toggle = page.locator( '#undergrowth_suggest_links' );

		if ( ! ( await toggle.isChecked() ) ) {
			await toggle.check();
			await page.locator( '.wrap #submit' ).click();
		}
	} );

	test( 'has no WCAG 2.2 AA violations before anything is suggested', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		await expect( page.locator( 'table.undergrowth-links' ) ).toHaveCount(
			0
		);
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test.describe( 'with suggestions', () => {
		let oca;

		test.beforeEach( async ( { requestUtils, admin } ) => {
			oca = await requestUtils.createPost( {
				title: 'Oca',
				content: '<p>Oca grows here. I keep yacon nearby.</p>',
				status: 'publish',
			} );
			await requestUtils.createPost( {
				title: 'Yacon',
				content: '<p>Yacon syrup is sweet.</p>',
				status: 'publish',
			} );
			await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );
		} );

		test( 'lists them with no WCAG 2.2 AA violations', async ( {
			page,
		} ) => {
			await expect(
				page.locator( 'table.undergrowth-links tbody tr' )
			).toHaveCount( 2 );
			await expect(
				page.locator( 'table.undergrowth-links q' ).first()
			).toBeVisible();
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'vetoes one by keyboard and moves focus to the result', async ( {
			page,
		} ) => {
			const rows = page.locator( 'table.undergrowth-links tbody tr' );
			const veto = actionButton( rows.first(), 'veto' );

			await veto.focus();
			await expect( veto ).toBeFocused();
			await page.keyboard.press( 'Enter' );

			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( rows ).toHaveCount( 1 );

			// The Undo comes first, then the result can be dismissed, as
			// core's own notices can.
			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator(
					'#undergrowth-notice a[href*="action=undergrowth_unveto"]'
				)
			).toBeFocused();
			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator( '#undergrowth-notice .notice-dismiss' )
			).toBeFocused();
			await page.keyboard.press( 'Enter' );
			await expect( page.locator( '#undergrowth-notice' ) ).toHaveCount(
				0
			);
		} );

		test( 'undoes a veto by keyboard and suggests the link again', async ( {
			page,
		} ) => {
			const rows = page.locator( 'table.undergrowth-links tbody tr' );

			await actionButton( rows.first(), 'veto' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( rows ).toHaveCount( 1 );

			const notice = page.locator( '#undergrowth-notice' );

			await expect( notice ).toBeFocused();
			await expectNoA11yViolations( page, '#undergrowth-notice' );
			await page.keyboard.press( 'Tab' );
			await page.keyboard.press( 'Enter' );

			await expect( notice ).toBeFocused();
			await expect( notice ).toHaveClass( /notice-success/ );
			await expect( rows ).toHaveCount( 2 );
			await expectNoA11yViolations( page, '#undergrowth-notice' );
		} );

		test( 'accepts one by keyboard and links a word in the post', async ( {
			page,
			requestUtils,
		} ) => {
			const row = page
				.locator( 'table.undergrowth-links tbody tr' )
				.filter( {
					has: page.locator( '.column-source a', { hasText: 'Oca' } ),
				} );
			const accept = actionButton( row, 'accept', /Oca[\s\S]*Yacon/ );
			const marked = await row.locator( 'q mark' ).textContent();

			await accept.focus();
			await expect( accept ).toBeFocused();
			await expect( row.locator( '.row-actions' ) ).toBeInViewport();
			await page.keyboard.press( 'Enter' );

			const notice = page.locator( '#undergrowth-notice' );
			await expect( notice ).toBeFocused();
			await expect(
				notice.locator( 'a[href*="revision.php"]' )
			).toHaveCount( 1 );
			await expectNoA11yViolations( page, '.wrap' );

			const post = await requestUtils.rest( {
				path: `/wp/v2/posts/${ oca.id }`,
				params: { context: 'edit' },
			} );
			expect( post.content.raw ).toContain( '<a href=' );
			expect( post.content.raw ).toContain( '>yacon</a>' );
			// The word marked before accepting is the word that was linked.
			expect( post.content.raw ).toContain( `>${ marked }</a>` );
		} );

		test( 'chooses the words to link by keyboard, then links exactly those', async ( {
			page,
			requestUtils,
		} ) => {
			const row = page
				.locator( 'table.undergrowth-links tbody tr' )
				.filter( {
					has: page.locator( '.column-source a', { hasText: 'Oca' } ),
				} );
			const choose = row.locator( '.row-actions .choose a' );

			await expect( choose ).toHaveAttribute(
				'aria-label',
				/Oca[\s\S]*Yacon/
			);
			await choose.focus();
			await page.keyboard.press( 'Enter' );

			// The form opens under the sentence with the first word focused.
			const form = page.locator( 'form.undergrowth-choose-words' );
			const first = form.locator( 'select[name="first"]' );
			const last = form.locator( 'select[name="last"]' );

			await expect( first ).toBeFocused();
			await expect( form.locator( 'legend' ) ).toBeVisible();
			await expect(
				form.locator( 'input:not([type="hidden"]), textarea' )
			).toHaveCount( 0 );
			await expectNoA11yViolations( page, '.wrap' );

			// "I keep yacon nearby.": words 3 and 4.
			await first.selectOption( '2' );
			await page.keyboard.press( 'Tab' );
			await expect( last ).toBeFocused();
			await last.selectOption( '3' );
			await page.keyboard.press( 'Tab' );
			await expect(
				form.locator( 'button[type="submit"]' )
			).toBeFocused();
			await page.keyboard.press( 'Enter' );

			const notice = page.locator( '#undergrowth-notice' );
			await expect( notice ).toBeFocused();
			await expect( notice ).toHaveClass( /notice-success/ );
			await expect( row.locator( 'q mark' ) ).toHaveText(
				'yacon nearby'
			);
			await expect( form ).toHaveCount( 0 );
			await expectNoA11yViolations( page, '.wrap' );

			await actionButton( row, 'accept', /Oca[\s\S]*Yacon/ ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( notice ).toBeFocused();

			const post = await requestUtils.rest( {
				path: `/wp/v2/posts/${ oca.id }`,
				params: { context: 'edit' },
			} );
			expect( post.content.raw ).toContain( '>yacon nearby</a>' );
		} );

		test( 'at phone width, the Choose words form fits and keeps no violations', async ( {
			page,
		} ) => {
			await page.setViewportSize( { width: 390, height: 800 } );

			const row = page
				.locator( 'table.undergrowth-links tbody tr' )
				.first();

			await row.locator( '.row-actions .choose a' ).click();

			const form = page.locator( 'form.undergrowth-choose-words' );

			await expect(
				form.locator( 'select[name="first"]' )
			).toBeInViewport();
			expect(
				await page.evaluate(
					() =>
						document.documentElement.scrollWidth <=
						window.innerWidth
				)
			).toBe( true );
			await expectNoA11yViolations( page, '.wrap' );

			// Cancel closes the form without changing anything.
			await form.locator( 'a.button' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( form ).toHaveCount( 0 );
		} );

		test( 'at phone width, each row leads with its sentence and target', async ( {
			page,
		} ) => {
			await page.setViewportSize( { width: 390, height: 800 } );

			const row = page
				.locator( 'table.undergrowth-links tbody tr' )
				.first();

			await expect( row.locator( 'q mark' ) ).toBeVisible();
			await expect(
				row.locator( '.column-sentence a[href*="post.php"]' )
			).toBeVisible();
			await expect( row.locator( '.row-actions' ) ).toBeInViewport();

			// Accept and Veto follow the target's title in tab order.
			await row.locator( '.column-sentence a' ).first().focus();
			await page.keyboard.press( 'Tab' );
			await expect( actionButton( row, 'accept' ) ).toBeFocused();
			await expect( row.locator( '.row-actions' ) ).toBeInViewport();
			await page.keyboard.press( 'Tab' );
			await expect( actionButton( row, 'veto' ) ).toBeFocused();
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'accepts the checked suggestions together by keyboard', async ( {
			page,
		} ) => {
			const rows = page.locator( 'table.undergrowth-links tbody tr' );
			const boxes = rows.locator( 'input[name="link[]"]' );

			// Nothing is checked to begin with.
			await expect( boxes ).toHaveCount( 2 );
			await expect( boxes.first() ).not.toBeChecked();

			for ( const box of await boxes.all() ) {
				await box.focus();
				await page.keyboard.press( 'Space' );
				await expect( box ).toBeChecked();
			}

			const menu = page.locator( '#bulk-action-selector-top' );

			// Typing an option's first letters picks it in a closed menu, on
			// every platform. Its words are read from the page, since this
			// spec also runs in Hebrew and in the en_XA pseudo-locale.
			const accept = (
				await menu.locator( 'option[value="accept"]' ).textContent()
			).trim();

			await menu.focus();
			await page.keyboard.type( accept.slice( 0, 4 ) );
			await expect( menu ).toHaveValue( 'accept' );
			await page.keyboard.press( 'Tab' );
			await expect( page.locator( '#doaction' ) ).toBeFocused();
			await page.keyboard.press( 'Enter' );

			const notice = page.locator( '#undergrowth-notice' );

			await expect( notice ).toBeFocused();
			await expect( notice.locator( 'li' ) ).toHaveCount( 2 );
			await expect( rows ).toHaveCount( 0 );
			await expectNoA11yViolations( page, '#undergrowth-notice' );
		} );

		test( 'opens the posts that could link to a post from its row, by keyboard', async ( {
			admin,
			page,
		} ) => {
			// The suggestion from Oca, which mentions yacon, to Yacon.
			const box = page.locator(
				`input[name="link[]"][value^="${ oca.id }-"]`
			);
			const target = ( await box.getAttribute( 'value' ) ).split(
				'-'
			)[ 1 ];

			await admin.visitAdminPage( 'edit.php', '' );

			const linksIn = page.locator(
				`#post-${ target } .undergrowth_links_in a`
			);

			await linksIn.focus();
			await expect( linksIn ).toBeFocused();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( new RegExp( `undergrowth_to=${ target }` ) );

			const rows = page.locator( 'table.undergrowth-links tbody tr' );

			await expect( rows ).toHaveCount( 1 );
			await expect( page.locator( '.wrap h2 bdi' ) ).toHaveCount( 1 );
			await expectNoA11yViolations( page, '.wrap' );

			// Accepting from the view comes back to it.
			await actionButton(
				rows.first(),
				'accept',
				/Oca[\s\S]*Yacon/
			).focus();
			await page.keyboard.press( 'Enter' );
			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( page ).toHaveURL(
				new RegExp( `undergrowth_to=${ target }` )
			);
			await expect( rows ).toHaveCount( 0 );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'is switched off and on from Settings, by keyboard', async ( {
			admin,
			page,
		} ) => {
			// The screen itself has no settings form.
			await expect(
				page.locator( '.wrap input[name="undergrowth_suggest_links"]' )
			).toHaveCount( 0 );

			await admin.visitAdminPage(
				'options-general.php',
				'page=undergrowth'
			);

			const toggle = page.locator( '#undergrowth_suggest_links' );

			await toggle.focus();
			await page.keyboard.press( 'Space' );
			await expect( toggle ).not.toBeChecked();
			await page.locator( '.wrap #submit' ).focus();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /settings-updated=true/ );
			await expect( toggle ).not.toBeChecked();

			await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );
			await expect(
				page.locator( 'table.undergrowth-links' )
			).toHaveCount( 0 );
			await expectNoA11yViolations( page, '.wrap' );

			// The screen says suggestions are off and links back to Settings.
			const back = page.locator(
				'.wrap a[href$="options-general.php?page=undergrowth"]'
			);

			// Tab reaches it straight after the screen's tabs.
			await page.locator( '.nav-tab' ).last().focus();
			await page.keyboard.press( 'Tab' );
			await expect( back ).toBeFocused();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /options-general\.php\?page=undergrowth/ );
			await toggle.focus();
			await page.keyboard.press( 'Space' );
			await page.locator( '.wrap #submit' ).focus();
			await page.keyboard.press( 'Enter' );
			await page.waitForURL( /settings-updated=true/ );
			await expect( toggle ).toBeChecked();
		} );
	} );

	test.describe( 'in scripts beyond Latin and CJK', () => {
		/**
		 * Publishes Oca with the given content, and Yacon, then opens Suggestions.
		 *
		 * @param {Object} fixtures              Test fixtures.
		 * @param {Object} fixtures.requestUtils Request utils.
		 * @param {Object} fixtures.admin        Admin utils.
		 * @param {Object} fixtures.page         Page.
		 * @param {string} content               Oca's content.
		 * @return {Promise<import('@playwright/test').Locator>} Oca's row.
		 */
		async function ocaRow( { requestUtils, admin, page }, content ) {
			await requestUtils.createPost( {
				title: 'Oca',
				content,
				status: 'publish',
			} );
			await requestUtils.createPost( {
				title: 'Yacon',
				content: '<p>Yacon syrup is sweet.</p>',
				status: 'publish',
			} );
			await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

			return page.locator( 'table.undergrowth-links tbody tr' ).filter( {
				has: page.locator( '.column-source a', { hasText: 'Oca' } ),
			} );
		}

		test( 'quotes one Hindi sentence, not the paragraph', async ( {
			requestUtils,
			admin,
			page,
		} ) => {
			const row = await ocaRow(
				{ requestUtils, admin, page },
				'<p>मैं oca उगाती हूँ। मेरे पास yacon भी है। वह मीठा है।</p>'
			);

			await expect( row.locator( 'q' ) ).toHaveText(
				'मेरे पास yacon भी है।'
			);
			await expect( row.locator( '.row-actions .choose a' ) ).toHaveCount(
				1
			);
		} );

		test( 'offers no words to choose in Thai', async ( {
			requestUtils,
			admin,
			page,
		} ) => {
			const row = await ocaRow(
				{ requestUtils, admin, page },
				'<p>ฉันปลูก oca ที่บ้าน และมี yacon อยู่ใกล้ๆ</p>'
			);

			await expect( row.locator( 'q' ) ).toBeVisible();
			await expect( row.locator( '.row-actions .choose a' ) ).toHaveCount(
				0
			);
			await expectNoA11yViolations( page, '.wrap' );
		} );
	} );
} );
