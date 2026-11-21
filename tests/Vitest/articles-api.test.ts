/**
 * Unit tests of `fetchRows()`, the Articles app's fetch of rows by ID.
 *
 * It sends the IDs as `include`, beside the filters, and no sort or paging.
 *
 * @since 1.1.0
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchRows } from '../../apps/articles/api';
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

describe( 'fetchRows', () => {
	it( 'sends the IDs as include, with the filters', () => {
		fetchRows( { ...defaultQuery( 20 ), search: 'needle' }, [ 4, 7 ] );

		expect( get ).toHaveBeenCalledWith(
			'articles',
			expect.objectContaining( { search: 'needle', include: [ 4, 7 ] } )
		);
	} );

	it( 'sends no sort or paging', () => {
		fetchRows( defaultQuery( 20 ), [ 4 ] );

		const args = vi.mocked( get ).mock.calls[ 0 ][ 1 ] ?? {};

		expect( args ).not.toHaveProperty( 'orderby' );
		expect( args ).not.toHaveProperty( 'page' );
		expect( args ).not.toHaveProperty( 'per_page' );
	} );
} );
