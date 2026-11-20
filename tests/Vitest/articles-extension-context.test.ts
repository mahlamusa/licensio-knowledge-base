/**
 * Unit tests of the Articles app's extension context.
 *
 * `articlesExtensionContext()` gives the app's screen and REST root and
 * nonce, clears the store's selection, and refreshes rows through the
 * store's `refreshRows()`: only the given rows on the current page are
 * fetched with `include` and put in place; a row that no longer matches the
 * view, or no IDs at all, reloads the page; and an answer that arrives after
 * a page load started is dropped.
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
 * @param {number} id    The article ID.
 * @param {string} title The title.
 *
 * @return {ArticleItem} The row.
 */
function item( id: number, title: string ): ArticleItem {
	return { id, title } as ArticleItem;
}

/**
 * A page holding the given rows.
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
	window.licensioKbArticles = {
		root: '/wp-json/licensio-kb/v1/',
		nonce: 'abc',
	};
	setActivePinia( createPinia() );
	vi.mocked( fetchRows ).mockReset();
	vi.mocked( fetchArticles ).mockReset();
	vi.mocked( fetchArticles ).mockResolvedValue( page( [] ) );

	useArticlesStore().items = [
		item( 1, 'One' ),
		item( 2, 'Two' ),
		item( 3, 'Three' ),
	];
} );

describe( 'articlesExtensionContext', () => {
	it( 'gives the screen and the REST root and nonce', () => {
		const ctx = articlesExtensionContext();

		expect( ctx.screen ).toBe( 'articles' );
		expect( ctx.rest ).toEqual( {
			root: '/wp-json/licensio-kb/v1/',
			nonce: 'abc',
		} );
	} );

	it( 'clears the selection', () => {
		const store = useArticlesStore();

		store.select( [ 1, 2 ] );
		articlesExtensionContext().clearSelection();

		expect( store.selected ).toEqual( [] );
	} );

	it( 'fetches just the given rows on the page and puts them in place', async () => {
		const store = useArticlesStore();

		vi.mocked( fetchRows ).mockResolvedValue(
			page( [ item( 3, 'Three, checked' ), item( 1, 'One, checked' ) ] )
		);

		await articlesExtensionContext().refresh( [ 1, 3, 99 ] );

		expect( fetchRows ).toHaveBeenCalledTimes( 1 );
		expect( vi.mocked( fetchRows ).mock.calls[ 0 ][ 0 ] ).toEqual(
			store.query
		);
		expect( vi.mocked( fetchRows ).mock.calls[ 0 ][ 1 ] ).toEqual( [
			1, 3,
		] );
		expect( fetchArticles ).not.toHaveBeenCalled();
		expect( store.items.map( ( row ) => row.title ) ).toEqual( [
			'One, checked',
			'Two',
			'Three, checked',
		] );
	} );

	it( 'sends nothing when none of the rows is on the page', async () => {
		await articlesExtensionContext().refresh( [ 98, 99 ] );

		expect( fetchRows ).not.toHaveBeenCalled();
		expect( fetchArticles ).not.toHaveBeenCalled();
	} );

	it( 'reloads the page without IDs', async () => {
		await articlesExtensionContext().refresh();

		expect( fetchRows ).not.toHaveBeenCalled();
		expect( fetchArticles ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reloads the page when a row no longer matches the view', async () => {
		vi.mocked( fetchRows ).mockResolvedValue(
			page( [ item( 1, 'One' ) ] )
		);

		await articlesExtensionContext().refresh( [ 1, 2 ] );

		expect( fetchArticles ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'drops an answer that arrives after a page load started', async () => {
		const store = useArticlesStore();
		let answer: ( value: ArticlePage ) => void = () => undefined;

		vi.mocked( fetchRows ).mockReturnValue(
			new Promise( ( resolve ) => {
				answer = resolve;
			} )
		);
		vi.mocked( fetchArticles ).mockResolvedValue(
			page( [ item( 2, 'Reloaded' ) ] )
		);

		const refreshing = articlesExtensionContext().refresh( [ 2 ] );

		await store.load();
		answer( page( [ item( 2, 'Stale' ) ] ) );
		await refreshing;

		expect( store.items.map( ( row ) => row.title ) ).toEqual( [
			'Reloaded',
		] );
	} );
} );
