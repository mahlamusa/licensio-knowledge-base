/**
 * Unit tests of `orderColumns()`, shared by the Articles and Tags tables.
 *
 * Extension columns sit after their position, in the declared order, or at
 * the end; a position naming a column with no position of its own is not
 * found, so that column goes at the end too.
 *
 * @since 1.1.0
 */

import { describe, expect, it } from 'vitest';
import { orderColumns } from '../../apps/components/column-order';
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

describe( 'orderColumns', () => {
	const core = [ 'title', 'author', 'tags', 'date' ];

	it( 'keeps the core order with no extension columns', () => {
		expect( orderColumns( core, [] ) ).toEqual( core );
	} );

	it( 'places each column after its position, in declared order', () => {
		expect(
			orderColumns( core, [
				declared( 'acme/a', { position: 'author' } ),
				declared( 'acme/b', { position: 'author' } ),
				declared( 'acme/c', { position: 'title' } ),
				declared( 'acme/d', { position: 'acme/c' } ),
			] )
		).toEqual( [
			'title',
			'acme/c',
			'acme/d',
			'author',
			'acme/a',
			'acme/b',
			'tags',
			'date',
		] );
	} );

	it( 'puts columns with no or an unknown position at the end', () => {
		expect(
			orderColumns( core, [
				declared( 'acme/a' ),
				declared( 'acme/b', { position: 'nowhere' } ),
				declared( 'acme/c', { position: 'acme/later' } ),
				declared( 'acme/later', { position: 'tags' } ),
			] )
		).toEqual( [
			'title',
			'author',
			'tags',
			'acme/later',
			'date',
			'acme/a',
			'acme/b',
			'acme/c',
		] );
	} );
} );
