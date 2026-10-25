/**
 * REST client for the analytics dashboard.
 *
 * Every request is authenticated with the `wp_rest` nonce the screen was
 * rendered with, which is what makes the `manage_licensio_kb` capability check
 * on the server side apply to the logged-in administrator rather than to an
 * anonymous caller.
 *
 * @since 1.0.0
 */

import type { AnalyticsConfig } from './types';
import { __ } from '../components/i18n';

declare global {
	interface Window {
		licensioKbAnalytics?: AnalyticsConfig;
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
	return { ...FALLBACK_CONFIG, ...( window.licensioKbAnalytics ?? {} ) };
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
			`${ __(
				'The analytics request failed.',
				'licensio-knowledge-base'
			) } (${ response.status })`
		);
	}

	return ( await response.json() ) as T;
}
