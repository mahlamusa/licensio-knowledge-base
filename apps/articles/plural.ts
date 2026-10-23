/**
 * Plural translation for the Articles app.
 *
 * The shared `i18n.ts` helpers cover `__` and `sprintf`. Counts such as
 * "Move 3 articles to the trash?" need the plural form, so this calls the
 * page's global `wp.i18n._n` the same way, and falls back to English rules.
 *
 * Call it with literal strings and the text domain, as with `@wordpress/i18n`.
 *
 * @since 1.0.0
 */

type Plural = (
	single: string,
	plural: string,
	number: number,
	domain?: string
) => string;

/**
 * Translate a string with a singular and a plural form.
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
	const translate = (
		window as unknown as { wp?: { i18n?: { _n?: Plural } } }
	 ).wp?.i18n?._n;

	if ( translate ) {
		return translate( single, plural, number, domain );
	}

	return 1 === number ? single : plural;
}
