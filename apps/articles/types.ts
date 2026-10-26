/**
 * Articles app types.
 *
 * The shapes the `licensio-kb/v1` article routes answer with
 * (`Licensio_KB_Articles_REST_Controller`) and the configuration the Articles
 * admin page localises (`Licensio_KB_Articles_Admin::app_data()`).
 *
 * @since 1.0.0
 */

import type { TermTaxonomy, TermValue } from '../components/types/terms';

/** A post status an article can have. */
export type ArticleStatus =
	| 'publish'
	| 'future'
	| 'draft'
	| 'pending'
	| 'private'
	| 'trash';

/** The status filter: one status, or every status except trash. */
export type StatusFilter = 'all' | ArticleStatus;

/** The sortable columns. */
export type ArticleOrderBy = 'date' | 'modified' | 'title' | 'views' | 'rating';

/** The metrics window, in days. */
export type MetricsWindow = 7 | 30 | 90;

/** The optional table columns. Title is always shown. */
export type ArticleColumn =
	| 'author'
	| 'categories'
	| 'tags'
	| 'attachments'
	| 'rating'
	| 'views'
	| 'reading_time'
	| 'date'
	| 'modified';

/** Rows per page. */
export type PerPage = 20 | 50 | 100;

/**
 * A term attached to an article. Names are plain text.
 */
export interface TermRef {
	id: number;
	name: string;
}

/**
 * The editor holding an article's post lock.
 */
export interface ArticleLock {
	user_id: number;
	name: string;
}

/**
 * One article row from `GET articles`.
 */
export interface ArticleItem {
	id: number;
	/** Plain text, entities decoded, for display. */
	title: string;
	/** As stored, for Quick edit, so an unchanged title saves the same bytes. */
	title_raw: string;
	slug: string;
	status: ArticleStatus;
	/** Site-local `YYYY-MM-DDTHH:mm:ss`. */
	date: string;
	/** Site-local `YYYY-MM-DDTHH:mm:ss`. */
	modified: string;
	author: { id: number; name: string };
	categories: TermRef[];
	tags: TermRef[];
	/** Child attachments. */
	attachments: number;
	/** Minutes. */
	reading_time: number;
	/** Views in the selected window. */
	views: number;
	/** Votes in the selected window; share is a 0-100 percentage, null with no votes. */
	rating: { up: number; down: number; share: number | null };
	/** Below the low rating threshold with enough votes, by the server's exact rule. */
	needs_attention: boolean;
	comment_status: 'open' | 'closed';
	has_password: boolean;
	/** Another user is editing; null when free. */
	lock: ArticleLock | null;
	/** Whether the current user may trash, restore or delete it. */
	can_delete: boolean;
	/** Block editor URL; empty when the user cannot edit. */
	edit_url: string;
	/** Permalink, or the preview link for an unpublished article. */
	view_url: string;
}

/** Status counts, as `wp_count_posts()` reports them; `all` excludes trash. */
export type StatusCounts = Record< StatusFilter, number >;

/**
 * The filters shared by `GET articles` and `GET articles/ids`.
 */
export interface ArticleFilters {
	status: StatusFilter;
	search: string;
	/** Category term ID; includes its children. 0 for any. */
	category: number;
	/** Tag term ID. 0 for any. */
	tag: number;
	/** Author user ID. 0 for any. */
	author: number;
	/** `YYYY-MM-DD`, inclusive. Empty for no bound. */
	after: string;
	/** `YYYY-MM-DD`, inclusive. Empty for no bound. */
	before: string;
	uncategorised: boolean;
	untagged: boolean;
	needs_attention: boolean;
	/** Applies to the views, rating, needs_attention and their sort. */
	window: MetricsWindow;
}

/**
 * The full list query: filters plus sort and page.
 */
export interface ArticleQuery extends ArticleFilters {
	orderby: ArticleOrderBy;
	order: 'asc' | 'desc';
	page: number;
	per_page: PerPage;
}

/**
 * `GET articles`.
 */
