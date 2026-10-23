const { describe, it, expect } = require( '@jest/globals' );

const { startMessage } = require( '../../bin/playground-real-text' );

const site = 'http://127.0.0.1:9400';

describe( 'startMessage', () => {
	it( 'gives the address of Settings > Undergrowth, not the front page', () => {
		const message = startMessage( {
			siteUrl: site,
			local: true,
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
			anthropic: false,
		} );

		expect( message ).toMatch( /username admin and the password password/ );
	} );

	it( 'says which way posts are read and whether questions are on', () => {
		expect(
			startMessage( { siteUrl: site, local: true, anthropic: false } )
		).toMatch( /Ollama[\s\S]*No Anthropic key/ );
		expect(
			startMessage( { siteUrl: site, local: false, anthropic: true } )
		).toMatch(
			/OpenAI, with your key[\s\S]*Claude, with your Anthropic key/
		);
	} );
} );
