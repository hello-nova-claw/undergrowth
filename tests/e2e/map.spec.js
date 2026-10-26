const fs = require( 'fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

/**
 * Publishes a post whose body repeats fixture words, so the e2e provider
 * gives it a known vector. Titles hold no fixture word.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} title        Post title.
 * @param {Object} words        Number of times to repeat each fixture word.
 * @return {Promise<Object>} The post.
 */
async function publish( requestUtils, title, words ) {
	const content = Object.entries( words )
		.map( ( [ word, count ] ) => Array( count ).fill( word ).join( ' ' ) )
		.join( ' ' );

	return requestUtils.createPost( {
		title,
		content: `<p>${ content }</p>`,
		status: 'publish',
	} );
}

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Map tab', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'is not offered before any cluster is made', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		await expect( page.locator( '.nav-tab' ) ).toHaveCount( 2 );
		await expect( page.locator( '.nav-tab[href*="tab=map"]' ) ).toHaveCount(
			0
		);
	} );

	test.describe( 'with two clusters, one a gap', () => {
		let roots;
		let bread;

		test.beforeEach(
			async ( { requestUtils, admin, context, baseURL } ) => {
				// Three identical posts: the middle of their cluster is a post.
				for ( const title of [ 'First', 'Second', 'Third' ] ) {
					await publish( requestUtils, title, { yacon: 6 } );
				}

				// Three posts pulling apart: none sits at their middle.
				roots = await publish( requestUtils, 'Roots', { oca: 6 } );
				await publish( requestUtils, 'Sweet roots', {
					oca: 6,
					syrup: 3,
				} );
				await publish( requestUtils, 'Roots on paper', {
					oca: 6,
					ledger: 3,
				} );

				// No fixture word, so it is near none of the others.
				bread = await publish( requestUtils, 'Bread', {} );

				const pass = await requestUtils.request.get(
					'wp-admin/index.php?undergrowth_e2e_cluster=1'
				);
				expect( pass.ok() ).toBe( true );

				await context.addCookies( [
					{
						name: 'undergrowth_e2e_fixture',
						value: 'tight-gaps',
						url: baseURL,
					},
				] );
				await admin.visitAdminPage(
					'edit.php',
					'page=undergrowth-links&tab=map'
				);
			}
		);

		test( 'lists each cluster and marks the gap, with no WCAG 2.2 AA violations', async ( {
			page,
		} ) => {
			await expect( page.locator( '.nav-tab-active' ) ).toHaveAttribute(
				'href',
				/tab=map/
			);
			// Two groups, the pairs between them, then the posts not in one.
			await expect( page.locator( '.wrap h2' ) ).toHaveCount( 4 );
			await expect(
				page.locator( 'ul.undergrowth-cluster' )
			).toHaveCount( 2 );
			await expect(
				page.locator( 'ul.undergrowth-cluster' ).first().locator( 'li' )
			).toHaveCount( 3 );
			await expect(
				page.locator( 'ul.undergrowth-cluster' ).last().locator( 'li' )
			).toHaveCount( 3 );

			// The gap is said right under its own cluster's heading, and only there.
			await expect( page.locator( '.undergrowth-gap' ) ).toHaveCount( 1 );
			await expect(
				page.locator(
					`#undergrowth-cluster-${ roots.id } + p.undergrowth-gap`
				)
			).toHaveCount( 1 );
			await expect(
				page.locator(
					`ul[aria-labelledby="undergrowth-cluster-${ roots.id }"] li a`
				)
			).toHaveCount( 3 );
			await expect(
				page
					.locator(
						`ul[aria-labelledby="undergrowth-cluster-${ roots.id }"] li a`
					)
					.first()
			).toHaveAttribute( 'href', new RegExp( `post=${ roots.id }&` ) );

			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'names each group after a post and counts every post', async ( {
			page,
		} ) => {
			// The heading holds the title of the post nearest the middle.
			await expect(
				page.locator( `#undergrowth-cluster-${ roots.id }` )
			).toContainText( 'Roots' );
			await expect(
				page.locator( '.undergrowth-group-about' )
			).toHaveCount( 2 );
			// The group's own word, as the posts spell it.
			await expect(
				page.locator(
					`#undergrowth-cluster-${ roots.id } ~ .undergrowth-group-about bdi`
				)
			).toHaveText( [ 'oca' ] );
			await expect(
				page.locator( '.undergrowth-map-counts span' )
			).toHaveCount( 2 );

			const outside = page.locator(
				'ul.undergrowth-ungrouped[aria-labelledby="undergrowth-ungrouped"] li a'
			);
			await expect( outside ).toHaveCount( 1 );
			await expect( outside ).toHaveAttribute(
				'href',
				new RegExp( `post=${ bread.id }&` )
			);
			await expect( page.locator( 'details' ) ).toHaveCount( 0 );
		} );

		test( 'fits a narrow screen', async ( { page } ) => {
			await page.setViewportSize( { width: 390, height: 800 } );

			const overflow = await page
				.locator( '.wrap' )
				.evaluate( ( wrap ) => wrap.scrollWidth - wrap.clientWidth );

			expect( overflow ).toBeLessThanOrEqual( 0 );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'exports every post on it as CSV and as Markdown, by keyboard', async ( {
			page,
		} ) => {
			const format = page.locator( '#undergrowth-export-format' );
			const button = page.locator(
				'form.undergrowth-map-export [type="submit"]'
			);

			await expect(
				page.locator( 'label[for="undergrowth-export-format"]' )
			).toHaveCount( 1 );

			// A heading and the separator line for Markdown, a heading for CSV.
			for ( const [ value, extension, lines ] of [
				[ 'csv', 'csv', 8 ],
				[ 'markdown', 'md', 9 ],
			] ) {
				// From the last post on the Map, the next stops are the
				// format, then the button. Which keys change a closed select
				// differs by platform, so the choice itself is set directly.
				await page.locator( 'ul.undergrowth-ungrouped li a' ).focus();
				await page.keyboard.press( 'Tab' );
				await expect( format ).toBeFocused();
				await format.selectOption( value );
				await expect( format ).toBeFocused();
				await page.keyboard.press( 'Tab' );
				await expect( button ).toBeFocused();

				const [ download ] = await Promise.all( [
					page.waitForEvent( 'download' ),
					page.keyboard.press( 'Enter' ),
				] );
				const text = fs.readFileSync( await download.path(), 'utf8' );

				expect( download.suggestedFilename() ).toMatch(
					new RegExp(
						`^undergrowth-map-\\d{4}-\\d{2}-\\d{2}\\.${ extension }$`
					)
				);
				expect( text.trim().split( '\n' ) ).toHaveLength( lines );

				for ( const title of [
					'First',
					'Second',
					'Third',
					'Roots',
					'Sweet roots',
					'Roots on paper',
					'Bread',
				] ) {
					expect( text ).toContain( title );
				}
			}
		} );

		test( 'is read by keyboard from the tab to each post', async ( {
			page,
		} ) => {
			const tab = page.locator( '.nav-tab-active' );

			await tab.focus();
			await expect( tab ).toBeFocused();

			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator( 'ul.undergrowth-cluster li a' ).first()
			).toBeFocused();

			// Every post in both clusters is reached in order, nothing else between.
			for ( let i = 1; i < 6; i++ ) {
				await page.keyboard.press( 'Tab' );
				await expect(
					page.locator( 'ul.undergrowth-cluster li a' ).nth( i )
				).toBeFocused();
			}

			// Then the way to the surprise scale, as no pair is found here.
			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator( '#undergrowth-pollination + p a' )
			).toBeFocused();

			// Then the post outside every group.
			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator( 'ul.undergrowth-ungrouped li a' )
			).toBeFocused();

			await page.keyboard.press( 'Enter' );
			await expect( page ).toHaveURL( /post\.php\?post=\d+&action=edit/ );
		} );
	} );

	test.describe( 'with many posts outside a group', () => {
		test.beforeEach( async ( { requestUtils, admin } ) => {
			for ( const title of [ 'First', 'Second', 'Third' ] ) {
				await publish( requestUtils, title, { yacon: 6 } );
			}

			const pass = await requestUtils.request.get(
				'wp-admin/index.php?undergrowth_e2e_cluster=1'
			);
			expect( pass.ok() ).toBe( true );

			// Published after the pass: identical notes would otherwise make
			// a group of their own.
			for ( let i = 1; i <= 11; i++ ) {
				await publish( requestUtils, `Note ${ i }`, {} );
			}

			await admin.visitAdminPage(
				'edit.php',
				'page=undergrowth-links&tab=map'
			);
		} );

		test( 'starts the list collapsed and opens it by keyboard, with no WCAG 2.2 AA violations', async ( {
			page,
		} ) => {
			const details = page.locator(
				'details.undergrowth-ungrouped-list'
			);
			const summary = details.locator( 'summary' );
			const posts = details.locator( 'ul.undergrowth-ungrouped li a' );

			await expect( details ).not.toHaveAttribute( 'open', '' );
			await expect( posts.first() ).toBeHidden();
			await expectNoA11yViolations( page, '.wrap' );

			await summary.focus();
			await page.keyboard.press( 'Enter' );
			await expect( details ).toHaveAttribute( 'open', '' );
			await expect( posts ).toHaveCount( 11 );

			await page.keyboard.press( 'Tab' );
			await expect( posts.first() ).toBeFocused();
			await expectNoA11yViolations( page, '.wrap' );
		} );
	} );

	test.describe( 'with a group dense enough for a hub', () => {
		let posts;

		test.beforeEach(
			async ( { requestUtils, admin, context, baseURL } ) => {
				await requestUtils.deleteAllPages();

				posts = [];

				for ( const title of [ 'First', 'Second', 'Third' ] ) {
					posts.push(
						await publish( requestUtils, title, { yacon: 6 } )
					);
				}

				const pass = await requestUtils.request.get(
					'wp-admin/index.php?undergrowth_e2e_cluster=1'
				);
				expect( pass.ok() ).toBe( true );

				// The fixture makes any group dense, as accepted links would.
				await context.addCookies( [
					{
						name: 'undergrowth_e2e_fixture',
						value: 'hubs',
						url: baseURL,
					},
				] );
				await admin.visitAdminPage(
					'edit.php',
					'page=undergrowth-links&tab=map'
				);
			}
		);

		test.afterEach( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPages();
		} );

		test( 'starts a draft holding only the titles as links, by keyboard, with no WCAG 2.2 AA violations', async ( {
			page,
			requestUtils,
		} ) => {
			const proposal = page.locator( '.undergrowth-hub-proposal' );
			const start = proposal.locator(
				'form:has([value="undergrowth_start_hub"]) button'
			);

			await expect( proposal ).toHaveCount( 1 );
			await expectNoA11yViolations( page, '.wrap' );

			await start.focus();
			await page.keyboard.press( 'Enter' );

			const notice = page.locator( '#undergrowth-notice' );
			await expect( notice ).toHaveClass( /notice-success/ );
			await expect( notice ).toBeFocused();
			await expect(
				page.locator( '.undergrowth-hub-proposal' )
			).toHaveCount( 0 );
			await expect( page.locator( 'p.undergrowth-hub' ) ).toHaveCount(
				1
			);
			await expectNoA11yViolations( page, '.wrap' );

			const pages = await requestUtils.rest( {
				path: '/wp/v2/pages',
				params: { status: 'draft', context: 'edit' },
			} );

			expect( pages ).toHaveLength( 1 );
			expect( pages[ 0 ].status ).toBe( 'draft' );
			expect( pages[ 0 ].title.raw ).toBe( '' );

			const content = pages[ 0 ].content.raw;
			const items = [
				...content.matchAll(
					/<li><a href="([^"]+)">([^<]*)<\/a><\/li>/g
				),
			];

			expect( items.map( ( item ) => item[ 2 ] ).sort() ).toEqual( [
				'First',
				'Second',
				'Third',
			] );
			expect( items.map( ( item ) => item[ 1 ] ).sort() ).toEqual(
				posts.map( ( post ) => post.link ).sort()
			);
			// Nothing but the list: no heading, no paragraph, no other text.
			expect( content ).toBe(
				'<!-- wp:list -->\n<ul class="wp-block-list">' +
					items
						.map(
							( item ) =>
								`<!-- wp:list-item -->\n${ item[ 0 ] }\n<!-- /wp:list-item -->\n\n`
						)
						.join( '' ) +
					'</ul>\n<!-- /wp:list -->'
			);

			// The notice leads to the draft in the editor.
			await expect( notice.locator( 'a' ) ).toHaveAttribute(
				'href',
				new RegExp( `post=${ pages[ 0 ].id }&` )
			);
		} );

		test( 'never proposes a vetoed group again', async ( {
			page,
			admin,
			requestUtils,
		} ) => {
			await page
				.locator(
					'.undergrowth-hub-proposal form:has([value="undergrowth_veto_hub"]) button'
				)
				.click();

			await expect( page.locator( '#undergrowth-notice' ) ).toHaveClass(
				/notice-success/
			);
			await expect(
				page.locator( '.undergrowth-hub-proposal' )
			).toHaveCount( 0 );

			// Next month's pass finds the same group again.
			const pass = await requestUtils.request.get(
				'wp-admin/index.php?undergrowth_e2e_cluster=1'
			);
			expect( pass.ok() ).toBe( true );

			await admin.visitAdminPage(
				'edit.php',
				'page=undergrowth-links&tab=map'
			);
			await expect(
				page.locator( 'ul.undergrowth-cluster' )
			).toHaveCount( 1 );
			await expect(
				page.locator( '.undergrowth-hub-proposal' )
			).toHaveCount( 0 );
			expect(
				await requestUtils.rest( {
					path: '/wp/v2/pages',
					params: { status: 'draft' },
				} )
			).toHaveLength( 0 );
		} );
	} );
} );
