const { describe, it, expect } = require( '@jest/globals' );

const { stripLineNumbers } = require( '../../bin/pot-references' );

describe( 'stripLineNumbers', () => {
	it( 'drops the line number from each reference', () => {
		expect(
			stripLineNumbers( '#: src/settings.php:22\nmsgid "A"\nmsgstr ""\n' )
		).toBe( '#: src/settings.php\nmsgid "A"\nmsgstr ""\n' );
	} );

	it( 'lists each file once per string, in first-seen order', () => {
		const pot = [
			'#. Plugin Name of the plugin',
			'#: undergrowth.php:3',
			'#: src/abilities.php:26',
			'#: src/settings.php:22',
			'#: src/settings.php:23',
			'#: src/abilities.php:90',
			'msgid "Undergrowth"',
			'msgstr ""',
			'',
			'#: src/settings.php:40',
			'msgid "B"',
			'msgstr ""',
			'',
		].join( '\n' );

		expect( stripLineNumbers( pot ) ).toBe(
			[
				'#. Plugin Name of the plugin',
				'#: undergrowth.php',
				'#: src/abilities.php',
				'#: src/settings.php',
				'msgid "Undergrowth"',
				'msgstr ""',
				'',
				'#: src/settings.php',
				'msgid "B"',
				'msgstr ""',
				'',
			].join( '\n' )
		);
	} );

	it( 'splits a line holding several references', () => {
		expect(
			stripLineNumbers( '#: a.php:1 b.js:2 a.php:9\nmsgid "A"\n' )
		).toBe( '#: a.php\n#: b.js\nmsgid "A"\n' );
	} );

	it( 'keeps a reference that has no line number', () => {
		expect( stripLineNumbers( '#: build/index.js\nmsgid "A"\n' ) ).toBe(
			'#: build/index.js\nmsgid "A"\n'
		);
	} );

	it( 'leaves comments, headers and strings alone', () => {
		const pot =
			'# Copyright\nmsgid ""\nmsgstr "X-Domain: undergrowth\\n"\n\n#. translators: %1$s: 12\n#, php-format\nmsgid "At 12:30"\n';
		expect( stripLineNumbers( pot ) ).toBe( pot );
	} );
} );
