/**
 * Tags app store.
 *
 * Holds the filters, sort, page and selection, and is the only place that
 * talks to the API. Every write re-reads the page, so counts and the list stay
 * true to the server. Writes are only ever called after the user confirmed
 * them in a Popconfirm.
 *
 * The page opens with the `search` and `term` query arguments the core tag
 * screens redirect with: `search` fills the search box, and `term` shows that
 * one tag until the filter is cleared.
 *
 * The view lives in the URL so a reload, back and forward, or a pasted link
 * restore it: filters (`search`, `unused`, `term`) are written with
 * `history.replaceState`, sort and page (`orderby`, `order`, `paged`) with
 * `history.pushState`. The page is `paged` because `page` already names the
 * admin screen. Values at their default are left out.
 *
 * Only the answer of the latest list request is kept, so a slow earlier
 * request can never overwrite a later one.
 *
 * @since 1.0.0
 */

import { defineStore } from 'pinia';
import {
	convertTags,
	createTag,
	deleteTags,
	fetchTags,
	mergeTags,
	settings,
	updateTag,
} from './api';
import type {
	BulkDeleteResult,
	ConvertResult,
	MergeResult,
	TagFields,
	TagItem,
	TagOrder,
	TagOrderBy,
} from './types';

interface State {
	items: TagItem[];
	total: number;
	page: number;
	perPage: number;
	search: string;
	unused: boolean;
	include: number[];
	orderby: TagOrderBy;
	order: TagOrder;
	selected: number[];
	loading: boolean;
	saving: boolean;
	error: string;
}

/**
 * The part of the state the URL holds.
 */
type UrlState = Pick<
	State,
	'search' | 'unused' | 'include' | 'orderby' | 'order' | 'page'
>;

/**
 * The ID of the latest list request; older answers are dropped.
 */
let latestRequest = 0;

/**
 * The view the URL describes.
 *
 * @since 1.0.0
 *
 * @return {UrlState} The filters, sort and page, with defaults for what is missing.
 */
function readUrl(): UrlState {
	const params = new URLSearchParams( window.location.search );
	const term = Number( params.get( 'term' ) ?? '' );
	const page = Number( params.get( 'paged' ) ?? '' );

	return {
		search: params.get( 'search' ) ?? '',
		unused: '1' === params.get( 'unused' ),
		include: Number.isInteger( term ) && term > 0 ? [ term ] : [],
		orderby: 'count' === params.get( 'orderby' ) ? 'count' : 'name',
		order: 'desc' === params.get( 'order' ) ? 'desc' : 'asc',
		page: Number.isInteger( page ) && page > 1 ? page : 1,
	};
}

/**
 * Write the view into the URL, leaving every other query argument alone.
 *
 * @since 1.0.0
 *
 * @param {UrlState} state The filters, sort and page.
 * @param {boolean}  push  True to add a history entry, false to replace the current one.
 *
 * @return {void}
 */
function writeUrl( state: UrlState, push: boolean ): void {
	const url = new URL( window.location.href );
	const values: Record< string, string > = {
		search: state.search,
		unused: state.unused ? '1' : '',
		term: 1 === state.include.length ? String( state.include[ 0 ] ) : '',
		orderby: 'name' === state.orderby ? '' : state.orderby,
		order: 'asc' === state.order ? '' : state.order,
		paged: state.page > 1 ? String( state.page ) : '',
	};

	Object.entries( values ).forEach( ( [ key, value ] ) => {
		if ( '' === value ) {
			url.searchParams.delete( key );
		} else {
			url.searchParams.set( key, value );
		}
	} );

	if ( url.href === window.location.href ) {
		return;
	}

	if ( push ) {
		window.history.pushState( null, '', url.href );
	} else {
		window.history.replaceState( null, '', url.href );
	}
}

/**
 * The message of a failed request.
 *
 * @since 1.0.0
 *
 * @param {unknown} error What was thrown.
 *
 * @return {string} The message.
 */
