/**
 * Unit tests of `runExtensionRowAction()`, shared by the Articles,
 * Categories and Tags apps, and `runExtensionBulkAction()`, used by the
 * Articles bulk bar.
 *
 * The handler gets the row. One that throws or rejects gets the app's error
 * toast with the action's label, the error is logged with the action's key,
 * and the returned promise still resolves. While a run is pending the same
 * action on the same row does not run again. A bulk action gets the IDs and
 * fails the same way, logged as a bulk action.
 *
 * @since 1.1.0
 */

import { message } from 'ant-design-vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	isExtensionRowActionRunning,
	runExtensionBulkAction,
	runExtensionRowAction,
} from '../../apps/components/extension-row-action';

const row = { id: 7 };
const logged = vi.fn();

beforeEach( () => {
	vi.spyOn( message, 'error' ).mockImplementation( () => undefined as never );
	vi.spyOn( console, 'error' ).mockImplementation( logged );

	// The page's `wp.i18n` fills the label in.
	( window as unknown as { wp: unknown } ).wp = {
		i18n: {
			sprintf: ( format: string, label: string ) =>
				format.replace( '%s', label ),
		},
	};
} );

afterEach( () => {
	vi.restoreAllMocks();
	logged.mockReset();
	delete ( window as unknown as { wp?: unknown } ).wp;
} );

describe( 'runExtensionRowAction', () => {
	it( 'runs the handler with the row and shows no error', async () => {
		const run = vi.fn( () => Promise.resolve( 'done' ) );

		await expect(
			runExtensionRowAction( { key: 'acme/ok', label: 'Ok', run }, row )
		).resolves.toBeUndefined();

		expect( run ).toHaveBeenCalledWith( row );
		expect( message.error ).not.toHaveBeenCalled();
		expect( logged ).not.toHaveBeenCalled();
	} );

	it.each( [
		[
			'throws',
			() => {
				throw new Error( 'boom' );
			},
		],
		[ 'rejects', () => Promise.reject( new Error( 'boom' ) ) ],
	] )( 'shows a generic error when the handler %s', async ( _, run ) => {
		const entry = { key: 'acme/fail', label: 'Check SEO', run };

		await expect(
			runExtensionRowAction( entry, row )
		).resolves.toBeUndefined();

		expect( message.error ).toHaveBeenCalledTimes( 1 );
		expect( message.error ).toHaveBeenCalledWith(
			'Check SEO could not be completed.'
		);
		expect( logged ).toHaveBeenCalledWith(
			'Row action acme/fail failed:',
			new Error( 'boom' )
		);
		expect( isExtensionRowActionRunning( entry, row ) ).toBe( false );
	} );

	it( 'runs once while a run of the same action on the row is pending', async () => {
		let settle: ( value?: unknown ) => void = () => undefined;
		const run = vi.fn(
			() =>
				new Promise( ( resolve, reject ) => {
					settle = () => reject( new Error( 'late' ) );
				} )
		);
		const entry = { key: 'acme/slow', label: 'Slow', run };
		const other = { id: 8 };

		const first = runExtensionRowAction( entry, row );
		const second = runExtensionRowAction( entry, row );

		await Promise.resolve();
		expect( isExtensionRowActionRunning( entry, row ) ).toBe( true );
		expect( isExtensionRowActionRunning( entry, other ) ).toBe( false );
		await second;
		expect( run ).toHaveBeenCalledTimes( 1 );

		settle();
		await first;

		expect( isExtensionRowActionRunning( entry, row ) ).toBe( false );

		// Settled, even by an error: it runs again.
		const third = runExtensionRowAction( entry, row );

		await Promise.resolve();
		settle();
		await third;
		expect( run ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'runExtensionBulkAction', () => {
	it( 'runs the handler with the IDs and shows no error', async () => {
		const run = vi.fn( () => 'done' );

		await expect(
			runExtensionBulkAction(
				{ key: 'acme/ok', label: 'Ok', run },
				[ 3, 5 ]
			)
		).resolves.toBeUndefined();

		expect( run ).toHaveBeenCalledWith( [ 3, 5 ] );
		expect( message.error ).not.toHaveBeenCalled();
		expect( logged ).not.toHaveBeenCalled();
	} );

	it.each( [
		[
			'throws',
			() => {
				throw new Error( 'boom' );
			},
		],
		[ 'rejects', () => Promise.reject( new Error( 'boom' ) ) ],
	] )( 'shows a generic error when the handler %s', async ( _, run ) => {
		await expect(
			runExtensionBulkAction(
				{ key: 'acme/fail', label: 'Optimise', run },
				[ 3 ]
			)
		).resolves.toBeUndefined();

		expect( message.error ).toHaveBeenCalledWith(
			'Optimise could not be completed.'
		);
		expect( logged ).toHaveBeenCalledWith(
			'Bulk action acme/fail failed:',
			new Error( 'boom' )
		);
	} );
} );
