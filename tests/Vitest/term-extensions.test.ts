/**
 * Unit tests of the term apps' extension helpers.
 *
 * Only default visible columns show; columns are placed after their
 * `position` in declared order; a refresh asks only for listed rows and
 * swaps the answer in place, dropping requested rows that are gone.
 *
 * @since 1.1.0
 */

import { describe, expect, it } from 'vitest';
import {
	placeColumns,
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
 * @param {string}  position       The column it follows; empty for the end.
 * @param {boolean} defaultVisible Whether it shows by default.
 *
 * @return {ExtensionColumn} The column.
 */
function column(
	key: string,
	position = '',
	defaultVisible = true
): ExtensionColumn {
	return {
		key,
		label: key,
		group: '',
		defaultVisible,
		sortable: false,
		position,
	};
}

const core = [ { key: 'name' }, { key: 'slug' }, { key: 'count' } ];
const make = ( extension: ExtensionColumn ) => ( { key: extension.key } );
const keys = ( columns: Array< { key: string } > ) =>
	columns.map( ( one ) => one.key );

describe( 'shownColumns', () => {
	it( 'keeps the default visible columns in order', () => {
		expect(
			keys(
				shownColumns( [
					column( 'acme/a' ),
					column( 'acme/b', '', false ),
					column( 'acme/c' ),
				] )
			)
		).toEqual( [ 'acme/a', 'acme/c' ] );
	} );
} );

describe( 'placeColumns', () => {
	it( 'puts columns with no or an unknown position at the end', () => {
		expect(
			keys(
				placeColumns(
					core,
					[ column( 'acme/a' ), column( 'acme/b', 'nope' ) ],
					make
				)
			)
		).toEqual( [ 'name', 'slug', 'count', 'acme/a', 'acme/b' ] );
	} );

	it( 'puts a column after its position, keeping the declared order', () => {
		expect(
			keys(
				placeColumns(
					core,
					[
						column( 'acme/a', 'name' ),
						column( 'acme/b', 'name' ),
						column( 'acme/c', 'acme/a' ),
					],
					make
				)
			)
		).toEqual( [ 'name', 'acme/a', 'acme/b', 'acme/c', 'slug', 'count' ] );
	} );

	it( 'leaves the core list alone', () => {
		placeColumns( core, [ column( 'acme/a', 'name' ) ], make );

		expect( keys( core ) ).toEqual( [ 'name', 'slug', 'count' ] );
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
