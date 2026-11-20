/**
 * Unit tests of `bulkActionEntries()`.
 *
 * Only the declared bulk actions with a registered `run` become bulk bar
 * entries, in the declared order, and running an entry calls
 * `run( ids, ctx )`.
 *
 * @since 1.1.0
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { bulkActionEntries } from '../../apps/components/bulk-actions';
import type {
	BulkActionHandler,
	ExtensionContext,
	ExtensionHandlers,
} from '../../apps/components/extensions';

const ctx: ExtensionContext = {
	refresh: () => Promise.resolve(),
	clearSelection: () => undefined,
	screen: 'articles',
	rest: { root: '/wp-json/licensio-kb/v1/', nonce: 'nonce' },
};

const declared = [
	{ key: 'acme/optimise', label: 'Optimise' },
	{ key: 'acme/missing', label: 'No handler' },
	{ key: 'acme/broken', label: 'Run is not a function' },
	{ key: 'acme/audit', label: 'Audit' },
];

afterEach( () => {
	delete window.licensioKb;
} );

describe( 'bulkActionEntries', () => {
	it( 'keeps only the declared actions with a run handler, in order', () => {
		const optimise = vi.fn( () => 'started' );
		const audit = vi.fn();
		const handlers = {
			columns: {},
			rowActions: {},
			bulkActions: {
				'acme/audit': { run: audit },
				'acme/optimise': { run: optimise },
				'acme/broken': { run: 'nope' } as unknown as BulkActionHandler,
				'acme/undeclared': { run: vi.fn() },
			},
		} as ExtensionHandlers;
		const get = vi.fn( () => handlers );

		window.licensioKb = { extensions: { register: () => true, get } };

		const entries = bulkActionEntries( 'articles', declared, ctx );

		expect( get ).toHaveBeenCalledWith( 'articles' );
		expect(
			entries.map( ( entry ) => [ entry.key, entry.label ] )
		).toEqual( [
			[ 'acme/optimise', 'Optimise' ],
			[ 'acme/audit', 'Audit' ],
		] );

		expect( entries[ 0 ].run( [ 3, 5 ] ) ).toBe( 'started' );
		expect( optimise ).toHaveBeenCalledWith( [ 3, 5 ], ctx );
		expect( audit ).not.toHaveBeenCalled();
	} );

	it( 'gives no entries when the registry script did not load', () => {
		expect( bulkActionEntries( 'articles', declared, ctx ) ).toEqual( [] );
	} );
} );
