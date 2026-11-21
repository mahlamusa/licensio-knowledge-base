/**
 * Unit tests of the term apps' extension helpers.
 *
 * Only default visible columns show; a refresh asks only for listed rows and
 * swaps the answer in place.
 *
 * @since 1.1.0
 */

import { describe, expect, it } from 'vitest';
import {
	refreshIds,
	replaceRows,
	shownColumns,
} from '../../apps/components/term-extensions';
import type { ExtensionColumn } from '../../apps/components/extensions';

/**
 * A declared extension column.
 *
 * @since 1.1.0
 *
 * @param {string}  key            The column key.
 * @param {boolean} defaultVisible Whether it shows by default.
 *
 * @return {ExtensionColumn} The column.
 */
function column( key: string, defaultVisible = true ): ExtensionColumn {
	return {
		key,
		label: key,
		group: '',
		defaultVisible,
		sortable: false,
		position: '',
	};
}

describe( 'shownColumns', () => {
	it( 'keeps the default visible columns in order', () => {
		expect(
			shownColumns( [
				column( 'acme/a' ),
				column( 'acme/b', false ),
				column( 'acme/c' ),
			] ).map( ( one ) => one.key )
		).toEqual( [ 'acme/a', 'acme/c' ] );
	} );
} );

describe( 'refreshIds', () => {
	it( 'keeps distinct IDs of listed rows, in the order asked', () => {
		expect(
			refreshIds( [ { id: 1 }, { id: 2 }, { id: 3 } ], [ 3, 9, 1, 3 ] )
		).toEqual( [ 3, 1 ] );
	} );
} );

describe( 'replaceRows', () => {
	it( 'swaps answered rows in place and keeps the others', () => {
		const rows = [
			{ id: 1, name: 'One' },
			{ id: 2, name: 'Two' },
			{ id: 3, name: 'Three' },
		];

		expect( replaceRows( rows, [ { id: 2, name: 'Two again' } ] ) ).toEqual(
			[
				{ id: 1, name: 'One' },
				{ id: 2, name: 'Two again' },
				{ id: 3, name: 'Three' },
			]
		);
		expect( rows[ 1 ].name ).toBe( 'Two' );
	} );
} );