export interface ArticlePage {
	items: ArticleItem[];
	total: number;
	pages: number;
	counts: StatusCounts;
}

/**
 * `GET articles/ids`: every matching ID, capped at 10,000.
 */
export interface ArticleIds {
	ids: number[];
	total: number;
	truncated: boolean;
}

/**
 * `PATCH articles/{id}` body. Only the keys sent are changed.
 *
 * Visibility is expressed through `status` (`private`) and `password`.
 */
export interface QuickEditPayload {
	title?: string;
	slug?: string;
	status?: Exclude< ArticleStatus, 'trash' >;
	/** Site-local `YYYY-MM-DDTHH:mm:ss`. */
	date?: string;
	/** An empty string removes the password. */
	password?: string;
	categories?: number[];
	/** IDs, or `{ name }` to create a tag. */
	tags?: TermValue[];
	author?: number;
	comment_status?: 'open' | 'closed';
}

/** The bulk actions. */
export type BulkAction =
	| 'trash'
	| 'restore'
	| 'delete'
	| 'status'
	| 'terms'
	| 'author'
	| 'comments';

/** How a terms bulk action combines with the existing terms. */
export type TermsMode = 'add' | 'remove' | 'replace';

/**
 * The `args` of each bulk action.
 */
export interface BulkArgs {
	/** For `status`. */
	status?: 'publish' | 'draft' | 'pending' | 'private';
	/** For `terms`. */
	taxonomy?: TermTaxonomy;
	mode?: TermsMode;
	term_ids?: number[];
	/** For `author`. */
	author?: number;
	/** For `comments`. */
	comment_status?: 'open' | 'closed';
}

/**
 * `POST articles/bulk` body. At most 100 IDs.
 */
export interface BulkRequest {
	ids: number[];
	action: BulkAction;
	args: BulkArgs;
}

/** Why an ID was skipped. */
export type SkipReason =
	| 'not_found'
	| 'forbidden'
	| 'locked'
	| 'not_in_trash'
	| 'in_trash';

/**
 * `POST articles/bulk` answer.
 *
 * `token` undoes the batch once through `POST articles/revert`, within
 * 15 minutes. It is empty for actions that cannot be undone (delete, author,
 * comments) or when nothing changed. The earlier values stay on the server.
 */
export interface BulkResult extends RevertRequest, RevertResult {}

/**
 * `POST articles/revert` body.
 */
export interface RevertRequest {
	token: string;
}

/**
 * `POST articles/revert` answer.
 */
export interface RevertResult {
	done: number[];
	skipped: Array< { id: number; reason: SkipReason } >;
	failed: Array< { id: number; message: string } >;
}

/**
 * One user who can edit articles.
 */
export interface AuthorItem {
	id: number;
	name: string;
}

/**
 * `GET authors`.
 */
export interface AuthorPage {
	items: AuthorItem[];
	total: number;
}

/**
 * `GET` / `PUT preferences`.
 */
export interface ListPreferences {
	columns: ArticleColumn[];
	per_page: PerPage;
}

/**
 * What the current user may do, as the page localises it.
 */
export interface ArticlesCan {
	/** Manage the knowledge base: Settings, Feedback and the other screens. */
	manage: boolean;
	/** Edit other people's articles, and so choose an author. */
	editOthers: boolean;
	/** Publish, schedule and make articles private. */
	publish: boolean;
	/** Create tags by name. */
	createTags: boolean;
}

/**
 * The configuration the page localises as `window.licensioKbArticles`.
 */
export interface ArticlesConfig {
	/** The `licensio-kb/v1` namespace URL. */
	root: string;
	nonce: string;
	settingsUrl: string;
	/** `post-new.php?post_type=licensio_article`. */
	newUrl: string;
	/** The Feedback inbox URL; append `&article_id={id}`. Empty without `can.manage`. */
	feedbackUrl: string;
	/** What the current user may do. */
	can: ArticlesCan;
	/** The current user's saved list preferences. */
	preferences: ListPreferences;
	/** The Needs attention rule, for its help text. */
	lowRating: { threshold: number; minVotes: number };
}
