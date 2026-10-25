<script setup lang="ts">
/**
 * The Migration tab.
 *
 * Moves a knowledge base from another plugin into this one, one batch per
 * request: pick a detected source, decide where its categories go, verify it
 * with a dry run, then run the real import and read the report and the log.
 * Every write decision stays on the server — the tab only paces the batches
 * and renders what comes back.
 *
 * An interrupted import is resumed, never restarted: the server keeps its
 * cursors, and starting again from zero would import the analytics twice.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref } from 'vue';
import {
	config,
	detectSources,
	fetchCategories,
	resetMigration,
	runBatch,
} from '../api';
import type {
	ImportSummary,
	SourceCategory,
	SourceDriver,
	TargetCategory,
} from '../types';
import MigrationLog from './MigrationLog.vue';
import { __, _n, sprintf } from '../../components/i18n';

/** How many rows one batch may read. */
const BATCH_SIZE = 50;

/** Pause between batches, so the operator can read the phase as it moves. */
const BATCH_PAUSE_MS = 300;

/** The wizard's steps. */
type Step = 'source' | 'categories' | 'run';

const chrome = config();

const booting = ref( true );
const drivers = ref< SourceDriver[] >( [] );
const detectError = ref( '' );
const selected = ref( '' );
const step = ref< Step >( 'source' );

const loadingCategories = ref( false );
const sourceCategories = ref< SourceCategory[] >( [] );
const targetCategories = ref< TargetCategory[] >( [] );
const categoryMap = ref< Record< number, number > >( {} );
const importSettings = ref( false );

const running = ref( false );
const runIsDry = ref( true );
const phase = ref( '' );
const lastBatch = ref( 0 );
const runError = ref( '' );
const summary = ref< ImportSummary | null >( null );

const driver = computed( () =>
	drivers.value.find( ( item ) => item.id === selected.value )
);

const phases = computed( () => [ ...( driver.value?.phases ?? [] ), 'complete' ] );

const summaryEntries = computed( () =>
	Object.entries( summary.value?.counts ?? {} )
);

const noteSections = computed( () =>
	Object.entries( summary.value?.notes ?? {} ).filter(
		( [ , lines ] ) => lines.length > 0
	)
);

/**
 * The source categories with their depth, for an indented list.
 *
 * The server returns them parents first, so one pass is enough.
 *
 * @since 1.0.0
 *
 * @return {Array<SourceCategory & {depth: number}>} The categories.
 */
const categoryRows = computed( () => {
	const depth: Record< number, number > = {};

	return sourceCategories.value.map( ( category ) => {
		depth[ category.id ] = category.parent > 0 ? ( depth[ category.parent ] ?? 0 ) + 1 : 0;

		return { ...category, depth: depth[ category.id ] };
	} );
} );

/**
 * The existing categories as select options, indented by depth.
 *
 * @since 1.0.0
 *
 * @return {Array<{value: number; label: string}>} The options, "create new" first.
 */
const targetOptions = computed( () => {
	const byParent: Record< number, TargetCategory[] > = {};

	for ( const target of targetCategories.value ) {
		( byParent[ target.parent ] ??= [] ).push( target );
	}

	const options: Array< { value: number; label: string } > = [
		{ value: 0, label: __( 'Create a new category', 'licensio-knowledge-base' ) },
	];
	const seen = new Set< number >();

	const walk = ( parent: number, depth: number ): void => {
		for ( const target of byParent[ parent ] ?? [] ) {
			if ( seen.has( target.id ) ) {
				continue;
			}

			seen.add( target.id );
			options.push( {
				value: target.id,
				label: sprintf(
					/* translators: %s: the target category's name. */
					__( 'Merge into: %s', 'licensio-knowledge-base' ),
					'— '.repeat( depth ) + target.name
				),
			} );
			walk( target.id, depth + 1 );
		}
	};

	walk( 0, 0 );

	// Orphans whose parent is not in the list still need to be offered.
	for ( const target of targetCategories.value ) {
		if ( ! seen.has( target.id ) ) {
			options.push( {
				value: target.id,
				label: sprintf(
					/* translators: %s: the target category's name. */
					__( 'Merge into: %s', 'licensio-knowledge-base' ),
					target.name
				),
			} );
		}
	}

	return options;
} );

/**
 * A snake_case report key as tab-readable words.
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
 * A Unix timestamp as a local date and time.
 *
 * @since 1.0.0
 *
 * @param {number} timestamp Seconds since the epoch.
 *
 * @return {string} The formatted date.
 */
