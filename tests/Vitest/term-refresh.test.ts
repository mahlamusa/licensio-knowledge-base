/**
 * Unit tests of the Categories and Tags stores' `refresh()`.
 *
 * With IDs, only the listed rows among them are read again and swapped in
 * place; rows gone from the answer leave the list (and the selection). With
 * no IDs, the whole list is loaded again.
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
		store.selectedId = 2;
		categoriesApi.fetchCategoriesById.mockResolvedValue( [
			category( 1, 'One again' ),
		] );

		await store.refresh( [ 1, 2, 9, 1 ] );

		expect( categoriesApi.fetchCategoriesById ).toHaveBeenCalledWith( [
			1, 2,
		] );
		expect( store.items.map( ( item ) => item.name ) ).toEqual( [
			'One again',
		] );
		expect( store.selectedId ).toBeNull();
		expect( categoriesApi.fetchCategories ).not.toHaveBeenCalled();
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
} );

describe( 'the Tags store refresh', () => {
	it( 'reads only the rows of the page and keeps the selection true', async () => {
		const store = useTagsStore();

		store.items = [ tag( 1, 'one' ), tag( 2, 'two' ), tag( 3, 'three' ) ];
		store.selected = [ 2, 3 ];
		tagsApi.fetchTagsById.mockResolvedValue( [ tag( 3, 'three again' ) ] );

		await store.refresh( [ 3, 2 ] );

		expect( tagsApi.fetchTagsById ).toHaveBeenCalledWith( [ 3, 2 ] );
		expect( store.items.map( ( item ) => item.name ) ).toEqual( [
			'one',
			'three again',
		] );
		expect( store.selected ).toEqual( [ 3 ] );
		expect( tagsApi.fetchTags ).not.toHaveBeenCalled();
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
} );
