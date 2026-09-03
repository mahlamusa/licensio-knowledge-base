/**
 * Shared types for the Tools app.
 *
 * @since 1.0.0
 */

/**
 * The bootstrap data PHP localised onto the page.
 *
 * Each tool contributes its key through the `lisensio_kb_tools_app_data`
 * filter on the server; a tool whose key is absent is treated as unavailable
 * by the client.
 *
 * @since 1.0.0
 */
export interface ToolsConfig {
	settingsUrl?: string;
	articlesUrl?: string;
	wizard?: ToolEndpointConfig;
	searchIndex?: ToolEndpointConfig;
	maintenance?: ToolEndpointConfig;
}

/**
 * One tab's endpoint configuration.
 *
 * @since 1.0.0
 */
export interface ToolEndpointConfig {
	ajaxUrl: string;
	nonce: string;
}

/**
 * One import source the wizard detected, with its preview counts.
 *
 * @since 1.0.0
 */
export interface SourceDriver {
	id: string;
	counts: {
		articles: number;
		categories: number;
		tags: number;
	};
}

/**
 * Progress of one migration batch.
 *
 * @since 1.0.0
 */
export interface ImportProgress {
	phase: string;
	done: number;
	total: number;
	finished: boolean;
}

/**
 * The summary the driver attaches to a finished run.
 *
 * Counts and notes come from the run report; the download URLs are only
 * present once the artefact files exist, so a dry run has neither.
 *
 * @since 1.0.0
 */
export interface ImportSummary {
	counts?: Record< string, number >;
	notes?: Record< string, string[] >;
	redirects_url?: string;
	url_map_url?: string;
}

/**
 * The search index status.
 *
 * @since 1.0.0
 */
export interface IndexStatus {
	indexed: number;
	articles: number;
	rebuilding: boolean;
}

/**
 * One chunk of a search index rebuild.
 *
 * @since 1.0.0
 */
export interface IndexChunkResponse {
	chunk: number;
	finished: boolean;
	status: IndexStatus;
}

/**
 * The data maintenance status.
 *
 * @since 1.0.0
 */
export interface MaintenanceStatus {
	articles: number;
	feedbackRows: number;
	viewRows: number;
	searchRows: number;
	retentionDays: number;
	cutoff: string;
}

/**
 * One retention sweep's result.
 *
 * @since 1.0.0
 */
export interface PruneResponse {
	deleted: Record< string, number >;
	complete: boolean;
	status: MaintenanceStatus;
}
