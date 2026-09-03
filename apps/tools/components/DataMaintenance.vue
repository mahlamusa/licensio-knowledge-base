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
import {
	maintenanceStatus,
	pruneAnalytics,
	recountScores,
	translate,
} from '../api';
import type { MaintenanceStatus } from '../types';

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
	translate( 'Rows older than' ) + ' ' + ( status.value?.cutoff ?? '—' )
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
		error.value = err instanceof Error ? err.message : translate( 'Failed to load the status.' );
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
		error.value = err instanceof Error ? err.message : translate( 'The recount failed.' );
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

			for ( const [ table, rows ] of Object.entries( response.deleted ) ) {
				prunedTables.value[ table ] =
					( prunedTables.value[ table ] ?? 0 ) + rows;
				prunedTotal.value += rows;
			}

			if ( response.complete ) {
				return;
			}
		}

		throw new Error(
			translate( 'The backlog is too large for the browser; finish it with WP-CLI.' )
		);
	} catch ( err ) {
		error.value = err instanceof Error ? err.message : translate( 'The deletion failed.' );
	} finally {
		pruning.value = false;
	}
}

onMounted( load );
</script>

<template>
	<div class="lisensio-kb-data-maintenance">
		<a-spin v-if="loading">
			<div class="lisensio-kb-data-maintenance__spin-body"></div>
		</a-spin>

		<a-alert v-else-if="error" type="error" show-icon :message="error" />

		<template v-else-if="status">
			<a-card class="lisensio-kb-data-maintenance__card" :title="translate( 'Feedback usefulness scores' )">
				<p>
					{{
						translate(
							'Each article\'s helpfulness score is a cache over the vote table. Migrated, imported or hand-edited votes can leave it stale; this recounts every article from the raw rows.'
						)
					}}
				</p>

				<dl class="lisensio-kb-data-maintenance__stats">
					<div>
						<dt>{{ translate( 'Articles' ) }}</dt>
						<dd>{{ status.articles }}</dd>
					</div>
					<div>
						<dt>{{ translate( 'Votes recorded' ) }}</dt>
						<dd>{{ status.feedbackRows }}</dd>
					</div>
				</dl>

				<a-button
					type="primary"
					:loading="recounting"
					:disabled="recounting || pruning"
					@click="recount"
				>
					{{ translate( 'Recount scores' ) }}
				</a-button>

				<p v-if="recounted" class="lisensio-kb-data-maintenance__done">
					{{
						translate( 'Recounted the score of' ) +
							' ' +
							recounted +
							' ' +
							translate( 'articles.' )
					}}
				</p>
			</a-card>

			<a-card
				class="lisensio-kb-data-maintenance__card"
				:title="translate( 'Analytics retention' )"
			>
				<p>
					{{
						translate(
							'Analytics rows older than the retention window are deleted by a scheduled job. This runs the same deletion now.'
						)
					}}
					{{ retentionSummary }}.
				</p>

				<dl class="lisensio-kb-data-maintenance__stats">
					<div>
						<dt>{{ translate( 'Retention window' ) }}</dt>
						<dd>{{ status.retentionDays }} {{ translate( 'days' ) }}</dd>
					</div>
					<div>
						<dt>{{ translate( 'Article views' ) }}</dt>
						<dd>{{ status.viewRows }}</dd>
					</div>
					<div>
						<dt>{{ translate( 'Searches' ) }}</dt>
						<dd>{{ status.searchRows }}</dd>
					</div>
					<div>
						<dt>{{ translate( 'Votes' ) }}</dt>
						<dd>{{ status.feedbackRows }}</dd>
					</div>
				</dl>

				<a-popconfirm
					:title="translate( 'Permanently delete the expired rows?' )"
					:ok-text="translate( 'Delete' )"
					:cancel-text="translate( 'Cancel' )"
					@confirm="prune"
				>
					<a-button
						type="primary"
						danger
						:loading="pruning"
						:disabled="recounting || pruning"
					>
						{{ translate( 'Delete expired rows' ) }}
					</a-button>
				</a-popconfirm>

				<template v-if="prunedTotal > 0 || Object.keys( prunedTables ).length > 0">
					<p class="lisensio-kb-data-maintenance__done">
						{{ translate( 'Deleted' ) }} {{ prunedTotal }}
						{{ translate( 'rows:' ) }}
					</p>
					<ul class="lisensio-kb-data-maintenance__deleted">
						<li v-for="( rows, table ) in prunedTables" :key="table">
							{{ table }}: {{ rows }}
						</li>
					</ul>
				</template>
			</a-card>
		</template>
	</div>
</template>

<style scoped>
.lisensio-kb-data-maintenance__spin-body {
	min-height: 120px;
}

.lisensio-kb-data-maintenance__card {
	margin-bottom: 16px;
}

.lisensio-kb-data-maintenance__stats {
	display: flex;
	flex-wrap: wrap;
	gap: 32px;
	margin: 0 0 16px;
}

.lisensio-kb-data-maintenance__stats dt {
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.lisensio-kb-data-maintenance__stats dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.lisensio-kb-data-maintenance__done {
	margin: 16px 0 0;
}

.lisensio-kb-data-maintenance__deleted {
	margin: 4px 0 0;
	padding-left: 20px;
}
</style>
