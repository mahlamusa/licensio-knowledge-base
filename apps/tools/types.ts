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
	duplicates?: ToolEndpointConfig;
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
 * One member of a duplicate group.
 *
 * @since 1.0.0
 */
export interface DuplicateMember {
	id: number;
	type: string;
	slug: string;
	status: string;
	metaCount: number;
}

/**
 * A duplicate group the scanner found.
 *
 * @since 1.0.0
 */
export interface DuplicateGroup {
	title: string;
	baseSlug: string;
	date: string;
	members: DuplicateMember[];
	canonical: number;
	winner: number;
	/** 'merge' — duplicate articles; 'source' — a migration source post beside its replacement; 'flagged' — left to a human. */
	kind: 'merge' | 'source' | 'flagged';
	fixable: boolean;
}

/**
 * One member of a duplicate term group.
 *
 * @since 1.0.0
 */
export interface DuplicateTermMember {
	id: number;
	name: string;
	slug: string;
	count: number;
	metaCount: number;
	termTaxonomyId: number;
}

/**
 * A duplicate category or tag group.
 *
 * @since 1.0.0
 */
export interface DuplicateTermGroup {
	name: string;
	baseSlug: string;
	taxonomy: string;
	members: DuplicateTermMember[];
	canonical: number;
	winner: number;
	fixable: boolean;
}

/**
 * The fix report.
 *
 * @since 1.0.0
 */
export interface DuplicateFixReport {
	groups_merged: number;
	posts_trashed: number;
	meta_copied: number;
	terms_assigned: number;
	votes_moved: number;
	views_moved: number;
	term_groups_merged: number;
	terms_deleted: number;
	term_meta_copied: number;
	articles_reassigned: number;
	source_groups_cleaned: number;
	sources_trashed: number;
	notes: string[];
	flagged: { posts: DuplicateGroup[]; terms: DuplicateTermGroup[] };
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
