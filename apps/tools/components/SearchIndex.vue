<script setup lang="ts">
/**
 * The Search Index tab.
 *
 * Shows what the index holds against how many articles exist, and rebuilds
 * the index on demand. The rebuild empties the table first and then walks the
 * same chunked path the plugin's cron uses — one bounded chunk per request —
 * so it cannot blow a request budget, and a rebuild interrupted by a closed
 * browser is resumable from exactly where it stopped.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref } from 'vue';
import { indexChunk, indexStart, indexStatus } from '../api';
import type { IndexStatus } from '../types';
import ConfirmButton from '../../components/ConfirmButton.vue';
import { __ } from '../../components/i18n';

/** Pause between chunks, so the progress can be read as it moves. */
const CHUNK_PAUSE_MS = 300;

/** A rebuild is stuck, not broken, after this many chunks without finishing. */
const MAX_CHUNKS = 100000;

const loading = ref( true );
const error = ref( '' );
const status = ref< IndexStatus | null >( null );

const running = ref( false );
const finishedAt = ref( '' );

const indexed = computed( () => status.value?.indexed ?? 0 );
const articles = computed( () => status.value?.articles ?? 0 );
const rebuilding = computed(
	() => Boolean( status.value?.rebuilding ) && ! running.value
);

const percent = computed( () => {
	if ( ! articles.value ) {
		return running.value ? 0 : 100;
	}

	return Math.min(
		100,
		Math.round( ( indexed.value / articles.value ) * 100 )
	);
} );

/**
 * Load the index status.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function load(): Promise< void > {
	loading.value = true;
	error.value = '';

	try {
		status.value = await indexStatus();
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __(
						'Failed to load the index status.',
						'licensio-knowledge-base'
					);
	} finally {
		loading.value = false;
	}
}

/**
 * Drive chunks until the rebuild finishes.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function driveChunks(): Promise< void > {
	for ( let call = 0; call < MAX_CHUNKS; call++ ) {
		const response = await indexChunk();

		status.value = response.status;

		if ( response.finished ) {
			return;
		}

		await new Promise( ( resolve ) =>
			setTimeout( resolve, CHUNK_PAUSE_MS )
		);
	}

	throw new Error(
		__( 'The rebuild did not finish.', 'licensio-knowledge-base' )
	);
}

/**
 * Empty the index and rebuild it from scratch.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function rebuild(): Promise< void > {
	running.value = true;
	error.value = '';
	finishedAt.value = '';

	try {
		status.value = await indexStart();
		await driveChunks();
		finishedAt.value = new Date().toLocaleTimeString();
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'The rebuild failed.', 'licensio-knowledge-base' );
	} finally {
		running.value = false;
	}
}

/**
 * Resume a rebuild that a previous session left part-way through.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function resume(): Promise< void > {
	running.value = true;
	error.value = '';
	finishedAt.value = '';

	try {
		await driveChunks();
		finishedAt.value = new Date().toLocaleTimeString();
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'The rebuild failed.', 'licensio-knowledge-base' );
	} finally {
		running.value = false;
	}
}

onMounted( load );
</script>

<template>
	<div class="licensio-kb-search-index">
		<a-spin v-if="loading">
			<div class="licensio-kb-search-index__spin-body"></div>
		</a-spin>

		<a-alert v-else-if="error" type="error" show-icon :message="error" />

		<template v-else-if="status">
			<p class="licensio-kb-search-index__lede">
				{{
					__(
						'The search index holds one row per article. It is kept in sync automatically; rebuild it after bulk edits outside the editor, or when search results look stale.',
						'licensio-knowledge-base'
					)
				}}
			</p>

			<dl class="licensio-kb-search-index__stats">
				<div>
					<dt>
						{{
							__( 'Indexed articles', 'licensio-knowledge-base' )
						}}
					</dt>
					<dd>{{ indexed }}</dd>
				</div>
				<div>
					<dt>{{ __( 'Articles', 'licensio-knowledge-base' ) }}</dt>
					<dd>{{ articles }}</dd>
				</div>
			</dl>

			<a-tag v-if="rebuilding" color="orange">
				{{
					__(
						'A rebuild is part-way through',
						'licensio-knowledge-base'
					)
				}}
			</a-tag>

			<a-progress
				v-if="running || finishedAt"
				:percent="percent"
				:status="
					error ? 'exception' : finishedAt ? 'success' : 'active'
				"
			/>

			<p v-if="finishedAt" class="licensio-kb-search-index__done">
				{{ __( 'The index was rebuilt.', 'licensio-knowledge-base' ) }}
			</p>

			<div class="licensio-kb-search-index__actions">
				<ConfirmButton
					type="primary"
					:disabled="running"
					:title="
						__(
							'Empty the index and re-index every article?',
							'licensio-knowledge-base'
						)
					"
					:ok-text="__( 'Rebuild', 'licensio-knowledge-base' )"
					@confirm="void rebuild()"
				>
					{{ __( 'Rebuild index', 'licensio-knowledge-base' ) }}
				</ConfirmButton>

				<a-button v-if="rebuilding" :disabled="running" @click="resume">
					{{ __( 'Resume rebuild', 'licensio-knowledge-base' ) }}
				</a-button>
			</div>
		</template>
	</div>
</template>

<style scoped>
.licensio-kb-search-index__spin-body {
	min-height: 120px;
}

.licensio-kb-search-index__lede {
	margin: 0 0 16px;
}

.licensio-kb-search-index__stats {
	display: flex;
	gap: 32px;
	margin: 0 0 16px;
}

.licensio-kb-search-index__stats dt {
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-search-index__stats dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.licensio-kb-search-index__done {
	margin: 12px 0 0;
}

.licensio-kb-search-index__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 20px;
}
</style>
