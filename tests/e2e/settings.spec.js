const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

/**
 * Sets the fixture cookie for the test site.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {string}                                    baseURL Site address.
 * @param {string}                                    name    Fixture name.
 */
async function useFixture( context, baseURL, name ) {
	await context.addCookies( [
		{ name: 'undergrowth_e2e_fixture', value: name, url: baseURL },
	] );
}

// Selectors use ids, not text, because this spec also runs in Hebrew and in
// the en_XA pseudo-locale.
test.describe( 'Settings page', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
	} );

	// A key left behind by a failed test would put the remove checkbox in the
	// tab order and break the keyboard test on the next run. A Discord field
	// left behind, or filled in by a fixture, would show on every later visit.
	test.afterEach( async ( { admin, page } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const remove = page.locator(
			'input[name="undergrowth_remove_openai_key"], input[name="undergrowth_remove_anthropic_key"], input[name="undergrowth_remove_discord_token"]'
		);

		for ( const checkbox of await remove.all() ) {
			await checkbox.check();
		}

		for ( const id of [
			'undergrowth_discord_application_id',
			'undergrowth_discord_public_key',
			'undergrowth_discord_channel_id',
		] ) {
			await page.locator( `#${ id }` ).fill( '' );
		}

		for ( const box of await page
			.locator( 'input[name="undergrowth_post_types[]"]' )
			.all() ) {
			if ( await box.isEnabled() ) {
				await box.setChecked( 'post' === ( await box.inputValue() ) );
			}
		}

		await page.locator( '.wrap #submit' ).click();
	} );

	test( 'has no WCAG 2.2 AA violations', async ( { page } ) => {
		await expectNoA11yViolations( page, '.wrap' );
	} );

	// The test site's own provider is code, so the OpenAI key field shows
	// only with a fixture that stands it aside.
	test( 'describes what is sent alongside the key field', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'no-provider' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const field = page.locator( '#undergrowth_openai_api_key' );

		await expect( field ).toHaveAttribute(
			'aria-describedby',
			'undergrowth-openai-notice'
		);
		await expect(
			page.locator( '#undergrowth-openai-notice' )
		).toBeVisible();
	} );

	test( 'describes what is sent to Anthropic alongside its key field', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_anthropic_api_key' );

		await expect( field ).toHaveAttribute(
			'aria-describedby',
			'undergrowth-anthropic-notice'
		);
		await expect(
			page.locator( '#undergrowth-anthropic-notice' )
		).toBeVisible();
	} );

	// en_XA pads strings with long unbroken runs, as some real languages do
	// with compound words. The last-error notice adds the longest of them.
	test( 'fits a phone screen without scrolling sideways', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'last-error',
				url: baseURL,
			},
		] );
		await page.setViewportSize( { width: 390, height: 800 } );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const width = await page
			.locator( '.wrap' )
			.evaluate( ( wrap ) => wrap.scrollWidth );

		expect( width ).toBeLessThanOrEqual( 390 );
	} );

	test( 'reaches every key field and the save button by keyboard', async ( {
		page,
	} ) => {
		const first = page.locator( '#undergrowth_suggest_links' );

		await first.focus();
		await expect( first ).toBeFocused();

		// The switch is followed by a checkbox for each post type.
		for ( const box of await page
			.locator( 'input[name="undergrowth_post_types[]"]' )
			.all() ) {
			await page.keyboard.press( 'Tab' );
			await expect( box ).toBeFocused();
		}

		// Then the surprise scale, a radio group with one stop: the chosen step.
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#undergrowth_surprise_2' ) ).toBeFocused();

		// Then the link to the monthly email on the Profile.
		await page.keyboard.press( 'Tab' );
		await expect(
			page.locator( '#undergrowth-digest-profile' )
		).toBeFocused();

		// The test site's provider is code, so there is no OpenAI key field.
		// Each key field is followed by the link to the provider's key page.
		for ( const id of [
			'undergrowth_anthropic_api_key',
			'undergrowth-anthropic-get-key',
		] ) {
			await page.keyboard.press( 'Tab' );
			await expect( page.locator( `#${ id }` ) ).toBeFocused();
		}

		for ( const id of [
			'undergrowth_discord_application_id',
			'undergrowth_discord_public_key',
			'undergrowth_discord_bot_token',
			'undergrowth_discord_channel_id',
		] ) {
			await page.keyboard.press( 'Tab' );
			await expect( page.locator( `#${ id }` ) ).toBeFocused();
		}

		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '.wrap #submit' ) ).toBeFocused();
	} );

	test( 'by keyboard, the OpenAI key field leads to its link, its remove checkbox and the switch', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'key-saved' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const field = page.locator( '#undergrowth_openai_api_key' );

		await field.focus();
		await expect( field ).toBeFocused();

		for ( const selector of [
			'#undergrowth-openai-get-key',
			'input[name="undergrowth_remove_openai_key"]',
			'#undergrowth_suggest_links',
		] ) {
			await page.keyboard.press( 'Tab' );
			await expect( page.locator( selector ) ).toBeFocused();
		}

		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'names the provider set up by code, with no OpenAI key field', async ( {
		page,
	} ) => {
		const provider = page.locator( '#undergrowth-links-provider' );

		// The names come from the provider's code and are never translated.
		await expect( provider ).toContainText( 'word-counts' );
		await expect( provider ).toContainText( 'e2e' );
		await expect(
			page.locator( '#undergrowth-openai-unused' )
		).toBeVisible();
		await expect(
			page.locator( '#undergrowth_openai_api_key' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '#undergrowth-openai-get-key' )
		).toHaveCount( 0 );
	} );

	test( 'says a saved OpenAI key is ignored while code reads the posts', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'code-provider-key-saved' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		await expect(
			page.locator( '#undergrowth-openai-unused' )
		).toContainText( 'wxyz' );
		await expect(
			page.locator( '#undergrowth_openai_api_key' )
		).toHaveCount( 0 );

		const remove = page.locator(
			'input[name="undergrowth_remove_openai_key"]'
		);

		await remove.focus();
		await expect( remove ).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect(
			page.locator( '#undergrowth_suggest_links' )
		).toBeFocused();

		await expectNoA11yViolations( page, '.wrap' );

		await page.setViewportSize( { width: 390, height: 800 } );

		const width = await page
			.locator( '.wrap' )
			.evaluate( ( wrap ) => wrap.scrollWidth );

		expect( width ).toBeLessThanOrEqual( 390 );
	} );

	test( 'shows the key field empty after saving a key', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'no-provider' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const field = page.locator( '#undergrowth_openai_api_key' );

		await field.fill( 'sk-e2e-0000000000wxyz' );
		await page.locator( '.wrap #submit' ).click();

		await expect( field ).toHaveValue( '' );
		await expect( page.locator( '.wrap' ) ).toContainText( 'wxyz' );
		await expect( page.locator( '.wrap' ) ).not.toContainText(
			'sk-e2e-0000000000wxyz'
		);

		await page
			.locator( 'input[name="undergrowth_remove_openai_key"]' )
			.check();
		await page.locator( '.wrap #submit' ).click();
		await expect(
			page.locator( 'input[name="undergrowth_remove_openai_key"]' )
		).toHaveCount( 0 );
	} );

	test( 'shows the Anthropic key field empty after saving a key', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_anthropic_api_key' );
		const remove = page.locator(
			'input[name="undergrowth_remove_anthropic_key"]'
		);

		await field.fill( 'sk-ant-e2e-00000000qrst' );
		await page.locator( '.wrap #submit' ).click();

		await expect( field ).toHaveValue( '' );
		await expect( page.locator( '.wrap' ) ).toContainText( 'qrst' );
		await expect( page.locator( '.wrap' ) ).not.toContainText(
			'sk-ant-e2e-00000000qrst'
		);
		await expect(
			page.locator( 'input[name="undergrowth_remove_openai_key"]' )
		).toHaveCount( 0 );

		await remove.check();
		await page.locator( '.wrap #submit' ).click();
		await expect( remove ).toHaveCount( 0 );
	} );

	test( 'saves the post types ticked by keyboard', async ( { page } ) => {
		const pages = page.locator( '#undergrowth_post_type_page' );

		await expect(
			page.locator( '#undergrowth_post_type_post' )
		).toBeChecked();
		await expect( pages ).not.toBeChecked();

		await pages.focus();
		await page.keyboard.press( 'Space' );
		await page.locator( '.wrap #submit' ).click();

		await expect( pages ).toBeChecked();
		await expectNoA11yViolations( page, '.wrap' );

		await pages.uncheck();
		await page.locator( '.wrap #submit' ).click();
		await expect( pages ).not.toBeChecked();
	} );

	test( 'shows the post types chosen by code, which cannot be changed', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await useFixture( context, baseURL, 'post-types-by-code' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		await expect(
			page.locator( '#undergrowth_post_type_page' )
		).toBeChecked();
		await expect(
			page.locator( '#undergrowth_post_type_page' )
		).toBeDisabled();
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'says when an Accept could not save its post', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await expect( page.locator( '#undergrowth-accept-error' ) ).toHaveCount(
			0
		);

		await useFixture( context, baseURL, 'accept-last-error' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		await expect(
			page.locator( '#undergrowth-accept-error' )
		).toBeVisible();
	} );

	test( 'keeps the post types when none is ticked, and says why', async ( {
		page,
	} ) => {
		await page.locator( '#undergrowth_post_type_post' ).uncheck();
		await page.locator( '.wrap #submit' ).click();

		await expect(
			page.locator( '#setting-error-undergrowth_post_types' )
		).toBeVisible();
		await expect(
			page.locator( '#undergrowth_post_type_post' )
		).toBeChecked();
	} );

	test( 'says why there is no question yet once an Anthropic key is saved', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		const sentence = page.locator( '#undergrowth-anthropic-no-question' );

		await expect( sentence ).toHaveCount( 0 );

		await useFixture( context, baseURL, 'prompt-key-saved' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
		await expect( sentence ).toBeVisible();

		await useFixture( context, baseURL, 'prompt-asked' );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
		await expect( sentence ).toHaveCount( 0 );
		await expect( page.locator( '.wrap' ) ).toContainText( 'mnop' );
	} );

	test( 'has no WCAG 2.2 AA violations with a failing Anthropic key', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'prompt-last-error',
				url: baseURL,
			},
		] );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		await expectNoA11yViolations( page, '.wrap' );
	} );
	test( 'describes each Discord field by where to find its value', async ( {
		page,
	} ) => {
		for ( const name of [
			'application_id',
			'public_key',
			'bot_token',
			'channel_id',
		] ) {
			const description = `undergrowth-discord-${ name.replaceAll(
				'_',
				'-'
			) }-description`;

			await expect(
				page.locator( `#undergrowth_discord_${ name }` )
			).toHaveAttribute( 'aria-describedby', description );
			await expect( page.locator( `#${ description }` ) ).toBeVisible();
		}
		await expect(
			page.locator( '#undergrowth-discord-notice' )
		).toBeVisible();
		await expect(
			page.locator( '#undergrowth-discord-endpoint' )
		).toContainText( 'undergrowth/v1/discord' );
	} );

	test( 'shows the folder that holds the per-post files', async ( {
		page,
	} ) => {
		await expect( page.locator( '#undergrowth-memory-folder' ) ).toHaveText(
			/undergrowth-[0-9a-f]{12}\/posts\/$/
		);
	} );

	test( 'shows the Discord token field empty after saving a token', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_discord_bot_token' );
		const remove = page.locator(
			'input[name="undergrowth_remove_discord_token"]'
		);

		await field.fill( 'not-a-real-discord-token-ghij' );
		await page.locator( '.wrap #submit' ).click();

		await expect( field ).toHaveValue( '' );
		await expect( page.locator( '.wrap' ) ).toContainText( 'ghij' );
		await expect( page.locator( '.wrap' ) ).not.toContainText(
			'not-a-real-discord-token-ghij'
		);

		await remove.check();
		await page.locator( '.wrap #submit' ).click();
		await expect( remove ).toHaveCount( 0 );
	} );

	test( 'keeps the saved application ID when the new one is not an ID', async ( {
		page,
	} ) => {
		const field = page.locator( '#undergrowth_discord_application_id' );

		await field.fill( '112233445566778899' );
		await page.locator( '.wrap #submit' ).click();
		await field.fill( 'abc' );
		await page.locator( '.wrap #submit' ).click();

		await expect( field ).toHaveValue( '112233445566778899' );
		await expect(
			page.locator( '#setting-error-undergrowth_discord_application_id' )
		).toBeVisible();

		await field.fill( '' );
		await page.locator( '.wrap #submit' ).click();
		await expect( field ).toHaveValue( '' );
	} );

	for ( const fixture of [
		'discord-saved',
		'discord-invalid',
		'discord-last-error',
		'file-last-error',
		'accept-last-error',
	] ) {
		test( `has no WCAG 2.2 AA violations with ${ fixture }`, async ( {
			admin,
			page,
			context,
			baseURL,
		} ) => {
			await context.addCookies( [
				{
					name: 'undergrowth_e2e_fixture',
					value: fixture,
					url: baseURL,
				},
			] );
			await admin.visitAdminPage(
				'options-general.php',
				'page=undergrowth'
			);

			await expectNoA11yViolations( page, '.wrap' );
		} );
	}

	test.describe( 'with posts', () => {
		let created = [];

		// The count covers every published post, so the site starts empty.
		test.beforeEach( async ( { admin, requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			created = [];

			for ( const title of [ 'Oca', 'Yacon' ] ) {
				created.push(
					await requestUtils.createPost( {
						title,
						content: `<p>${ title } grows here.</p>`,
						status: 'publish',
					} )
				);
			}

			await admin.visitAdminPage(
				'options-general.php',
				'page=undergrowth'
			);
		} );

		test.afterEach( async ( { requestUtils } ) => {
			for ( const post of created ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/posts/${ post.id }`,
					params: { force: true },
				} );
			}
		} );

		test( 'counts the indexed posts', async ( { page } ) => {
			await expect(
				page.locator( '#undergrowth-index-count' )
			).toContainText( /2\D+2/ );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'counts posts not indexed with the current model', async ( {
			admin,
			page,
			context,
			baseURL,
		} ) => {
			await context.addCookies( [
				{
					name: 'undergrowth_e2e_fixture',
					value: 'index-partial',
					url: baseURL,
				},
			] );
			await page.setViewportSize( { width: 390, height: 800 } );
			await admin.visitAdminPage(
				'options-general.php',
				'page=undergrowth'
			);

			await expect(
				page.locator( '#undergrowth-index-count' )
			).toContainText( /0\D+2/ );
			await expectNoA11yViolations( page, '.wrap' );

			const width = await page
				.locator( '.wrap' )
				.evaluate( ( wrap ) => wrap.scrollWidth );

			expect( width ).toBeLessThanOrEqual( 390 );
		} );

		test( 'says when no post has been read for a day', async ( {
			admin,
			page,
			context,
			baseURL,
		} ) => {
			await context.addCookies( [
				{
					name: 'undergrowth_e2e_fixture',
					value: 'backfill-stalled',
					url: baseURL,
				},
			] );
			await admin.visitAdminPage(
				'options-general.php',
				'page=undergrowth'
			);

			const notice = page.locator( '#undergrowth-backfill-stalled' );

			await expect( notice ).toContainText( 'WP-Cron' );
			await expect( notice ).toContainText( 'DISABLE_WP_CRON' );
			await expectNoA11yViolations( page, '.wrap' );

			await admin.visitAdminPage( 'site-health.php' );

			await expect(
				page.getByText( 'Undergrowth has not read a post for a day' )
			).toBeVisible();
		} );

		test( 'says nothing of a stall once every post is read', async ( {
			admin,
			page,
		} ) => {
			await expect(
				page.locator( '#undergrowth-backfill-stalled' )
			).toHaveCount( 0 );

			await admin.visitAdminPage( 'site-health.php' );

			await expect(
				page.getByText( 'Undergrowth has not read a post for a day' )
			).toHaveCount( 0 );
		} );
	} );

	test( 'fits a phone screen with Discord set up', async ( {
		admin,
		page,
		context,
		baseURL,
	} ) => {
		await context.addCookies( [
			{
				name: 'undergrowth_e2e_fixture',
				value: 'discord-saved',
				url: baseURL,
			},
		] );
		await page.setViewportSize( { width: 390, height: 800 } );
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const width = await page
			.locator( '.wrap' )
			.evaluate( ( wrap ) => wrap.scrollWidth );

		expect( width ).toBeLessThanOrEqual( 390 );
	} );
} );
