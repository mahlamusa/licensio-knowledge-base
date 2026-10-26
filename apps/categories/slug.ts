/**
 * A slug made from a name the way WordPress makes one.
 *
 * Follows core's `sanitize_title()` in its save context, which is what the
 * server runs on a category name when no slug is sent: accents removed, tags
 * stripped, lower case, other non-ASCII characters percent-encoded, a few
 * punctuation characters dropped or turned into dashes, then everything but
 * `a-z`, `0-9`, `%`, `_` and `-` removed. Accents are removed only from the
 * Latin letters core's `remove_accents()` lists (its default, non-German
 * rules), so Cyrillic, Greek and other accented letters are kept and encoded
 * as core keeps them. Core's `strip_tags()` also drops an unclosed tag, which
 * this does not; the server's slug is what is stored.
 *
 * @since 1.0.0
 */

/** Letters core maps that do not decompose into a base letter and an accent. */
const LETTERS: Record< string, string > = {
	ª: 'a',
	º: 'o',
	ß: 's',
	æ: 'ae',
	Æ: 'AE',
	ø: 'o',
	Ø: 'O',
	œ: 'oe',
	Œ: 'OE',
	đ: 'd',
	Đ: 'D',
	ð: 'd',
	Ð: 'D',
	ł: 'l',
	Ł: 'L',
	þ: 'th',
	Þ: 'TH',
	ħ: 'h',
	Ħ: 'H',
	ı: 'i',
	Ĳ: 'IJ',
	ĳ: 'ij',
	ĸ: 'k',
	ŀ: 'l',
	Ŀ: 'L',
	ŉ: 'n',
	ŋ: 'n',
	Ŋ: 'N',
	ſ: 's',
	ŧ: 't',
	Ŧ: 'T',
	Ə: 'E',
	ǝ: 'e',
	ɑ: 'a',
	'€': 'E',
	'£': '',
};

/**
 * The accented letters core's `remove_accents()` lists: Latin-1, Latin
 * Extended-A, Vietnamese, Pinyin and Romanian. Only these lose their accents.
 */
const ACCENTED =
	/[\u00c0-\u017f\u01a0\u01a1\u01af\u01b0\u01cd-\u01dc\u0218-\u021b\u1ea0-\u1ef9]/g;

/** Encoded characters core turns into a dash. */
const DASHES = [
	'%c2%a0',
	'%e2%80%91',
	'%e2%80%93',
	'%e2%80%94',
	'%e2%80%af',
	'%e2%80%a8',
	'%e2%80%a9',
	'%e2%80%80',
	'%e2%80%81',
	'%e2%80%82',
	'%e2%80%83',
	'%e2%80%84',
	'%e2%80%85',
	'%e2%80%86',
	'%e2%80%87',
	'%e2%80%88',
	'%e2%80%89',
	'%e2%80%8a',
	'%e2%81%9f',
	'%e3%80%80',
	'&nbsp;',
	'&#160;',
	'&ndash;',
	'&#8211;',
	'&mdash;',
	'&#8212;',
	'/',
];

/** Encoded characters core removes. */
const DROPPED = [
	'%c2%ad',
	'%c2%a1',
	'%c2%bf',
	'%c2%ab',
	'%c2%bb',
	'%e2%80%b9',
	'%e2%80%ba',
	'%e2%80%98',
	'%e2%80%99',
	'%e2%80%9c',
	'%e2%80%9d',
	'%e2%80%9a',
	'%e2%80%9b',
	'%e2%80%9e',
	'%e2%80%9f',
	'%e2%80%a2',
	'%c2%a9',
	'%c2%ae',
	'%c2%b0',
	'%e2%80%a6',
	'%e2%84%a2',
	'%c2%b4',
	'%cb%8a',
	'%cc%81',
	'%cd%81',
	'%cc%80',
	'%cc%84',
	'%cc%8c',
	'%e2%80%8b',
	'%e2%80%8c',
	'%e2%80%8d',
	'%e2%80%8e',
	'%e2%80%8f',
	'%e2%80%aa',
	'%e2%80%ab',
	'%e2%80%ac',
	'%e2%80%ad',
	'%e2%80%ae',
	'%ef%bb%bf',
	'%ef%bf%bc',
];

/**
 * Percent-encode the non-ASCII characters, as core's `utf8_uri_encode()`.
 *
 * Stops before the encoded text would pass 200 characters, as core does.
 *
 * @since 1.0.0
 *
 * @param {string} text The text.
 *
 * @return {string} The encoded text.
 */
function encode( text: string ): string {
	let out = '';

	for ( const char of text ) {
		const part =
			char.charCodeAt( 0 ) < 128
				? char
				: encodeURIComponent( char ).toLowerCase();

		if ( out.length + part.length > 200 ) {
			break;
		}

		out += part;
	}

	return out;
}

/**
 * Replace every occurrence of each string in a list.
 *
 * @since 1.0.0
 *
 * @param {string}   text The text.
 * @param {string[]} list The strings to replace.
 * @param {string}   by   What to put in their place.
 *
 * @return {string} The text.
 */
function replaceAll( text: string, list: string[], by: string ): string {
	return list.reduce( ( out, item ) => out.split( item ).join( by ), text );
}

/**
 * The slug WordPress makes from a name.
 *
 * @since 1.0.0
 *
 * @param {string} name The category name.
 *
 * @return {string} The slug, empty when nothing of the name is kept.
 */
export function slugify( name: string ): string {
	let slug = name
		.normalize( 'NFC' )
		.replace( /[^\u0000-\u007f]/g, ( char ) => LETTERS[ char ] ?? char )
		.replace( ACCENTED, ( char ) =>
			char.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
		)
		.replace( /<[^>]*>/g, '' )
		.replace( /%([a-fA-F0-9]{2})/g, '---$1---' )
		.replace( /%/g, '' )
		.replace( /---([a-fA-F0-9]{2})---/g, '%$1' )
		.toLowerCase();

	slug = encode( slug );
	slug = replaceAll( slug, DASHES, '-' );
	slug = replaceAll( slug, DROPPED, '' );
	slug = slug.split( '%c3%97' ).join( 'x' );

	return slug
		.replace( /&.+?;/g, '' )
		.replace( /\./g, '-' )
		.replace( /[^%a-z0-9 _-]/g, '' )
		.replace( /\s+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}
