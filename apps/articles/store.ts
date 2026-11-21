/**
 * Articles app store.
 *
 * Holds the view (filters, sort, page, window), the loaded page, the
 * selection and the state of a running bulk action, and is the only place
 * that talks to the API. The view is mirrored into the URL, with the same
 * history rule as the Tags app: a filter, search, status or window change
 * replaces the current history entry, while a page or sort change pushes a
 * new one, so back and forward step through pages and sorts.
 *
 * @since 1.0.0
 * @since 1.1.0 Shows and sorts by the extension columns, refreshes rows in place for the extensions, and holds the extension filters.
 */

import { ref } from 'vue';
import { defineStore } from 'pinia';
import { errorMessage, PER_PAGE } from '../components/rest';
import {
	config,
	fetchArticles,
	fetchIds,
	fetchRows,
	revert,
	runBulk,
	savePreferences,
} from './api';
import { isExtensionKey, sortableKeys } from './columns';
import { withExtFilter } from './ext-filters';
import { defaultQuery, ORDER_BY, readQuery, writeQuery } from './url';
import type {
	ArticleColumn,
	ArticleFilters,
	ArticleItem,
	ArticleQuery,
	BulkAction,
	BulkArgs,
	BulkResult,
	ExtensionColumnKey,
	ListColumn,
	ListOrderBy,
	ListPreferences,
	PerPage,
	RevertResult,
	StatusCounts,
} from './types';

/** How many IDs one bulk request carries. */
export const BATCH_SIZE = 100;

/** How long to wait before saving changed preferences, in ms. */
const PREFERENCES_DELAY = 600;

/**
 * The progress and outcome of a bulk run.
 */
export interface BulkRun extends RevertResult {
	open: boolean;
	running: boolean;
	total: number;
	processed: number;
	/** Set when a batch failed and the run stopped. */
	error: string;
	/** Each successful batch's answer, kept for Undo. */
	results: BulkResult[];
}

interface State {
	query: ArticleQuery;
	items: ArticleItem[];
	total: number;
	counts: Partial< StatusCounts >;
	loading: boolean;
	loaded: boolean;
	error: string;
	selected: number[];
	allMatching: boolean;
	/** Every ID "Select all matching" fetched, so a re-ticked row is known. */
	matching: number[];
	truncated: boolean;
	selecting: boolean;
	preferences: ListPreferences;
	quickEditId: number;
	bulk: BulkRun;
}

/**
 * The default columns hidden on medium screens (783 to 1279px, the range the
 * table's styles call small laptop screens) until the user picks their own
 * columns, so Categories and Tags get a readable width there.
 */
const MEDIUM_HIDDEN: ArticleColumn[] = [
	'attachments',
	'rating',
	'views',
	'reading_time',
];

const mediumQuery = window.matchMedia?.(
	'(min-width: 783px) and (max-width: 1279px)'
);
const medium = ref( mediumQuery?.matches ?? false );

mediumQuery?.addEventListener( 'change', ( event ) => {
	medium.value = event.matches;
} );

let preferencesTimer: ReturnType< typeof setTimeout > | undefined;
let reloadAfterSave = false;
let latestLoad = 0;
let latestSelect = 0;

/**
 * Whether a column is one of the defaults hidden on medium screens.
 *
 * @since 1.1.0
 *
 * @param {ListColumn} column The column.
 *
 * @return {boolean} True for a core column hidden there.
 */
function mediumHidden( column: ListColumn ): column is ArticleColumn {
	return ( MEDIUM_HIDDEN as ListColumn[] ).includes( column );
}

/**
 * The keys of the extension columns the list can be sorted by.
 *
 * @since 1.1.0
 *
 * @return {ExtensionColumnKey[]} The keys.
 */
function extensionSorts(): ExtensionColumnKey[] {
	return sortableKeys( config().extensions.columns );
}

/**
 * An empty bulk run.
 *
 * @since 1.0.0
 *
 * @return {BulkRun} The run.
 */
function emptyRun(): BulkRun {
	return {
		open: false,
		running: false,
		total: 0,
		processed: 0,
		done: [],
		skipped: [],
		failed: [],
		error: '',
		results: [],
	};
}

