/**
 * URL state of the Articles app.
 *
 * The filters, sort, list page and metrics window live in the address bar, so
 * reload, back/forward and a pasted link restore the view. The WordPress
 * admin page argument `page=licensio-kb-articles` is always kept, which is why
 * the list page number is written as `paged`. Default values are left out.
 *
 * The keys `status`, `search`, `category`, `tag`, `author`, `orderby`,
 * `order` and `paged` are the ones the core screen redirects produce, so a
 * redirected request opens the right view. So are the extension filters'
 * `ext_filters[{key}]`, read only for the filters the page declared.
 *
 * @since 1.0.0
 * @since 1.1.0 Keeps a sort by a sortable extension column, and reads and writes the extension filters.
 */

import type { ExtensionFilter } from '../components/extensions';
import { readExtFilters, writeExtFilters } from './ext-filters';
import type {
	ArticleOrderBy,
	ArticleQuery,
	ExtensionColumnKey,
	ListOrderBy,
	MetricsWindow,
	PerPage,
	StatusFilter,
} from './types';

const STATUSES: StatusFilter[] = [
	'all',
	'publish',
	'future',
	'draft',
	'pending',
	'private',
	'trash',
];

/** The columns the list can be sorted by. */
export const ORDER_BY: ArticleOrderBy[] = [
	'date',
	'modified',
	'title',
	'views',
	'rating',
];

const WINDOWS: MetricsWindow[] = [ 7, 30, 90 ];

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * The view every key falls back to.
 *
 * @since 1.0.0
 *
 * @param {PerPage} perPage The user's rows per page.
 *
 * @return {ArticleQuery} The default query.
 */
export function defaultQuery( perPage: PerPage ): ArticleQuery {
	return {
		status: 'all',
		search: '',
		category: 0,
		tag: 0,
		author: 0,
		after: '',
		before: '',
		uncategorised: false,
		untagged: false,
		needs_attention: false,
		window: 30,
		ext_filters: {},
		orderby: 'date',
		order: 'desc',
		page: 1,
		per_page: perPage,
	};
}

/**
 * A positive integer from a query value, or 0.
 *
 * @since 1.0.0
 *
 * @param {string|null} value The raw value.
 *
 * @return {number} The integer.
 */
function positive( value: string | null ): number {
	const number = Number.parseInt( value ?? '', 10 );

	return Number.isFinite( number ) && number > 0 ? number : 0;
}

/**
 * Read the view from a query string.
 *
 * Unknown or malformed values fall back to their defaults.
 *
 * @since 1.0.0
 * @since 1.1.0 Takes the sortable extension columns, which `orderby` may name, and reads the extension filters the page declared.
 *
 * @param {string}               search           `location.search`.
 * @param {PerPage}              perPage          The user's rows per page.
 * @param {ExtensionColumnKey[]} extension        The sortable extension column keys.
 * @param {ExtensionFilter[]}    extensionFilters The extension filters the page declared.
 *
 * @return {ArticleQuery} The query.
 */
export function readQuery(
	search: string,
	perPage: PerPage,
	extension: ExtensionColumnKey[],
	extensionFilters: ExtensionFilter[]
): ArticleQuery {
	const params = new URLSearchParams( search );
	const query = defaultQuery( perPage );
	const sorts: ListOrderBy[] = [ ...ORDER_BY, ...extension ];

	const status = params.get( 'status' ) as StatusFilter | null;
	const orderby = params.get( 'orderby' ) as ListOrderBy | null;
	const order = params.get( 'order' );
	const window = positive( params.get( 'window' ) ) as MetricsWindow;
	const after = params.get( 'after' ) ?? '';
	const before = params.get( 'before' ) ?? '';

	if ( status && STATUSES.includes( status ) ) {
		query.status = status;
	}

	if ( orderby && sorts.includes( orderby ) ) {
		query.orderby = orderby;
	}

	if ( 'asc' === order || 'desc' === order ) {
		query.order = order;
	}

	if ( WINDOWS.includes( window ) ) {
		query.window = window;
	}

	query.search = params.get( 'search' ) ?? '';
	query.category = positive( params.get( 'category' ) );
	query.tag = positive( params.get( 'tag' ) );
	query.author = positive( params.get( 'author' ) );
	query.after = DATE.test( after ) ? after : '';
	query.before = DATE.test( before ) ? before : '';
	query.uncategorised = '1' === params.get( 'uncategorised' );
	query.untagged = '1' === params.get( 'untagged' );
	query.needs_attention = '1' === params.get( 'needs_attention' );
	query.page = Math.max( 1, positive( params.get( 'paged' ) ) );
	query.ext_filters = readExtFilters( params, extensionFilters );

	return query;
}

/**
 * Write the view into a URL, keeping every argument the app does not own.
 *
 * @since 1.0.0
 * @since 1.1.0 Writes the extension filters.
 *
 * @param {string}       href  The current URL.
 * @param {ArticleQuery} query The view.
 *
 * @return {string} The new URL.
 */
export function writeQuery( href: string, query: ArticleQuery ): string {
	const url = new URL( href );
	const params = url.searchParams;
	const defaults = defaultQuery( query.per_page );

	const values: Record< string, string > = {
		status: query.status !== defaults.status ? query.status : '',
		search: query.search.trim(),
		category: query.category ? String( query.category ) : '',
		tag: query.tag ? String( query.tag ) : '',
		author: query.author ? String( query.author ) : '',
		after: query.after,
		before: query.before,
		uncategorised: query.uncategorised ? '1' : '',
		untagged: query.untagged ? '1' : '',
		needs_attention: query.needs_attention ? '1' : '',
		window: query.window !== defaults.window ? String( query.window ) : '',
		orderby: query.orderby !== defaults.orderby ? query.orderby : '',
		order: query.order !== defaults.order ? query.order : '',
		paged: query.page > 1 ? String( query.page ) : '',
	};

	Object.entries( values ).forEach( ( [ key, value ] ) => {
		if ( '' === value ) {
			params.delete( key );
		} else {
			params.set( key, value );
		}
	} );

	writeExtFilters( params, query.ext_filters );

	return url.toString();
}
