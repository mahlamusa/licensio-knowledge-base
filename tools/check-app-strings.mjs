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
 * A `translators:` comment written right before a call must reach the
 * template on that call's entry too, for the singular and the plural form.
 * The bundler and the minifier both drop comments they do not recognise, and
 * a lost comment leaves translators guessing what a placeholder holds. The
 * comment belongs to a call only when nothing but whitespace separates them,
 * or the start of the expression the call sits in: a property key, a ternary
 * condition, a `sprintf(`. Any other comment is left alone, so a stray one
 * cannot be charged to a call further down. The `translators:` keyword is
 * matched in any case, as the build and `wp i18n make-pot` do.
 *
 * Translator comments must be block comments: the build only keeps those, so
 * a `// translators:` line comment fails the check.
 *
 * A template entry is shared by every call with the same text and context,
 * so a comment that reaches it from one file also covers the same string in
 * another file whose comment was lost.
 *
 * Usage: node tools/check-app-strings.mjs <file.pot> [apps-directory]
 *
 * @since 1.0.0
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);

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
 * @return {Map<string, string[]>} A key() for every msgid and msgid_plural, mapped to the entry's extracted comments.
 */
function potStrings( file ) {
	const strings = new Map();

	for ( const entry of readFileSync( file, 'utf8' ).split( /\n\s*\n/ ) ) {
		const references = [ ...entry.matchAll( /^#: (.*)$/gm ) ].flatMap(
			( [ , line ] ) => line.trim().split( /\s+/ )
		);

		if ( ! references.some( ( ref ) => ref.startsWith( 'dist/' ) ) ) {
			continue;
		}

		const context = poField( entry, 'msgctxt' ) ?? '';
		const comments = [ ...entry.matchAll( /^#\. (.*)$/gm ) ].map(
			( [ , comment ] ) => comment.trim()
		);

		for ( const field of [ 'msgid', 'msgid_plural' ] ) {
			const text = poField( entry, field );

			if ( undefined !== text ) {
				strings.set( key( context, text ), comments );
			}
		}
	}

	return strings;
}

const known = potStrings( potFile );
const call =
	/\b(__|_x|_n|_nx)\(\s*(['"`])((?:\\.|(?!\2)[^\\])*)\2(?:\s*,\s*(['"`])((?:\\.|(?!\4)[^\\])*)\4)?/g;

// What may sit between a translator comment and the call it describes: the
// start of the expression the call is in, such as `'aria-label': sprintf( `
// or `count ? sprintf( `.
const leadIn =
	/^\s*(?:['"]?[\w$-]+['"]?\s*:\s*)?(?:[\w$.]+\s*\?\s*)?(?:sprintf\(\s*)?$/;

const missing = [];
let checked = 0;

for ( const file of sourceFiles( appsDir ) ) {
	const raw = readFileSync( file, 'utf8' );

	// Usage examples in docblocks and markup comments are not calls. Blank
	// them out, keeping their line breaks so reported line numbers stay right.
	const source = raw.replace(
		/\/\*[\s\S]*?\*\/|<!--[\s\S]*?-->/g,
		( comment ) => comment.replace( /[^\n]/g, ' ' )
	);

	// Translator comments, each waiting for the call right after it. Block
	// comments are matched the same way they are blanked out above.
	const notes = [ ...raw.matchAll( /\/\*[\s\S]*?\*\//g ) ].flatMap(
		( comment ) => {
			const note = comment[ 0 ].match(
				/^\/\*\s*(translators:[\s\S]*?)\s*\*\/$/i
			);

			return note
				? [
						{
							end: comment.index + comment[ 0 ].length,
							text: note[ 1 ].replace( /\s+/g, ' ' ),
						},
					]
				: [];
		}
	);

	// The build drops line comments, so a translator note must be a block.
	for ( const lineNote of source.matchAll( /\/\/\s*translators:/gi ) ) {
		missing.push(
			`${ path.relative( root, file ) }:${
				source.slice( 0, lineNote.index ).split( '\n' ).length
			}  ${ lineNote[ 0 ] } (a line comment does not survive the build; use /* translators: */)`
		);
	}

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

		let note;

		while ( notes.length && notes[ 0 ].end <= match.index ) {
			const candidate = notes.shift();

			note = leadIn.test( source.slice( candidate.end, match.index ) )
				? candidate.text
				: undefined;
		}

		const line = source.slice( 0, match.index ).split( '\n' ).length;

		for ( const text of wanted ) {
			checked++;

			if ( ! known.has( key( context, text ) ) ) {
				missing.push(
					`${ path.relative( root, file ) }:${ line }  ${ text }${
						context ? ` (context: ${ context })` : ''
					}`
				);
			}
		}

		for ( const text of note ? wanted : [] ) {
			const comments = known.get( key( context, text ) );

			if ( comments && ! comments.includes( note ) ) {
				missing.push(
					`${ path.relative(
						root,
						file
					) }:${ line }  ${ text } (comment lost: ${ note })`
				);
			}
		}
	}
}

if ( missing.length > 0 ) {
	process.stderr.write(
		`App string check FAILED: ${ missing.length } Vue app strings or translator comments are missing from ${ potFile } (${ checked } strings checked):\n`
	);
	process.stderr.write(
		missing.map( ( item ) => `  ${ item }\n` ).join( '' )
	);
	process.exit( 1 );
}

process.stdout.write(
	`App string check: all ${ checked } Vue app strings and their translator comments are in the template.\n`
);
