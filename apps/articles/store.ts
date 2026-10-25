/**
 * Articles app store.
 *
 * Holds the view (filters, sort, page, window), the loaded page, the
 * selection and the state of a running bulk action, and is the only place
 * that talks to the API. The view is mirrored into the URL: every filter,
 * status, window, page and sort change pushes a history entry, so back and
 * forward step through them; a typed search replaces the entry instead.
 *
 * @since 1.0.0
 */

import { defineStore } from 'pinia';
import { errorMessage, PER_PAGE } from '../components/rest';
import {
	config,
	fetchArticles,
	fetchIds,
	revert,
	runBulk,
	savePreferences,
} from './api';
import { defaultQuery, readQuery, writeQuery } from './url';
import type {
	ArticleColumn,
	ArticleFilters,
	ArticleItem,
	ArticleOrderBy,
	ArticleQuery,
	BulkAction,
	BulkArgs,
	BulkResult,
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
	truncated: boolean;
	selecting: boolean;
	preferences: ListPreferences;
	quickEditId: number;
	bulk: BulkRun;
}

let preferencesTimer: ReturnType< typeof setTimeout > | undefined;
let latestLoad = 0;
let latestSelect = 0;

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
			truncated: false,
			selecting: false,
			preferences: {
				columns: [ ...preferences.columns ],
				per_page: perPage,
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
			};
		},

		/**
		 * How many filters besides status, search and window are set.
		 *
		 * @param {State} state The store state.
		 *
		 * @return {number} The count.
		 */
		activeFilterCount( state ): number {
			const q = state.query;

			return [
				q.category > 0,
				q.tag > 0,
				q.author > 0,
				'' !== q.after || '' !== q.before,
				q.uncategorised,
				q.untagged,
				q.needs_attention,
			].filter( Boolean ).length;
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
		 * Whether a column is shown.
		 *
		 * @param {State} state The store state.
		 *
		 * @return {(column: ArticleColumn) => boolean} The check.
		 */
		shows( state ): ( column: ArticleColumn ) => boolean {
			return ( column ) => state.preferences.columns.includes( column );
		},
	},

	actions: {
		/**
		 * Read the view from the URL and load it.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the page is loaded.
		 */
		async init(): Promise< void > {
			this.query = readQuery(
				window.location.search,
				this.preferences.per_page
			);

			window.addEventListener( 'popstate', () => {
				this.query = readQuery(
					window.location.search,
					this.preferences.per_page
				);
				this.clearSelection();
				this.load();
			} );

			await this.load();
		},

		/**
		 * Load the current page.
		 *
		 * Only the latest request's answer is kept.
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
		 * @since 1.0.0
		 *
		 * @param {Partial<ArticleFilters>} filters The filters to change.
		 * @param {boolean}                 push    Push a history entry; false replaces it, for typed search.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async setFilters(
			filters: Partial< ArticleFilters >,
			push = true
		): Promise< void > {
			this.query = { ...this.query, ...filters, page: 1 };
			this.clearSelection();
			this.quickEditId = 0;
			this.syncUrl( push );

			await this.load();
		},

		/**
		 * Clear every filter except the status.
		 *
		 * @since 1.0.0
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
		 * @param {ArticleOrderBy} orderby The column.
		 * @param {string}         order   The direction.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 */
		async setSort(
			orderby: ArticleOrderBy,
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
		 * @since 1.0.0
		 *
		 * @param {Partial<ListPreferences>} preferences The changes.
		 *
		 * @return {void}
		 */
		setPreferences( preferences: Partial< ListPreferences > ): void {
			const perPageChanged =
				undefined !== preferences.per_page &&
				preferences.per_page !== this.preferences.per_page;

			this.preferences = { ...this.preferences, ...preferences };

			clearTimeout( preferencesTimer );
			preferencesTimer = setTimeout( () => {
				savePreferences( this.preferences ).catch( () => undefined );
			}, PREFERENCES_DELAY );

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
		 * Replace the selection from the table's checkboxes.
		 *
		 * After "Select all matching", unticking rows keeps the rest of the
		 * matching selection across pages. Anything else is a selection of
		 * rows on this page.
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
				const current = new Set( this.selected );

				if ( ids.every( ( id ) => current.has( id ) ) ) {
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
		 * Run a bulk action over IDs in batches of 100.
		 *
		 * Batches run one after another. The first batch the server refuses
		 * stops the run; what was done so far stays done and is reported.
		 * Reloads the list and clears the selection afterwards.
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

			for ( let start = 0; start < ids.length; start += BATCH_SIZE ) {
				const batch = ids.slice( start, start + BATCH_SIZE );

				try {
					const result = await runBulk( {
						ids: batch,
						action,
						args,
					} );

					this.bulk.done.push( ...result.done );
					this.bulk.skipped.push( ...result.skipped );
					this.bulk.failed.push( ...result.failed );
					this.bulk.results.push( result );
					this.bulk.processed += batch.length;
				} catch ( error ) {
					this.bulk.error = errorMessage( error );
					break;
				}
			}

			this.bulk.running = false;
			this.clearSelection();
			this.quickEditId = 0;

			await this.load();

			return this.bulk;
		},

		/**
		 * Undo the batches of a bulk run.
		 *
		 * @since 1.0.0
		 *
		 * @param {BulkResult[]} results The batches to undo.
		 *
		 * @return {Promise<RevertResult>} What was put back, skipped or failed, over every batch.
		 */
		async undo( results: BulkResult[] ): Promise< RevertResult > {
			const reverted: RevertResult = {
				done: [],
				skipped: [],
				failed: [],
			};

			try {
				for ( const result of results ) {
					if ( 0 === Object.keys( result.previous ?? {} ).length ) {
						continue;
					}

					const answer = await revert( {
						previous: result.previous,
						snapshot_at: result.snapshot_at,
						token: result.token,
					} );

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
