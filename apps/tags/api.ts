/**
 * REST calls of the Tags app.
 *
 * Every call goes through the shared `rest.ts` client, which `index.ts`
 * configures with the root and nonce the page localised.
 *
 * @since 1.0.0
 */

import { get, patch, post } from '../components/rest';
import type {
	BulkDeleteResult,
	ConvertResult,
	MergeResult,
	TagFields,
	TagItem,
	TagOrder,
	TagOrderBy,
	TagPage,
	TagsConfig,
} from './types';

declare global {
	interface Window {
		licensioKbTags?: TagsConfig;
	}
}

/**
 * The configuration PHP localised onto the page.
 *
 * @since 1.0.0
 *
 * @return {TagsConfig} The configuration, with safe fallbacks.
 */
export function settings(): TagsConfig {
	return (
		window.licensioKbTags ?? {
			root: '',
			nonce: '',
			perPage: 20,
			settingsUrl: '',
		}
	);
}

export interface TagQuery {
	search: string;
	unused: boolean;
	include: number[];
	orderby: TagOrderBy;
	order: TagOrder;
	page: number;
	perPage: number;
}

/**
 * Fetch one page of tags.
 *
 * @since 1.0.0
 *
 * @param {TagQuery} query The filters, sort and page.
 *
 * @return {Promise<TagPage>} The page.
 */
export function fetchTags( query: TagQuery ): Promise< TagPage > {
	return get< TagPage >( 'tags', {
		search: query.search,
		unused: query.unused ? 1 : 0,
		include: query.include,
		orderby: query.orderby,
		order: query.order,
		page: query.page,
		per_page: query.perPage,
	} );
}

/**
 * Create a tag.
 *
 * @since 1.0.0
 *
 * @param {TagFields} fields The name, and optionally the slug and description.
 *
 * @return {Promise<TagItem>} The new tag.
 */
export function createTag( fields: TagFields ): Promise< TagItem > {
	return post< TagItem >( 'tags', fields );
}

/**
 * Change a tag's name, slug or description.
 *
 * @since 1.0.0
 *
 * @param {number}    id     The tag.
 * @param {TagFields} fields The fields to change.
 *
 * @return {Promise<TagItem>} The updated tag, with the slug the server settled on.
 */
export function updateTag( id: number, fields: TagFields ): Promise< TagItem > {
	return patch< TagItem >( 'tags/' + String( id ), fields );
}

/**
 * Delete tags.
 *
 * @since 1.0.0
 *
 * @param {number[]} ids The tags, at most 100.
 *
 * @return {Promise<BulkDeleteResult>} Which were deleted and which failed.
 */
export function deleteTags( ids: number[] ): Promise< BulkDeleteResult > {
	return post< BulkDeleteResult >( 'tags/bulk-delete', { ids } );
}

/**
 * Merge tags into another tag.
 *
 * @since 1.0.0
 *
 * @param {number[]} sources The tags to merge away, at most 100.
 * @param {number}   target  The tag that remains.
 *
 * @return {Promise<MergeResult>} The merge result.
 */
export function mergeTags(
	sources: number[],
	target: number
): Promise< MergeResult > {
	return post< MergeResult >( 'tags/merge', { sources, target } );
}

/**
 * Turn tags into categories.
 *
 * @since 1.0.0
 *
 * @param {number[]} ids    The tags, at most 100.
 * @param {number}   parent The parent category, or 0 for the top level.
 *
 * @return {Promise<ConvertResult>} Which tags were converted and which failed.
 */
export function convertTags(
	ids: number[],
	parent: number
): Promise< ConvertResult > {
	return post< ConvertResult >( 'tags/convert', { ids, parent } );
}
