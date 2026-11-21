/**
 * Unit tests of `rowActionEntries()` and `rowActionsFor()`.
 *
 * Only the declared row actions with a registered `run` become menu entries,
 * in the declared order, and running an entry calls `run( row, ctx )`. On
 * each row only the entries whose `visible( row )` is true show: one with no
 * `visible` shows everywhere, and one whose `visible` throws is hidden and
 * logs its error once.
 *
 * @since 1.1.0
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	rowActionEntries,
	rowActionsFor,
} from '../../apps/components/row-actions';
import type {
	ExtensionContext,
	ExtensionHandlers,
	RowActionHandler,
} from '../../apps/components/extensions';

const ctx: ExtensionContext = {
	refresh: () => Promise.resolve(),
	clearSelection: () => undefined,
	screen: 'tags',
	rest: { root: '/wp-json/licensio-kb/v1/', nonce: 'nonce' },
};

const declared = [
	{ key: 'acme/edit', label: 'Edit SEO' },
	{ key: 'acme/missing', label: 'No handler' },
	{ key: 'acme/broken', label: 'Run is not a function' },
	{ key: 'acme/check', label: 'Check' },
];

afterEach( () => {
	delete window.licensioKb;
	vi.restoreAllMocks();
} );

/**
 * Register row action handlers for the Tags app.
 *
 * @since 1.1.0
 *
 * @param {Object} rowActions The handlers, keyed by action key.
 *
 * @return {void}
 */
function registerRowActions(
	rowActions: Record< string, RowActionHandler >
): void {
	const handlers = {
		columns: {},
		bulkActions: {},
		rowActions,
	} as ExtensionHandlers;

	window.licensioKb = {
		extensions: { register: () => true, get: () => handlers },
	};
}

describe( 'rowActionEntries', () => {
	it( 'keeps only the declared actions with a run handler, in order', () => {
		const edit = vi.fn( () => 'edited' );
		const check = vi.fn();
		const handlers = {
			columns: {},
			bulkActions: {},
			rowActions: {
				'acme/check': { run: check },
				'acme/edit': { run: edit },
				'acme/broken': { run: 'nope' } as unknown as RowActionHandler,
				'acme/undeclared': { run: vi.fn() },
			},
		} as ExtensionHandlers;
		const get = vi.fn( () => handlers );

		window.licensioKb = { extensions: { register: () => true, get } };

		const entries = rowActionEntries( 'tags', declared, ctx );

		expect( get ).toHaveBeenCalledWith( 'tags' );
		expect(
			entries.map( ( entry ) => [ entry.key, entry.label ] )
		).toEqual( [
			[ 'acme/edit', 'Edit SEO' ],
			[ 'acme/check', 'Check' ],
		] );

		const row = { id: 12 };

		expect( entries[ 0 ].run( row ) ).toBe( 'edited' );
		expect( edit ).toHaveBeenCalledWith( row, ctx );
		expect( check ).not.toHaveBeenCalled();
	} );

	it( 'has no entries when the registry script did not load', () => {
		expect( rowActionEntries( 'tags', declared, ctx ) ).toEqual( [] );
	} );

	it( 'shows each action only on the rows its visible check allows', () => {
		const error = vi
			.spyOn( console, 'error' )
			.mockImplementation( () => undefined );
		const failure = new Error( 'No such row' );

		registerRowActions( {
			'acme/edit': {
				run: vi.fn(),
				visible: ( row ) => 0 === row.id % 2,
			},
			'acme/check': {
				run: vi.fn(),
				visible: ( row ) => {
					if ( 3 === row.id ) {
						throw failure;
					}

					return true;
				},
			},
			'acme/broken': {
				run: vi.fn(),
				visible: ( () => 'yes' ) as unknown as () => boolean,
			},
			'acme/missing': {
				run: vi.fn(),
				visible: ( () => undefined ) as unknown as () => boolean,
			},
		} );

		const entries = rowActionEntries( 'tags', declared, ctx );
		const keysFor = ( id: number ) =>
			rowActionsFor( entries, { id } ).map( ( entry ) => entry.key );

		expect( keysFor( 2 ) ).toEqual( [
			'acme/edit',
			'acme/broken',
			'acme/check',
		] );
		expect( keysFor( 1 ) ).toEqual( [ 'acme/broken', 'acme/check' ] );
		expect( error ).not.toHaveBeenCalled();

		expect( keysFor( 3 ) ).toEqual( [ 'acme/broken' ] );
		expect( keysFor( 3 ) ).toEqual( [ 'acme/broken' ] );
		expect( error ).toHaveBeenCalledTimes( 1 );
		expect( error ).toHaveBeenCalledWith(
			'Row action acme/check visible failed:',
			failure
		);
		expect( keysFor( 4 ) ).toEqual( [
			'acme/edit',
			'acme/broken',
			'acme/check',
		] );
	} );

	it( 'shows an action with no visible check on every row', () => {
		registerRowActions( { 'acme/edit': { run: vi.fn() } } );

		const entries = rowActionEntries( 'tags', declared, ctx );

		expect( rowActionsFor( entries, { id: 1 } ) ).toEqual( entries );
		expect( rowActionsFor( entries, { id: 2 } ) ).toEqual( entries );
	} );
} );
