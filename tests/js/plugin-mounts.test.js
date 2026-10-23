const { describe, it, expect } = require( '@jest/globals' );

const { buildPlugin } = require( '../../bin/plugin-mounts' );

describe( 'buildPlugin', () => {
	it( 'runs npm run build in the plugin folder', () => {
		const calls = [];

		buildPlugin( '/plugin', ( ...args ) => calls.push( args ), 'darwin' );

		expect( calls ).toEqual( [
			[
				'npm',
				[ 'run', 'build' ],
				{ cwd: '/plugin', stdio: 'ignore', shell: false },
			],
		] );
	} );

	it( 'runs npm through a shell on Windows, where it is a .cmd file', () => {
		const calls = [];

		buildPlugin( '/plugin', ( ...args ) => calls.push( args ), 'win32' );

		expect( calls[ 0 ][ 2 ] ).toMatchObject( { shell: true } );
	} );

	it( 'says which command failed when the build fails', () => {
		expect( () =>
			buildPlugin( '/plugin', () => {
				throw new Error( 'exit 1' );
			} )
		).toThrow( /npm run build/ );
	} );
} );
