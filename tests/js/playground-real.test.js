const { describe, it, expect } = require( '@jest/globals' );

const {
	startMessage,
	autoAccept,
	autoAcceptMessage,
} = require( '../../bin/playground-real-text' );

const site = 'http://127.0.0.1:9400';

describe( 'startMessage', () => {
	it( 'gives the address of Settings > Undergrowth, not the front page', () => {
		const message = startMessage( {
			siteUrl: site,
			local: true,
			model: 'nomic-embed-text',
			anthropic: false,
		} );

		expect( message ).toContain(
			`${ site }/wp-admin/options-general.php?page=undergrowth`
		);
		expect( message ).not.toMatch(
			/running at http:\/\/127\.0\.0\.1:9400\.$/m
		);
	} );

	it( 'says how to log in when Playground does not', () => {
		const message = startMessage( {
			siteUrl: site,
			local: true,
			model: 'nomic-embed-text',
			anthropic: false,
		} );

		expect( message ).toMatch( /username admin and the password password/ );
	} );

	it( 'says which way posts are read and whether questions are on', () => {
		expect(
			startMessage( {
				siteUrl: site,
				local: true,
				model: 'nomic-embed-text',
				anthropic: false,
			} )
		).toMatch( /nomic-embed-text in Ollama[\s\S]*No Anthropic key/ );
		expect(
			startMessage( { siteUrl: site, local: false, anthropic: true } )
		).toMatch(
			/OpenAI, with your key[\s\S]*Claude, with your Anthropic key/
		);
	} );
} );

describe( 'autoAccept', () => {
	it( 'is on only when .env.local sets UNDERGROWTH_AUTO_ACCEPT to 1', () => {
		expect( autoAccept( { UNDERGROWTH_AUTO_ACCEPT: '1' } ) ).toBe( true );
		expect( autoAccept( { UNDERGROWTH_AUTO_ACCEPT: ' 1 ' } ) ).toBe( true );
		expect( autoAccept( {} ) ).toBe( false );
		expect( autoAccept( { UNDERGROWTH_AUTO_ACCEPT: '' } ) ).toBe( false );
		expect( autoAccept( { UNDERGROWTH_AUTO_ACCEPT: '0' } ) ).toBe( false );
		expect( autoAccept( { UNDERGROWTH_AUTO_ACCEPT: 'yes' } ) ).toBe( false );
	} );

	it( 'is announced at start, as test-only, and not otherwise', () => {
		const on = startMessage( {
			siteUrl: site,
			local: true,
			model: 'nomic-embed-text',
			anthropic: false,
			autoAccept: true,
		} );
		const off = startMessage( {
			siteUrl: site,
			local: true,
			model: 'nomic-embed-text',
			anthropic: false,
		} );

		expect( on ).toMatch( /UNDERGROWTH_AUTO_ACCEPT/ );
		expect( on ).toMatch( /test/i );
		expect( off ).not.toMatch( /UNDERGROWTH_AUTO_ACCEPT/ );
	} );

	it( 'reports what was accepted, what was refused and the groups made', () => {
		const message = autoAcceptMessage( {
			counts: { accepted: 120, edited: 2, no_word: 1 },
			groups: 7,
		} );

		expect( message ).toMatch( /Accepted 120 suggestions/ );
		expect( message ).toMatch( /3 were refused/ );
		expect( message ).toMatch( /7 groups/ );
		expect(
			autoAcceptMessage( { counts: { accepted: 4 }, groups: 1 } )
		).not.toMatch( /refused/ );
	} );
} );
