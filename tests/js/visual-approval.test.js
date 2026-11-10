const {
	describe,
	it,
	expect,
	beforeEach,
	afterEach,
} = require( '@jest/globals' );
const { execFileSync } = require( 'child_process' );
const { mkdirSync, mkdtempSync, writeFileSync, rmSync } = require( 'fs' );
const { tmpdir } = require( 'os' );
const { join } = require( 'path' );
const { PNG } = require( 'pngjs' );

const script = join( __dirname, '../../bin/visual-approval.mjs' );

/**
 * Writes a 4 × 4 PNG of one grey.
 *
 * @param {string} dir   Folder to write into.
 * @param {string} name  File name.
 * @param {number} shade Grey level, 0 to 255.
 */
function png( dir, name, shade ) {
	const image = new PNG( { width: 4, height: 4 } );

	image.data.fill( shade );
	for ( let i = 3; i < image.data.length; i += 4 ) {
		image.data[ i ] = 255;
	}

	mkdirSync( dir, { recursive: true } );
	writeFileSync( join( dir, name ), PNG.sync.write( image ) );
}

/**
 * Runs the script and returns what it printed, parsed.
 *
 * @param {...string} args Arguments after the script.
 * @return {{changes: number, sinceApproved: number}} The counts.
 */
function counts( ...args ) {
	return JSON.parse(
		execFileSync( 'node', [ script, ...args ], { encoding: 'utf8' } )
	);
}

describe( 'visual-approval', () => {
	let dir;
	let before;
	let after;
	let approved;

	beforeEach( () => {
		dir = mkdtempSync( join( tmpdir(), 'visual-' ) );
		before = join( dir, 'before' );
		after = join( dir, 'after' );
		approved = join( dir, 'approved' );

		for ( const folder of [ before, after, approved ] ) {
			png( folder, 'map--empty--en_US--1280.png', 255 );
			png( folder, 'map--full--en_US--1280.png', 128 );
		}
	} );

	afterEach( () => {
		rmSync( dir, { recursive: true, force: true } );
	} );

	it( 'counts nothing when every shot matches', () => {
		expect( counts( before, after, approved ) ).toEqual( {
			changes: 0,
			sinceApproved: 0,
		} );
	} );

	it( 'counts changed, new and removed shots against the base', () => {
		png( after, 'map--full--en_US--1280.png', 0 );
		png( after, 'map--new--en_US--1280.png', 0 );
		rmSync( join( after, 'map--empty--en_US--1280.png' ) );

		expect( counts( before, after, after ).changes ).toBe( 3 );
	} );

	it( 'does not count a state the build skipped', () => {
		writeFileSync(
			join( after, 'map--later--en_US--1280.png.skipped' ),
			''
		);

		expect( counts( before, after, approved ).changes ).toBe( 0 );
	} );

	it( 'keeps an approval when the shots match the approved set', () => {
		png( after, 'map--full--en_US--1280.png', 0 );
		png( approved, 'map--full--en_US--1280.png', 0 );

		expect( counts( before, after, approved ) ).toEqual( {
			changes: 1,
			sinceApproved: 0,
		} );
	} );

	it( 'counts shots that changed again since the approved set', () => {
		png( after, 'map--full--en_US--1280.png', 0 );

		expect( counts( before, after, approved ) ).toEqual( {
			changes: 1,
			sinceApproved: 1,
		} );
	} );

	it( 'treats a missing approved set as all new', () => {
		expect(
			counts( before, after, join( dir, 'missing' ) ).sinceApproved
		).toBe( 2 );
	} );

	it( 'exits 2 without its arguments', () => {
		expect( () =>
			execFileSync( 'node', [ script, before ], { stdio: 'pipe' } )
		).toThrow( expect.objectContaining( { status: 2 } ) );
	} );
} );
