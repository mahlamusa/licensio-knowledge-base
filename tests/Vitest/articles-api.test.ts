/**
 * Unit tests of `fetchArticles()`'s rows by ID.
 *
 * With IDs it sends them as `include`, beside the filters; without, it sends
 * no `include` at all.
 *
 * @since 1.1.0
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchArticles } from '../../apps/articles/api';
import { defaultQuery } from '../../apps/articles/url';
import { get } from '../../apps/components/rest';

vi.mock( '../../apps/components/rest', async ( importOriginal ) => ( {
	...( await importOriginal<
		typeof import( '../../apps/components/rest' )
	>() ),
	get: vi.fn( () => Promise.resolve( {} ) ),
} ) );

beforeEach( () => {
	vi.mocked( get ).mockClear();
} );

describe( 'fetchArticles', () => {
	it( 'sends the IDs as include, with the filters', () => {
		const query = { ...defaultQuery( 20 ), search: 'needle' };

		fetchArticles( query, [ 4, 7 ] );

		expect( get ).toHaveBeenCalledWith(
			'articles',
			expect.objectContaining( { search: 'needle', include: [ 4, 7 ] } )
		);
	} );

	it( 'sends no include without IDs', () => {
		fetchArticles( defaultQuery( 20 ) );

		expect(
			vi.mocked( get ).mock.calls[ 0 ][ 1 ]?.include
		).toBeUndefined();
	} );
} );
