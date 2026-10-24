const {
	describe,
	it,
	expect,
	beforeEach,
	afterEach,
} = require( '@jest/globals' );
const {
	mkdtempSync,
	mkdirSync,
	writeFileSync,
	readFileSync,
	existsSync,
	rmSync,
} = require( 'fs' );
const { tmpdir } = require( 'os' );
const { join } = require( 'path' );

const {
	parseFragment,
	readFragments,
	foldInto,
	fold,
} = require( '../../bin/changelog' );

const README = [
	'=== Undergrowth ===',
	'',
	'== Changelog ==',
	'',
	'= Unreleased =',
	'* First.',
	'* Second.',
	'',
	'= 0.1.0 =',
	'* Old.',
	'',
].join( '\n' );

describe( 'parseFragment', () => {
	it( 'returns each non-empty line as an entry', () => {
		expect( parseFragment( 'One.\n\nTwo.\n' ) ).toEqual( [
			'One.',
			'Two.',
		] );
	} );

	it( 'drops a leading bullet and surrounding space', () => {
		expect( parseFragment( '  * One.  \n*Two.' ) ).toEqual( [
			'One.',
			'Two.',
		] );
	} );

	it( 'returns nothing for an empty fragment', () => {
		expect( parseFragment( ' \n\n' ) ).toEqual( [] );
	} );
} );

describe( 'foldInto', () => {
	it( 'adds entries after the existing Unreleased lines', () => {
		const out = foldInto( README, [ 'Third.', 'Fourth.' ] );
		expect( out ).toContain(
			'= Unreleased =\n* First.\n* Second.\n* Third.\n* Fourth.\n\n= 0.1.0 ='
		);
	} );

	it( 'adds entries straight under an empty Unreleased heading', () => {
		const out = foldInto(
			'== Changelog ==\n\n= Unreleased =\n\n= 0.1.0 =\n* Old.\n',
			[ 'New.' ]
		);
		expect( out ).toBe(
			'== Changelog ==\n\n= Unreleased =\n* New.\n\n= 0.1.0 =\n* Old.\n'
		);
	} );

	it( 'works when Unreleased is the last section', () => {
		const out = foldInto( '= Unreleased =\n* First.\n', [ 'Second.' ] );
		expect( out ).toBe( '= Unreleased =\n* First.\n* Second.\n' );
	} );

	it( 'leaves the readme alone when there is nothing to add', () => {
		expect( foldInto( README, [] ) ).toBe( README );
	} );

	it( 'opens an Unreleased section above the last release', () => {
		const out = foldInto(
			'== Changelog ==\n\n= 0.1.0 =\n* First release.\n',
			[ 'New.' ]
		);
		expect( out ).toBe(
			'== Changelog ==\n\n= Unreleased =\n* New.\n\n= 0.1.0 =\n* First release.\n'
		);
	} );

	it( 'opens an Unreleased section in an empty changelog', () => {
		expect( foldInto( '== Changelog ==\n', [ 'New.' ] ) ).toBe(
			'== Changelog ==\n\n= Unreleased =\n* New.\n'
		);
	} );

	it( 'throws when there is no Changelog section', () => {
		expect( () => foldInto( '=== Undergrowth ===\n', [ 'New.' ] ) ).toThrow(
			/Changelog/
		);
	} );
} );

describe( 'files', () => {
	let root;

	beforeEach( () => {
		root = mkdtempSync( join( tmpdir(), 'changelog-' ) );
		mkdirSync( join( root, 'changelog' ) );
		writeFileSync( join( root, 'readme.txt' ), README );
		writeFileSync( join( root, 'changelog', 'README.md' ), '# Notes' );
	} );

	afterEach( () => {
		rmSync( root, { recursive: true, force: true } );
	} );

	it( 'reads .txt fragments in issue number order', () => {
		writeFileSync( join( root, 'changelog', '100-late.txt' ), 'Late.' );
		writeFileSync( join( root, 'changelog', '9-early.txt' ), 'Early.' );
		writeFileSync( join( root, 'changelog', '9-also.txt' ), 'Also.' );

		expect(
			readFragments( join( root, 'changelog' ) ).map( ( f ) => f.name )
		).toEqual( [ '9-also.txt', '9-early.txt', '100-late.txt' ] );
	} );

	it( 'folds fragments into readme.txt and deletes them', () => {
		writeFileSync( join( root, 'changelog', '12-b.txt' ), 'Twelve.\n' );
		writeFileSync( join( root, 'changelog', '3-a.txt' ), '* Three.\n' );

		expect( fold( root ) ).toBe( 2 );

		expect( readFileSync( join( root, 'readme.txt' ), 'utf8' ) ).toContain(
			'* Second.\n* Three.\n* Twelve.\n\n= 0.1.0 ='
		);
		expect( existsSync( join( root, 'changelog', '3-a.txt' ) ) ).toBe(
			false
		);
		expect( existsSync( join( root, 'changelog', '12-b.txt' ) ) ).toBe(
			false
		);
		expect( existsSync( join( root, 'changelog', 'README.md' ) ) ).toBe(
			true
		);
	} );

	it( 'refuses an empty fragment and changes nothing', () => {
		writeFileSync( join( root, 'changelog', '3-a.txt' ), 'Three.' );
		writeFileSync( join( root, 'changelog', '4-blank.txt' ), ' \n' );

		expect( () => fold( root ) ).toThrow( /4-blank\.txt/ );

		expect( readFileSync( join( root, 'readme.txt' ), 'utf8' ) ).toBe(
			README
		);
		expect( existsSync( join( root, 'changelog', '3-a.txt' ) ) ).toBe(
			true
		);
	} );

	it( 'changes nothing when there are no fragments', () => {
		expect( fold( root ) ).toBe( 0 );
		expect( readFileSync( join( root, 'readme.txt' ), 'utf8' ) ).toBe(
			README
		);
	} );
} );
