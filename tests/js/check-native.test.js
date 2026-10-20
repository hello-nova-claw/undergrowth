const {
	describe,
	it,
	expect,
	beforeEach,
	afterEach,
} = require( '@jest/globals' );
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require( 'fs' );
const { tmpdir } = require( 'os' );
const { join } = require( 'path' );

const {
	findInlineStyles,
	findHexColours,
	findMockingLibraries,
	findFrameworks,
	check,
} = require( '../../bin/check-native' );

describe( 'findInlineStyles', () => {
	it( 'finds a style attribute and its line', () => {
		const php = '<?php\n$a = 1;\necho \'<form style="display:inline">\';\n';

		expect( findInlineStyles( php ) ).toEqual( [ 3 ] );
	} );

	it( 'finds single-quoted and escaped attributes', () => {
		expect(
			findInlineStyles( `<div style='color:red'>\n"<p style=\\"x\\">"` )
		).toEqual( [ 1, 2 ] );
	} );

	it( 'finds JSX style props', () => {
		expect(
			findInlineStyles( '<Button style={ { margin: 0 } }>' )
		).toEqual( [ 1 ] );
	} );

	it( 'finds an attribute split across lines', () => {
		expect(
			findInlineStyles(
				'<div\n\tclass="wrap"\n\tstyle\n\t\t="margin:0">'
			)
		).toEqual( [ 3 ] );
	} );

	it( 'ignores variables, array keys and data attributes', () => {
		const php = [
			"$style = 'button-primary';",
			"array( 'style' => true );",
			'<div data-style="x">',
			'esc_attr( $style )',
			'const style = { margin: 0 };',
			'el.style = "color: red";',
		].join( '\n' );

		expect( findInlineStyles( php ) ).toEqual( [] );
	} );
} );

describe( 'findHexColours', () => {
	it( 'finds hex colours in declarations', () => {
		const css = '.a {\n\tcolor: #fff;\n\tborder: 1px solid #2271b1aa;\n}';

		expect( findHexColours( css ) ).toEqual( [ 2, 3 ] );
	} );

	it( 'ignores id selectors and custom properties', () => {
		const css =
			'#add, #undergrowth-notice {\n\tcolor: var(--wp-admin-theme-color);\n}\nli:hover #add {\n}\na:not(#fade) {\n}';

		expect( findHexColours( css ) ).toEqual( [] );
	} );
} );

describe( 'findMockingLibraries', () => {
	it( 'finds mocking libraries in require and require-dev', () => {
		const composer = {
			require: { php: '>=7.4', 'brain/monkey': '^2' },
			'require-dev': {
				'mockery/mockery': '^1',
				'10up/wp_mock': '^1',
				'phpunit/phpunit': '^9',
			},
		};

		expect( findMockingLibraries( composer ) ).toEqual( [
			'brain/monkey',
			'mockery/mockery',
			'10up/wp_mock',
		] );
	} );

	it( 'passes the WordPress test tooling', () => {
		expect(
			findMockingLibraries( {
				'require-dev': {
					'phpunit/phpunit': '^9',
					'yoast/phpunit-polyfills': '^4',
				},
			} )
		).toEqual( [] );
	} );
} );

describe( 'findFrameworks', () => {
	it( 'allows @wordpress packages and the allowlist', () => {
		expect(
			findFrameworks( {
				devDependencies: {
					'@wordpress/scripts': '^36',
					'@wordpress/components': '^30',
					'@playwright/test': '^1',
				},
			} )
		).toEqual( [] );
	} );

	it( 'finds anything else in any dependency list', () => {
		expect(
			findFrameworks( {
				dependencies: { tailwindcss: '^4' },
				devDependencies: { '@wordpress/scripts': '^36', sinon: '^1' },
				peerDependencies: { '@mui/material': '^6' },
			} )
		).toEqual( [ 'tailwindcss', 'sinon', '@mui/material' ] );
	} );
} );

describe( 'check', () => {
	let root;

	beforeEach( () => {
		root = mkdtempSync( join( tmpdir(), 'undergrowth-native-' ) );
		mkdirSync( join( root, 'src', 'admin' ), { recursive: true } );
		writeFileSync( join( root, 'composer.json' ), '{}' );
		writeFileSync( join( root, 'package.json' ), '{}' );
	} );

	afterEach( () => {
		rmSync( root, { recursive: true, force: true } );
	} );

	it( 'passes a clean tree', () => {
		writeFileSync(
			join( root, 'src', 'admin', 'page.php' ),
			'<div class="wrap"></div>'
		);

		expect( check( root ) ).toEqual( [] );
	} );

	it( 'reports every breach with its file and line', () => {
		writeFileSync(
			join( root, 'src', 'admin', 'page.php' ),
			'<?php\n<p style="margin:0">'
		);
		writeFileSync(
			join( root, 'src', 'admin.scss' ),
			'a { color: #000; }'
		);
		writeFileSync( join( root, 'src', 'notes.txt' ), 'style="x" #fff' );
		writeFileSync(
			join( root, 'src', 'admin', 'panel.js' ),
			'const a = 1;\n<div style={ { color: "red" } } />'
		);
		writeFileSync(
			join( root, 'composer.json' ),
			JSON.stringify( { 'require-dev': { 'mockery/mockery': '^1' } } )
		);
		writeFileSync(
			join( root, 'package.json' ),
			JSON.stringify( { dependencies: { bootstrap: '^5' } } )
		);

		expect(
			check( root ).map( ( { file, line } ) => `${ file }:${ line }` )
		).toEqual( [
			'src/admin.scss:1',
			'src/admin/page.php:2',
			'src/admin/panel.js:2',
			'composer.json:1',
			'package.json:1',
		] );
	} );
} );
