/**
 * Unit tests of the Categories and Tags stores' `refresh()`.
 *
 * With IDs, only the listed rows among them are read again and swapped in
 * place; when one is gone, or with no IDs, the whole list loads again. A
 * failure lands in `error` instead of rejecting, and an answer a load
 * overtook is dropped.
 *
 * @since 1.1.0
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const categoriesApi = vi.hoisted( () => ( {
	fetchCategories: vi.fn(),
	fetchCategoriesById: vi.fn(),
} ) );

const tagsApi = vi.hoisted( () => ( {
	fetchTags: vi.fn(),
	fetchTagsById: vi.fn(),
} ) );

vi.mock( '../../apps/categories/api', () => categoriesApi );

vi.mock( '../../apps/tags/api', () => ( {
	...tagsApi,
	settings: () => ( { perPage: 20 } ),
} ) );

import { useCategoriesStore } from '../../apps/categories/store';
import { useTagsStore } from '../../apps/tags/store';
import type { CategoryItem } from '../../apps/categories/types';
import type { TagItem } from '../../apps/tags/types';

/**
 * A category row.
 *
 * @since 1.1.0
 *
 * @param {number} id   The ID.
 * @param {string} name The name.
 *
 * @return {CategoryItem} The row.
 */
function category( id: number, name: string ): CategoryItem {
	return { id, name, parent: 0 } as CategoryItem;
}

/**
 * A tag row.
 *
 * @since 1.1.0
 *
 * @param {number} id   The ID.
 * @param {string} name The name.
 *
 * @return {TagItem} The row.
 */
function tag( id: number, name: string ): TagItem {
	return { id, name, slug: name, description: '', count: 0 };
}

beforeEach( () => {
	setActivePinia( createPinia() );
	vi.clearAllMocks();
} );

describe( 'the Categories store refresh', () => {
	it( 'reads only the listed categories and swaps them in place', async () => {
		const store = useCategoriesStore();

		store.items = [ category( 1, 'One' ), category( 2, 'Two' ) ];
		categoriesApi.fetchCategoriesById.mockResolvedValue( [
			category( 2, 'Two again' ),
			category( 1, 'One again' ),
		] );

		await store.refresh( [ 1, 2, 9, 1 ] );

		expect( categoriesApi.fetchCategoriesById ).toHaveBeenCalledWith( [
			1, 2,
		] );
		expect( store.items.map( ( item ) => item.name ) ).toEqual( [
			'One again',
			'Two again',
		] );
		expect( categoriesApi.fetchCategories ).not.toHaveBeenCalled();
	} );

	it( 'loads every category when a listed one is gone', async () => {
		const store = useCategoriesStore();

		store.items = [ category( 1, 'One' ), category( 2, 'Two' ) ];
		categoriesApi.fetchCategoriesById.mockResolvedValue( [
			category( 1, 'One again' ),
		] );
		categoriesApi.fetchCategories.mockResolvedValue( [
			category( 1, 'One again' ),
		] );

		await store.refresh( [ 1, 2 ] );

		expect( categoriesApi.fetchCategories ).toHaveBeenCalledTimes( 1 );
		expect( store.items.map( ( item ) => item.id ) ).toEqual( [ 1 ] );
	} );

	it( 'loads every category without IDs', async () => {
		const store = useCategoriesStore();

		categoriesApi.fetchCategories.mockResolvedValue( [
			category( 3, 'Three' ),
		] );

		await store.refresh();

		expect( categoriesApi.fetchCategoriesById ).not.toHaveBeenCalled();
		expect( store.items.map( ( item ) => item.id ) ).toEqual( [ 3 ] );
	} );

	it( 'asks for nothing when no requested category is listed', async () => {
		const store = useCategoriesStore();

		store.items = [ category( 1, 'One' ) ];

		await store.refresh( [ 7 ] );

		expect( categoriesApi.fetchCategoriesById ).not.toHaveBeenCalled();
		expect( categoriesApi.fetchCategories ).not.toHaveBeenCalled();
	} );

	it( 'shows a failure in error instead of rejecting', async () => {
		const store = useCategoriesStore();

		store.items = [ category( 1, 'One' ) ];
		categoriesApi.fetchCategoriesById.mockRejectedValue(
			new Error( 'Offline' )
		);

		await expect( store.refresh( [ 1 ] ) ).resolves.toBeUndefined();

		expect( store.error ).toBe( 'Offline' );
		expect( store.items.map( ( item ) => item.name ) ).toEqual( [ 'One' ] );
	} );

	it( 'drops an answer that a load overtook', async () => {
		const store = useCategoriesStore();
		let answer: ( items: CategoryItem[] ) => void = () => undefined;

		store.items = [ category( 1, 'One' ) ];
		categoriesApi.fetchCategoriesById.mockReturnValue(
			new Promise( ( resolve ) => ( answer = resolve ) )
		);
		categoriesApi.fetchCategories.mockResolvedValue( [
			category( 1, 'One loaded' ),
		] );

		const refreshing = store.refresh( [ 1 ] );

		await store.load();
		answer( [ category( 1, 'One stale' ) ] );
		await refreshing;

		expect( store.items.map( ( item ) => item.name ) ).toEqual( [
			'One loaded',
		] );
	} );
} );

