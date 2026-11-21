/**
 * Unit tests of the Articles table's column helpers.
 *
 * The Columns picker groups the extension columns, and only the sortable
 * ones sort. Their placement is tested in `column-order.test.ts`.
 *
 * @since 1.1.0
 */

import { describe, expect, it } from 'vitest';
import {
	groupColumns,
	isExtensionKey,
	sortableKeys,
} from '../../apps/articles/columns';
import type { ExtensionColumn } from '../../apps/components/extensions';

/**
 * A declared extension column.
 *
 * @since 1.1.0
 *
 * @param {string} key    The key.
 * @param {Object} fields Fields to override.
 *
 * @return {ExtensionColumn} The column.
 */
function declared(
	key: string,
	fields: Partial< ExtensionColumn > = {}
): ExtensionColumn {
	return {
		key,
		label: key,
		group: '',
		defaultVisible: false,
		sortable: false,
		position: '',
		...fields,
	};
}

describe( 'isExtensionKey', () => {
	it( 'tells extension keys from core ones', () => {
		expect( isExtensionKey( 'acme/score' ) ).toBe( true );
		expect( isExtensionKey( 'author' ) ).toBe( false );
	} );
} );

describe( 'groupColumns', () => {
	it( 'puts the ungrouped columns first, then each group in first-seen order', () => {
		const groups = groupColumns( [
			declared( 'acme/a', { group: 'SEO' } ),
			declared( 'acme/b' ),
			declared( 'acme/c', { group: 'Stats' } ),
			declared( 'acme/d', { group: ' SEO ' } ),
		] );

		expect(
			groups.map( ( group ) => [
				group.label,
				group.columns.map( ( column ) => column.key ),
			] )
		).toEqual( [
			[ '', [ 'acme/b' ] ],
			[ 'SEO', [ 'acme/a', 'acme/d' ] ],
			[ 'Stats', [ 'acme/c' ] ],
		] );
	} );

	it( 'has no groups without extension columns', () => {
		expect( groupColumns( [] ) ).toEqual( [] );
	} );
} );

describe( 'sortableKeys', () => {
	it( 'keeps the sortable columns only', () => {
		expect(
			sortableKeys( [
				declared( 'acme/a', { sortable: true } ),
				declared( 'acme/b' ),
				declared( 'acme/c', { sortable: true } ),
			] )
		).toEqual( [ 'acme/a', 'acme/c' ] );
	} );
} );