function formatTime( timestamp: number ): string {
	return new Date( timestamp * 1000 ).toLocaleString();
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

	const index = Math.max( 0, phases.value.indexOf( phase.value ) );

	return Math.min( 100, Math.round( ( ( index + 1 ) / phases.value.length ) * 100 ) );
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
		return __( 'Complete', 'licensio-knowledge-base' );
	}

	if ( ! phase.value ) {
		return __( 'Preparing…', 'licensio-knowledge-base' );
	}

	if ( 'complete' === phase.value ) {
		return __( 'Wrapping up…', 'licensio-knowledge-base' );
	}

	return sprintf(
		/* translators: %s: the name of the import phase being processed. */
		__( 'Processing phase: %s', 'licensio-knowledge-base' ),
		humanize( phase.value )
	);
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
	runError.value = '';
	step.value = 'source';

	try {
		drivers.value = await detectSources();

		if ( ! drivers.value.some( ( item ) => item.id === selected.value ) ) {
			selected.value = drivers.value[ 0 ]?.id ?? '';
		}
	} catch ( error ) {
		detectError.value =
			error instanceof Error ? error.message : __( 'Failed to detect sources.', 'licensio-knowledge-base' );
	} finally {
		booting.value = false;
	}
}

/**
 * Move to the category step, loading the selected source's categories.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function toCategories(): Promise< void > {
	if ( ! selected.value ) {
		return;
	}

	step.value = 'categories';
	loadingCategories.value = true;
	runError.value = '';

	try {
		const lists = await fetchCategories( selected.value );

		sourceCategories.value = lists.source;
		targetCategories.value = lists.targets;
		categoryMap.value = Object.fromEntries(
			lists.source.map( ( category ) => [ category.id, categoryMap.value[ category.id ] ?? 0 ] )
		);
	} catch ( error ) {
		runError.value =
			error instanceof Error ? error.message : __( 'The categories could not be loaded.', 'licensio-knowledge-base' );
	} finally {
		loadingCategories.value = false;
	}
}

/**
 * Start a run and drive its batches.
 *
 * A dry run always starts clean — its state is scratch. A real run never
 * resets: the server resumes an interrupted run or opens a new one after a
 * finished one, keeping the analytics cursors either way.
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

	step.value = 'run';
	running.value = true;
	runIsDry.value = isDryRun;
	runError.value = '';
	summary.value = null;
	phase.value = '';
	lastBatch.value = 0;

	try {
		if ( isDryRun ) {
			await resetMigration( selected.value, true );
		}

		await loop( isDryRun );
	} catch ( error ) {
		runError.value =
			error instanceof Error ? error.message : __( 'The import failed.', 'licensio-knowledge-base' );
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
	const map = Object.fromEntries(
		Object.entries( categoryMap.value ).filter( ( [ , target ] ) => target > 0 )
	);

	// The driver's own loop guard: a site of any sane size finishes well
	// inside this many batches, and a run that does not has a server-side
	// problem a client cannot fix.
	for ( let batch = 0; batch < 100000; batch++ ) {
		const response = await runBatch( selected.value, isDryRun, BATCH_SIZE, map, importSettings.value );

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

onMounted( detect );
</script>

<template>
	<div class="licensio-kb-migration">
		<a-spin v-if="booting" :tip="__( 'Checking for import sources…', 'licensio-knowledge-base' )">
			<div class="licensio-kb-migration__spin-body"></div>
		</a-spin>

		<a-alert
			v-else-if="detectError"
			type="error"
			show-icon
			:message="detectError"
		/>

		<template v-else>
			<a-steps
				:current="[ 'source', 'categories', 'run' ].indexOf( step )"
				size="small"
				class="licensio-kb-migration__steps"
				:items="[
					{ title: __( 'Source', 'licensio-knowledge-base' ) },
					{ title: __( 'Categories', 'licensio-knowledge-base' ) },
					{ title: __( 'Import', 'licensio-knowledge-base' ) },
				]"
			/>

			<a-alert
				v-if="0 === drivers.length && 'run' !== step"
				type="info"
				show-icon
				:message="__( 'No import sources detected on this installation. Earlier imports are in the log below.', 'licensio-knowledge-base' )"
			/>

			<template v-else-if="'source' === step">
				<p class="licensio-kb-migration__lede">
					{{ __( 'These knowledge base plugins have data available for import:', 'licensio-knowledge-base' ) }}
				</p>

				<a-radio-group v-model:value="selected" class="licensio-kb-migration__sources">
					<a-card
						v-for="item in drivers"
						:key="item.id"
						class="licensio-kb-migration__source"
					>
						<a-radio :value="item.id">
							<strong>{{ item.label }}</strong>
						</a-radio>

						<dl class="licensio-kb-migration__counts">
							<div v-for="count in item.counts" :key="count.key">
								<dt>{{ count.label }}</dt>
								<dd>{{ count.count }}</dd>
							</div>
						</dl>
						<p class="licensio-kb-migration__hint">
							{{ __( 'Totals as stored by the source. Records for deleted articles, and analytics from today, are not imported; the dry run shows the exact numbers.', 'licensio-knowledge-base' ) }}
						</p>

						<a-alert
							v-if="item.status.in_progress"
							type="warning"
							show-icon
							class="licensio-kb-migration__status"
							:message="__( 'An import from this source was interrupted. Resume it to carry on from where it stopped; nothing is imported twice.', 'licensio-knowledge-base' )"
						/>
						<a-alert
							v-else-if="item.status.finished_at"
							type="info"
							show-icon
							class="licensio-kb-migration__status"
							:message="sprintf(
								/* translators: %s: when the last import finished. */
								__( 'Last imported %s. Importing again adds new content and the analytics recorded since; content already imported is left as it is.', 'licensio-knowledge-base' ),
								formatTime( item.status.finished_at )
							)"
						/>
					</a-card>
				</a-radio-group>

				<div class="licensio-kb-migration__actions">
					<a-button
						v-if="driver?.status.in_progress"
						type="primary"
						@click="start( false )"
					>
						{{ __( 'Resume import', 'licensio-knowledge-base' ) }}
					</a-button>
					<a-button
						v-else
						type="primary"
						:disabled="! selected"
						@click="toCategories"
					>
						{{ __( 'Next: categories', 'licensio-knowledge-base' ) }}
					</a-button>
				</div>
			</template>

			<template v-else-if="'categories' === step">
				<p class="licensio-kb-migration__lede">
					{{ __( 'Choose where each category goes. A new category is created by default; merging files its articles under an existing category and redirects its old address there.', 'licensio-knowledge-base' ) }}
				</p>

				<a-alert v-if="runError" type="error" show-icon :message="runError" class="licensio-kb-migration__error" />

				<a-spin :spinning="loadingCategories">
					<a-empty
						v-if="! loadingCategories && ! categoryRows.length"
						:description="__( 'This source has no categories.', 'licensio-knowledge-base' )"
					/>

					<table v-else class="licensio-kb-migration__map">
						<thead>
							<tr>
								<th>{{ __( 'Source category', 'licensio-knowledge-base' ) }}</th>
								<th>{{ __( 'Articles', 'licensio-knowledge-base' ) }}</th>
								<th>{{ __( 'Import as', 'licensio-knowledge-base' ) }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="category in categoryRows" :key="category.id">
								<td :style="{ paddingLeft: 8 + category.depth * 20 + 'px' }">
									{{ category.name }}
									<a-tag v-if="'knowledge_base' === category.type" color="blue">
										{{ __( 'Knowledge base', 'licensio-knowledge-base' ) }}
									</a-tag>
								</td>
								<td>{{ category.count }}</td>
								<td>
									<a-select
										v-model:value="categoryMap[ category.id ]"
										:options="targetOptions"
										show-search
										option-filter-prop="label"
										class="licensio-kb-migration__target"
										:aria-label="sprintf(
											/* translators: %s: the source category's name. */
											__( 'Import “%s” as', 'licensio-knowledge-base' ),
											category.name
										)"
									/>
								</td>
							</tr>
						</tbody>
					</table>
				</a-spin>

				<div class="licensio-kb-migration__options">
					<a-checkbox v-model:checked="importSettings">
						{{ __( 'Also copy the matching settings (layout, columns, sidebars, search placeholder, feedback). URL slugs are never changed, and every change is in the log.', 'licensio-knowledge-base' ) }}
					</a-checkbox>
				</div>

				<div class="licensio-kb-migration__actions">
					<a-button @click="step = 'source'">{{ __( 'Back', 'licensio-knowledge-base' ) }}</a-button>
					<a-button type="primary" :disabled="loadingCategories" @click="start( true )">
						{{ __( 'Start dry run', 'licensio-knowledge-base' ) }}
					</a-button>
					<a-popconfirm
						:title="__( 'Run the import now, without a dry run first?', 'licensio-knowledge-base' )"
						:ok-text="__( 'Run import', 'licensio-knowledge-base' )"
						:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
						@confirm="start( false )"
					>
						<a-button :disabled="loadingCategories">{{ __( 'Skip the dry run and import', 'licensio-knowledge-base' ) }}</a-button>
					</a-popconfirm>
				</div>
			</template>

			<div v-else class="licensio-kb-migration__run">
				<h2 class="licensio-kb-migration__run-title">
					{{
						runIsDry
							? __( 'Verifying import (dry run)', 'licensio-knowledge-base' )
							: __( 'Importing data', 'licensio-knowledge-base' )
					}}
				</h2>

				<a-alert
					v-if="runError"
					type="error"
					show-icon
					:message="runError"
					class="licensio-kb-migration__error"
				/>

				<a-progress
					:percent="percent"
					:status="runError ? 'exception' : summary ? 'success' : 'active'"
				/>

				<p class="licensio-kb-migration__phase">{{ phaseLabel }}</p>
				<p v-if="running" class="licensio-kb-migration__batch">
					{{
						sprintf(
							/* translators: %d: how many items the latest batch processed. */
							_n( 'Processed %d item in the latest batch.', 'Processed %d items in the latest batch.', lastBatch, 'licensio-knowledge-base' ),
							lastBatch
						)
					}}
				</p>

				<template v-if="summary">
					<a-alert
						v-if="runIsDry"
						type="info"
						show-icon
						class="licensio-kb-migration__status"
						:message="__( 'Dry run complete. Nothing was imported; the counts below are what the import would do, and the log shows every item.', 'licensio-knowledge-base' )"
					/>

					<a-descriptions
						:column="2"
						bordered
						size="small"
						class="licensio-kb-migration__counts-table"
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
						class="licensio-kb-migration__notes"
					>
						<h3>{{ humanize( category ) }}</h3>
						<ul>
							<li v-for="line in lines" :key="line">{{ line }}</li>
						</ul>
					</section>

					<div v-if="summary.url_map_url" class="licensio-kb-migration__downloads">
						<a-button :href="summary.url_map_url">
							{{ __( 'Download URL map (CSV)', 'licensio-knowledge-base' ) }}
						</a-button>
					</div>
				</template>

				<div class="licensio-kb-migration__actions">
					<a-button
						v-if="summary && runIsDry"
						type="primary"
						@click="start( false )"
					>
						{{ __( 'Looks good, run the import', 'licensio-knowledge-base' ) }}
					</a-button>
					<a-button
						v-if="runError"
						type="primary"
						@click="start( runIsDry )"
					>
						{{ __( 'Resume', 'licensio-knowledge-base' ) }}
					</a-button>
					<a-button
						v-if="summary && ! runIsDry && chrome.articlesUrl"
						:href="chrome.articlesUrl"
					>
						{{ __( 'View knowledge base', 'licensio-knowledge-base' ) }}
					</a-button>
					<a-button :disabled="running" @click="detect">
						{{ __( 'Back to sources', 'licensio-knowledge-base' ) }}
					</a-button>
				</div>
			</div>

			<section class="licensio-kb-migration__log">
				<h2 class="licensio-kb-migration__run-title">{{ __( 'Migration log', 'licensio-knowledge-base' ) }}</h2>
				<MigrationLog :key="summary?.run_id ?? 'log'" :run-id="summary?.run_id" />
			</section>
		</template>
	</div>
