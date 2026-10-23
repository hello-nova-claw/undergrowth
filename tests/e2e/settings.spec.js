const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

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

		await page.locator( '.wrap #submit' ).click();
	} );

	test( 'has no WCAG 2.2 AA violations', async ( { page } ) => {
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test( 'describes what is sent alongside the key field', async ( {
		page,
	} ) => {
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
		const field = page.locator( '#undergrowth_openai_api_key' );

		await field.focus();
		await expect( field ).toBeFocused();

		// Each key field is followed by the link to the provider's key page,
		// and the OpenAI key by the switch for suggestions.
		for ( const id of [
			'undergrowth-openai-get-key',
			'undergrowth_suggest_links',
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

	test( 'shows the key field empty after saving a key', async ( {
		page,
	} ) => {
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
