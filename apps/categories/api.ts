/**
 * REST calls for the categories app.
 *
 * Thin wrappers over the shared `rest.ts` client, one per route
 * `Licensio_KB_Categories_REST_Controller` and
 * `Licensio_KB_Category_Order_REST_Controller` register. The store is the only
 * caller of the category routes; the article order pane calls its two routes
 * itself.
 *
 * @since 1.0.0
 */

import { RestError, del, get, patch, post, put } from '../components/rest';
import {
	CATEGORY_ORDER_MAX_PER_PAGE,
	categoryArticlesPath,
	categoryOrderPath,
} from '../components/types/category-order';
import type {
	CategoryOrderPage,
	CategoryOrderSaved,
} from '../components/types/category-order';
import type {
	ArticleReassignment,
	CategoriesConfig,
	CategoryDeleted,
	CategoryInput,
	CategoryItem,
	CategoryMerged,
	CategoryMergeInput,
	CategoryOrderInput,
	ChildReassignment,
} from './types';

declare global {
	interface Window {
		licensioKbCategories?: CategoriesConfig;
	}
}

/**
 * The configuration PHP localised onto the page.
 *
 * @since 1.0.0
 *
 * @return {CategoriesConfig} The page configuration, with safe fallbacks.
 */
export function settings(): CategoriesConfig {
	return (
		window.licensioKbCategories ?? { root: '', nonce: '', settingsUrl: '' }
	);
}

/**
 * Whether an error means the route is not built yet (HTTP 501).
 *
 * @since 1.0.0
 *
 * @param {unknown} error The caught error.
 *
 * @return {boolean} True for a "not implemented" answer.
 */
export function isNotImplemented( error: unknown ): boolean {
	return (
		error instanceof RestError &&
		( 501 === error.status || 'licensio_kb_not_implemented' === error.code )
	);
}

/**
 * The message to show for a caught error.
 *
 * @since 1.0.0
 *
 * @param {unknown} error The caught error.
 *
 * @return {string} The message.
 */
export function errorMessage( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

/**
 * Every category, flat, siblings in their order.
 *
 * @since 1.0.0
 *
 * @return {Promise<CategoryItem[]>} The categories.
 */
export function fetchCategories(): Promise< CategoryItem[] > {
	return get< CategoryItem[] >( 'categories' );
}

/**
 * Create a category.
 *
 * @since 1.0.0
 *
 * @param {CategoryInput} input The new category; `name` is required.
 *
 * @return {Promise<CategoryItem>} The created category.
 */
export function createCategory(
	input: CategoryInput
): Promise< CategoryItem > {
	return post< CategoryItem >( 'categories', input );
}

/**
 * Change a category.
 *
 * @since 1.0.0
 *
 * @param {number}        id    The category.
 * @param {CategoryInput} input The fields to change.
 *
 * @return {Promise<CategoryItem>} The updated category.
 */
export function updateCategory(
	id: number,
	input: CategoryInput
): Promise< CategoryItem > {
	return patch< CategoryItem >( `categories/${ id }`, input );
}

/**
 * Delete a category.
 *
 * @since 1.0.0
 *
 * @param {number}              id       The category.
 * @param {ArticleReassignment} articles Where its articles go.
 * @param {ChildReassignment}   children Where its child categories go.
 *
 * @return {Promise<CategoryDeleted>} What was deleted and moved.
 */
export function deleteCategory(
	id: number,
	articles: ArticleReassignment,
	children: ChildReassignment
): Promise< CategoryDeleted > {
	return del< CategoryDeleted >( `categories/${ id }`, {
		reassign_articles: articles,
		reassign_children: children,
	} );
}

/**
 * Save the complete, ordered child list of one parent.
 *
 * @since 1.0.0
 *
 * @param {CategoryOrderInput} input The parent and its ordered children.
 *
 * @return {Promise<CategoryItem[]>} Every category, as `fetchCategories`.
 */
export function saveCategoryOrder(
	input: CategoryOrderInput
): Promise< CategoryItem[] > {
	return post< CategoryItem[] >( 'categories/order', input );
}

/**
 * Merge categories into another.
 *
 * @since 1.0.0
 *
 * @param {CategoryMergeInput} input The sources and the target.
 *
 * @return {Promise<CategoryMerged>} What was moved and deleted.
 */
export function mergeCategories(
	input: CategoryMergeInput
): Promise< CategoryMerged > {
	return post< CategoryMerged >( 'categories/merge', input );
}

/**
 * The first page of a category's articles, in their order.
 *
 * @since 1.0.0
 *
 * @param {number} categoryId The category.
 *
 * @return {Promise<CategoryOrderPage>} The articles.
 */
export function fetchCategoryArticles(
	categoryId: number
): Promise< CategoryOrderPage > {
	return get< CategoryOrderPage >( categoryArticlesPath( categoryId ), {
		page: 1,
		per_page: CATEGORY_ORDER_MAX_PER_PAGE,
	} );
}

/**
 * Save a category's article order.
 *
 * @since 1.0.0
 *
 * @param {number}   categoryId The category.
 * @param {number[]} ids        Article IDs, first comes first.
 *
 * @return {Promise<CategoryOrderSaved>} The order as saved.
 */
export function saveArticleOrder(
	categoryId: number,
	ids: number[]
): Promise< CategoryOrderSaved > {
	return put< CategoryOrderSaved >( categoryOrderPath( categoryId ), {
		ids,
	} );
}
