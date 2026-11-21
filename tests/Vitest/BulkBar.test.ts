/**
 * Unit tests of the Articles bulk bar's extension bulk actions.
 *
 * The declared bulk actions with a registered handler follow the built-in
 * choices, an action with no handler is left out, and applying one hands the
 * selected IDs and the Articles context to the handler straight away, with
 * no confirmation, no bulk request and the selection left to the handler.
 * The Trash view offers none, and a handler that throws or rejects gets an
 * error toast and leaves the bar usable.
 *
 * Ant Design's components are stubbed: the select exposes its options and
 * takes a value, and the buttons are plain buttons.
 *
 * @since 1.1.0
 */

import { flushPromises, mount } from '@vue/test-utils';
import { message } from 'ant-design-vue';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BulkBar from '../../apps/articles/components/BulkBar.vue';
import { runBulk } from '../../apps/articles/api';
import { useArticlesStore as articlesStore } from '../../apps/articles/store';
import type { ExtensionHandlers } from '../../apps/components/extensions';
import type { StatusFilter } from '../../apps/articles/types';

vi.mock( 'ant-design-vue', () => ( { message: { error: vi.fn() } } ) );

vi.mock( '../../apps/articles/api', async ( importOriginal ) => ( {
	...( await importOriginal< typeof import( '../../apps/articles/api' ) >() ),
	runBulk: vi.fn(),
} ) );

/** The bulk action select, with its options as data attributes. */
const SelectStub = defineComponent( {
	name: 'ASelect',
	props: {
		options: { type: Array, default: () => [] },
		value: { type: [ String, Number ], default: undefined },
	},
	emits: [ 'update:value' ],
	setup() {
		return () => h( 'div', { class: 'select-stub' } );
	},
} );

/** A plain button that passes its clicks on. */
const ButtonStub = defineComponent( {
	name: 'AButton',
	inheritAttrs: false,
	emits: [ 'click' ],
	setup( _props, { attrs, emit, slots } ) {
		return () =>
			h(
				'button',
				{
					class: [ 'button-stub', attrs.class ],
					onClick: () => emit( 'click' ),
				},
				slots.default?.()
			);
	},
} );

const ConfirmButtonStub = defineComponent( {
	name: 'ConfirmButton',
	setup( _props, { slots } ) {
		return () =>
			h( 'button', { class: 'confirm-stub' }, slots.default?.() );
	},
} );

let run: ReturnType< typeof vi.fn >;

/**
 * Mount the bar with three rows selected.
 *
 * @since 1.1.0
 *
 * @param {StatusFilter} status The status view the list shows.
 *
 * @return {ReturnType<typeof mount>} The mounted bar.
 */
function mountBar( status: StatusFilter = 'all' ) {
	const store = articlesStore();

	store.query.status = status;

	store.items = [ { id: 4 }, { id: 7 }, { id: 9 } ] as typeof store.items;
	store.select( [ 4, 9 ] );

	return mount( BulkBar, {
		global: {
			stubs: {
				'a-select': SelectStub,
				'a-button': ButtonStub,
				ConfirmButton: ConfirmButtonStub,
				TermSelect: true,
				AuthorSelect: true,
			},
		},
	} );
}

/**
 * The bulk action select.
 *
 * @since 1.1.0
 *
 * @param {ReturnType<typeof mount>} wrapper The mounted bar.
 *
 * @return {import('@vue/test-utils').VueWrapper} The select.
 */
function chooser( wrapper: ReturnType< typeof mount > ) {
	return wrapper.findAllComponents( SelectStub )[ 0 ];
}

/**
 * Choose the extension action and press Apply.
 *
 * @since 1.1.0
 *
 * @param {ReturnType<typeof mount>} wrapper The mounted bar.
 *
 * @return {Promise<void>} Resolves once the click is handled.
 */
async function applyOptimise( wrapper: ReturnType< typeof mount > ) {
	await chooser( wrapper ).vm.$emit( 'update:value', 'acme/optimise' );

	await wrapper
		.findAll( '.button-stub' )
		.find( ( button ) => 'Apply' === button.text() )
		?.trigger( 'click' );
}

