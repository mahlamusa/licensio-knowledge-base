<script setup lang="ts">
/**
 * The migration log.
 *
 * Every run an import makes — dry or real — leaves one row per category, tag
 * and article, one per analytics batch, and one per record that failed. This
 * lists the runs, filters a run's rows by status (failures first is the usual
 * question), and expands an article to show the categories it was filed under
 * and every source meta key with what became of it.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref, watch } from 'vue';
import { clearLog, fetchLogEntries, fetchLogRuns } from '../api';
import type { LogEntry, LogMeta, LogRun, LogTerm } from '../types';
import { __ } from '../../components/i18n';

const props = defineProps< {
	/** A run to open first, e.g. the one the wizard just finished. */
	runId?: string;
} >();

const PAGE_SIZE = 50;

const STATUS_COLORS: Record< string, string > = {
	created: 'green',
	adopted: 'cyan',
	merged: 'blue',
	updated: 'purple',
	skipped: 'default',
	failed: 'red',
	orphaned: 'orange',
	warning: 'gold',
	info: 'default',
};

const ACTION_COLORS: Record< string, string > = {
	migrated: 'green',
	derived: 'blue',
	skipped: 'default',
	not_migrated: 'orange',
};

const runs = ref< LogRun[] >( [] );
const selectedRun = ref( '' );
const status = ref( '' );
const page = ref( 1 );
const entries = ref< LogEntry[] >( [] );
const total = ref( 0 );
const loading = ref( false );
const error = ref( '' );

const run = computed( () =>
	runs.value.find( ( item ) => item.run_id === selectedRun.value )
);

const statusOptions = computed( () => [
	{ value: '', label: __( 'All', 'licensio-knowledge-base' ) },
	{ value: 'failed', label: __( 'Failed', 'licensio-knowledge-base' ) },
	{ value: 'warning', label: __( 'Warnings', 'licensio-knowledge-base' ) },
	{ value: 'orphaned', label: __( 'Orphaned', 'licensio-knowledge-base' ) },
	{ value: 'created', label: __( 'Created', 'licensio-knowledge-base' ) },
	{ value: 'adopted', label: __( 'Adopted', 'licensio-knowledge-base' ) },
	{ value: 'merged', label: __( 'Merged', 'licensio-knowledge-base' ) },
	{ value: 'updated', label: __( 'Updated', 'licensio-knowledge-base' ) },
	{ value: 'skipped', label: __( 'Skipped', 'licensio-knowledge-base' ) },
	{ value: 'info', label: __( 'Info', 'licensio-knowledge-base' ) },
] );

const columns = computed( () => [
	{ title: __( 'Status', 'licensio-knowledge-base' ), key: 'status', width: 110 },
	{ title: __( 'Type', 'licensio-knowledge-base' ), dataIndex: 'item_type', key: 'item_type', width: 120 },
	{ title: __( 'Title', 'licensio-knowledge-base' ), key: 'title' },
	{ title: __( 'Source ID', 'licensio-knowledge-base' ), dataIndex: 'source_id', key: 'source_id', width: 100 },
	{ title: __( 'New ID', 'licensio-knowledge-base' ), dataIndex: 'target_id', key: 'target_id', width: 90 },
	{ title: __( 'Details', 'licensio-knowledge-base' ), dataIndex: 'message', key: 'message' },
] );

/**
 * A run as one line for the run picker.
 *
 * @since 1.0.0
 *
 * @param {LogRun} item The run.
 *
 * @return {string} The label.
 */
function runLabel( item: LogRun ): string {
	const kind = item.dry_run ? __( 'Dry run', 'licensio-knowledge-base' ) : __( 'Import', 'licensio-knowledge-base' );
	const failed = item.failed > 0 ? ` · ${ item.failed } ${ __( 'failed', 'licensio-knowledge-base' ) }` : '';

	return `${ item.started_at } UTC · ${ item.source } · ${ kind } · ${ item.entries } ${ __( 'entries', 'licensio-knowledge-base' ) }${ failed }`;
}

/**
 * A snake_case word as plain words.
 *
 * @since 1.0.0
 *
 * @param {string} value The value.
 *
 * @return {string} The value with underscores as spaces.
 */
function words( value: string ): string {
	return value.replace( /_/g, ' ' );
}

/**
 * The status a row shows: dry runs say what would have happened.
 *
 * @since 1.0.0
 *
 * @param {LogEntry} entry The entry.
 *
 * @return {string} The status label.
 */
function statusLabel( entry: LogEntry ): string {
	if ( entry.dry_run ) {
		const wouldBe: Record< string, string > = {
			created: __( 'would be created', 'licensio-knowledge-base' ),
			adopted: __( 'would be adopted', 'licensio-knowledge-base' ),
			merged: __( 'would be merged', 'licensio-knowledge-base' ),
			updated: __( 'would be updated', 'licensio-knowledge-base' ),
		};

		if ( wouldBe[ entry.status ] ) {
			return wouldBe[ entry.status ];
		}
	}

	return words( entry.status );
}

