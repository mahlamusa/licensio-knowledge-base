/**
 * Shared REST client for the content manager apps.
 *
 * A thin wrapper over `fetch` for the `licensio-kb/v1` namespace. Every
 * request carries the `X-WP-Nonce` header, sends and reads JSON, and turns an
 * error response into a `RestError` that keeps the server's error code, HTTP
 * status and data, so a caller can tell a locked article (409) or a field
 * error (400 with `data.params`) from any other failure. A success answer
 * that is not JSON is an error too. `errorMessage()` gives the text to show
 * for anything a request threw.
 *
 * Each app calls `configure()` once, from its `index.ts`, with the root and
 * nonce its PHP page localised:
 *
 *     configure( { root: window.licensioKbArticles.root, nonce: window.licensioKbArticles.nonce } );
 *
 * `root` is the namespace URL, `Licensio_KB_REST_API::url()` in PHP. With plain
 * permalinks it is `index.php?rest_route=/licensio-kb/v1/` and already has a
 * query string, so query arguments are joined with `&` instead of `?`, as in
 * the feedback inbox.
 *
 * Consumed by the shared components and the articles, categories and tags apps.
 *
 * @since 1.0.0
 */

import { __ } from './i18n';

/**
 * Where requests go and the nonce they carry.
 */
export interface RestConfig {
	root: string;
	nonce: string;
}

/**
 * A query argument value. Arrays are sent as `key[]=a&key[]=b`; null and
 * undefined are left out.
 */
export type QueryValue =
	| string
	| number
	| boolean
	| Array< string | number >
	| null
	| undefined;

export type Query = Record< string, QueryValue >;

/** The rows per page of a list app, when the page set no other. */
export const PER_PAGE = 20;

/**
 * A request the server refused, with what it said about it.
 *
 * @since 1.0.0
 */
export class RestError extends Error {
	/** The server's error code, e.g. `licensio_kb_not_implemented`. */
	public readonly code: string;

	/** The HTTP status, or 0 when the request never got an answer. */
	public readonly status: number;

	/** The error's `data`, e.g. `{ status, params }` for a validation error. */
	public readonly data: unknown;

	/**
	 * Build the error.
	 *
	 * @since 1.0.0
	 *
	 * @param {string}  message The message to show.
	 * @param {string}  code    The server's error code.
	 * @param {number}  status  The HTTP status.
	 * @param {unknown} data    The error's data.
	 */
	public constructor(
		message: string,
		code: string,
		status: number,
		data: unknown = null
	) {
		super( message );

		this.name = 'RestError';
		this.code = code;
		this.status = status;
		this.data = data;
	}
}

let config: RestConfig = { root: '', nonce: '' };

/**
 * The message to show for a failed request.
 *
 * @since 1.0.0
 *
 * @param {unknown} error What was thrown.
 *
 * @return {string} The error's message, or the thrown value as text.
 */
export function errorMessage( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

/**
 * Set the root and nonce every later request uses.
 *
 * @since 1.0.0
 *
 * @param {RestConfig} next The namespace URL and the `wp_rest` nonce.
 *
 * @return {void}
 */
export function configure( next: RestConfig ): void {
	config = { root: next.root, nonce: next.nonce };
}

/**
 * The full URL of a route, with its query arguments.
 *
 * @since 1.0.0
 *
 * @param {string} path  The route below the namespace, e.g. `terms`.
 * @param {Query}  query Optional query arguments.
 *
 * @return {string} The URL.
 */
export function url( path: string, query: Query = {} ): string {
	const base =
		config.root.replace( /\/+$/, '' ) + '/' + path.replace( /^\/+/, '' );
	const params = new URLSearchParams();

	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value === null || value === undefined ) {
			return;
		}

		if ( Array.isArray( value ) ) {
			value.forEach( ( item ) =>
				params.append( key + '[]', String( item ) )
			);
			return;
		}

		params.append( key, String( value ) );
	} );

	const search = params.toString();

	if ( '' === search ) {
		return base;
	}

	return base + ( base.includes( '?' ) ? '&' : '?' ) + search;
}