describe( 'the Tags store refresh', () => {
	it( 'reads only the rows of the page and swaps them in place', async () => {
		const store = useTagsStore();

		store.items = [ tag( 1, 'one' ), tag( 2, 'two' ), tag( 3, 'three' ) ];
		store.selected = [ 2, 3 ];
		tagsApi.fetchTagsById.mockResolvedValue( [
			tag( 3, 'three again' ),
			tag( 2, 'two again' ),
		] );

		await store.refresh( [ 3, 2, 8 ] );

		expect( tagsApi.fetchTagsById ).toHaveBeenCalledWith( [ 3, 2 ] );
		expect( store.items.map( ( item ) => item.name ) ).toEqual( [
			'one',
			'two again',
			'three again',
		] );
		expect( store.selected ).toEqual( [ 2, 3 ] );
		expect( tagsApi.fetchTags ).not.toHaveBeenCalled();
	} );

	it( 'loads the page again when a listed row is gone', async () => {
		const store = useTagsStore();

		store.items = [ tag( 1, 'one' ), tag( 2, 'two' ) ];
		tagsApi.fetchTagsById.mockResolvedValue( [ tag( 1, 'one again' ) ] );
		tagsApi.fetchTags.mockResolvedValue( {
			items: [ tag( 1, 'one again' ) ],
			total: 1,
			pages: 1,
			page: 1,
			per_page: 20,
		} );

		await store.refresh( [ 1, 2 ] );

		expect( tagsApi.fetchTags ).toHaveBeenCalledTimes( 1 );
		expect( store.items.map( ( item ) => item.id ) ).toEqual( [ 1 ] );
		expect( store.total ).toBe( 1 );
	} );

	it( 'loads the page again without IDs', async () => {
		const store = useTagsStore();

		tagsApi.fetchTags.mockResolvedValue( {
			items: [ tag( 4, 'four' ) ],
			total: 1,
			pages: 1,
			page: 1,
			per_page: 20,
		} );

		await store.refresh( [] );

		expect( tagsApi.fetchTagsById ).not.toHaveBeenCalled();
		expect( store.items.map( ( item ) => item.id ) ).toEqual( [ 4 ] );
	} );

	it( 'shows a failure in error instead of rejecting', async () => {
		const store = useTagsStore();

		store.items = [ tag( 1, 'one' ) ];
		tagsApi.fetchTagsById.mockRejectedValue( new Error( 'Offline' ) );

		await expect( store.refresh( [ 1 ] ) ).resolves.toBeUndefined();

		expect( store.error ).toBe( 'Offline' );
	} );
} );
