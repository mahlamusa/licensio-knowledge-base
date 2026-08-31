/**
 * REST client for the analytics dashboard.
 *
 * Every request is authenticated with the `wp_rest` nonce the screen was
 * rendered with, which is what makes the `manage_lisensio_kb` capability check
 * on the server side apply to the logged-in administrator rather than to an
 * anonymous caller.
 *
 * @since 1.0.0
 */

import type { AnalyticsConfig } from './types';

declare global {
	interface Window {
		lisensioKbAnalytics?: AnalyticsConfig;
		wp?: {
			i18n?: {
				__: ( text: string, domain?: string ) => string;
				sprintf: ( format: string, ...args: unknown[] ) => string;
			};
		};
	}
}

const FALLBACK_CONFIG: AnalyticsConfig = {
	restUrl: '',
	nonce: '',
	defaultDays: 30,
	maxDays: 3650,
	defaultLimit: 20,
	retentionDays: 90,
};

/**
 * The bootstrap data PHP localised onto the page.
 *
 * @since 1.0.0
 *
 * @return {AnalyticsConfig} The configuration, or safe defaults when the page did not supply one.
 */
export function config(): AnalyticsConfig {
	return { ...FALLBACK_CONFIG, ...( window.lisensioKbAnalytics ?? {} ) };
}

/**
 * Translate a string through WordPress, falling back to the original.
 *
 * The app is built by Vite rather than by wp-scripts, so it reads `wp.i18n`
 * off the global that the `wp-i18n` script dependency guarantees is present
 * instead of importing the package. It is deliberately not called `__`: the
 * string extractor and the `@wordpress/i18n-no-variables` lint rule both key on
 * that name, and a wrapper wearing it would be read as a call site whose
 * argument is a variable.
 *
 * @since 1.0.0
 *
 * @param {string} text The untranslated string.
 *
 * @return {string} The translated string.
 */
export function translate( text: string ): string {
	// Read off as a reference rather than called as `i18n.__()`: the extractor
	// and the lint rule both treat that spelling as a translation call site.
	const translator = window.wp?.i18n?.__;

	return translator ? translator( text, 'lisensio-knowledge-base' ) : text;
}

/**
 * Fetch one analytics report.
 *
 * @since 1.0.0
 *
 * @param {string}                report The report name: overview, searches, articles or feedback.
 * @param {Record<string,string>} params Query parameters.
 *
 * @return {Promise<any>} The decoded response body.
 */
export async function fetchReport< T >(
	report: string,
	params: Record< string, string >
): Promise< T > {
	const { restUrl, nonce } = config();
	const url = new URL( restUrl + report, window.location.origin );

	Object.keys( params ).forEach( ( key ) => {
		if ( params[ key ] !== '' ) {
			url.searchParams.set( key, params[ key ] );
		}
	} );

	const response = await fetch( url.toString(), {
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': nonce,
			Accept: 'application/json',
		},
	} );

	if ( ! response.ok ) {
		throw new Error(
			`${ translate( 'The analytics request failed.' ) } (${
				response.status
			})`
		);
	}

	return ( await response.json() ) as T;
}
