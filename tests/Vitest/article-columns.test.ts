/**
 * Unit tests of the Articles table's column helpers.
 *
 * Extension columns sit after their position, in the declared order, or at
 * the end; the Columns picker groups them; only the sortable ones sort.
 *
 * @since 1.1.0
 */

import { describe, expect, it } from 'vitest';
import {
	groupColumns,
	isExtensionKey,
	orderColumns,
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
			'acme/b',
			'acme/a',
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
