/**
 * REST calls of the Articles app.
 *
 * Typed wrappers over the shared `rest.ts` client for the article routes of
 * `licensio-kb/v1` (`Licensio_KB_Articles_REST_Controller`). `configure()`
 * must have run first; `index.ts` does that before mounting.
 *
 * @since 1.0.0
 */

import { get, patch, PER_PAGE, post, put } from '../components/rest';
import type { Query } from '../components/rest';
import type {
	ArticleFilters,
	ArticleIds,
	ArticleItem,
	ArticlePage,
	ArticleQuery,
	ArticlesConfig,
	AuthorPage,
	BulkRequest,
	BulkResult,
	ListPreferences,
	QuickEditPayload,
	RevertRequest,
	RevertResult,
} from './types';

declare global {
	interface Window {
		licensioKbArticles?: Partial< ArticlesConfig >;
	}
}

const FALLBACK_CONFIG: ArticlesConfig = {
	root: '',
	nonce: '',
	settingsUrl: '',
	newUrl: '',
	feedbackUrl: '',
	can: {
		editOthers: true,
		publish: true,
		createTags: true,
	},
	preferences: {
		columns: [
			'author',
			'categories',
			'tags',
			'attachments',
			'rating',
			'views',
			'reading_time',
			'date',
		],
		per_page: PER_PAGE,
		columns_chosen: false,
		medium_columns: [],
	},
	lowRating: { threshold: 50, minVotes: 5 },
};

/**
 * The configuration PHP localised onto the page, with safe fallbacks.
 *
 * @since 1.0.0
 *
 * @return {ArticlesConfig} The app configuration.
 */
export function config(): ArticlesConfig {
	const given = window.licensioKbArticles ?? {};
	const lowRating = given.lowRating ?? FALLBACK_CONFIG.lowRating;

	return {
		...FALLBACK_CONFIG,
		...given,
		preferences: given.preferences ?? FALLBACK_CONFIG.preferences,
		can: { ...FALLBACK_CONFIG.can, ...( given.can ?? {} ) },
		lowRating: {
			threshold: Number( lowRating.threshold ),
			minVotes: Number( lowRating.minVotes ),
		},
	};
}

/**
 * The filters as query arguments; empty and false values are left out.
 *
 * @since 1.0.0
 *
 * @param {ArticleFilters} filters The filters.
 *
 * @return {Query} The query arguments.
 */
function filterQuery( filters: ArticleFilters ): Query {
	return {
		status: filters.status,
		search: filters.search || undefined,
		category: filters.category || undefined,
		tag: filters.tag || undefined,
		author: filters.author || undefined,
		after: filters.after || undefined,
		before: filters.before || undefined,
		uncategorised: filters.uncategorised ? 1 : undefined,
		untagged: filters.untagged ? 1 : undefined,
		needs_attention: filters.needs_attention ? 1 : undefined,
		window: filters.window,
	};
}

/**
 * Fetch one page of articles.
 *
 * @since 1.0.0
 *
 * @param {ArticleQuery} query The filters, sort and page.
 *
 * @return {Promise<ArticlePage>} The page.
 */
export function fetchArticles( query: ArticleQuery ): Promise< ArticlePage > {
	return get< ArticlePage >( 'articles', {
		...filterQuery( query ),
		orderby: query.orderby,
		order: query.order,
		page: query.page,
		per_page: query.per_page,
	} );
}

/**
 * Fetch every matching article ID.
 *
 * @since 1.0.0
 *
 * @param {ArticleFilters} filters The filters.
 *
 * @return {Promise<ArticleIds>} The IDs, capped by the server.
 */
export function fetchIds( filters: ArticleFilters ): Promise< ArticleIds > {
	return get< ArticleIds >( 'articles/ids', filterQuery( filters ) );
}

/**
 * Save a quick edit.
 *
 * @since 1.0.0
 *
 * @param {number}           id      The article.
 * @param {QuickEditPayload} payload The changed fields.
 *
 * @return {Promise<ArticleItem>} The updated article.
 */
export function updateArticle(
	id: number,
	payload: QuickEditPayload
): Promise< ArticleItem > {
	return patch< ArticleItem >( `articles/${ id }`, payload );
}

/**
 * Run one bulk batch.
 *
 * @since 1.0.0
 *
 * @param {BulkRequest} request At most 100 IDs, the action and its args.
 *
 * @return {Promise<BulkResult>} What was done, skipped and failed.
 */
export function runBulk( request: BulkRequest ): Promise< BulkResult > {
	return post< BulkResult >( 'articles/bulk', request );
}

/**
 * Undo one bulk batch.
 *
 * @since 1.0.0
 *
 * @param {RevertRequest} request The batch's undo token.
 *
 * @return {Promise<RevertResult>} What was reverted.
 */
export function revert( request: RevertRequest ): Promise< RevertResult > {
	return post< RevertResult >( 'articles/revert', request );
}

/**
 * Search the users who can edit articles.
 *
 * @since 1.0.0
 *
 * @param {string}   search  The search text.
 * @param {number[]} include IDs to include, e.g. to label a chosen author.
 *
 * @return {Promise<AuthorPage>} The matching users.
 */
export function fetchAuthors(
	search = '',
	include: number[] = []
): Promise< AuthorPage > {
	return get< AuthorPage >( 'authors', {
		search: search || undefined,
		include: include.length ? include : undefined,
		per_page: 20,
	} );
}

/**
 * Save the current user's list preferences.
 *
 * @since 1.0.0
 *
 * @param {ListPreferences} preferences The columns and rows per page.
 *
 * @return {Promise<ListPreferences>} The saved preferences.
 */
export function savePreferences(
	preferences: ListPreferences
): Promise< ListPreferences > {
	return put< ListPreferences >( 'preferences', preferences );
}
