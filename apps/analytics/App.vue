<script setup lang="ts">
import { lkbTheme } from '../styles/theme';
/**
 * The analytics dashboard.
 *
 * Four reports over one selectable date range: the headline totals, the search
 * report with its documentation-gap list, the article report, and the feedback
 * summary. The reports show only once something has loaded: when the first
 * load fails there is an error with a Retry, not a page of zeros.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref } from 'vue';
import { config } from './api';
import { __, _n, sprintf } from '../components/i18n';
import { useAnalyticsStore } from './store';
import ArticleTable from './components/ArticleTable.vue';
import SearchTable from './components/SearchTable.vue';
import AppHeader from '../components/AppHeader.vue';
import TrendChart from './components/TrendChart.vue';
import ZeroResultTable from './components/ZeroResultTable.vue';

const store = useAnalyticsStore();
const settings = config();
const start = ref( store.range.start );
const end = ref( store.range.end );

const presets = [ 7, 30, 90 ];

const trend = computed( () => [
	{
		label: __( 'Searches', 'licensio-knowledge-base' ),
		color: '#247a5c',
		points: store.overview?.search_trend ?? [],
	},
	{
		label: __( 'Article views', 'licensio-knowledge-base' ),
		color: '#16a34a',
		points: store.overview?.view_trend ?? [],
	},
] );

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
 * Apply a preset window of the last N days.
 *
 * @since 1.0.0
 *
 * @param {number} days How many days the window covers.
 *
 * @return {void}
 */
function applyPreset( days: number ): void {
	const from = new Date();

	from.setDate( from.getDate() - ( days - 1 ) );

	start.value = toIsoDate( from );
	end.value = toIsoDate( new Date() );

	store.setRange( start.value, end.value );
}