</template>

<style scoped>
.licensio-kb-migration__spin-body {
	min-height: 120px;
}

.licensio-kb-migration__steps {
	margin-bottom: 20px;
}

.licensio-kb-migration__lede {
	margin: 0 0 12px;
}

.licensio-kb-migration__sources {
	display: block;
	width: 100%;
}

.licensio-kb-migration__source {
	margin-bottom: 12px;
}

.licensio-kb-migration__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 16px 32px;
	margin: 12px 0 0 26px;
}

.licensio-kb-migration__counts dt {
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-migration__counts dd {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.licensio-kb-migration__options {
	margin-top: 16px;
}

.licensio-kb-migration__hint {
	margin: 8px 0 0 26px;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-migration__status {
	margin-top: 12px;
}

.licensio-kb-migration__map {
	width: 100%;
	border-collapse: collapse;
}

.licensio-kb-migration__map th,
.licensio-kb-migration__map td {
	padding: 6px 8px;
	border-bottom: 1px solid var( --lkb-border-wp, #dcdcde );
	text-align: left;
}

.licensio-kb-migration__target {
	width: 100%;
	min-width: 220px;
}

.licensio-kb-migration__run-title {
	margin: 0 0 16px;
	font-size: 16px;
}

.licensio-kb-migration__error {
	margin-bottom: 16px;
}

.licensio-kb-migration__phase {
	margin: 12px 0 4px;
	font-weight: 600;
}

.licensio-kb-migration__batch {
	margin: 0;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-migration__counts-table {
	margin-top: 20px;
}

.licensio-kb-migration__notes h3 {
	margin: 20px 0 4px;
	font-size: 13px;
}

.licensio-kb-migration__notes ul {
	margin: 0;
	padding-left: 20px;
}

.licensio-kb-migration__downloads,
.licensio-kb-migration__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 20px;
}

.licensio-kb-migration__log {
	margin-top: 32px;
	padding-top: 20px;
	border-top: 1px solid var( --lkb-border-wp, #dcdcde );
}
</style>
