/**
 * Per-category article order types.
 *
 * The shapes of the two routes `Licensio_KB_Category_Order_REST_Controller`
 * registers. The routes answer 501 until the per-category article order is
 * built; these types are the contract that work keeps.
 *
 * Consumed by the categories app and the per-category article order.
 *
 * @since 1.0.0
 */

/** The most articles one page of the list returns. */
export const CATEGORY_ORDER_MAX_PER_PAGE = 200;

/**
 * One article in a category's order.
 */
export interface CategoryOrderArticle {
	id: number;
	title: string;
	/** The post status. Trashed articles are never listed. */
	status: string;
	/** The article's position in this category, or null when it has none. */
	order: number | null;
	edit_url: string;
}

/**
 * `GET categories/{id}/articles` query arguments.
 */
export interface CategoryOrderQuery {
	page?: number;
	/** 1 to 200. Defaults to 200. */
	per_page?: number;
}

/**
 * `GET categories/{id}/articles` answer.
 */
export interface CategoryOrderPage {
	items: CategoryOrderArticle[];
	total: number;
	pages: number;
}

/**
 * `PUT categories/{id}/articles/order` body: article IDs, first comes first.
 */
export interface CategoryOrderUpdate {
	ids: number[];
}

/**
 * `PUT categories/{id}/articles/order` answer: the order as saved.
 */
export interface CategoryOrderSaved {
	ids: number[];
}

/**
 * The route of a category's article list, for `rest.ts`.
 *
 * @since 1.0.0
 *
 * @param {number} categoryId The category.
 *
 * @return {string} The route below the namespace.
 */
export function categoryArticlesPath( categoryId: number ): string {
	return `categories/${ categoryId }/articles`;
}

/**
 * The route that saves a category's article order, for `rest.ts`.
 *
 * @since 1.0.0
 *
 * @param {number} categoryId The category.
 *
 * @return {string} The route below the namespace.
 */
export function categoryOrderPath( categoryId: number ): string {
	return `categories/${ categoryId }/articles/order`;
}