/**
 * Apply the range currently typed into the two date fields.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function applyRange(): void {
	store.setRange( start.value, end.value );
}

onMounted( () => {
	store.load();
} );
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page licensio-kb-analytics">
			<AppHeader :settings-url="settings.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Analytics', 'licensio-knowledge-base' ) }}
					</h1>
				</div>

				<a-alert
					v-if="store.error"
					type="error"
					show-icon
					:message="store.error"
					class="licensio-kb-analytics__notice"
				>
					<template #action>
						<a-button
							size="small"
							:loading="store.loading"
							@click="store.load()"
						>
							{{ __( 'Retry', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</a-alert>

				<a-alert
					v-else-if="store.overview && ! store.collecting"
					type="warning"
					show-icon
					:message="
						__(
							'Analytics collection is switched off, so nothing new is being recorded.',
							'licensio-knowledge-base'
						)
					"
					class="licensio-kb-analytics__notice"
				/>

				<div class="licensio-kb-analytics__range">
					<label>
						{{ __( 'From', 'licensio-knowledge-base' ) }}
						<input v-model="start" type="date" />
					</label>
					<label>
						{{ __( 'To', 'licensio-knowledge-base' ) }}
						<input v-model="end" type="date" />
					</label>
					<a-button type="primary" @click="applyRange">
						{{ __( 'Apply', 'licensio-knowledge-base' ) }}
					</a-button>
					<a-button
						v-for="days in presets"
						:key="days"
						@click="applyPreset( days )"
					>
						{{
							sprintf(
								/* translators: %d: The number of days the preset covers. */
								_n(
									'%d day',
									'%d days',
									days,
									'licensio-knowledge-base'
								),
								days
							)
						}}
					</a-button>
					<span class="licensio-kb-analytics__retention">
						{{
							sprintf(
								/* translators: %d: How many days analytics rows are kept. */
								_n(
									'Rows are kept for %d day.',
									'Rows are kept for %d days.',
									settings.retentionDays,
									'licensio-knowledge-base'
								),
								settings.retentionDays
							)
						}}
					</span>
				</div>

				<a-spin
					v-if="store.overview || store.loading"
					:spinning="store.loading"
				>
					<a-row :gutter="16" class="licensio-kb-analytics__cards">
						<a-col :span="6">
							<a-card>
								<a-statistic
									:title="
										__(
											'Searches',
											'licensio-knowledge-base'
										)
									"
									:value="
										store.overview?.totals.total_searches ??
										0
									"
								/>
							</a-card>
						</a-col>
						<a-col :span="6">
							<a-card>
								<a-statistic
									:title="
										__(
											'Search effectiveness',
											'licensio-knowledge-base'
										)
									"
									:value="store.effectiveness"
									suffix="%"
								/>
							</a-card>
						</a-col>
						<a-col :span="6">
							<a-card>
								<a-statistic
									:title="
										__(
											'Article views',
											'licensio-knowledge-base'
										)
									"
									:value="
										store.overview?.totals.total_views ?? 0
									"
								/>
							</a-card>
						</a-col>
						<a-col :span="6">
							<a-card>
								<a-statistic
									:title="
										__(
											'Empty searches',
											'licensio-knowledge-base'
										)
									"
									:value="
										store.overview?.totals
											.zero_result_searches ?? 0
									"
								/>
							</a-card>
						</a-col>
					</a-row>

					<a-card
						:title="__( 'Activity', 'licensio-knowledge-base' )"
						class="licensio-kb-analytics__block"
					>
						<TrendChart :series="trend" />
					</a-card>

					<a-card
						:title="
							__(
								'Questions with no answer',
								'licensio-knowledge-base'
							)
						"
						class="licensio-kb-analytics__block"
					>
						<ZeroResultTable
							:rows="store.searches?.zero_results ?? []"
						/>
					</a-card>

					<a-card
						:title="__( 'Top searches', 'licensio-knowledge-base' )"
						class="licensio-kb-analytics__block"
					>
						<SearchTable :rows="store.searches?.top ?? []" />
					</a-card>

					<a-row :gutter="16" class="licensio-kb-analytics__block">
						<a-col :span="12">
							<a-card
								:title="
									__(
										'Most useful articles',
										'licensio-knowledge-base'
									)
								"
							>
								<ArticleTable
									:rows="store.articles?.most_useful ?? []"
									metric="usefulness"
								/>
							</a-card>
						</a-col>
						<a-col :span="12">
							<a-card
								:title="
									__(
										'Least useful articles',
										'licensio-knowledge-base'
									)
								"
							>
								<ArticleTable
									:rows="store.articles?.least_useful ?? []"
									metric="usefulness"
								/>
							</a-card>
						</a-col>
					</a-row>

					<a-card
						:title="
							__(
								'Most read articles',
								'licensio-knowledge-base'
							)
						"
						class="licensio-kb-analytics__block"
					>
						<ArticleTable
							:rows="store.articles?.most_viewed ?? []"
							metric="views"
						/>
					</a-card>

					<a-card
						:title="__( 'Feedback', 'licensio-knowledge-base' )"
						class="licensio-kb-analytics__block"
					>
						<a-descriptions
							bordered
							size="small"
							:column="{ xs: 1, sm: 2, md: 4 }"
						>
							<a-descriptions-item
								:label="
									__( 'Votes', 'licensio-knowledge-base' )
								"
							>
								{{ store.feedback?.totals.total ?? 0 }}
							</a-descriptions-item>
							<a-descriptions-item
								:label="
									__( 'Helpful', 'licensio-knowledge-base' )
								"
							>
								{{ store.feedback?.totals.positive ?? 0 }}
							</a-descriptions-item>
							<a-descriptions-item
								:label="
									__(
										'Not helpful',
										'licensio-knowledge-base'
									)
								"
							>
								{{ store.feedback?.totals.negative ?? 0 }}
							</a-descriptions-item>
							<a-descriptions-item
								:label="
									__( 'Useful', 'licensio-knowledge-base' )
								"
							>
								{{ store.feedback?.totals.usefulness ?? 0 }}%
							</a-descriptions-item>
						</a-descriptions>
					</a-card>
				</a-spin>
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.licensio-kb-analytics__notice,
.licensio-kb-analytics__block {
	margin-bottom: 16px;
}

.licensio-kb-analytics__range {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.licensio-kb-analytics__cards {
	margin-bottom: 16px;
}

.licensio-kb-analytics__retention {
	color: #646970;
}
</style>
