const {
	describe,
	it,
	expect,
	beforeAll,
	afterAll,
} = require( '@jest/globals' );
const { execFileSync } = require( 'child_process' );
const { mkdtempSync, writeFileSync, rmSync } = require( 'fs' );
const { tmpdir } = require( 'os' );
const { join } = require( 'path' );
const { PNG } = require( 'pngjs' );

const script = join( __dirname, '../../bin/gallery-sheet.mjs' );
const BASE = 'https://github.com/o/r/blob/abc/gallery-12';

/**
 * Writes a blank PNG of the given size.
 *
 * @param {string} dir    Folder to write into.
 * @param {string} name   File name.
 * @param {number} width  Width in pixels.
 * @param {number} height Height in pixels.
 */
function png( dir, name, width, height ) {
	writeFileSync(
		join( dir, name ),
		PNG.sync.write( new PNG( { width, height } ) )
	);
}

/**
 * Runs the sheet script and returns its Markdown.
 *
 * @param {string[]} args Arguments after the script.
 * @return {string} What it printed.
 */
function sheet( ...args ) {
	return execFileSync( 'node', [ script, ...args ], { encoding: 'utf8' } );
}

describe( 'gallery-sheet', () => {
	let dir;

	beforeAll( () => {
		dir = mkdtempSync( join( tmpdir(), 'gallery-' ) );

		for ( const kind of [ 'page', 'post' ] ) {
			png(
				dir,
				`gallery-twentytwentyfive--${ kind }--1280.png`,
				1280,
				3000
			);
			png(
				dir,
				`gallery-twentytwentyfive--${ kind }--390.png`,
				390,
				5000
			);
		}

		// A theme with one shot missing, as a crash mid-theme would leave.
		png( dir, 'gallery-astra--page--1280.png', 1280, 2000 );
		writeFileSync( join( dir, 'skipped.json' ), '["ollie"]\n' );
	} );

	afterAll( () => {
		rmSync( dir, { recursive: true, force: true } );
	} );

	it( 'starts with the marker and one row per theme in the blueprint', () => {
		const lines = sheet( dir ).split( '\n' );

		expect( lines[ 0 ] ).toBe( '<!-- undergrowth-theme-gallery -->' );
		expect(
			lines.filter( ( line ) => line.startsWith( '| `' ) )
		).toHaveLength( 10 );
		expect( lines ).toContain(
			'| Theme | Garden page, 1280 | Garden page, 390 | Post, 1280 | Post, 390 |'
		);
	} );

	it( 'gives each shot its size without a base URL, for the job summary', () => {
		const out = sheet( dir );

		expect( out ).toMatch(
			/^\| `twentytwentyfive` \| 1280 × 3000 \| 390 × 5000 \| 1280 × 3000 \| 390 × 5000 \|$/m
		);
		expect( out ).not.toContain( '<img' );
	} );

	it( 'embeds each shot from the base URL, for the comment', () => {
		const out = sheet( dir, BASE );

		expect( out ).toContain(
			`<img src="${ BASE }/gallery-twentytwentyfive--page--1280.png?raw=true" width="320" alt="twentytwentyfive, Garden page, 1280">`
		);
		expect( out ).toContain(
			`<img src="${ BASE }/gallery-twentytwentyfive--post--390.png?raw=true" width="110" alt="twentytwentyfive, post, 390">`
		);
	} );

	it( 'says which themes were not installed and which shots are missing', () => {
		const out = sheet( dir );

		expect( out ).toMatch(
			/^\| `ollie` \| not installed \| not installed \| not installed \| not installed \|$/m
		);
		expect( out ).toMatch(
			/^\| `astra` \| 1280 × 2000 \| missing \| missing \| missing \|$/m
		);
		expect( out ).toMatch(
			/^\| `kadence` \| missing \| missing \| missing \| missing \|$/m
		);
		expect( out ).toContain( '2 themes shot, 1 not installed, 7 missing' );
	} );

	it( 'says when nothing was taken', () => {
		const empty = mkdtempSync( join( tmpdir(), 'gallery-empty-' ) );

		try {
			expect( sheet( empty ) ).toContain(
				'No shots were taken. Check the workflow run for errors.'
			);
		} finally {
			rmSync( empty, { recursive: true, force: true } );
		}
	} );

	it( 'refuses to run without a folder', () => {
		expect( () =>
			execFileSync( 'node', [ script ], {
				encoding: 'utf8',
				stdio: 'pipe',
			} )
		).toThrow();
	} );
} );
