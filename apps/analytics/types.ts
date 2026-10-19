/**
 * Shapes returned by the `licensio-kb/v1/analytics/*` endpoints.
 *
 * @since 1.0.0
 */

export interface Range {
	start: string;
	end: string;
}

export interface OverviewTotals {
	total_searches: number;
	unique_terms: number;
	search_visitors: number;
	zero_result_searches: number;
	effectiveness: number;
	total_views: number;
	viewed_articles: number;
	view_visitors: number;
	total_feedback: number;
	positive_feedback: number;
	negative_feedback: number;
}

export interface TrendPoint {
	date: string;
	total: number;
}

export interface OverviewResponse {
	range: Range;
	totals: OverviewTotals;
	search_trend: TrendPoint[];
	view_trend: TrendPoint[];
	collecting: boolean;
	retention_days: number;
}

export interface SearchRow {
	term: string;
	normalized_term: string;
	searches: number;
	zero_results?: number;
	average_results?: number;
	create_article_url?: string;
}

export interface SearchesResponse {
	range: Range;
	top: SearchRow[];
	zero_results: SearchRow[];
	effectiveness: number;
	total: number;
}

export interface ArticleRow {
	article_id: number;
	title: string;
	permalink: string;
	edit_link: string;
	views?: number;
	visitors?: number;
	votes?: number;
	positive?: number;
	negative?: number;
	usefulness?: number;
}

export interface ArticlesResponse {
	range: Range;
	most_viewed: ArticleRow[];
	most_useful: ArticleRow[];
	least_useful: ArticleRow[];
}

export interface FeedbackResponse {
	range: Range;
	totals: {
		total: number;
		positive: number;
		negative: number;
		articles: number;
		usefulness: number;
	};
	by_status: Record< string, number >;
	trend: TrendPoint[];
}

export interface AnalyticsConfig {
	restUrl: string;
	nonce: string;
	defaultDays: number;
	maxDays: number;
	defaultLimit: number;
	retentionDays: number;
	settingsUrl?: string;
}
