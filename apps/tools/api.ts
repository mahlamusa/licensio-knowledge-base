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
	ImportProgress,
	ImportSummary,
	IndexStatus,
	IndexChunkResponse,
	MaintenanceStatus,
	PruneResponse,
	SourceDriver,
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
 * Run one migration batch.
 *
 * @since 1.0.0
 *
 * @param {string}  source    The driver ID.
 * @param {boolean} dryRun    Whether this batch may write.
 * @param {number}  batchSize How many rows the batch may read.
 *
 * @return {Promise<{progress: ImportProgress; summary?: ImportSummary}>} Batch progress, and the summary once finished.
 */
export async function runBatch(
	source: string,
	dryRun: boolean,
	batchSize: number
): Promise< { progress: ImportProgress; summary?: ImportSummary } > {
	return postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_run',
		{
			source,
			dry_run: dryRun ? 'true' : 'false',
			batch_size: String( batchSize ),
		}
	) as Promise< {
		progress: ImportProgress;
		summary?: ImportSummary;
	} >;
}

/**
 * Reset the migration state.
 *
 * @since 1.0.0
 *
 * @param {string} source The driver ID.
 *
 * @return {Promise<void>}
 */
export async function resetMigration( source: string ): Promise< void > {
	await postAction(
		requireEndpoint( config().wizard ),
		'lisensio_import_reset',
		{
			source,
		}
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
