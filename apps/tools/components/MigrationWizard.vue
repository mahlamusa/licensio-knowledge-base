<script setup lang="ts">
/**
 * The Migration tab.
 *
 * Moves a knowledge base from another plugin into this one, one batch per
 * request: pick a detected source, verify it with a dry run, then commit the
 * real import and read the report. Every write decision stays on the server —
 * the tab only paces the batches and renders what comes back.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref } from 'vue';
import {
	config,
	detectSources,
	resetMigration,
	runBatch,
	translate,
} from '../api';
import type { ImportSummary, SourceDriver } from '../types';

/**
 * The phases a run walks, in order. Mirrors
 * `Lisensio_KB_Heroic_Import_State::PHASES`; the bar advances by phase rather
 * than by row count because a true row total would need a COUNT query on
 * every batch.
 */
const PHASES = [ 'terms', 'articles', 'feedback', 'views', 'searches', 'complete' ];

/** How many rows one batch may read. */
const BATCH_SIZE = 50;

/** Pause between batches, so the operator can read the phase as it moves. */
const BATCH_PAUSE_MS = 300;

const chrome = config();

const booting = ref( true );
const drivers = ref< SourceDriver[] >( [] );
const detectError = ref( '' );
const selected = ref( '' );
const dryRun = ref( true );

const running = ref( false );
const runIsDry = ref( true );
const phase = ref( '' );
const lastBatch = ref( 0 );
const runError = ref( '' );
const summary = ref< ImportSummary | null >( null );

const summaryEntries = computed( () =>
	Object.entries( summary.value?.counts ?? {} )
);

const noteSections = computed( () =>
	Object.entries( summary.value?.notes ?? {} ).filter(
		( [ , lines ] ) => lines.length > 0
	)
);

/**
 * A snake_case report key as tab-readable words.
 *
 * The server counts what it counts; the names are its vocabulary, so they are
 * only capitalised here rather than renamed.
 *
 * @since 1.0.0
 *
 * @param {string} key The report key.
 *
 * @return {string} The key in title case.
 */
function humanize( key: string ): string {
	return key
		.split( /[_\s]+/ )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
}

/**
 * How far through the run the active phase is.
 *
 * @since 1.0.0
 *
 * @return {number} Percent, 0-100.
 */
const percent = computed( () => {
	if ( summary.value ) {
		return 100;
	}

	const index = Math.max( 0, PHASES.indexOf( phase.value ) );

	return Math.min( 100, Math.round( ( ( index + 1 ) / PHASES.length ) * 100 ) );
} );

/**
 * The phase name as a sentence fragment.
 *
 * @since 1.0.0
 *
 * @return {string} The phase, capitalised, or the start-up label.
 */
const phaseLabel = computed( () => {
	if ( summary.value ) {
		return translate( 'Complete' );
	}

	if ( ! phase.value ) {
		return translate( 'Preparing…' );
	}

	if ( 'complete' === phase.value ) {
		return translate( 'Wrapping up…' );
	}

	return translate( 'Processing phase' ) + ': ' + humanize( phase.value );
} );

/**
 * Look for sources to import.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function detect(): Promise< void > {
	booting.value = true;
	detectError.value = '';
	drivers.value = [];
	summary.value = null;
	running.value = false;

	try {
		drivers.value = await detectSources();
		selected.value = drivers.value[ 0 ]?.id ?? '';
	} catch ( error ) {
		detectError.value =
			error instanceof Error ? error.message : translate( 'Failed to detect sources.' );
	} finally {
		booting.value = false;
	}
}

/**
 * Start a run: reset the previous run's state, then drive batches.
 *
 * The reset is unconditional because a previous run's cursors must never
 * bleed into this one — an abandoned dry run half-way through the articles
 * would otherwise make the real run skip everything the dry run walked past.
 *
 * @since 1.0.0
 *
 * @param {boolean} isDryRun Whether this run may write.
 *
 * @return {Promise<void>}
 */
async function start( isDryRun: boolean ): Promise< void > {
	if ( ! selected.value ) {
		return;
	}

	running.value = true;
	runIsDry.value = isDryRun;
	runError.value = '';
	summary.value = null;
	phase.value = '';
	lastBatch.value = 0;

	try {
		await resetMigration( selected.value );
		await loop( isDryRun );
	} catch ( error ) {
		runError.value =
			error instanceof Error ? error.message : translate( 'The import failed.' );
		running.value = false;
	}
}

/**
 * Drive batches until the server reports the run finished.
 *
 * @since 1.0.0
 *
 * @param {boolean} isDryRun Whether this run may write.
 *
 * @return {Promise<void>}
 */
async function loop( isDryRun: boolean ): Promise< void > {
	// The driver's own loop guard: a site of any sane size finishes well
	// inside this many batches, and a run that does not has a server-side
	// problem a client cannot fix.
	for ( let batch = 0; batch < 100000; batch++ ) {
		const response = await runBatch( selected.value, isDryRun, BATCH_SIZE );

		phase.value = response.progress.phase;
		lastBatch.value = response.progress.done;

		if ( response.progress.finished ) {
			summary.value = response.summary ?? null;
			running.value = false;
			return;
		}

		await new Promise( ( resolve ) =>
			setTimeout( resolve, BATCH_PAUSE_MS )
		);
	}
}

