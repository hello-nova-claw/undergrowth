const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, not text, because this spec also runs in Hebrew and in
// the en_XA pseudo-locale.
test.describe( 'Settings page', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );
	} );

	// A key left behind by a failed test would put the remove checkbox in the
	// tab order and break the keyboard test on the next run.
	test.afterEach( async ( { admin, page } ) => {
		await admin.visitAdminPage( 'options-general.php', 'page=undergrowth' );

		const remove = page.locator(
			'input[name="undergrowth_remove_openai_key"], input[name="undergrowth_remove_anthropic_key"], input[name="undergrowth_remove_discord_token"]'
		);

		if ( ( await remove.count() ) > 0 ) {
			for ( const checkbox of await remove.all() ) {
				await checkbox.check();
			}
			await page.locator( '.wrap #submit' ).click();
		}
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

		await page.keyboard.press( 'Tab' );
		await expect(
			page.locator( '#undergrowth_anthropic_api_key' )
		).toBeFocused();

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
	test( 'describes what is sent to Discord alongside its fields', async ( {
		page,
	} ) => {
		for ( const id of [
			'undergrowth_discord_application_id',
			'undergrowth_discord_public_key',
			'undergrowth_discord_bot_token',
			'undergrowth_discord_channel_id',
		] ) {
			await expect( page.locator( `#${ id }` ) ).toHaveAttribute(
				'aria-describedby',
				'undergrowth-discord-notice'
			);
		}
		await expect(
			page.locator( '#undergrowth-discord-notice' )
		).toBeVisible();
		await expect( page.locator( '.wrap code' ) ).toContainText(
			'undergrowth/v1/discord'
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
