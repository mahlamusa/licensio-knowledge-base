/**
 * Term picker types.
 *
 * The shape `GET licensio-kb/v1/terms` answers with
 * (`Licensio_KB_Terms_REST_Controller`), and the values `TermSelect` emits.
 *
 * Consumed by the articles, categories and tags apps.
 *
 * @since 1.0.0
 */

export type TermTaxonomy = 'licensio_category' | 'licensio_tag';

/**
 * One term from `GET terms`.
 */
export interface TermItem {
	id: number;
	name: string;
	slug: string;
	parent: number;
	count: number;
	/** Ancestor names, top first. Always empty for tags. */
	path: string[];
}

/**
 * A term the user typed that does not exist yet. The write route that
 * receives it creates it, e.g. `PATCH articles/{id}` with `tags: [ 4, { name } ]`.
 */
export interface NewTerm {
	name: string;
}

/**
 * A picked term: an existing term's ID, or a new term's name.
 */
export type TermValue = number | NewTerm;
