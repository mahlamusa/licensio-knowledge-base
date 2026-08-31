/**
 * Pinia store backing the analytics dashboard.
 *
 * Holds the selected reporting range and the four report payloads, and reloads
 * every report whenever the range changes.
 *
 * @since 1.0.0
 */

import { defineStore } from 'pinia';
import { config, fetchReport } from './api';
import type {
	ArticlesResponse,
	FeedbackResponse,
	OverviewResponse,
	SearchesResponse,
} from './types';

/**
 * A date as `YYYY-MM-DD`, in the browser's local calendar.
 *
 * @since 1.0.0
 *
 * @param {Date} date The date to format.
 *
 * @return {string} The formatted date.
 */
function toIsoDate( date: Date ): string {
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );

	return `${ date.getFullYear() }-${ month }-${ day }`;
}

/**
 * The default range: the last `defaultDays` days, ending today.
 *
 * @since 1.0.0
 *
 * @return {{start: string, end: string}} The default range.
 */
function defaultRange(): { start: string; end: string } {
	const days = config().defaultDays;
	const end = new Date();
	const start = new Date();

	start.setDate( start.getDate() - ( days - 1 ) );

	return { start: toIsoDate( start ), end: toIsoDate( end ) };
}

export const useAnalyticsStore = defineStore( 'lisensioKbAnalytics', {
	state: () => ( {
		range: defaultRange(),
		limit: config().defaultLimit,
		loading: false,
		error: '',
		overview: null as OverviewResponse | null,
		searches: null as SearchesResponse | null,
		articles: null as ArticlesResponse | null,
		feedback: null as FeedbackResponse | null,
	} ),

	getters: {
		/**
		 * Whether collection is currently switched on.
		 *
		 * @since 1.0.0
		 *
		 * @param {Object} state The store state.
		 *
		 * @return {boolean} True when the server reports collection is enabled.
		 */
		collecting( state ): boolean {
			return state.overview ? state.overview.collecting : true;
		},

		/**
		 * The share of searches that returned at least one article.
		 *
		 * @since 1.0.0
		 *
		 * @param {Object} state The store state.
		 *
		 * @return {number} The effectiveness percentage.
		 */
		effectiveness( state ): number {
			return state.overview ? state.overview.totals.effectiveness : 0;
		},
	},

	actions: {
		/**
		 * Set the reporting range and reload every report.
		 *
		 * @since 1.0.0
		 *
		 * @param {string} start Range start, `YYYY-MM-DD`.
		 * @param {string} end   Range end, `YYYY-MM-DD`.
		 *
		 * @return {Promise<void>} Resolves once the reports have reloaded.
		 */
		async setRange( start: string, end: string ): Promise< void > {
			this.range = { start, end };

			await this.load();
		},

		/**
		 * Load all four reports for the current range.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once every report has settled.
		 */
		async load(): Promise< void > {
			const params = {
				start: this.range.start,
				end: this.range.end,
				limit: String( this.limit ),
			};

			this.loading = true;
			this.error = '';

			try {
				const [ overview, searches, articles, feedback ] =
					await Promise.all( [
						fetchReport< OverviewResponse >( 'overview', params ),
						fetchReport< SearchesResponse >( 'searches', params ),
						fetchReport< ArticlesResponse >( 'articles', params ),
						fetchReport< FeedbackResponse >( 'feedback', params ),
					] );

				this.overview = overview;
				this.searches = searches;
				this.articles = articles;
				this.feedback = feedback;
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );
			} finally {
				this.loading = false;
			}
		},
	},
} );
