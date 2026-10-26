<script setup lang="ts">
/**
 * The Data Maintenance tab.
 *
 * Two jobs an administrator runs once in a while: recounting every article's
 * usefulness score from the raw votes (the score is a cache, and caches can
 * drift), and deleting analytics rows past the retention window now rather
 * than waiting for the next scheduled sweep.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref } from 'vue';
import { maintenanceStatus, pruneAnalytics, recountScores } from '../api';
import type { MaintenanceStatus } from '../types';
import ConfirmButton from '../../components/ConfirmButton.vue';
import { __, _n, sprintf } from '../../components/i18n';

/** A backlog bigger than this many sweeps is a job for WP-CLI, not a browser. */
const MAX_SWEEPS = 50;

const loading = ref( true );
const error = ref( '' );
const status = ref< MaintenanceStatus | null >( null );

const recounting = ref( false );
const recounted = ref( 0 );

const pruning = ref( false );
const prunedTotal = ref( 0 );
const prunedTables = ref< Record< string, number > >( {} );

const retentionSummary = computed( () =>
	sprintf(
		/* translators: %s: the date before which rows are deleted. */
		__( 'Rows older than %s', 'licensio-knowledge-base' ),
		status.value?.cutoff ?? '—'
	)
);

/**
 * Load the maintenance status.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function load(): Promise< void > {
	loading.value = true;
	error.value = '';

	try {
		status.value = await maintenanceStatus();
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'Failed to load the status.', 'licensio-knowledge-base' );
	} finally {
		loading.value = false;
	}
}

/**
 * Recompute every article's usefulness score.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function recount(): Promise< void > {
	recounting.value = true;
	error.value = '';
	recounted.value = 0;

	try {
		const response = await recountScores();

		recounted.value = response.articles;
		status.value = response.status;
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'The recount failed.', 'licensio-knowledge-base' );
	} finally {
		recounting.value = false;
	}
}

/**
 * Delete every expired analytics row, one bounded sweep at a time.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function prune(): Promise< void > {
	pruning.value = true;
	error.value = '';
	prunedTotal.value = 0;
	prunedTables.value = {};

	try {
		for ( let sweep = 0; sweep < MAX_SWEEPS; sweep++ ) {
			const response = await pruneAnalytics();

			status.value = response.status;

			for ( const [ table, rows ] of Object.entries(
				response.deleted
			) ) {
				prunedTables.value[ table ] =
					( prunedTables.value[ table ] ?? 0 ) + rows;
				prunedTotal.value += rows;
			}

			if ( response.complete ) {
				return;
			}
		}

		throw new Error(
			__(
				'The backlog is too large for the browser; finish it with WP-CLI.',
				'licensio-knowledge-base'
			)
		);
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'The deletion failed.', 'licensio-knowledge-base' );
	} finally {
		pruning.value = false;
	}
}

onMounted( load );
</script>

<template>
	<div class="licensio-kb-data-maintenance">
		<a-spin v-if="loading">
			<div class="licensio-kb-data-maintenance__spin-body"></div>
		</a-spin>

		<a-alert v-else-if="error" type="error" show-icon :message="error" />

		<template v-else-if="status">
			<a-card
				class="licensio-kb-data-maintenance__card"
				:title="
					__(
						'Feedback usefulness scores',
						'licensio-knowledge-base'
					)
				"
			>
				<p>
					{{
						__(
							"Each article's helpfulness score is a cache over the vote table. Migrated, imported or hand-edited votes can leave it stale; this recounts every article from the raw rows.",
							'licensio-knowledge-base'
						)
					}}
				</p>

				<dl class="licensio-kb-data-maintenance__stats">
					<div>
						<dt>
							{{ __( 'Articles', 'licensio-knowledge-base' ) }}
						</dt>
						<dd>{{ status.articles }}</dd>
					</div>
					<div>
						<dt>
							{{
								__(
									'Votes recorded',
									'licensio-knowledge-base'
								)
							}}
						</dt>
						<dd>{{ status.feedbackRows }}</dd>
					</div>
				</dl>

				<a-button
					type="primary"
					:loading="recounting"
					:disabled="recounting || pruning"
					@click="recount"
				>
					{{ __( 'Recount scores', 'licensio-knowledge-base' ) }}
				</a-button>

				<p v-if="recounted" class="licensio-kb-data-maintenance__done">
					{{
						sprintf(
							/* translators: %d: how many articles had their score recounted. */
							_n(
								'Recounted the score of %d article.',
								'Recounted the score of %d articles.',
								recounted,
								'licensio-knowledge-base'
							),
							recounted
						)
					}}
				</p>
			</a-card>

			<a-card
				class="licensio-kb-data-maintenance__card"
				:title="__( 'Analytics retention', 'licensio-knowledge-base' )"
			>
				<p>
					{{
						__(
							'Analytics rows older than the retention window are deleted by a scheduled job. This runs the same deletion now.',
							'licensio-knowledge-base'
						)
					}}
					{{ retentionSummary }}.
				</p>

				<dl class="licensio-kb-data-maintenance__stats">
					<div>
						<dt>
							{{
								__(
									'Retention window',
									'licensio-knowledge-base'
								)
							}}
						</dt>
						<dd>
							{{
								sprintf(
									/* translators: %d: how many days analytics rows are kept. */
									_n(
										'%d day',
										'%d days',
										status.retentionDays,
										'licensio-knowledge-base'
									),
									status.retentionDays
								)
							}}
						</dd>
					</div>
					<div>
						<dt>
							{{
								__( 'Article views', 'licensio-knowledge-base' )
							}}
						</dt>
						<dd>{{ status.viewRows }}</dd>
					</div>
					<div>
						<dt>
							{{ __( 'Searches', 'licensio-knowledge-base' ) }}
						</dt>
						<dd>{{ status.searchRows }}</dd>
					</div>
					<div>
						<dt>{{ __( 'Votes', 'licensio-knowledge-base' ) }}</dt>
						<dd>{{ status.feedbackRows }}</dd>
					</div>
				</dl>

				<ConfirmButton
					type="primary"
					danger
					:loading="pruning"
					:disabled="recounting || pruning"
					:title="
						__(
							'Permanently delete the expired rows?',
							'licensio-knowledge-base'
						)
					"
					:ok-text="__( 'Delete', 'licensio-knowledge-base' )"
					@confirm="prune"
				>
					{{ __( 'Delete expired rows', 'licensio-knowledge-base' ) }}
				</ConfirmButton>

				<template
					v-if="
						prunedTotal > 0 ||
						Object.keys( prunedTables ).length > 0
					"
				>
					<p class="licensio-kb-data-maintenance__done">
						{{
							sprintf(
								/* translators: %d: how many expired rows were deleted. */
								_n(
									'Deleted %d row:',
									'Deleted %d rows:',
									prunedTotal,
									'licensio-knowledge-base'
								),
								prunedTotal
							)
						}}
					</p>
					<ul class="licensio-kb-data-maintenance__deleted">
						<li v-for="(rows, table) in prunedTables" :key="table">
							{{ table }}: {{ rows }}
						</li>
					</ul>
				</template>
			</a-card>
		</template>
	</div>
</template>

<style scoped>
.licensio-kb-data-maintenance__spin-body {
	min-height: 120px;
}

.licensio-kb-data-maintenance__card {
	margin-bottom: 16px;
}

.licensio-kb-data-maintenance__stats {
	display: flex;
	flex-wrap: wrap;
	gap: 32px;
	margin: 0 0 16px;
}

.licensio-kb-data-maintenance__stats dt {
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-data-maintenance__stats dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.licensio-kb-data-maintenance__done {
	margin: 16px 0 0;
}

.licensio-kb-data-maintenance__deleted {
	margin: 4px 0 0;
	padding-left: 20px;
}
</style>
