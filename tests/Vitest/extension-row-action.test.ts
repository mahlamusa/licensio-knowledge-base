/**
 * Unit tests of `runExtensionRowAction()`.
 *
 * The handler gets the row. One that throws or rejects gets the app's error
 * toast with the action's label, and the returned promise still resolves.
 *
 * @since 1.1.0
 */

import { message } from 'ant-design-vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { runExtensionRowAction } from '../../apps/articles/extension-row-action';
import type { ArticleItem } from '../../apps/articles/types';

const row = { id: 7 } as ArticleItem;

afterEach( () => {
	vi.restoreAllMocks();
	delete ( window as unknown as { wp?: unknown } ).wp;
} );

describe( 'runExtensionRowAction', () => {
	it( 'runs the handler with the row and shows no error', async () => {
		const error = vi.spyOn( message, 'error' );
		const run = vi.fn( () => Promise.resolve( 'done' ) );

		await expect(
			runExtensionRowAction( { key: 'acme/ok', label: 'Ok', run }, row )
		).resolves.toBeUndefined();

		expect( run ).toHaveBeenCalledWith( row );
		expect( error ).not.toHaveBeenCalled();
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
		const error = vi
			.spyOn( message, 'error' )
			.mockImplementation( () => undefined as never );

		// The page's `wp.i18n` fills the label in.
		( window as unknown as { wp: unknown } ).wp = {
			i18n: {
				sprintf: ( format: string, label: string ) =>
					format.replace( '%s', label ),
			},
		};

		await expect(
			runExtensionRowAction(
				{ key: 'acme/fail', label: 'Check SEO', run },
				row
			)
		).resolves.toBeUndefined();

		expect( error ).toHaveBeenCalledTimes( 1 );
		expect( error ).toHaveBeenCalledWith(
			'Check SEO could not be completed.'
		);
	} );
} );
