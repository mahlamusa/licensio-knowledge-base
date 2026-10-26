<script setup lang="ts">
/**
 * The progress and outcome of a bulk run.
 *
 * A modal with a progress bar while the batches run, then the outcome: how
 * many articles were done, skipped (with the reason for each) and failed, and
 * why the run stopped if a batch was refused. It cannot be closed while the
 * run is going.
 *
 * Used by the Articles app only. If another app needs it, it moves to the
 * shared components.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { __, sprintf } from '../../components/i18n';
import { reasonLabel } from '../actions';
import { useArticlesStore } from '../store';

const store = useArticlesStore();

const percent = computed( () =>
	store.bulk.total > 0
		? Math.round( ( store.bulk.processed / store.bulk.total ) * 100 )
		: 0
);

const status = computed( () => {
	if ( store.bulk.running ) {
		return 'active';
	}

	return '' !== store.bulk.error ? 'exception' : 'success';
} );

const titleById = computed( () => {
	const titles: Record< number, string > = {};

	store.items.forEach( ( item ) => {
		titles[ item.id ] = item.title;
	} );

	return titles;
} );

/**
 * A readable name for an article in the outcome lists.
 *
 * @since 1.0.0
 *
 * @param {number} id The article ID.
 *
 * @return {string} Its title when it is on screen, else its ID.
 */
function nameOf( id: number ): string {
	return (
		titleById.value[ id ] ||
		sprintf(
			/* translators: %d: article ID. */
			__( 'Article #%d', 'licensio-knowledge-base' ),
			id
		)
	);
}
</script>

<template>
	<a-modal
		:open="store.bulk.open"
		:title="__( 'Bulk action', 'licensio-knowledge-base' )"
		:closable="! store.bulk.running"
		:mask-closable="false"
		:keyboard="! store.bulk.running"
		:footer="null"
		@cancel="store.closeBulk()"
	>
		<a-progress :percent="percent" :status="status" />

		<p class="lkb-bulkprogress__line" aria-live="polite">
			{{
				sprintf(
					/* translators: 1: articles processed, 2: articles in the run. */
					__( '%1$d of %2$d processed', 'licensio-knowledge-base' ),
					store.bulk.processed,
					store.bulk.total
				)
			}}
		</p>

		<ul v-if="! store.bulk.running" class="lkb-bulkprogress__summary">
			<li>
				{{
					sprintf(
						/* translators: %d: number of articles. */
						__( 'Done: %d', 'licensio-knowledge-base' ),
						store.bulk.done.length
					)
				}}
			</li>
			<li>
				{{
					sprintf(
						/* translators: %d: number of articles. */
						__( 'Skipped: %d', 'licensio-knowledge-base' ),
						store.bulk.skipped.length
					)
				}}
			</li>
			<li>
				{{
					sprintf(
						/* translators: %d: number of articles. */
						__( 'Failed: %d', 'licensio-knowledge-base' ),
						store.bulk.failed.length
					)
				}}
			</li>
		</ul>

		<a-alert
			v-if="store.bulk.error"
			type="error"
			show-icon
			:message="
				__(
					'The run stopped at a batch the server refused.',
					'licensio-knowledge-base'
				)
			"
			:description="store.bulk.error"
			class="lkb-bulkprogress__alert"
		/>

		<template v-if="! store.bulk.running && store.bulk.skipped.length">
			<h3 class="lkb-bulkprogress__heading">
				{{ __( 'Skipped', 'licensio-knowledge-base' ) }}
			</h3>
			<ul class="lkb-bulkprogress__list">
				<li v-for="entry in store.bulk.skipped" :key="'s' + entry.id">
					{{
						sprintf(
							/* translators: 1: article title, 2: why it was skipped. */
							__( '%1$s — %2$s', 'licensio-knowledge-base' ),
							nameOf( entry.id ),
							reasonLabel( entry.reason )
						)
					}}
				</li>
			</ul>
		</template>

		<template v-if="! store.bulk.running && store.bulk.failed.length">
			<h3 class="lkb-bulkprogress__heading">
				{{ __( 'Failed', 'licensio-knowledge-base' ) }}
			</h3>
			<ul class="lkb-bulkprogress__list">
				<li v-for="entry in store.bulk.failed" :key="'f' + entry.id">
					{{
						sprintf(
							/* translators: 1: article title, 2: why it failed. */
							__( '%1$s — %2$s', 'licensio-knowledge-base' ),
							nameOf( entry.id ),
							entry.message
						)
					}}
				</li>
			</ul>
		</template>

		<div class="lkb-bulkprogress__footer">
			<a-button
				type="primary"
				:disabled="store.bulk.running"
				@click="store.closeBulk()"
			>
				{{ __( 'Close', 'licensio-knowledge-base' ) }}
			</a-button>
		</div>
	</a-modal>
</template>

<style scoped>
.lkb-bulkprogress__line {
	margin: 8px 0;
	color: var( --lkb-ink-muted );
}

.lkb-bulkprogress__summary {
	display: flex;
	gap: 16px;
	margin: 8px 0;
	padding: 0;
	list-style: none;
	font-weight: 600;
}

.lkb-bulkprogress__alert {
	margin: 12px 0;
}

.lkb-bulkprogress__heading {
	margin: 12px 0 4px;
	font-size: 13px;
}

.lkb-bulkprogress__list {
	max-height: 160px;
	margin: 0;
	padding-left: 18px;
	overflow-y: auto;
	font-size: 13px;
}

.lkb-bulkprogress__footer {
	display: flex;
	justify-content: flex-end;
	margin-top: 16px;
}
</style>
