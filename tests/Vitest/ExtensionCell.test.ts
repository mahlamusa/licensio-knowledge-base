/**
 * Unit tests of `ExtensionCell.vue`.
 *
 * A registered column's `render` gets a plain container, its cleanup runs
 * before each new render and on unmount, and without a usable `render` the
 * cell shows the row's `extensions[ key ].text`.
 *
 * @since 1.1.0
 */

import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ExtensionCell from '../../apps/components/ExtensionCell.vue';
import type {
	ColumnHandler,
	ExtensionContext,
	ExtensionHandlers,
	ExtensionRow,
} from '../../apps/components/extensions';

const ctx: ExtensionContext = {
	refresh: () => Promise.resolve(),
	clearSelection: () => undefined,
	screen: 'articles',
	rest: { root: '/wp-json/licensio-kb/v1/', nonce: 'nonce' },
};

const row: ExtensionRow = {
	id: 4,
	extensions: { 'acme/score': { text: 'Fallback 80' } },
};

/**
 * Put a registry on the window that holds one articles column.
 *
 * @since 1.1.0
 *
 * @param {unknown} column The column handler, or anything else to test with.
 *
 * @return {void}
 */
function registerColumn( column: unknown ): void {
	const handlers = {
		columns: { 'acme/score': column as ColumnHandler },
		bulkActions: {},
		rowActions: {},
	} as ExtensionHandlers;

	window.licensioKb = {
		extensions: { register: () => true, get: () => handlers },
	};
}

/**
 * Mount the cell for the registered column.
 *
 * @since 1.1.0
 *
 * @param {ExtensionRow} cellRow The row.
 *
 * @return {ReturnType<typeof mount>} The mounted cell.
 */
function mountCell( cellRow: ExtensionRow = row ) {
	return mount( ExtensionCell, {
		props: {
			screen: 'articles',
			columnKey: 'acme/score',
			row: cellRow,
			ctx,
		},
	} );
}

afterEach( () => {
	delete window.licensioKb;
} );

describe( 'ExtensionCell', () => {
	it( 'gives render a container, the row and ctx, then cleans up on unmount', () => {
		const cleanup = vi.fn();
		const render = vi.fn(
			(
				el: HTMLElement,
				cellRow: ExtensionRow,
				cellCtx: ExtensionContext
			) => {
				el.textContent = `Score for ${ cellRow.id } on ${ cellCtx.screen }`;

				return cleanup;
			}
		);

		registerColumn( { render } );

		const wrapper = mountCell();

		expect( render ).toHaveBeenCalledTimes( 1 );
		expect( render.mock.calls[ 0 ][ 1 ] ).toEqual( row );
		expect( render.mock.calls[ 0 ][ 2 ] ).toEqual( ctx );
		expect( wrapper.text() ).toBe( 'Score for 4 on articles' );
		expect( wrapper.text() ).not.toContain( 'Fallback' );

		const container = wrapper.element as HTMLElement;

		wrapper.unmount();

		expect( cleanup ).toHaveBeenCalledTimes( 1 );
		expect( container.childNodes ).toHaveLength( 0 );
	} );

	it( 'cleans up the last render before rendering a replaced row', async () => {
		const cleanups: Array< ReturnType< typeof vi.fn > > = [];
		const render = vi.fn( ( el: HTMLElement, cellRow: ExtensionRow ) => {
			const cleanup = vi.fn();

			cleanups.push( cleanup );
			el.textContent = `Score for ${ cellRow.id }`;

			return cleanup;
		} );

		registerColumn( { render } );

		const wrapper = mountCell();

		await wrapper.setProps( { row: { ...row, id: 5 } } );

		expect( render ).toHaveBeenCalledTimes( 2 );
		expect( cleanups[ 0 ] ).toHaveBeenCalledTimes( 1 );
		expect( cleanups[ 1 ] ).not.toHaveBeenCalled();
		expect( wrapper.text() ).toBe( 'Score for 5' );
	} );

	it( 'shows the row text when no render is registered', () => {
		const wrapper = mountCell();

		expect( wrapper.text() ).toBe( 'Fallback 80' );
	} );

	it( 'shows the row text when render is not a function', () => {
		registerColumn( { render: 'not a function' } );

		const wrapper = mountCell();

		expect( wrapper.text() ).toBe( 'Fallback 80' );
	} );

	it( 'shows nothing when the row has no data for the column', () => {
		const wrapper = mountCell( { id: 6 } );

		expect( wrapper.text() ).toBe( '' );
	} );
} );
