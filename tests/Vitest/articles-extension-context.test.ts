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
import { fetchArticles, fetchRows } from '../../apps/articles/api';
import { articlesExtensionContext } from '../../apps/articles/extension-context';
import { useArticlesStore } from '../../apps/articles/store';
import type { ArticleItem, ArticlePage } from '../../apps/articles/types';

vi.mock( '../../apps/articles/api', async ( importOriginal ) => ( {
	...( await importOriginal< typeof import( '../../apps/articles/api' ) >() ),
	fetchArticles: vi.fn(),
	fetchRows: vi.fn(),
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

beforeEach( () => {
	vi.mocked( fetchArticles ).mockReset();
	vi.mocked( fetchRows ).mockReset();
	window.licensioKbArticles = {
		root: '/wp-json/licensio-kb/v1/',
		nonce: 'abc',
	};
	setActivePinia( createPinia() );
} );

describe( 'articlesExtensionContext', () => {
	it( 'names the screen and passes the REST root and nonce', () => {
		const ctx = articlesExtensionContext( useArticlesStore() );

		expect( ctx.screen ).toBe( 'articles' );
		expect( ctx.rest ).toEqual( {
			root: '/wp-json/licensio-kb/v1/',
			nonce: 'abc',
		} );
	} );

	it( 'clears the selection', () => {
		const store = useArticlesStore();

		store.selected = [ 1, 2 ];
		articlesExtensionContext( useArticlesStore() ).clearSelection();

		expect( store.selected ).toEqual( [] );
	} );

	it( 'refreshes only the given rows, with the current filters', async () => {
		const store = useArticlesStore();

		store.items = [ item( 1, 'One' ), item( 2, 'Two' ) ];
		store.query = { ...store.query, search: 'needle' };
		vi.mocked( fetchRows ).mockResolvedValue(
			page( [ item( 2, 'Two, changed' ) ] )
		);

		await articlesExtensionContext( useArticlesStore() ).refresh( [ 2 ] );

		expect( fetchRows ).toHaveBeenCalledWith( store.query, [ 2 ] );
		expect( fetchArticles ).not.toHaveBeenCalled();
		expect( store.items.map( ( row ) => row.title ) ).toEqual( [
			'One',
			'Two, changed',
		] );
	} );

	it( 'reloads the page when no IDs are given', async () => {
		const store = useArticlesStore();

		vi.mocked( fetchArticles ).mockResolvedValue(
			page( [ item( 3, 'Three' ) ] )
		);

		await articlesExtensionContext( useArticlesStore() ).refresh();

		expect( fetchRows ).not.toHaveBeenCalled();
		expect( store.items.map( ( row ) => row.id ) ).toEqual( [ 3 ] );
	} );
} );

describe( 'refreshRows', () => {
	it( 'reloads the page when a row no longer matches the filters', async () => {
		const store = useArticlesStore();

		store.items = [ item( 1, 'One' ), item( 2, 'Two' ) ];
		vi.mocked( fetchRows ).mockResolvedValue( page( [] ) );
		vi.mocked( fetchArticles ).mockResolvedValue(
			page( [ item( 1, 'One' ) ] )
		);

		await store.refreshRows( [ 2 ] );

		expect( fetchArticles ).toHaveBeenCalledTimes( 1 );
		expect( store.items.map( ( row ) => row.id ) ).toEqual( [ 1 ] );
	} );

	it( 'fetches nothing for rows that are not on the page', async () => {
		const store = useArticlesStore();

		store.items = [ item( 1, 'One' ) ];

		await store.refreshRows( [ 9 ] );

		expect( fetchRows ).not.toHaveBeenCalled();
		expect( fetchArticles ).not.toHaveBeenCalled();
	} );

	it( 'drops an answer that a page load overtook', async () => {
		const store = useArticlesStore();
		let answer: ( value: ArticlePage ) => void = () => undefined;

		store.items = [ item( 1, 'One' ) ];
		vi.mocked( fetchRows ).mockReturnValue(
			new Promise( ( resolve ) => {
				answer = resolve;
			} )
		);
		vi.mocked( fetchArticles ).mockResolvedValue(
			page( [ item( 1, 'One, reloaded' ) ] )
		);

		const refreshing = store.refreshRows( [ 1 ] );

		await store.load();
		answer( page( [ item( 1, 'One, stale' ) ] ) );
		await refreshing;

		expect( store.items[ 0 ].title ).toBe( 'One, reloaded' );
	} );
} );
