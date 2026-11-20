/**
 * Unit tests of `rowActionEntries()`.
 *
 * Only the declared row actions with a registered `run` become menu entries,
 * in the declared order, and running an entry calls `run( row, ctx )`.
 *
 * @since 1.1.0
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { rowActionEntries } from '../../apps/components/row-actions';
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
} );

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
} );