function messageOf( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

export const useTagsStore = defineStore( 'licensioKBTags', {
	state: (): State => ( {
		items: [],
		total: 0,
		page: 1,
		perPage: settings().perPage,
		...readUrl(),
		selected: [],
		loading: false,
		saving: false,
		error: '',
	} ),

	actions: {
		/**
		 * Load the current page of tags.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the page is in state.
		 */
		async load(): Promise< void > {
			const request = ++latestRequest;

			this.loading = true;
			this.error = '';

			try {
				const page = await fetchTags( {
					search: this.search,
					unused: this.unused,
					include: this.include,
					orderby: this.orderby,
					order: this.order,
					page: this.page,
					perPage: this.perPage,
				} );

				// A newer request is in flight; its answer wins.
				if ( request !== latestRequest ) {
					return;
				}

				this.items = page.items;
				this.total = page.total;

				// A page emptied by a delete steps back to the last page with rows.
				if ( 0 === page.items.length && this.page > 1 ) {
					this.page = Math.max( 1, page.pages );
					writeUrl( this.$state, false );
					await this.load();
					return;
				}

				const visible = new Set(
					page.items.map( ( item ) => item.id )
				);
				this.selected = this.selected.filter( ( id ) =>
					visible.has( id )
				);
			} catch ( error ) {
				if ( request === latestRequest ) {
					this.error = messageOf( error );
				}
			} finally {
				if ( request === latestRequest ) {
					this.loading = false;
				}
			}
		},

		/**
		 * Take the view back from the URL, after back or forward.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once that page is loaded.
		 */
		async restore(): Promise< void > {
			Object.assign( this, readUrl() );
			this.selected = [];

			await this.load();
		},

		/**
		 * Change the filters and go back to the first page.
		 *
		 * @since 1.0.0
		 *
		 * @param {Object} filters The filters to change.
		 *
		 * @return {Promise<void>} Resolves once the first page is loaded.
		 */
		async filter(
			filters: Partial< Pick< State, 'search' | 'unused' | 'include' > >
		): Promise< void > {
			Object.assign( this, filters );
			this.page = 1;
			this.selected = [];
			writeUrl( this.$state, false );

			await this.load();
		},

		/**
		 * Change the sort and page.
		 *
		 * @since 1.0.0
		 *
		 * @param {number}     page    The page to show.
		 * @param {TagOrderBy} orderby The sort column.
		 * @param {TagOrder}   order   The sort direction.
		 *
		 * @return {Promise<void>} Resolves once the page is loaded.
		 */
		async view(
			page: number,
			orderby: TagOrderBy,
			order: TagOrder
		): Promise< void > {
			if ( orderby !== this.orderby || order !== this.order ) {
				page = 1;
			}

			this.page = Math.max( 1, page );
			this.orderby = orderby;
			this.order = order;
			writeUrl( this.$state, true );

			await this.load();
		},

		/**
		 * Run a write, then reload the page.
		 *
		 * The error is kept in state and thrown on, so the caller can keep a
		 * form open with the user's input.
		 *
		 * @since 1.0.0
		 *
		 * @template T
		 *
		 * @param {Function} write The request.
		 *
		 * @return {Promise<T>} What the request answered.
		 */
		async write< T >( write: () => Promise< T > ): Promise< T > {
			this.saving = true;
			this.error = '';

			try {
				const result = await write();

				await this.load();

				return result;
			} catch ( error ) {
				this.error = messageOf( error );

				throw error;
			} finally {
				this.saving = false;
			}
		},

		/**
		 * Create a tag.
		 *
		 * @since 1.0.0
		 *
		 * @param {TagFields} fields The new tag.
		 *
		 * @return {Promise<TagItem>} The new tag.
		 */
		create( fields: TagFields ): Promise< TagItem > {
			return this.write( () => createTag( fields ) );
		},

		/**
		 * Change a tag.
		 *
		 * @since 1.0.0
		 *
		 * @param {number}    id     The tag.
		 * @param {TagFields} fields The changed fields.
		 *
		 * @return {Promise<TagItem>} The updated tag.
		 */
		update( id: number, fields: TagFields ): Promise< TagItem > {
			return this.write( () => updateTag( id, fields ) );
		},

		/**
		 * Delete the selected tags.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<BulkDeleteResult>} Which were deleted and which failed.
		 */
		removeSelected(): Promise< BulkDeleteResult > {
			const ids = [ ...this.selected ];

			return this.write( () => deleteTags( ids ) );
		},

		/**
		 * Merge the selected tags into a target.
		 *
		 * @since 1.0.0
		 *
		 * @param {number} target The tag that remains.
		 *
		 * @return {Promise<MergeResult>} The merge result.
		 */
		mergeSelected( target: number ): Promise< MergeResult > {
			const sources = this.selected.filter( ( id ) => id !== target );

			return this.write( () => mergeTags( sources, target ) );
		},

		/**
		 * Turn the selected tags into categories.
		 *
		 * @since 1.0.0
		 *
		 * @param {number} parent The parent category, or 0 for the top level.
		 *
		 * @return {Promise<ConvertResult>} Which were converted and which failed.
		 */
		convertSelected( parent: number ): Promise< ConvertResult > {
			const ids = [ ...this.selected ];

			return this.write( () => convertTags( ids, parent ) );
		},
	},
} );
