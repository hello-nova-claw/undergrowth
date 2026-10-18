/**
 * Writes languages/undergrowth-en_XA.po from the POT.
 *
 * Every string is accented, lengthened by about 40 per cent and wrapped in
 * brackets, so untranslated strings, clipped text and concatenated sentences
 * all show up in end-to-end screenshots.
 *
 * Usage: node bin/pseudo-locale.mjs languages/undergrowth.pot
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const ACCENTS = {
	a: 'á',
	b: 'ƀ',
	c: 'ç',
	d: 'ð',
	e: 'é',
	f: 'ƒ',
	g: 'ĝ',
	h: 'ĥ',
	i: 'í',
	j: 'ĵ',
	k: 'ķ',
	l: 'ļ',
	m: 'ɱ',
	n: 'ñ',
	o: 'ö',
	p: 'þ',
	r: 'ŕ',
	s: 'š',
	t: 'ţ',
	u: 'ü',
	w: 'ŵ',
	y: 'ý',
	z: 'ž',
	A: 'Å',
	C: 'Ç',
	E: 'É',
	I: 'Î',
	N: 'Ñ',
	O: 'Ö',
	U: 'Ü',
};

// Placeholders, HTML tags and entities must survive untouched or sprintf()
// and the markup break, which would hide real layout problems behind fake ones.
const PROTECTED =
	/(%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]|<[^>]+>|&[a-z#0-9]+;)/;

/**
 * Turns one source string into its pseudo-locale form.
 *
 * @param {string} text Source string.
 * @return {string} Pseudo-localised string.
 */
function pseudo( text ) {
	if ( text === '' ) {
		return '';
	}

	const accented = text
		.split( PROTECTED )
		.map( ( part, index ) =>
			index % 2
				? part
				: part.replace(
						/[a-zA-Z]/g,
						( char ) => ACCENTS[ char ] ?? char
					)
		)
		.join( '' );

	const padding = '~'.repeat(
		Math.max( 1, Math.round( text.length * 0.4 ) )
	);

	return `[${ accented } ${ padding }]`;
}

/**
 * Reads the quoted value of a PO keyword and any continuation lines.
 *
 * @param {string[]} lines All lines of the file.
 * @param {number}   start Index of the line holding the keyword.
 * @return {{value: string, next: number}} Unescaped value and the next unread line.
 */
function readValue( lines, start ) {
	const quoted = [ lines[ start ].replace( /^\S+\s+/, '' ) ];
	let next = start + 1;

	while ( next < lines.length && lines[ next ].startsWith( '"' ) ) {
		quoted.push( lines[ next ] );
		next++;
	}

	return { value: quoted.map( ( q ) => JSON.parse( q ) ).join( '' ), next };
}

/**
 * Parses the entries of a POT file, skipping the header entry.
 *
 * @param {string} source POT file contents.
 * @return {Array<{msgctxt?: string, msgid: string, msgid_plural?: string}>} Entries.
 */
function parse( source ) {
	const lines = source.split( /\r?\n/ );
	const entries = [];
	let entry = {};
	let i = 0;

	while ( i < lines.length ) {
		const keyword = lines[ i ].match( /^(msgctxt|msgid_plural|msgid)\s/ );

		if ( ! keyword ) {
			if ( lines[ i ].trim() === '' && entry.msgid !== undefined ) {
				entries.push( entry );
				entry = {};
			}
			i++;
			continue;
		}

		const { value, next } = readValue( lines, i );
		entry[ keyword[ 1 ] ] = value;
		i = next;
	}

	if ( entry.msgid !== undefined ) {
		entries.push( entry );
	}

	return entries.filter( ( e ) => e.msgid !== '' );
}

const potPath = process.argv[ 2 ];

if ( ! potPath ) {
	process.stderr.write( 'Usage: node bin/pseudo-locale.mjs <file.pot>\n' );
	process.exit( 1 );
}

const quote = ( value ) => JSON.stringify( value );

const out = [
	'msgid ""',
	'msgstr ""',
	quote( 'Project-Id-Version: Undergrowth pseudo-locale\n' ),
	quote( 'Content-Type: text/plain; charset=UTF-8\n' ),
	quote( 'Language: en_XA\n' ),
	quote( 'Plural-Forms: nplurals=2; plural=(n != 1);\n' ),
	'',
];

for ( const entry of parse( readFileSync( potPath, 'utf8' ) ) ) {
	if ( entry.msgctxt !== undefined ) {
		out.push( `msgctxt ${ quote( entry.msgctxt ) }` );
	}
	out.push( `msgid ${ quote( entry.msgid ) }` );

	if ( entry.msgid_plural !== undefined ) {
		out.push( `msgid_plural ${ quote( entry.msgid_plural ) }` );
		out.push( `msgstr[0] ${ quote( pseudo( entry.msgid ) ) }` );
		out.push( `msgstr[1] ${ quote( pseudo( entry.msgid_plural ) ) }` );
	} else {
		out.push( `msgstr ${ quote( pseudo( entry.msgid ) ) }` );
	}
	out.push( '' );
}

const poPath = join( dirname( potPath ), 'undergrowth-en_XA.po' );
writeFileSync( poPath, out.join( '\n' ) );
process.stdout.write( `Wrote ${ poPath }\n` );
