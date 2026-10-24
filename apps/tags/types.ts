/**
 * Tags app types.
 *
 * The shapes `Licensio_KB_Tags_REST_Controller` answers with, so a change on
 * the server is a compile error here rather than an undefined at runtime.
 *
 * @since 1.0.0
 */

/**
 * One tag. The name is plain text; the description is as stored, so an
 * unchanged one is never sent back.
 */
export interface TagItem {
	id: number;
	name: string;
	slug: string;
	description: string;
	count: number;
}

/**
 * One page of `GET tags`.
 */
export interface TagPage {
	items: TagItem[];
	total: number;
	pages: number;
	page: number;
	per_page: number;
}

export type TagOrderBy = 'name' | 'count';

export type TagOrder = 'asc' | 'desc';

/**
 * The editable fields of a tag. Only the fields sent are changed.
 */
export interface TagFields {
	name?: string;
	slug?: string;
	description?: string;
}

/**
 * A tag a bulk route could not change, and why.
 */
export interface TagFailure {
	id: number;
	message: string;
}

/**
 * The answer of `POST tags/bulk-delete`.
 */
export interface BulkDeleteResult {
	deleted: number[];
	failed: TagFailure[];
}

/**
 * The answer of `POST tags/merge`.
 */
export interface MergeResult {
	moved: number;
	deleted: number[];
	target: TagItem | null;
}

/**
 * One converted tag in the answer of `POST tags/convert`.
 */
export interface ConvertedTag {
	tag: number;
	category: { id: number; name: string; slug: string; parent: number };
	reused: boolean;
	articles: number;
}

/**
 * The answer of `POST tags/convert`.
 */
export interface ConvertResult {
	converted: ConvertedTag[];
	failed: TagFailure[];
}

/**
 * The configuration `Licensio_KB_Tags_Admin::app_data()` localises.
 */
export interface TagsConfig {
	root: string;
	nonce: string;
	perPage: number;
	settingsUrl: string;
}
