/**
 * REST client for the feedback inbox.
 *
 * A thin wrapper over `fetch` rather than a HTTP library: the inbox makes two
 * kinds of request, both to the same origin, and every one of them carries the
 * `X-WP-Nonce` header the endpoint's permission callback checks.
 *
 * @since 1.0.0
 */

import type {
	FeedbackItem,
	FeedbackPage,
	FeedbackStatus,
	InboxSettings,
} from './types';

declare global {
	interface Window {
		licensioKBFeedbackInbox?: InboxSettings;
	}
}

/**
 * The configuration PHP localised onto the page.
 *
 * @since 1.0.0
 *
 * @return {InboxSettings} The inbox settings, with safe fallbacks.
 */
export function settings(): InboxSettings {
	return (
		window.licensioKBFeedbackInbox ?? {
			root: '',
			nonce: '',
			perPage: 20,
			statuses: [ 'new', 'reviewed', 'actioned' ],
		}
	);
}

/**
 * Run one request against the feedback endpoint.
 *
 * @since 1.0.0
 *
 * @param {string} path Path appended to the endpoint root.
 * @param {Object} init Fetch options.
 *
 * @return {Promise<unknown>} The decoded response body.
 */
async function request(
	path: string,
	init: RequestInit = {}
): Promise< unknown > {
	const config = settings();
	const response = await window.fetch( config.root + path, {
		credentials: 'same-origin',
		...init,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
			...( init.headers ?? {} ),
		},
	} );

	const body = await response.json();

	if ( ! response.ok ) {
		const message =
			body && typeof body.message === 'string'
				? body.message
				: 'The request failed.';

		throw new Error( message );
	}

	return body;
}

export interface ListQuery {
	vote: number;
	status: FeedbackStatus | '';
	page: number;
	perPage: number;
}

/**
 * Fetch one page of feedback.
 *
 * @since 1.0.0
 *
 * @param {ListQuery} query The active filters and page.
 *
 * @return {Promise<FeedbackPage>} The page of rows.
 */
export async function fetchPage( query: ListQuery ): Promise< FeedbackPage > {
	const params = new URLSearchParams( {
		vote: String( query.vote ),
		status: query.status,
		page: String( query.page ),
		per_page: String( query.perPage ),
	} );

	// With plain permalinks the root is `index.php?rest_route=...` and already has a query string.
	const separator = settings().root.includes( '?' ) ? '&' : '?';

	return ( await request( separator + params.toString() ) ) as FeedbackPage;
}

/**
 * Move one row to a new triage status.
 *
 * @since 1.0.0
 *
 * @param {number}         id     The row to update.
 * @param {FeedbackStatus} status The status to set.
 *
 * @return {Promise<FeedbackItem>} The updated row.
 */
export async function patchStatus(
	id: number,
	status: FeedbackStatus
): Promise< FeedbackItem > {
	return ( await request( '/' + String( id ), {
		method: 'PATCH',
		body: JSON.stringify( { status } ),
	} ) ) as FeedbackItem;
}
