/**
 * Vue app string coverage check.
 *
 * Fails when a string the Vue admin apps pass to `__`, `_x`, `_n` or `_nx` is
 * missing from a translation template. The template is generated from the
 * built JavaScript in `dist/`, because that is what WordPress.org reads and
 * what the JSON translation files are keyed by, so a build that hides a call
 * from `wp i18n make-pot` (a mangled name, a wrapper around the function)
 * shows up here as a missing string instead of an untranslatable screen.
 *
 * Only template entries referenced from `dist/` count, so a PHP string with
 * the same text cannot hide a missing app string, and a string with a context
 * (`_x`, `_nx`) must be in the template under that same context.
 *
 * Usage: node tools/check-app-strings.mjs <file.pot> [apps-directory]
 *
 * @since 1.0.0
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

const potFile = process.argv[ 2 ];
const appsDir = path.resolve( process.argv[ 3 ] ?? path.join( root, 'apps' ) );

if ( ! potFile || ! existsSync( potFile ) ) {
	process.stderr.write(
		'Usage: node tools/check-app-strings.mjs <file.pot> [apps-directory]\n'
	);
	process.exit( 2 );
}

/**
 * Every `.ts` and `.vue` file under a directory.
 *
 * @since 1.0.0
 *
 * @param {string} dir The directory to walk.
 *
 * @return {string[]} Absolute file paths.
 */
function sourceFiles( dir ) {
	return readdirSync( dir ).flatMap( ( entry ) => {
		const file = path.join( dir, entry );

		if ( statSync( file ).isDirectory() ) {
			return sourceFiles( file );
		}

		return /\.(ts|vue)$/.test( entry ) ? [ file ] : [];
	} );
}

/**
 * Decode the escapes of a JavaScript or PO string literal body.
 *
 * @since 1.0.0
 *
 * @param {string} text The characters between the quotes.
 *
 * @return {string} The string value.
 */
function unescapeString( text ) {
	return text.replace(
		/\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|.)/g,
		( match, code ) => {
			if ( code.startsWith( 'u' ) ) {
				return String.fromCodePoint(
					parseInt( code.replace( /[u{}]/g, '' ), 16 )
				);
			}

			return { n: '\n', t: '\t', r: '\r' }[ code ] ?? code;
		}
	);
}

/**
 * Join a context and a string the way gettext keys them.
 *
 * @since 1.0.0
 *
 * @param {string} context The message context, or an empty string for none.
 * @param {string} text    The message.
 *
 * @return {string} The lookup key.
 */
function key( context, text ) {
	return `${ context }\u0004${ text }`;
}

/**
 * The value of a quoted PO field, which may run over several lines.
 *
 * @since 1.0.0
 *
 * @param {string} entry The entry text.
 * @param {string} field The field name, such as `msgid`.
 *
 * @return {string|undefined} The decoded value, or undefined when absent.
 */
function poField( entry, field ) {
	const match = entry.match(
		new RegExp( `^${ field } ((?:"(?:\\\\.|[^"\\\\])*"\\s*)+)`, 'm' )
	);

	if ( ! match ) {
		return undefined;
	}

	return [ ...match[ 1 ].matchAll( /"((?:\\.|[^"\\])*)"/g ) ]
		.map( ( [ , part ] ) => unescapeString( part ) )
		.join( '' );
}

/**
 * The app strings of a translation template, keyed by context.
 *
 * Only entries with a `#: dist/` reference are read: those are the strings
 * extracted from the built apps.
 *
 * @since 1.0.0
 *
 * @param {string} file The template path.
 *
 * @return {Set<string>} A key() for every msgid and msgid_plural.
 */
function potStrings( file ) {
	const strings = new Set();

	for ( const entry of readFileSync( file, 'utf8' ).split( /\n\s*\n/ ) ) {
		const references = [ ...entry.matchAll( /^#: (.*)$/gm ) ].flatMap(
			( [ , line ] ) => line.trim().split( /\s+/ )
		);

		if ( ! references.some( ( ref ) => ref.startsWith( 'dist/' ) ) ) {
			continue;
		}

		const context = poField( entry, 'msgctxt' ) ?? '';

		for ( const field of [ 'msgid', 'msgid_plural' ] ) {
			const text = poField( entry, field );

			if ( undefined !== text ) {
				strings.add( key( context, text ) );
			}
		}
	}

	return strings;
}

const known = potStrings( potFile );
const call =
	/\b(__|_x|_n|_nx)\(\s*(['"`])((?:\\.|(?!\2)[^\\])*)\2(?:\s*,\s*(['"`])((?:\\.|(?!\4)[^\\])*)\4)?/g;

const missing = [];
let checked = 0;

for ( const file of sourceFiles( appsDir ) ) {
	// Usage examples in docblocks and markup comments are not calls. Blank
	// them out, keeping their line breaks so reported line numbers stay right.
	const source = readFileSync( file, 'utf8' ).replace(
		/\/\*[\s\S]*?\*\/|<!--[\s\S]*?-->/g,
		( comment ) => comment.replace( /[^\n]/g, ' ' )
	);

	for ( const match of source.matchAll( call ) ) {
		const [ , name, , single, , second ] = match;
		const wanted = [ unescapeString( single ) ];
		let context = '';

		// The second argument of _n and _nx is the plural form, and the
		// second of _x is the context.
		if ( ( name === '_n' || name === '_nx' ) && undefined !== second ) {
			wanted.push( unescapeString( second ) );
		} else if ( name === '_x' && undefined !== second ) {
			context = unescapeString( second );
		}

		// The context of _nx follows the count: _nx( single, plural, n, context ).
		if ( name === '_nx' ) {
			const rest = source
				.slice( match.index + match[ 0 ].length )
				.match( /^\s*,[^,]+?,\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/ );
			context = rest ? unescapeString( rest[ 2 ] ) : '';
		}

		for ( const text of wanted ) {
			checked++;

			if ( ! known.has( key( context, text ) ) ) {
				const line = source.slice( 0, match.index ).split( '\n' ).length;

				missing.push(
					`${ path.relative( root, file ) }:${ line }  ${ text }${
						context ? ` (context: ${ context })` : ''
					}`
				);
			}
		}
	}
}

if ( missing.length > 0 ) {
	process.stderr.write(
		`App string check FAILED: ${ missing.length } of ${ checked } Vue app strings are missing from ${ potFile }:\n`
	);
	process.stderr.write(
		missing.map( ( item ) => `  ${ item }\n` ).join( '' )
	);
	process.exit( 1 );
}

process.stdout.write(
	`App string check: all ${ checked } Vue app strings are in the template.\n`
);
