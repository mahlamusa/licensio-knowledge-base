/**
 * API client for the Tools app.
 *
 * Every tool talks to admin-ajax endpoints rather than REST routes — they
 * drive the plugin's own components (the importer, the search index, the
 * retention job) rather than resources — so every call posts the nonce its
 * tab was rendered with and reads the `success` envelope `wp_send_json_*`
 * produces.
 *
 * @since 1.0.0
 */

import type {
	DuplicateFixReport,
	DuplicateGroup,
	DuplicateTermGroup,
	ImportProgress,
	ImportSummary,
	IndexStatus,
	IndexChunkResponse,
	LogEntry,
	LogRun,
	MaintenanceStatus,
	PruneResponse,
	SourceCategory,
	SourceDriver,
	TargetCategory,
	ToolsConfig,
} from './types';

declare global {
	interface Window {
		lisensioKbTools?: ToolsConfig;
		wp?: {
			i18n?: {
				__: ( text: string, domain?: string ) => string;
			};
		};
	}
}

/**
 * The bootstrap data PHP localised onto the page.
 *
 * @since 1.0.0
 *
 * @return {ToolsConfig} The configuration, with safe defaults when the page did not supply one.
 */
export function config(): ToolsConfig {
	return window.lisensioKbTools ?? {};
}

/**
 * Translate a string through WordPress, falling back to the original.
 *
 * The app is built by Vite rather than by wp-scripts, so it reads `wp.i18n`
 * off the global that the `wp-i18n` script dependency guarantees is present
 * instead of importing the package. It is deliberately not called `__`: the
 * string extractor and the `@wordpress/i18n-no-variables` lint rule both key on
 * that name, and a wrapper wearing it would be read as a call site whose
 * argument is a variable.
 *
 * @since 1.0.0
 *
 * @param {string} text The untranslated string.
 *
 * @return {string} The translated string.
 */
export function translate( text: string ): string {
	// Read off as a reference rather than called as `i18n.__()`: the extractor
	// and the lint rule both treat that spelling as a translation call site.
	const translator = window.wp?.i18n?.__;

	return translator ? translator( text, 'lisensio-knowledge-base' ) : text;
}

/**
 * The body every admin-ajax action answers with.
 *
 * @since 1.0.0
 */
interface AjaxEnvelope {
	success: boolean;
	data?: unknown;
}

/**
 * One tab's endpoint configuration.
 *
 * @since 1.0.0
 */
interface ToolEndpoint {
	ajaxUrl: string;
	nonce: string;
}

/**
 * Resolve one tool's endpoint, failing loudly when its tab data is missing.
 *
 * A silently empty endpoint would post to the current screen and come back as
 * HTML, which the JSON parse would turn into an unrelated error.
 *
 * @since 1.0.0
 *
 * @param {ToolEndpoint|undefined} endpoint The tab's localised endpoint.
 *
 * @return {ToolEndpoint} The endpoint.
 */
function requireEndpoint( endpoint: ToolEndpoint | undefined ): ToolEndpoint {
	if ( ! endpoint || ! endpoint.ajaxUrl ) {
		throw new Error( translate( 'This tool is not available.' ) );
	}

	return endpoint;
}

/**
 * Call one tool admin-ajax action.
 *
 * @since 1.0.0
 *
 * @param {ToolEndpoint}          endpoint The tab's endpoint configuration.
 * @param {string}                action   The AJAX action name.
 * @param {Record<string,string>} body     The action's parameters.
 *
 * @return {Promise<unknown>} The `data` member of the success envelope.
 */
async function postAction(
	endpoint: ToolEndpoint,
	action: string,
	body: Record< string, string > = {}
): Promise< unknown > {
	const response = await window.fetch( endpoint.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { action, nonce: endpoint.nonce, ...body } ),
	} );

	const envelope = ( await response.json() ) as AjaxEnvelope;

	if ( ! envelope.success ) {
		throw new Error(
			typeof envelope.data === 'string'
				? envelope.data
				: translate( 'The request failed.' )
		);
	}

	return envelope.data;
}

/**
 * Detect the import sources installed on the site.
 *
 * @since 1.0.0
 *
 * @return {Promise<SourceDriver[]>} The detected drivers.
 */
export async function detectSources(): Promise< SourceDriver[] > {
	const data = ( await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_detect'
	) ) as {
		drivers?: SourceDriver[];
	};

	return data.drivers ?? [];
}

/**
 * A source's categories, and the existing categories they can be merged into.
 *
 * @since 1.0.0
 *
 * @param {string} source The driver ID.
 *
 * @return {Promise<{source: SourceCategory[]; targets: TargetCategory[]}>} Both lists.
 */
export async function fetchCategories(
	source: string
): Promise< { source: SourceCategory[]; targets: TargetCategory[] } > {
	const data = ( await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_categories',
		{ source }
	) ) as { source?: SourceCategory[]; targets?: TargetCategory[] };

	return { source: data.source ?? [], targets: data.targets ?? [] };
}

/**
 * Run one import batch.
 *
 * @since 1.0.0
 *
 * @param {string}                source         The driver ID.
 * @param {boolean}               dryRun         Whether this batch may write.
 * @param {number}                batchSize      How many rows the batch may read.
 * @param {Record<number,number>} categoryMap    Source category ID to existing category ID; read on a run's first batch only.
 * @param {boolean}               importSettings Whether to copy the source's matching settings; read on a run's first batch only.
 *
 * @return {Promise<{progress: ImportProgress; summary?: ImportSummary}>} Batch progress, and the summary once finished.
 */