/**
 * Reset the migration state and return to source selection.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function resetState(): Promise< void > {
	if ( selected.value ) {
		await resetMigration( selected.value );
	}

	await detect();
}

onMounted( detect );
</script>

<template>
	<div class="lisensio-kb-migration">
		<a-spin v-if="booting" :tip="translate( 'Checking for import sources…' )">
			<div class="lisensio-kb-migration__spin-body"></div>
		</a-spin>

		<a-alert
			v-else-if="detectError"
			type="error"
			show-icon
			:message="detectError"
		/>

		<a-alert
			v-else-if="0 === drivers.length"
			type="warning"
			show-icon
			:message="translate( 'No import sources detected on this installation.' )"
		/>

		<template v-else-if="! running && ! summary && ! runError">
			<p class="lisensio-kb-migration__lede">
				{{
					translate(
						'The following knowledge base plugins have data available for import:'
					)
				}}
			</p>

			<a-radio-group
				v-model:value="selected"
				class="lisensio-kb-migration__sources"
			>
				<a-card
					v-for="driver in drivers"
					:key="driver.id"
					class="lisensio-kb-migration__source"
				>
					<a-radio :value="driver.id">
						<strong>{{ driver.id.toUpperCase() }}</strong>
					</a-radio>

					<dl class="lisensio-kb-migration__counts">
						<div>
							<dt>{{ translate( 'Articles' ) }}</dt>
							<dd>{{ driver.counts.articles }}</dd>
						</div>
						<div>
							<dt>{{ translate( 'Categories' ) }}</dt>
							<dd>{{ driver.counts.categories }}</dd>
						</div>
						<div>
							<dt>{{ translate( 'Tags' ) }}</dt>
							<dd>{{ driver.counts.tags }}</dd>
						</div>
					</dl>
				</a-card>
			</a-radio-group>

			<div class="lisensio-kb-migration__options">
				<a-checkbox v-model:checked="dryRun">
					{{ translate( 'Dry run (verify before importing)' ) }}
				</a-checkbox>
			</div>

			<a-button
				type="primary"
				:disabled="! selected"
				@click="start( dryRun )"
			>
				{{ translate( 'Start migration' ) }}
			</a-button>
		</template>

		<div v-else class="lisensio-kb-migration__run">
			<h2 class="lisensio-kb-migration__run-title">
				{{
					runIsDry
						? translate( 'Verifying import (dry run)' )
						: translate( 'Importing data' )
				}}
			</h2>

			<a-alert
				v-if="runError"
				type="error"
				show-icon
				:message="runError"
				class="lisensio-kb-migration__error"
			/>

			<a-progress
				:percent="percent"
				:status="runError ? 'exception' : summary ? 'success' : 'active'"
			/>

			<p class="lisensio-kb-migration__phase">{{ phaseLabel }}</p>
			<p
				v-if="running"
				class="lisensio-kb-migration__batch"
			>
				{{
					translate( 'Processed' ) +
						' ' +
						lastBatch +
						' ' +
						translate( 'items in the latest batch.' )
				}}
			</p>

			<template v-if="summary">
				<a-descriptions
					:column="2"
					bordered
					size="small"
					class="lisensio-kb-migration__counts-table"
				>
					<a-descriptions-item
						v-for="[ key, value ] in summaryEntries"
						:key="key"
						:label="humanize( key )"
					>
						{{ value }}
					</a-descriptions-item>
				</a-descriptions>

				<section
					v-for="[ category, lines ] in noteSections"
					:key="category"
					class="lisensio-kb-migration__notes"
				>
					<h3>{{ humanize( category ) }}</h3>
					<ul>
						<li v-for="line in lines" :key="line">{{ line }}</li>
					</ul>
				</section>

				<div
					v-if="summary.redirects_url || summary.url_map_url"
					class="lisensio-kb-migration__downloads"
				>
					<a-button
						v-if="summary.redirects_url"
						:href="summary.redirects_url"
					>
						{{ translate( 'Download redirect drop-in' ) }}
					</a-button>
					<a-button v-if="summary.url_map_url" :href="summary.url_map_url">
						{{ translate( 'Download URL map (CSV)' ) }}
					</a-button>
				</div>
			</template>

			<div class="lisensio-kb-migration__actions">
				<a-button
					v-if="summary && runIsDry"
					type="primary"
					@click="start( false )"
				>
					{{ translate( 'Looks good, run the full import' ) }}
				</a-button>
				<a-button
					v-if="summary && ! runIsDry && chrome.articlesUrl"
					:href="chrome.articlesUrl"
				>
					{{ translate( 'View knowledge base' ) }}
				</a-button>
				<a-button
					:disabled="running"
					@click="resetState"
				>
					{{ translate( 'Reset state' ) }}
				</a-button>
			</div>
		</div>
	</div>
</template>

<style scoped>
.lisensio-kb-migration__spin-body {
	min-height: 120px;
}

.lisensio-kb-migration__lede {
	margin: 0 0 12px;
}

.lisensio-kb-migration__sources {
	display: block;
	width: 100%;
}

.lisensio-kb-migration__source {
	margin-bottom: 12px;
}

.lisensio-kb-migration__counts {
	display: flex;
	gap: 32px;
	margin: 12px 0 0 26px;
}

.lisensio-kb-migration__counts dt {
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.lisensio-kb-migration__counts dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.lisensio-kb-migration__options {
	margin: 16px 0;
}

.lisensio-kb-migration__run-title {
	margin: 0 0 16px;
	font-size: 16px;
}

.lisensio-kb-migration__error {
	margin-bottom: 16px;
}

.lisensio-kb-migration__phase {
	margin: 12px 0 4px;
	font-weight: 600;
}

.lisensio-kb-migration__batch {
	margin: 0;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.lisensio-kb-migration__counts-table {
	margin-top: 20px;
}

.lisensio-kb-migration__notes h3 {
	margin: 20px 0 4px;
	font-size: 13px;
}

.lisensio-kb-migration__notes ul {
	margin: 0;
	padding-left: 20px;
}

.lisensio-kb-migration__downloads,
.lisensio-kb-migration__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 20px;
}
</style>
