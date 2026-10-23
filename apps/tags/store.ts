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
 * The filters the page was opened with.
 *
 * @since 1.0.0
 *
 * @return {{ search: string, include: number[] }} The search text and the tag to show.
 */
function initialFilters(): { search: string; include: number[] } {
	const params = new URLSearchParams( window.location.search );
	const term = Number( params.get( 'term' ) ?? '' );

	return {
		search: params.get( 'search' ) ?? '',
		include: Number.isInteger( term ) && term > 0 ? [ term ] : [],
	};
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
		...initialFilters(),
		unused: false,
		orderby: 'name',
		order: 'asc',
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

				this.items = page.items;
				this.total = page.total;

				// A page emptied by a delete steps back to the last page with rows.
				if ( 0 === page.items.length && this.page > 1 ) {
					this.page = Math.max( 1, page.pages );
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
				this.error = messageOf( error );
			} finally {
				this.loading = false;
			}
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
			filters: Partial< {
				search: string;
				unused: boolean;
				include: number[];
			} >
		): Promise< void > {
			Object.assign( this, filters );
			this.page = 1;
			this.selected = [];

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

			await this.load();
		},

		/**
		 * Replace the selection.
		 *
		 * @since 1.0.0
		 *
		 * @param {number[]} ids The selected tag IDs.
		 *
		 * @return {void}
		 */
		select( ids: number[] ): void {
			this.selected = ids;
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
