/**
 * Feedback inbox store.
 *
 * Holds the filters, the current page and the selection, and is the only place
 * that talks to the API. Filtering, paging and bulk updating all mutate this
 * store and re-fetch, so the screen never reloads.
 *
 * @since 1.0.0
 */

import { defineStore } from 'pinia';
import { fetchPage, patchStatus, settings } from './api';
import type { FeedbackItem, FeedbackStatus } from './types';

interface State {
	items: FeedbackItem[];
	total: number;
	pages: number;
	page: number;
	perPage: number;
	vote: number;
	status: FeedbackStatus | '';
	selected: number[];
	loading: boolean;
	saving: boolean;
	error: string;
}

export const useFeedbackStore = defineStore( 'lisensioKBFeedback', {
	state: (): State => ( {
		items: [],
		total: 0,
		pages: 0,
		page: 1,
		perPage: settings().perPage,
		vote: 0,
		status: '',
		selected: [],
		loading: false,
		saving: false,
		error: '',
	} ),

	actions: {
		/**
		 * Load the current page of feedback.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the page is in state.
		 */
		async load(): Promise< void > {
			this.loading = true;
			this.error = '';

			try {
				const page = await fetchPage( {
					vote: this.vote,
					status: this.status,
					page: this.page,
					perPage: this.perPage,
				} );

				this.items = page.items;
				this.total = page.total;
				this.pages = page.pages;

				// Drop any selection that is no longer on screen.
				const visible = new Set(
					page.items.map( ( item ) => item.id )
				);
				this.selected = this.selected.filter( ( id ) =>
					visible.has( id )
				);
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );
			} finally {
				this.loading = false;
			}
		},

		/**
		 * Apply a filter change and go back to the first page.
		 *
		 * @since 1.0.0
		 *
		 * @param {Partial<{ vote: number; status: FeedbackStatus | '' }>} filters The filters to change.
		 *
		 * @return {Promise<void>} Resolves once the first page is loaded.
		 */
		async filter(
			filters: Partial< { vote: number; status: FeedbackStatus | '' } >
		): Promise< void > {
			if ( undefined !== filters.vote ) {
				this.vote = filters.vote;
			}

			if ( undefined !== filters.status ) {
				this.status = filters.status;
			}

			this.page = 1;
			this.selected = [];

			await this.load();
		},

		/**
		 * Move to another page.
		 *
		 * @since 1.0.0
		 *
		 * @param {number} page The page to show.
		 *
		 * @return {Promise<void>} Resolves once the page is loaded.
		 */
		async goToPage( page: number ): Promise< void > {
			this.page = Math.max( 1, page );

			await this.load();
		},

		/**
		 * Set one row's status.
		 *
		 * @since 1.0.0
		 *
		 * @param {number}         id     The row to update.
		 * @param {FeedbackStatus} status The status to set.
		 *
		 * @return {Promise<void>} Resolves once the row is updated in state.
		 */
		async setStatus( id: number, status: FeedbackStatus ): Promise< void > {
			this.saving = true;
			this.error = '';

			try {
				const updated = await patchStatus( id, status );
				const index = this.items.findIndex(
					( item ) => item.id === updated.id
				);

				if ( index > -1 ) {
					this.items[ index ] = updated;
				}
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );
			} finally {
				this.saving = false;
			}
		},

		/**
		 * Set the status of every selected row.
		 *
		 * Each row is its own PATCH, because that is the endpoint the issue
		 * specifies; they are issued together rather than one after another so a
		 * page of twenty does not take twenty round trips end to end.
		 *
		 * @since 1.0.0
		 *
		 * @param {FeedbackStatus} status The status to set.
		 *
		 * @return {Promise<void>} Resolves once every row has been updated.
		 */
		async bulkSetStatus( status: FeedbackStatus ): Promise< void > {
			if ( 0 === this.selected.length ) {
				return;
			}

			this.saving = true;
			this.error = '';

			try {
				const results = await Promise.all(
					this.selected.map( ( id ) => patchStatus( id, status ) )
				);

				results.forEach( ( updated ) => {
					const index = this.items.findIndex(
						( item ) => item.id === updated.id
					);

					if ( index > -1 ) {
						this.items[ index ] = updated;
					}
				} );

				this.selected = [];
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );
			} finally {
				this.saving = false;
			}
		},

		/**
		 * Replace the selection.
		 *
		 * @since 1.0.0
		 *
		 * @param {number[]} ids The selected row IDs.
		 *
		 * @return {void}
		 */
		select( ids: number[] ): void {
			this.selected = ids;
		},
	},
} );