/**
 * The article's categories and tags, for the expanded row.
 *
 * @since 1.0.0
 *
 * @param {LogEntry} entry The entry.
 *
 * @return {LogTerm[]} Both lists, categories first.
 */
function termsOf( entry: LogEntry ): Array< LogTerm & { group: string } > {
	return [
		...( entry.details.categories ?? [] ).map( ( term ) => ( { ...term, group: __( 'Category', 'licensio-knowledge-base' ) } ) ),
		...( entry.details.tags ?? [] ).map( ( term ) => ( { ...term, group: __( 'Tag', 'licensio-knowledge-base' ) } ) ),
	];
}

/**
 * The entry's meta rows, for the expanded row.
 *
 * @since 1.0.0
 *
 * @param {LogEntry} entry The entry.
 *
 * @return {LogMeta[]} The meta rows.
 */
function metaOf( entry: LogEntry ): LogMeta[] {
	return entry.details.meta ?? [];
}

/**
 * Everything else in the details, as key / value lines.
 *
 * @since 1.0.0
 *
 * @param {LogEntry} entry The entry.
 *
 * @return {Array<[string, string]>} The remaining details.
 */
function otherDetails( entry: LogEntry ): Array< [ string, string ] > {
	return Object.entries( entry.details )
		.filter( ( [ key ] ) => ! [ 'categories', 'tags', 'meta' ].includes( key ) )
		.map( ( [ key, value ] ) => [
			words( key ),
			typeof value === 'object' ? JSON.stringify( value ) : String( value ),
		] );
}

/**
 * Whether an entry has anything to expand.
 *
 * @since 1.0.0
 *
 * @param {LogEntry} entry The entry.
 *
 * @return {boolean} True when the details are not empty.
 */
function expandable( entry: LogEntry ): boolean {
	return Object.keys( entry.details ).length > 0;
}

/**
 * Load the runs, and pick one.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function loadRuns(): Promise< void > {
	error.value = '';

	try {
		runs.value = await fetchLogRuns();

		const wanted = props.runId && runs.value.some( ( item ) => item.run_id === props.runId );

		selectedRun.value = wanted ? ( props.runId as string ) : runs.value[ 0 ]?.run_id ?? '';
	} catch ( caught ) {
		error.value = caught instanceof Error ? caught.message : __( 'The log could not be loaded.', 'licensio-knowledge-base' );
	}
}

/**
 * Load the current page of the selected run.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function loadEntries(): Promise< void > {
	if ( ! selectedRun.value ) {
		entries.value = [];
		total.value = 0;
		return;
	}

	loading.value = true;
	error.value = '';

	try {
		const result = await fetchLogEntries( selectedRun.value, status.value, page.value );

		entries.value = result.items;
		total.value = result.total;
	} catch ( caught ) {
		error.value = caught instanceof Error ? caught.message : __( 'The log could not be loaded.', 'licensio-knowledge-base' );
	} finally {
		loading.value = false;
	}
}

/**
 * Delete the selected run's entries, or the whole log.
 *
 * @since 1.0.0
 *
 * @param {boolean} everything Whether to clear every run.
 *
 * @return {Promise<void>}
 */
async function clear( everything: boolean ): Promise< void > {
	try {
		await clearLog( everything ? '' : selectedRun.value );
		await loadRuns();
	} catch ( caught ) {
		error.value = caught instanceof Error ? caught.message : __( 'The log could not be cleared.', 'licensio-knowledge-base' );
	}
}

/**
 * Follow the table's pager.
 *
 * @since 1.0.0
 *
 * @param {{current?: number}} pagination The table's pagination state.
 *
 * @return {void}
 */
function onTableChange( pagination: { current?: number } ): void {
	page.value = pagination.current ?? 1;
}

watch( [ selectedRun, status ], () => {
	page.value = 1;
	loadEntries();
} );

watch( page, loadEntries );

watch(
	() => props.runId,
	() => loadRuns()
);

onMounted( loadRuns );

defineExpose( { reload: loadRuns } );
</script>