export const useArticlesStore = defineStore( 'licensioKbArticles', {
	state: (): State => {
		const preferences = config().preferences;
		const perPage = (
			[ 20, 50, 100 ].includes( Number( preferences.per_page ) )
				? Number( preferences.per_page )
				: PER_PAGE
		) as PerPage;

		return {
			query: defaultQuery( perPage ),
			items: [],
			total: 0,
			counts: {},
			loading: false,
			loaded: false,
			error: '',
			selected: [],
			allMatching: false,
			matching: [],
			truncated: false,
			selecting: false,
			preferences: {
				columns: [ ...preferences.columns ],
				per_page: perPage,
				columns_chosen: !! preferences.columns_chosen,
				medium_columns: [ ...( preferences.medium_columns ?? [] ) ],
			},
			quickEditId: 0,
			bulk: emptyRun(),
		};
	},

	getters: {
		/**
		 * The filters part of the view.
		 *
		 * @param {State} state The store state.
		 *
		 * @return {ArticleFilters} The filters.
		 */
		filters( state ): ArticleFilters {
			const q = state.query;

			return {
				status: q.status,
				search: q.search,
				category: q.category,
				tag: q.tag,
				author: q.author,
				after: q.after,
				before: q.before,
				uncategorised: q.uncategorised,
				untagged: q.untagged,
				needs_attention: q.needs_attention,
				window: q.window,
				ext_filters: q.ext_filters,
			};
		},

		/**
		 * How many filters besides status, search and window are set,
		 * extension filters included.
		 *
		 * @param {State} state The store state.
		 *
		 * @return {number} The count.
		 */
		activeFilterCount( state ): number {
			const q = state.query;

			return (
				[
					q.category > 0,
					q.tag > 0,
					q.author > 0,
					'' !== q.after || '' !== q.before,
					q.uncategorised,
					q.untagged,
					q.needs_attention,
				].filter( Boolean ).length + Object.keys( q.ext_filters ).length
			);
		},

		/**
		 * Whether anything narrows the list.
		 *
		 * @return {boolean} True with any filter, search or non-default status.
		 */
		isFiltered(): boolean {
			return (
				this.activeFilterCount > 0 ||
				'' !== this.query.search.trim() ||
				'all' !== this.query.status
			);
		},

		/**
		 * What the list can be sorted by: the core sort keys and the
		 * sortable extension columns.
		 *
		 * @return {ListOrderBy[]} The keys.
		 */
		sortKeys(): ListOrderBy[] {
			return [ ...ORDER_BY, ...extensionSorts() ];
		},

		/**
		 * Whether a column is shown.
		 *
		 * @return {(column: ListColumn) => boolean} The check.
		 */
		shows(): ( column: ListColumn ) => boolean {
			const visible = this.visibleColumns;

			return ( column ) => visible.includes( column );
		},

		/**
		 * The columns shown at the current width, which the Columns picker
		 * shows ticked.
		 *
		 * Until the user picks their columns on a wider screen, the less used
		 * defaults are left out on medium screens, except those the user
		 * turned on there.
		 *
		 * @param {State} state The store state.
		 *
		 * @return {ListColumn[]} The columns.
		 */
		visibleColumns( state ): ListColumn[] {
			const {
				columns,
				columns_chosen: chosen,
				medium_columns: mediumColumns,
			} = state.preferences;

			if ( chosen || ! medium.value ) {
				return columns;
			}

			return columns.filter(
				( column ) =>
					! mediumHidden( column ) || mediumColumns.includes( column )
			);
		},

		/**
		 * Whether columns are left out at the current width.
		 *
		 * @param {State} state The store state.
		 *
		 * @return {boolean} True when some are.
		 */
		trimmed( state ): boolean {
			return (
				this.visibleColumns.length < state.preferences.columns.length
			);
		},
	},

	actions: {
		/**
		 * Read the view from the URL and load it.
		 *
		 * @since 1.0.0
		 * @since 1.1.0 Reads the extension filters the page declared.
		 *
		 * @return {Promise<void>} Resolves once the page is loaded.
		 */
		async init(): Promise< void > {
			const extensionFilters = config().extensions.filters;

			this.query = readQuery(
				window.location.search,
				this.preferences.per_page,
				extensionSorts(),
				extensionFilters
			);

			window.addEventListener( 'popstate', () => {
				this.query = readQuery(
					window.location.search,
					this.preferences.per_page,
					extensionSorts(),
					extensionFilters
				);
				this.clearSelection();
				this.load();
			} );

			await this.load();
		},

		/**
		 * Load the current page.
		 *
		 * Only the latest request's answer is kept. A page past the last one
		 * is moved back to the last page, replacing the history entry.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the page is in state.
		 */
		async load(): Promise< void > {
			const ticket = ++latestLoad;

			this.loading = true;
			this.error = '';

			try {
				const page = await fetchArticles( this.query );

				if ( ticket !== latestLoad ) {
					return;
				}

				// A page past the end, from a pasted link or after the last
				// rows of the last page left the view, steps back to the
				// last page with rows.
				if (
					0 === page.items.length &&
					this.query.page > 1 &&
					page.pages < this.query.page
				) {
					this.query = {
						...this.query,
						page: Math.max( 1, page.pages ),
					};
					this.syncUrl( false );
					await this.load();
					return;
				}

				this.items = page.items;
				this.total = page.total;
				this.counts = page.counts ?? {};
				this.loaded = true;

				if ( ! this.allMatching ) {
					const visible = new Set( page.items.map( ( i ) => i.id ) );

					this.selected = this.selected.filter( ( id ) =>
						visible.has( id )
					);
				}
			} catch ( error ) {
				if ( ticket === latestLoad ) {
					this.error = errorMessage( error );
				}
			} finally {
				if ( ticket === latestLoad ) {
					this.loading = false;
				}
			}
		},

		/**
		 * Write the view into the URL.
		 *
		 * @since 1.0.0
		 *
		 * @param {boolean} push Push a history entry instead of replacing.
		 *
		 * @return {void}
		 */
		syncUrl( push: boolean ): void {
			const next = writeQuery( window.location.href, this.query );

			if ( next === window.location.href ) {
				return;
			}

			if ( push ) {
				window.history.pushState( null, '', next );
			} else {
				window.history.replaceState( null, '', next );
			}
		},

		/**
		 * Change filters, go back to the first page and reload.
		 *
		 * The history entry is replaced, not pushed, as in the Tags app.
		 *
		 * @since 1.0.0
		 *
		 * @param {Partial<ArticleFilters>} filters The filters to change.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async setFilters(
			filters: Partial< ArticleFilters >
		): Promise< void > {
			this.query = { ...this.query, ...filters, page: 1 };
			this.clearSelection();
			this.quickEditId = 0;
			this.syncUrl( false );

			await this.load();
		},

		/**
		 * Set one extension filter, or clear it with an empty value.
		 *
		 * @since 1.1.0
		 *
		 * @param {string} key   The filter key.
		 * @param {string} value The option, or an empty string for any.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async setExtFilter( key: string, value: string ): Promise< void > {
			await this.setFilters( {
				ext_filters: withExtFilter(
					this.query.ext_filters,
					key,
					value
				),
			} );
		},

		/**
		 * Clear every filter except the status.
		 *
		 * @since 1.0.0
		 * @since 1.1.0 Clears the extension filters too.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async clearFilters(): Promise< void > {
			await this.setFilters( {
				search: '',
				category: 0,
				tag: 0,
				author: 0,
				after: '',
				before: '',
				uncategorised: false,
				untagged: false,
				needs_attention: false,
				ext_filters: {},
				status: 'all',
			} );
		},

		/**
		 * Move to another page.
		 *
		 * @since 1.0.0
		 *
		 * @param {number} page The page.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async setPage( page: number ): Promise< void > {
			if ( page === this.query.page ) {
				return;
			}

			this.query = { ...this.query, page: Math.max( 1, page ) };
			this.quickEditId = 0;

			if ( ! this.allMatching ) {
				this.selected = [];
			}

			this.syncUrl( true );

			await this.load();
		},

		/**
		 * Change the sort and go back to the first page.
		 *
		 * @since 1.0.0
		 *
		 * @param {ListOrderBy} orderby The column.
		 * @param {string}      order   The direction.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async setSort(
			orderby: ListOrderBy,
			order: 'asc' | 'desc'
		): Promise< void > {
			if (
				orderby === this.query.orderby &&
				order === this.query.order
			) {
				return;
			}

			this.query = { ...this.query, orderby, order, page: 1 };
			this.syncUrl( true );

			await this.load();
		},

		/**
		 * Change the shown columns or rows per page, and save them.
		 *
		 * Saved after a short pause so a run of checkbox clicks is one
		 * request. A failed save only leaves the old preference stored.
		 *
		 * The server fills the extension columns of the rows from the saved
		 * columns, so turning one on saves straight away and then reloads
		 * the page, to fetch its data.
		 *
		 * @since 1.0.0
		 * @since 1.1.0 Saves at once and reloads when an extension column is turned on.
		 *
		 * @param {Partial<ListPreferences>} preferences The changes.
		 *
		 * @return {void}
		 */
		setPreferences( preferences: Partial< ListPreferences > ): void {
			const perPageChanged =
				undefined !== preferences.per_page &&
				preferences.per_page !== this.preferences.per_page;

			const shown = this.preferences.columns;

			if (
				( preferences.columns ?? [] ).some(
					( column ) =>
						isExtensionKey( column ) && ! shown.includes( column )
				)
			) {
				reloadAfterSave = true;
			}

			this.preferences = {
				...this.preferences,
				...preferences,
			};

			clearTimeout( preferencesTimer );
			preferencesTimer = setTimeout(
				() => {
					const reload = reloadAfterSave;

					reloadAfterSave = false;

					savePreferences( this.preferences )
						.then( () => ( reload ? this.load() : undefined ) )
						.catch( () => undefined );
				},
				reloadAfterSave ? 0 : PREFERENCES_DELAY
			);

			if ( perPageChanged ) {
				this.query = {
					...this.query,
					per_page: this.preferences.per_page,
					page: 1,
				};
				this.syncUrl( false );
				this.load();
			}
		},

		/**
		 * Apply a change made in the Columns picker.
		 *
		 * The picker ticks the columns shown at the current width. On a
		 * medium screen, before the user picked columns on a wider one, only
		 * the clicked column changes: a column hidden there is turned on or
		 * off for medium screens (and added back to the user's columns if
		 * they had removed it), and any other column everywhere, so a pick
		 * there never drops the columns wider screens show. Anywhere
		 * else the ticked columns are the user's columns, shown exactly from
		 * then on.
		 *
		 * @since 1.0.0
		 * @since 1.1.0 Takes extension columns too.
		 *
		 * @param {ListColumn[]} picked The columns ticked.
		 *
		 * @return {void}
		 */
		pickColumns( picked: ListColumn[] ): void {
			if ( this.preferences.columns_chosen || ! medium.value ) {
				this.setPreferences( {
					columns: picked,
					columns_chosen: true,
				} );

				return;
			}

			const shown = this.visibleColumns;
			const added = picked.filter(
				( column ) => ! shown.includes( column )
			);
			const removed = shown.filter(
				( column ) => ! picked.includes( column )
			);
			const { columns, medium_columns: mediumColumns } = this.preferences;

			this.setPreferences( {
				columns: [
					...columns.filter(
						( column ) =>
							mediumHidden( column ) ||
							! removed.includes( column )
					),
					...added.filter(
						( column ) => ! columns.includes( column )
					),
				],
				medium_columns: [
					...mediumColumns.filter(
						( column ) => ! removed.includes( column )
					),
					...added.filter( mediumHidden ),
				],
			} );
		},

		/**
		 * Replace the selection from the table's checkboxes.
		 *
		 * After "Select all matching", unticking rows keeps the rest of the
		 * matching selection across pages, and ticking them again gives the
		 * whole selection back. Anything else is a selection of rows on this
		 * page.
		 *
		 * @since 1.0.0
		 *
		 * @param {number[]} ids The selected IDs.
		 *
		 * @return {void}
		 */
		select( ids: number[] ): void {
			latestSelect++;

			if ( this.allMatching && ids.length > 0 ) {
				const matching = new Set( this.matching );

				if ( ids.every( ( id ) => matching.has( id ) ) ) {
					this.selected = ids;
					return;
				}
			}

			this.selected = ids;
			this.allMatching = false;
			this.truncated = false;
		},

		/**
		 * Select every article matching the filters, across pages.
		 *
		 * The answer is dropped when the filters or the selection changed
		 * while it was on its way, so it can never select articles from a
		 * view the user has left.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the IDs are selected.
		 */
		async selectAllMatching(): Promise< void > {
			const ticket = ++latestSelect;

			this.selecting = true;
			this.error = '';

			try {
				const result = await fetchIds( this.filters );

				if ( ticket !== latestSelect ) {
					return;
				}

				this.selected = result.ids;
				this.matching = result.ids;
				this.allMatching = true;
				this.truncated = result.truncated;
			} catch ( error ) {
				if ( ticket === latestSelect ) {
					this.error = errorMessage( error );
				}
			} finally {
				if ( ticket === latestSelect ) {
					this.selecting = false;
				}
			}
		},

		/**
		 * Drop the selection.
		 *
		 * @since 1.0.0
		 *
		 * @return {void}
		 */
		clearSelection(): void {
			latestSelect++;
			this.selecting = false;
			this.selected = [];
			this.allMatching = false;
			this.matching = [];
			this.truncated = false;
		},

		/**
		 * Put an updated row in place.
		 *
		 * @since 1.0.0
		 *
		 * @param {ArticleItem} item The row.
		 *
		 * @return {void}
		 */
		replaceItem( item: ArticleItem ): void {
			const index = this.items.findIndex( ( row ) => row.id === item.id );

			if ( index > -1 ) {
				this.items[ index ] = item;
			}
		},

		/**
		 * Fetch some rows again with the current filters and swap them in,
		 * or reload the page.
		 *
		 * - No IDs, or an empty list: the whole page loads again.
		 * - Otherwise only the given rows on the current page are fetched;
		 *   IDs not on the page are passed over, and nothing is fetched when
		 *   none are on it. The rows are swapped in place and the status
		 *   counts updated.
		 * - When one of them no longer matches the filters, the whole page
		 *   loads again instead, so the rows, total and paging stay right.
		 *
		 * Like `load()`, it clears `error` first and never rejects: a failure
		 * shows in `error`. An answer that a page load overtook is dropped.
		 *
		 * @since 1.1.0
		 *
		 * @param {number[]} ids The rows; none for the whole page.
		 *
		 * @return {Promise<void>} Resolves once the rows are in state.
		 */
		async refreshRows( ids?: number[] ): Promise< void > {
			if ( ! ids?.length ) {
				await this.load();
				return;
			}

			const wanted = new Set( ids );
			const shown = this.items
				.map( ( row ) => row.id )
				.filter( ( id ) => wanted.has( id ) );

			if ( 0 === shown.length ) {
				return;
			}

			const ticket = latestLoad;

			this.error = '';

			try {
				const page = await fetchRows( this.filters, shown );

				if ( ticket !== latestLoad ) {
					return;
				}

				if ( page.items.length < shown.length ) {
					await this.load();
					return;
				}

				page.items.forEach( ( item ) => this.replaceItem( item ) );
				this.counts = page.counts ?? this.counts;
			} catch ( error ) {
				if ( ticket === latestLoad ) {
					this.error = errorMessage( error );
				}
			}
		},

		/**
		 * Run a bulk action over IDs in batches of 100.
		 *
		 * Batches run one after another. The first batch the server refuses
		 * stops the run; what was done so far stays done and is reported.
		 * Reloads the list and clears the selection afterwards. Each run
		 * gets its own state object, so its answer and Undo never mix with
		 * another run's.
		 *
		 * @since 1.0.0
		 *
		 * @param {BulkAction} action The action.
		 * @param {BulkArgs}   args   Its arguments.
		 * @param {number[]}   ids    The articles.
		 * @param {boolean}    modal  Whether to show the progress modal.
		 *
		 * @return {Promise<BulkRun>} The finished run.
		 */
		async runBulk(
			action: BulkAction,
			args: BulkArgs,
			ids: number[],
			modal = true
		): Promise< BulkRun > {
			this.bulk = {
				...emptyRun(),
				open: modal,
				running: true,
				total: ids.length,
			};

			// The store's reactive copy, so the progress modal follows it.
			const run = this.bulk;

			for ( let start = 0; start < ids.length; start += BATCH_SIZE ) {
				const batch = ids.slice( start, start + BATCH_SIZE );

				try {
					const result = await runBulk( {
						ids: batch,
						action,
						args,
					} );

					run.done.push( ...result.done );
					run.skipped.push( ...result.skipped );
					run.failed.push( ...result.failed );
					run.results.push( result );
					run.processed += batch.length;
				} catch ( error ) {
					run.error = errorMessage( error );
					break;
				}
			}

			run.running = false;
			this.clearSelection();
			this.quickEditId = 0;

			await this.load();

			return run;
		},

		/**
		 * Undo the batches of a bulk run, or an editor trash, by their tokens.
		 *
		 * @since 1.0.0
		 *
		 * @param {string[]} tokens The undo token of each batch; empty ones are passed over.
		 *
		 * @return {Promise<RevertResult>} What was put back, skipped or failed, over every batch.
		 */
		async undo( tokens: string[] ): Promise< RevertResult > {
			const reverted: RevertResult = {
				done: [],
				skipped: [],
				failed: [],
			};

			try {
				for ( const token of tokens ) {
					if ( ! token ) {
						continue;
					}

					const answer = await revert( { token } );

					reverted.done.push( ...answer.done );
					reverted.skipped.push( ...answer.skipped );
					reverted.failed.push( ...answer.failed );
				}
			} finally {
				await this.load();
			}

			return reverted;
		},

		/**
		 * Close the progress modal.
		 *
		 * @since 1.0.0
		 *
		 * @return {void}
		 */
		closeBulk(): void {
			if ( ! this.bulk.running ) {
				this.bulk.open = false;
			}
		},
	},
} );
