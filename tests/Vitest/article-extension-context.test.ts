/**
 * Unit tests of the Articles app's extension context and the store actions
 * behind it.
 *
 * `refresh( ids )` fetches only those rows on the page and puts them in
 * place, loading the page again when one left the view or no IDs are given;
 * turning an extension column on saves the preferences at once and reloads
 * the page, so its data arrives.
 *
 * @since 1.1.0
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import type { ArticleItem, ArticlePage } from '../../apps/articles/types';

const api = vi.hoisted( () => ( {
	fetchArticles: vi.fn(),
	fetchRows: vi.fn(),
	savePreferences: vi.fn(),
} ) );

vi.mock( '../../apps/articles/api', async ( original ) => ( {
	...( await original< typeof import( '../../apps/articles/api' ) >() ),
	...api,
} ) );

const { useArticlesExtensionContext } =
	await import( '../../apps/articles/extension-context' );
const { useArticlesStore } = await import( '../../apps/articles/store' );

/**
 * A list row.
 *
 * @since 1.1.0
 *
 * @param {number} id    The article ID.
 * @param {string} title The title.
 *
 * @return {ArticleItem} The row.
 */
function row( id: number, title = `Article ${ id }` ): ArticleItem {
	return { id, title, extensions: {} } as ArticleItem;
}

/**
 * A page answer.
 *
 * @since 1.1.0
 *
 * @param {ArticleItem[]} items The rows.
 *
 * @return {ArticlePage} The page.
 */
function page( items: ArticleItem[] ): ArticlePage {
	return {
		items,
		total: items.length,
		pages: 1,
		counts: {} as ArticlePage[ 'counts' ],
	};
}

beforeEach( () => {
	window.licensioKbArticles = {
		root: 'https://example.test/wp-json/licensio-kb/v1/',
		nonce: 'a-nonce',
		extensions: {
			columns: [
				{
					key: 'acme/score',
					label: 'Score',
					group: '',
					defaultVisible: false,
					sortable: true,
					position: '',
				},
			],
			filters: [],
			bulkActions: [],
			rowActions: [],
		},
	};
	setActivePinia( createPinia() );
	vi.useFakeTimers();
	api.fetchArticles.mockResolvedValue( page( [ row( 1 ), row( 2 ) ] ) );
	api.savePreferences.mockImplementation( ( value ) =>
		Promise.resolve( value )
	);
} );

afterEach( () => {
	vi.useRealTimers();
	vi.clearAllMocks();
	delete window.licensioKbArticles;
} );

describe( 'useArticlesExtensionContext', () => {
	it( 'gives the screen and the REST root and nonce', () => {
		const ctx = useArticlesExtensionContext();

		expect( ctx.screen ).toBe( 'articles' );
		expect( ctx.rest ).toEqual( {
			root: 'https://example.test/wp-json/licensio-kb/v1/',
			nonce: 'a-nonce',
		} );
	} );

	it( 'refreshes only the given rows on the page, in place', async () => {
		const store = useArticlesStore();

		await store.load();
		api.fetchArticles.mockClear();
		api.fetchRows.mockResolvedValue( page( [ row( 2, 'Renamed' ) ] ) );

		await useArticlesExtensionContext().refresh( [ 2, 99 ] );

		expect( api.fetchRows ).toHaveBeenCalledWith( store.filters, [ 2 ] );
		expect( store.items.map( ( item ) => item.title ) ).toEqual( [
			'Article 1',
			'Renamed',
		] );
		expect( api.fetchArticles ).not.toHaveBeenCalled();
	} );

	it( 'loads the page again when a row left the view or no IDs are given', async () => {
		const store = useArticlesStore();

		await store.load();
		api.fetchRows.mockResolvedValue( page( [] ) );

		const ctx = useArticlesExtensionContext();

		await ctx.refresh( [ 1 ] );
		await ctx.refresh();

		expect( api.fetchArticles ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'asks for nothing when none of the rows is on the page', async () => {
		const store = useArticlesStore();

		await store.load();
		await useArticlesExtensionContext().refresh( [ 42 ] );

		expect( api.fetchRows ).not.toHaveBeenCalled();
	} );

	it( 'clears the selection', async () => {
		const store = useArticlesStore();

		store.select( [ 1, 2 ] );
		useArticlesExtensionContext().clearSelection();

		expect( store.selected ).toEqual( [] );
	} );
} );

describe( 'turning an extension column on', () => {
	it( 'saves at once and reloads, so the column gets its data', async () => {
		const store = useArticlesStore();

		store.setPreferences( { columns: [ 'author', 'acme/score' ] } );
		await vi.advanceTimersByTimeAsync( 0 );

		expect( api.savePreferences ).toHaveBeenCalledTimes( 1 );
		expect( api.fetchArticles ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits and does not reload for a core column', async () => {
		const store = useArticlesStore();

		store.setPreferences( { columns: [ 'author', 'date' ] } );
		await vi.advanceTimersByTimeAsync( 0 );

		expect( api.savePreferences ).not.toHaveBeenCalled();

		await vi.advanceTimersByTimeAsync( 600 );

		expect( api.savePreferences ).toHaveBeenCalledTimes( 1 );
		expect( api.fetchArticles ).not.toHaveBeenCalled();
	} );

	it( 'reads a sort by a sortable extension column from the URL', async () => {
		window.history.replaceState( null, '', '/?orderby=acme/score' );

		const store = useArticlesStore();

		await store.init();

		expect( store.query.orderby ).toBe( 'acme/score' );
		expect( store.sortKeys ).toContain( 'acme/score' );
	} );
} );
