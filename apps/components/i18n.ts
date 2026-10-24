/**
 * Translation helpers for the content manager apps.
 *
 * The apps are built by Vite, which would bundle its own copy of
 * `@wordpress/i18n` if it were imported. That copy never receives the strings
 * `wp_set_script_translations()` loads into the page's global `wp.i18n`, so
 * every string would stay in English. These helpers call the global instead,
 * and fall back to the untranslated text when it is missing.
 *
 * Call them with literal strings and the text domain, exactly as with
 * `@wordpress/i18n`, so the string extractor and the text domain lint rule
 * see every call:
 *
 *     __( 'Save', 'licensio-knowledge-base' )
 *
 * The page must enqueue its app with the `wp-i18n` script dependency.
 *
 * Consumed by the shared components and the articles, categories and tags apps.
 *
 * @since 1.0.0
 */

interface Translator {
	__?: ( text: string, domain?: string ) => string;
	_n?: (
		single: string,
		plural: string,
		number: number,
		domain?: string
	) => string;
	sprintf?: ( format: string, ...args: Array< string | number > ) => string;
}

/**
 * The page's global `wp.i18n`, or an empty object when it is missing.
 *
 * @since 1.0.0
 *
 * @return {Translator} The translation functions available.
 */
function translator(): Translator {
	return (
		( window as unknown as { wp?: { i18n?: Translator } } ).wp?.i18n ?? {}
	);
}

/**
 * Translate a string.
 *
 * @since 1.0.0
 *
 * @param {string} text   The untranslated string.
 * @param {string} domain The text domain, always `licensio-knowledge-base`.
 *
 * @return {string} The translated string.
 */
export function __( text: string, domain?: string ): string {
	const translate = translator().__;

	return translate ? translate( text, domain ) : text;
}

/**
 * Translate a string with a singular and a plural form.
 *
 * Falls back to the English rule (one is singular) when `wp.i18n` is missing.
 *
 * @since 1.0.0
 *
 * @param {string} single The singular form.
 * @param {string} plural The plural form.
 * @param {number} number The count that picks the form.
 * @param {string} domain The text domain, always `licensio-knowledge-base`.
 *
 * @return {string} The translated form.
 */
export function _n(
	single: string,
	plural: string,
	number: number,
	domain?: string
): string {
	const translate = translator()._n;

	if ( translate ) {
		return translate( single, plural, number, domain );
	}

	return 1 === number ? single : plural;
}

/**
 * Fill the placeholders in a translated format string.
 *
 * Delegates to `wp.i18n.sprintf`, which the page's `wp-i18n` script
 * dependency provides.
 *
 * @since 1.0.0
 *
 * @param {string}           format The format string, usually from `__()`.
 * @param {...string|number} args   The values for the placeholders.
 *
 * @return {string} The filled string.
 */
export function sprintf(
	format: string,
	...args: Array< string | number >
): string {
	const format_ = translator().sprintf;

	return format_ ? format_( format, ...args ) : format;
}
