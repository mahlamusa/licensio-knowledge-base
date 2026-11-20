/**
 * Unit tests of the Articles app's extension context and row refresh.
 *
 * `refresh( ids )` fetches only the rows on the page again, with the current
 * filters, and swaps them in; the whole page reloads when no IDs are given or
 * a row no longer matches. `clearSelection()` drops the selection, and
 * `screen` and `rest` come from the page's config.
 *
 * @since 1.1.0
 */

import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchArticles } from '../../apps/articles/api';
import { articlesExtensionContext } from '../../apps/articles/extension-context';
import { useArticlesStore } from '../../apps/articles/store';
import type { ArticleItem, ArticlePage } from '../../apps/articles/types';

vi.mock( '../../apps/articles/api', async ( importOriginal ) => ( {
	...( await importOriginal< typeof import( '../../apps/articles/api' ) >() ),
	fetchArticles: vi.fn(),
} ) );

/**
 * A row with only the fields these tests read.
 *
 * @since 1.1.0
 *
 * @param {number} id    The row ID.
 * @param {string} title The title.
 *
 * @return {ArticleItem} The row.
 */
function item( id: number, title: string ): ArticleItem {
	return { id, title } as ArticleItem;
}

/**
 * A page of rows.
 *
 * @since 1.1.0
 *
 * @param {ArticleItem[]} items The rows.
 *
 * @return {ArticlePage} The page.
 */
function page( items: ArticleItem[] ): ArticlePage {
	return { items, total: items.length, pages: 1 } as ArticlePage;
}

/**
 * Answer row fetches (with IDs) and page loads (without) differently.
 *
 * @since 1.1.0
 *
 * @param {ArticlePage|Promise<ArticlePage>} rows     The answer to a row fetch.
 * @param {ArticlePage}                      fullPage The answer to a page load.
 *
 * @return {void}
 */
function answer(
	rows: ArticlePage | Promise< ArticlePage >,
	fullPage: ArticlePage = page( [] )
): void {
	vi.mocked( fetchArticles ).mockImplementation( ( query, include = [] ) =>
		include.length ? Promise.resolve( rows ) : Promise.resolve( fullPage )
	);
}

/**
 * The ID lists of the row fetches made so far.
 *
 * @since 1.1.0
 *
 * @return {number[][]} One list per row fetch.
 */
function rowFetches(): number[][] {
	return vi
		.mocked( fetchArticles )
		.mock.calls.map( ( call ) => call[ 1 ] ?? [] )
		.filter( ( ids ) => ids.length > 0 );
}

/**
 * How many whole-page loads were made so far.
 *
 * @since 1.1.0
 *
 * @return {number} The count.
 */
function pageLoads(): number {
	return vi
		.mocked( fetchArticles )
		.mock.calls.filter( ( call ) => ! call[ 1 ]?.length ).length;
}

beforeEach( () => {
	vi.mocked( fetchArticles ).mockReset();
	window.licensioKbArticles = {
		root: '/wp-json/licensio-kb/v1/',
		nonce: 'abc',
	};
	setActivePinia( createPinia() );
} );

describe( 'articlesExtensionContext', () => {
	it( 'names the screen and passes the REST root and nonce', () => {
		const ctx = articlesExtensionContext();

		expect( ctx.screen ).toBe( 'articles' );
		expect( ctx.rest ).toEqual( {
			root: '/wp-json/licensio-kb/v1/',
			nonce: 'abc',
		} );
	} );

	it( 'clears the selection', () => {
		const store = useArticlesStore();

		store.selected = [ 1, 2 ];
		articlesExtensionContext().clearSelection();

		expect( store.selected ).toEqual( [] );
	} );

	it( 'refreshes only the given rows, with the current filters', async () => {
		const store = useArticlesStore();

		store.items = [ item( 1, 'One' ), item( 2, 'Two' ) ];
		store.query = { ...store.query, search: 'needle' };
		answer( page( [ item( 2, 'Two, changed' ) ] ) );

		await articlesExtensionContext().refresh( [ 2 ] );

		expect( fetchArticles ).toHaveBeenCalledWith( store.query, [ 2 ] );
		expect( pageLoads() ).toBe( 0 );
		expect( store.items.map( ( row ) => row.title ) ).toEqual( [
			'One',
			'Two, changed',
		] );
	} );

	it( 'reloads the page when no IDs are given', async () => {
		const store = useArticlesStore();

		answer( page( [] ), page( [ item( 3, 'Three' ) ] ) );

		await articlesExtensionContext().refresh();

		expect( rowFetches() ).toEqual( [] );
		expect( store.items.map( ( row ) => row.id ) ).toEqual( [ 3 ] );
	} );
} );

describe( 'refreshRows', () => {
	it( 'reloads the page when a row no longer matches the filters', async () => {
		const store = useArticlesStore();

		store.items = [ item( 1, 'One' ), item( 2, 'Two' ) ];
		answer( page( [] ), page( [ item( 1, 'One' ) ] ) );

		await store.refreshRows( [ 2 ] );

		expect( pageLoads() ).toBe( 1 );
		expect( store.items.map( ( row ) => row.id ) ).toEqual( [ 1 ] );
	} );

	it( 'asks for a repeated ID once and does not reload for it', async () => {
		const store = useArticlesStore();

		store.items = [ item( 5, 'Five' ) ];
		answer( page( [ item( 5, 'Five, changed' ) ] ) );

		await store.refreshRows( [ 5, 5 ] );

		expect( rowFetches() ).toEqual( [ [ 5 ] ] );
		expect( pageLoads() ).toBe( 0 );
	} );

	it( 'fetches nothing for rows that are not on the page', async () => {
		const store = useArticlesStore();

		store.items = [ item( 1, 'One' ) ];

		await store.refreshRows( [ 9 ] );

		expect( fetchArticles ).not.toHaveBeenCalled();
	} );

	it( 'drops an answer that a page load overtook', async () => {
		const store = useArticlesStore();
		let resolveRows: ( value: ArticlePage ) => void = () => undefined;

		store.items = [ item( 1, 'One' ) ];
		answer(
			new Promise( ( resolve ) => {
				resolveRows = resolve;
			} ),
			page( [ item( 1, 'One, reloaded' ) ] )
		);

		const refreshing = store.refreshRows( [ 1 ] );

		await store.load();
		resolveRows( page( [ item( 1, 'One, stale' ) ] ) );
		await refreshing;

		expect( store.items[ 0 ].title ).toBe( 'One, reloaded' );
	} );
} );
