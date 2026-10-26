/**
 * Categories app types.
 *
 * The shapes `Licensio_KB_Categories_REST_Controller` answers with and
 * accepts, and the page configuration `Licensio_KB_Categories_Admin`
 * localises. The article order pane uses the shared types in
 * `../components/types/category-order.ts` instead.
 *
 * @since 1.0.0
 */

/**
 * One category from `GET categories`.
 *
 * Names are plain text (entities decoded). The list is flat; `parent` links
 * a category to its parent, 0 for the top level. Siblings come in their
 * manual order, then by name.
 */
export interface CategoryItem {
	id: number;
	name: string;
	slug: string;
	parent: number;
	/** As stored, entities and all. Shown as text, never as HTML. */
	description: string;
	/** Published articles in this category, as core counts them. */
	count: number;
	/** The manual sort order among siblings (`licensio_order`). */
	order: number;
	/** A Dashicon name, e.g. `dashicons-book-alt`, or empty. */
	icon: string;
	/** The uploaded icon's attachment ID, or 0. */
	icon_id: number;
	/** Sanitised inline SVG markup, or empty. Shown as text, never as HTML. */
	icon_svg: string;
	/** An inline Base64 PNG, JPEG, GIF or WebP icon as a data URL, or empty. */
	icon_data: string;
	/** The category image's attachment ID, or 0. */
	image_id: number;
	/** The uploaded icon's URL, or empty. */
	icon_url: string;
	/** The image's URL, or empty. */
	image_url: string;
}

/**
 * The body of `POST categories` (name required) and `PATCH categories/{id}`
 * (every field optional; only the fields sent are changed).
 */
export interface CategoryInput {
	name?: string;
	slug?: string;
	parent?: number;
	description?: string;
	icon?: string;
	icon_id?: number;
	icon_svg?: string;
	icon_data?: string;
	image_id?: number;
}

/**
 * Where a deleted category's articles go: its parent, nowhere, or a
 * category ID. `parent` is refused for a top-level category.
 */
export type ArticleReassignment = 'parent' | 'none' | number;

/**
 * Where a deleted category's child categories go: its parent (the top level
 * for a top-level category) or a category ID.
 */
export type ChildReassignment = 'parent' | number;

/**
 * `DELETE categories/{id}` answer.
 */
export interface CategoryDeleted {
	deleted: number;
	/** How many articles were moved to the chosen category. */
	moved: number;
	/** The child categories that were given a new parent. */
	reparented: number[];
}

/**
 * `POST categories/order` body: the complete, ordered list of children for
 * `parent` (0 for the top level). Every ID is moved under `parent`. Answers
 * the whole tree, as `GET categories` does.
 */
export interface CategoryOrderInput {
	parent: number;
	ids: number[];
}

/**
 * `POST categories/merge` body. At most 50 sources.
 */
export interface CategoryMergeInput {
	sources: number[];
	target: number;
}

/**
 * `POST categories/merge` answer.
 */
export interface CategoryMerged {
	moved: number;
	deleted: number[];
	reparented: number[];
}

/**
 * The page configuration on `window.licensioKbCategories`.
 */
export interface CategoriesConfig {
	/** The REST namespace URL, for `rest.ts` `configure()`. */
	root: string;
	nonce: string;
	settingsUrl: string;
	/** The Articles app, which the article counts link to. */
	articlesUrl: string;
}