<template>
	<div class="licensio-kb-migration-log">
		<a-alert v-if="error" type="error" show-icon :message="error" class="licensio-kb-migration-log__error" />

		<a-empty
			v-if="! runs.length && ! error"
			:description="__( 'No imports have been logged yet.', 'licensio-knowledge-base' )"
		/>

		<template v-else-if="runs.length">
			<div class="licensio-kb-migration-log__toolbar">
				<a-select
					v-model:value="selectedRun"
					class="licensio-kb-migration-log__run"
					:aria-label="__( 'Run', 'licensio-knowledge-base' )"
				>
					<a-select-option v-for="item in runs" :key="item.run_id" :value="item.run_id">
						{{ runLabel( item ) }}
					</a-select-option>
				</a-select>

				<a-select
					v-model:value="status"
					class="licensio-kb-migration-log__status"
					:options="statusOptions"
					:aria-label="__( 'Status', 'licensio-knowledge-base' )"
				/>

				<a-button v-if="run" :href="run.download_url">
					{{ __( 'Download CSV', 'licensio-knowledge-base' ) }}
				</a-button>

				<a-popconfirm
					:title="__( 'Delete this run\'s log entries? The imported content is not affected.', 'licensio-knowledge-base' )"
					:ok-text="__( 'Delete', 'licensio-knowledge-base' )"
					:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
					@confirm="clear( false )"
				>
					<a-button>{{ __( 'Delete this run\'s log', 'licensio-knowledge-base' ) }}</a-button>
				</a-popconfirm>

				<a-popconfirm
					:title="__( 'Clear the whole migration log? The imported content is not affected.', 'licensio-knowledge-base' )"
					:ok-text="__( 'Clear log', 'licensio-knowledge-base' )"
					:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
					@confirm="clear( true )"
				>
					<a-button danger>{{ __( 'Clear all logs', 'licensio-knowledge-base' ) }}</a-button>
				</a-popconfirm>
			</div>

			<a-table
				:columns="columns"
				:data-source="entries"
				:row-key="( entry: LogEntry ) => entry.id"
				:loading="loading"
				:pagination="{ current: page, pageSize: PAGE_SIZE, total, showSizeChanger: false }"
				:row-expandable="expandable"
				size="small"
				class="licensio-kb-migration-log__table"
				@change="onTableChange"
			>
				<template #emptyText>
					{{ __( 'No entries match this filter.', 'licensio-knowledge-base' ) }}
				</template>

				<template #bodyCell="{ column, record }">
					<template v-if="column.key === 'status'">
						<a-tag :color="STATUS_COLORS[ record.status ] ?? 'default'">
							{{ statusLabel( record ) }}
						</a-tag>
					</template>
					<template v-else-if="column.key === 'item_type'">
						{{ words( record.item_type ) }}
					</template>
					<template v-else-if="column.key === 'title'">
						{{ record.title || '—' }}
					</template>
				</template>

				<template #expandedRowRender="{ record }">
					<div class="licensio-kb-migration-log__details">
						<section v-if="termsOf( record ).length">
							<h4>{{ __( 'Categories and tags', 'licensio-knowledge-base' ) }}</h4>
							<ul>
								<li v-for="term in termsOf( record )" :key="term.group + term.source_id">
									{{ term.group }}: <strong>{{ term.name || term.source_id }}</strong>
									({{ term.source_id }} → {{ term.target_id || '—' }})
									<a-tag :color="term.status === 'missing' ? 'red' : 'default'">{{ words( term.status ) }}</a-tag>
								</li>
							</ul>
						</section>

						<section v-if="metaOf( record ).length">
							<h4>{{ __( 'Meta', 'licensio-knowledge-base' ) }}</h4>
							<table class="licensio-kb-migration-log__meta">
								<thead>
									<tr>
										<th>{{ __( 'Key', 'licensio-knowledge-base' ) }}</th>
										<th>{{ __( 'Value', 'licensio-knowledge-base' ) }}</th>
										<th>{{ __( 'Outcome', 'licensio-knowledge-base' ) }}</th>
										<th>{{ __( 'Where', 'licensio-knowledge-base' ) }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="meta in metaOf( record )" :key="meta.key">
										<td><code>{{ meta.key }}</code></td>
										<td class="licensio-kb-migration-log__value">{{ meta.value }}</td>
										<td>
											<a-tag :color="ACTION_COLORS[ meta.action ] ?? 'default'">{{ words( meta.action ) }}</a-tag>
										</td>
										<td>{{ meta.target }}</td>
									</tr>
								</tbody>
							</table>
						</section>

						<dl v-if="otherDetails( record ).length" class="licensio-kb-migration-log__other">
							<template v-for="[ key, value ] in otherDetails( record )" :key="key">
								<dt>{{ key }}</dt>
								<dd>{{ value }}</dd>
							</template>
						</dl>
					</div>
				</template>
			</a-table>
		</template>
	</div>
</template>

<style scoped>
.licensio-kb-migration-log__error {
	margin-bottom: 12px;
}

.licensio-kb-migration-log__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 12px;
}

.licensio-kb-migration-log__run {
	flex: 1 1 360px;
	min-width: 0;
}

.licensio-kb-migration-log__status {
	width: 140px;
}

.licensio-kb-migration-log__details h4 {
	margin: 8px 0 4px;
	font-size: 13px;
}

.licensio-kb-migration-log__details ul {
	margin: 0;
	padding-left: 18px;
}

.licensio-kb-migration-log__meta {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}

.licensio-kb-migration-log__meta th,
.licensio-kb-migration-log__meta td {
	padding: 4px 8px;
	border-bottom: 1px solid var( --lkb-border-wp, #dcdcde );
	text-align: left;
	vertical-align: top;
}

.licensio-kb-migration-log__value {
	max-width: 320px;
	overflow-wrap: anywhere;
	color: var( --lkb-ink-wp-muted, #646970 );
}

.licensio-kb-migration-log__other {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 12px;
	margin: 8px 0 0;
	font-size: 12px;
}

.licensio-kb-migration-log__other dt {
	font-weight: 600;
	text-transform: capitalize;
}

.licensio-kb-migration-log__other dd {
	margin: 0;
	overflow-wrap: anywhere;
}
</style>
