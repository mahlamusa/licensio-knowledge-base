/**
 * Unit tests of `actionEntries()`.
 *
 * Only the declared actions with a registered `run` become entries, in the
 * declared order, and running an entry calls `run( target, ctx )`.
 *
 * @since 1.1.0
 */

import { describe, expect, it, vi } from 'vitest';
import { actionEntries } from '../../apps/components/extensions';
import type {
	BulkActionHandler,
	ExtensionContext,
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

describe( 'actionEntries', () => {
	it( 'keeps only the declared actions with a run handler, in order', () => {
		const optimise = vi.fn( () => 'started' );
		const audit = vi.fn();
		const handlers: Record< string, BulkActionHandler > = {
			'acme/audit': { run: audit },
			'acme/optimise': { run: optimise },
			'acme/broken': { run: 'nope' } as unknown as BulkActionHandler,
			'acme/undeclared': { run: vi.fn() },
		};

		const entries = actionEntries( declared, handlers, ctx );

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

	it( 'gives no entries when no handlers are registered', () => {
		expect( actionEntries( declared, {}, ctx ) ).toEqual( [] );
	} );
} );