export async function runBatch(
	source: string,
	dryRun: boolean,
	batchSize: number,
	categoryMap: Record< number, number > = {},
	importSettings = false
): Promise< { progress: ImportProgress; summary?: ImportSummary } > {
	return postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_run',
		{
			source,
			dry_run: dryRun ? 'true' : 'false',
			batch_size: String( batchSize ),
			category_map: JSON.stringify( categoryMap ),
			import_settings: importSettings ? 'true' : 'false',
		}
	) as Promise< {
		progress: ImportProgress;
		summary?: ImportSummary;
	} >;
}

/**
 * Forget a run's state, so the next batch starts a fresh run.
 *
 * @since 1.0.0
 *
 * @param {string}  source The driver ID.
 * @param {boolean} dryRun Whether to forget the dry run's state rather than the import's.
 *
 * @return {Promise<void>}
 */
export async function resetMigration(
	source: string,
	dryRun: boolean
): Promise< void > {
	await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_reset',
		{
			source,
			dry_run: dryRun ? 'true' : 'false',
		}
	);
}

/**
 * Every run in the import log, newest first.
 *
 * @since 1.0.0
 *
 * @return {Promise<LogRun[]>} The runs.
 */
export async function fetchLogRuns(): Promise< LogRun[] > {
	const data = ( await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_log_runs'
	) ) as { runs?: LogRun[] };

	return data.runs ?? [];
}

/**
 * One page of a run's log entries.
 *
 * @since 1.0.0
 *
 * @param {string} runId  The run.
 * @param {string} status Only this status, or '' for all.
 * @param {number} page   1-based page.
 *
 * @return {Promise<{items: LogEntry[]; total: number}>} The entries and how many match.
 */
export async function fetchLogEntries(
	runId: string,
	status: string,
	page: number
): Promise< { items: LogEntry[]; total: number } > {
	const data = ( await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_log_entries',
		{ run_id: runId, status, page: String( page ) }
	) ) as { items?: LogEntry[]; total?: number };

	return { items: data.items ?? [], total: data.total ?? 0 };
}

/**
 * Clear the import log, or one run of it.
 *
 * @since 1.0.0
 *
 * @param {string} runId The run to clear, or '' for the whole log.
 *
 * @return {Promise<void>}
 */
export async function clearLog( runId: string ): Promise< void > {
	await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_log_clear',
		{ run_id: runId }
	);
}

/**
 * The search index status.
 *
 * @since 1.0.0
 *
 * @return {Promise<IndexStatus>} The status.
 */
export async function indexStatus(): Promise< IndexStatus > {
	return postAction(
		requireEndpoint( config().searchIndex ),
		'lisensio_tools_index_status'
	) as Promise< IndexStatus >;
}

/**
 * Empty the search index and begin a rebuild.
 *
 * @since 1.0.0
 *
 * @return {Promise<IndexStatus>} The status after the rebuild began.
 */
export async function indexStart(): Promise< IndexStatus > {
	return postAction(
		requireEndpoint( config().searchIndex ),
		'lisensio_tools_index_start'
	) as Promise< IndexStatus >;
}

/**
 * Run one chunk of the search index rebuild.
 *
 * @since 1.0.0
 *
 * @return {Promise<IndexChunkResponse>} The chunk result and the refreshed status.
 */
export async function indexChunk(): Promise< IndexChunkResponse > {
	return postAction(
		requireEndpoint( config().searchIndex ),
		'lisensio_tools_index_chunk'
	) as Promise< IndexChunkResponse >;
}

/**
 * The data maintenance status.
 *
 * @since 1.0.0
 *
 * @return {Promise<MaintenanceStatus>} The status.
 */
export async function maintenanceStatus(): Promise< MaintenanceStatus > {
	return postAction(
		requireEndpoint( config().maintenance ),
		'lisensio_tools_maintenance_status'
	) as Promise< MaintenanceStatus >;
}

/**
 * Recompute every article's usefulness score.
 *
 * @since 1.0.0
 *
 * @return {Promise<{articles: number; status: MaintenanceStatus}>} How many articles were recounted, and the refreshed status.
 */
export async function recountScores(): Promise< {
	articles: number;
	status: MaintenanceStatus;
} > {
	return postAction(
		requireEndpoint( config().maintenance ),
		'lisensio_tools_recount_scores'
	) as Promise< { articles: number; status: MaintenanceStatus } >;
}

/**
 * Scan for duplicate articles and terms. Writes nothing.
 *
 * @since 1.0.0
 *
 * @return {Promise<{posts: DuplicateGroup[]; terms: DuplicateTermGroup[]}>} The duplicate groups, fixable and flagged.
 */
export async function scanDuplicates(): Promise< {
	posts: DuplicateGroup[];
	terms: DuplicateTermGroup[];
} > {
	const data = ( await postAction(
		requireEndpoint( config().duplicates ),
		'lisensio_tools_duplicates_scan'
	) ) as {
		posts?: DuplicateGroup[];
		terms?: DuplicateTermGroup[];
	};

	return {
		posts: data.posts ?? [],
		terms: data.terms ?? [],
	};
}

/**
 * Fix every fixable duplicate group.
 *
 * @since 1.0.0
 *
 * @return {Promise<DuplicateFixReport>} What the fix did.
 */
export async function fixDuplicates(): Promise< DuplicateFixReport > {
	return postAction(
		requireEndpoint( config().duplicates ),
		'lisensio_tools_duplicates_fix'
	) as Promise< DuplicateFixReport >;
}

/**
 * Run one retention sweep over the analytics tables.
 *
 * @since 1.0.0
 *
 * @return {Promise<PruneResponse>} Rows deleted per table, whether the backlog is gone, and the refreshed status.
 */
export async function pruneAnalytics(): Promise< PruneResponse > {
	return postAction(
		requireEndpoint( config().maintenance ),
		'lisensio_tools_prune_analytics'
	) as Promise< PruneResponse >;
}