/**
 * Send one request and decode the answer.
 *
 * @since 1.0.0
 *
 * @template T
 *
 * @param {string}  method The HTTP method.
 * @param {string}  path   The route below the namespace.
 * @param {Query}   query  Query arguments.
 * @param {unknown} body   The JSON body, if any.
 *
 * @return {Promise<T>} The decoded body; undefined for an empty answer.
 * @throws {RestError} When the request fails, the server refuses it, or a
 *                     success answer is not JSON.
 */
async function request< T >(
	method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
	path: string,
	query: Query = {},
	body?: unknown
): Promise< T > {
	const headers: Record< string, string > = {
		Accept: 'application/json',
		'X-WP-Nonce': config.nonce,
	};

	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	let response: Response;

	try {
		response = await window.fetch( url( path, query ), {
			method,
			credentials: 'same-origin',
			headers,
			body: body !== undefined ? JSON.stringify( body ) : undefined,
		} );
	} catch {
		throw new RestError(
			__(
				'The server could not be reached. Check your connection and try again.',
				'licensio-knowledge-base'
			),
			'licensio_kb_network_error',
			0
		);
	}

	const text = await response.text();
	let payload: unknown = null;

	if ( '' !== text ) {
		try {
			payload = JSON.parse( text );
		} catch {
			// A success answer that is not JSON is a PHP notice or a page
			// some other code printed, not data the caller can use.
			if ( response.ok ) {
				throw new RestError(
					__(
						'The server sent an answer that could not be read. Reload the page and try again.',
						'licensio-knowledge-base'
					),
					'licensio_kb_invalid_response',
					response.status
				);
			}

			payload = null;
		}
	}

	if ( ! response.ok ) {
		const error = ( payload ?? {} ) as {
			code?: unknown;
			message?: unknown;
			data?: unknown;
		};

		throw new RestError(
			typeof error.message === 'string' && '' !== error.message
				? error.message
				: __( 'The request failed.', 'licensio-knowledge-base' ),
			typeof error.code === 'string'
				? error.code
				: 'licensio_kb_request_failed',
			response.status,
			error.data ?? null
		);
	}

	return ( '' === text ? undefined : payload ) as T;
}

/**
 * GET a route.
 *
 * @since 1.0.0
 *
 * @template T
 *
 * @param {string} path  The route below the namespace.
 * @param {Query}  query Query arguments.
 *
 * @return {Promise<T>} The decoded body.
 */
export function get< T >( path: string, query: Query = {} ): Promise< T > {
	return request< T >( 'GET', path, query );
}

/**
 * POST a JSON body to a route.
 *
 * @since 1.0.0
 *
 * @template T
 *
 * @param {string}  path  The route below the namespace.
 * @param {unknown} body  The JSON body.
 * @param {Query}   query Query arguments.
 *
 * @return {Promise<T>} The decoded body.
 */
export function post< T >(
	path: string,
	body: unknown = {},
	query: Query = {}
): Promise< T > {
	return request< T >( 'POST', path, query, body );
}

/**
 * PATCH a route with a JSON body.
 *
 * @since 1.0.0
 *
 * @template T
 *
 * @param {string}  path  The route below the namespace.
 * @param {unknown} body  The JSON body.
 * @param {Query}   query Query arguments.
 *
 * @return {Promise<T>} The decoded body.
 */
export function patch< T >(
	path: string,
	body: unknown = {},
	query: Query = {}
): Promise< T > {
	return request< T >( 'PATCH', path, query, body );
}

/**
 * PUT a JSON body to a route.
 *
 * @since 1.0.0
 *
 * @template T
 *
 * @param {string}  path  The route below the namespace.
 * @param {unknown} body  The JSON body.
 * @param {Query}   query Query arguments.
 *
 * @return {Promise<T>} The decoded body.
 */
export function put< T >(
	path: string,
	body: unknown = {},
	query: Query = {}
): Promise< T > {
	return request< T >( 'PUT', path, query, body );
}

/**
 * DELETE a route.
 *
 * Named `del` because `delete` is a reserved word.
 *
 * @since 1.0.0
 *
 * @template T
 *
 * @param {string} path  The route below the namespace.
 * @param {Query}  query Query arguments, e.g. where to reassign articles.
 *
 * @return {Promise<T>} The decoded body.
 */
export function del< T >( path: string, query: Query = {} ): Promise< T > {
	return request< T >( 'DELETE', path, query );
}