beforeEach( () => {
	vi.mocked( message.error ).mockClear();
	run = vi.fn();
	window.licensioKbArticles = {
		root: '/wp-json/licensio-kb/v1/',
		nonce: 'abc',
		extensions: {
			columns: [],
			filters: [],
			rowActions: [],
			bulkActions: [
				{ key: 'acme/optimise', label: 'Optimise' },
				{ key: 'acme/missing', label: 'No handler' },
			],
		},
	};
	window.licensioKb = {
		extensions: {
			register: () => true,
			get: () =>
				( {
					columns: {},
					rowActions: {},
					bulkActions: { 'acme/optimise': { run } },
				} ) as ExtensionHandlers,
		},
	};
	setActivePinia( createPinia() );
} );

afterEach( () => {
	delete window.licensioKb;
	delete window.licensioKbArticles;
	delete ( window as unknown as { wp?: unknown } ).wp;
} );

describe( 'BulkBar extension bulk actions', () => {
	it( 'offers the actions with a handler after the built-in ones', () => {
		const options = chooser( mountBar() ).props( 'options' ) as Array< {
			value: string;
			label: string;
		} >;

		expect( options[ 0 ].value ).toBe( 'trash' );
		expect( options.at( -1 ) ).toEqual( {
			value: 'acme/optimise',
			label: 'Optimise',
		} );
		expect( options.map( ( option ) => option.value ) ).not.toContain(
			'acme/missing'
		);
	} );

	it( 'hands the selection and the context to the handler without asking', async () => {
		const wrapper = mountBar();
		const store = articlesStore();

		await chooser( wrapper ).vm.$emit( 'update:value', 'acme/optimise' );

		expect( wrapper.find( '.confirm-stub' ).exists() ).toBe( false );
		expect( wrapper.findAllComponents( SelectStub ) ).toHaveLength( 1 );

		const apply = wrapper
			.findAll( '.button-stub' )
			.find( ( button ) => 'Apply' === button.text() );

		await apply?.trigger( 'click' );

		expect( run ).toHaveBeenCalledTimes( 1 );
		expect( run.mock.calls[ 0 ][ 0 ] ).toEqual( [ 4, 9 ] );
		expect( run.mock.calls[ 0 ][ 1 ] ).toMatchObject( {
			screen: 'articles',
			rest: { root: '/wp-json/licensio-kb/v1/', nonce: 'abc' },
		} );
		expect( runBulk ).not.toHaveBeenCalled();
		expect( store.bulk.open ).toBe( false );
		expect( store.selected ).toEqual( [ 4, 9 ] );
		expect( chooser( wrapper ).props( 'value' ) ).toBeUndefined();
	} );

	it( 'offers no extension action in the Trash view', () => {
		const options = chooser( mountBar( 'trash' ) ).props(
			'options'
		) as Array< { value: string } >;

		expect( options.map( ( option ) => option.value ) ).toEqual( [
			'restore',
			'delete',
		] );
	} );

	it( 'keeps the chooser disabled until the handler settles', async () => {
		let finish: () => void = () => undefined;

		run.mockReturnValue(
			new Promise< void >( ( resolve ) => {
				finish = resolve;
			} )
		);

		const wrapper = mountBar();

		await applyOptimise( wrapper );

		expect( chooser( wrapper ).attributes( 'disabled' ) ).toBe( 'true' );
		expect( chooser( wrapper ).props( 'value' ) ).toBe( 'acme/optimise' );

		finish();
		await flushPromises();

		expect( chooser( wrapper ).attributes( 'disabled' ) ).toBe( 'false' );
		expect( chooser( wrapper ).props( 'value' ) ).toBeUndefined();
		expect( message.error ).not.toHaveBeenCalled();
	} );

	it.each( [
		[
			'rejects',
			() => Promise.reject( new Error( 'Extension request failed' ) ),
		],
		[
			'throws',
			() => {
				throw new Error( 'Extension bug' );
			},
		],
	] )(
		'shows an error toast and frees the bar when the handler %s',
		async ( _how, handler ) => {
			run.mockImplementation( handler );
			( window as unknown as { wp: unknown } ).wp = {
				i18n: {
					sprintf: ( format: string, label: string ) =>
						format.replace( '%s', label ),
				},
			};

			const wrapper = mountBar();

			await applyOptimise( wrapper );
			await flushPromises();

			expect( run ).toHaveBeenCalledTimes( 1 );
			expect( message.error ).toHaveBeenCalledWith(
				'Optimise could not be completed.'
			);
			expect( chooser( wrapper ).attributes( 'disabled' ) ).toBe(
				'false'
			);
			expect( chooser( wrapper ).props( 'value' ) ).toBeUndefined();
		}
	);
} );
