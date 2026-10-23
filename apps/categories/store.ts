/**
 * Categories store.
 *
 * Holds every category as the flat list `GET categories` answers, in server
 * order, and the tree is derived from it: children keep the order they have
 * in the list. A move is applied to the list locally first (so the tree shows
 * it while the user is asked to confirm), and the snapshot taken before it is
 * put back when the user cancels. When the server refuses, the list is loaded
 * again, since part of the move may have been applied.
 *
 * The category writes go through here; they throw on failure so the dialog
 * that asked can show the error where the user is looking.
 *
 * @since 1.0.0
 */

import { defineStore } from 'pinia';
import {
	createCategory,
	deleteCategory,
	errorMessage,
	fetchCategories,
	mergeCategories,
	saveCategoryOrder,
	updateCategory,
} from './api';
import type {
	ArticleReassignment,
	CategoryDeleted,
	CategoryInput,
	CategoryItem,
	CategoryMerged,
	CategoryMergeInput,
	CategoryOrderInput,
	ChildReassignment,
} from './types';

/**
 * One node of the derived tree, in the shape `a-tree` reads.
 */
export interface CategoryNode {
	key: number;
	title: string;
	item: CategoryItem;
	children: CategoryNode[];
}

/**
 * The IDs of a parent's children, in order.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem[]} items  Every category.
 * @param {number}         parent The parent, 0 for the top level.
 *
 * @return {number[]} The child IDs.
 */
export function childIds( items: CategoryItem[], parent: number ): number[] {
	return items
		.filter( ( item ) => item.parent === parent )
		.map( ( item ) => item.id );
}

/**
 * The IDs of every category below one, at any depth.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem[]} items Every category.
 * @param {number}         id    The category.
 *
 * @return {number[]} The descendant IDs.
 */
export function descendantIds( items: CategoryItem[], id: number ): number[] {
	const found: number[] = [];
	const queue = [ id ];

	while ( queue.length ) {
		const current = queue.shift() as number;

		items.forEach( ( item ) => {
			if ( item.parent === current && ! found.includes( item.id ) ) {
				found.push( item.id );
				queue.push( item.id );
			}
		} );
	}

	return found;
}

/**
 * A category's ID followed by the IDs of every category below it.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem[]} items Every category.
 * @param {number}         id    The category.
 *
 * @return {number[]} The category and its descendant IDs.
 */
export function selfAndDescendantIds(
	items: CategoryItem[],
	id: number
): number[] {
	return [ id, ...descendantIds( items, id ) ];
}

/**
 * The list with one category moved under a parent at a position.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem[]} items  Every category.
 * @param {number}         id     The category to move.
 * @param {number}         parent The new parent, 0 for the top level.
 * @param {number}         index  Its position among the new siblings.
 *
 * @return {CategoryItem[]} A new list.
 */
export function applyMove(
	items: CategoryItem[],
	id: number,
	parent: number,
	index: number
): CategoryItem[] {
	const moving = items.find( ( item ) => item.id === id );

	if ( ! moving ) {
		return items;
	}

	const rest = items.filter( ( item ) => item.id !== id );
	const moved = { ...moving, parent };
	const siblings = rest.filter( ( item ) => item.parent === parent );

	if ( index < siblings.length ) {
		rest.splice(
			rest.indexOf( siblings[ Math.max( 0, index ) ] ),
			0,
			moved
		);
	} else if ( siblings.length ) {
		rest.splice(
			rest.indexOf( siblings[ siblings.length - 1 ] ) + 1,
			0,
			moved
		);
	} else {
		rest.push( moved );
	}

	return rest;
}

/**
 * Bumped by every load and by every write that replaces the list, so only
 * the latest answer is kept: a load that started before a move was saved
 * must not put the old tree back when it resolves last.
 */
let listTicket = 0;

interface State {
	items: CategoryItem[];
	loading: boolean;
	loaded: boolean;
	error: string;
	selectedId: number | null;
	search: string;
}

export const useCategoriesStore = defineStore( 'licensioKBCategories', {
	state: (): State => ( {
		items: [],
		loading: false,
		loaded: false,
		error: '',
		selectedId: null,
		search: '',
	} ),

	getters: {
		/**
		 * The categories as a tree.
		 *
		 * @since 1.0.0
		 *
		 * @param {State} state The store state.
		 *
		 * @return {CategoryNode[]} The top-level nodes.
		 */
		tree( state ): CategoryNode[] {
			const nodes = new Map< number, CategoryNode >();

			state.items.forEach( ( item ) => {
				nodes.set( item.id, {
					key: item.id,
					title: item.name,
					item,
					children: [],
				} );
			} );

			const roots: CategoryNode[] = [];

			state.items.forEach( ( item ) => {
				const node = nodes.get( item.id ) as CategoryNode;
				const parent = nodes.get( item.parent );

				// An orphan (parent missing) is shown at the top level.
				if ( parent ) {
					parent.children.push( node );
				} else {
					roots.push( node );
				}
			} );

			return roots;
		},

		/**
		 * The selected category, if any.
		 *
		 * @since 1.0.0
		 *
		 * @param {State} state The store state.
		 *
		 * @return {CategoryItem|null} The category.
		 */
		selected( state ): CategoryItem | null {
			return (
				state.items.find( ( item ) => item.id === state.selectedId ) ??
				null
			);
		},
	},

	actions: {
		/**
		 * Wait for a write, then reload the tree.
		 *
		 * @since 1.0.0
		 *
		 * @template T
		 *
		 * @param {Promise<T>} write The write request.
		 *
		 * @return {Promise<T>} The write's answer, once the tree is reloaded.
		 */
		async reloadAfter< T >( write: Promise< T > ): Promise< T > {
			const result = await write;

			await this.load();

			return result;
		},

		/**
		 * One category by ID.
		 *
		 * @since 1.0.0
		 *
		 * @param {number} id The category.
		 *
		 * @return {CategoryItem|undefined} The category.
		 */
		find( id: number ): CategoryItem | undefined {
			return this.items.find( ( item ) => item.id === id );
		},

		/**
		 * Load every category.
		 *
		 * An answer that arrives after a later load started, or after a saved
		 * order replaced the list, is dropped.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the list is in state.
		 */
		async load(): Promise< void > {
			const ticket = ++listTicket;

			this.loading = true;
			this.error = '';

			try {
				const items = await fetchCategories();

				if ( ticket === listTicket ) {
					this.setItems( items );
					this.loaded = true;
				}
			} catch ( error ) {
				if ( ticket === listTicket ) {
					this.error = errorMessage( error );
				}
			} finally {
				if ( ticket === listTicket ) {
					this.loading = false;
				}
			}
		},

		/**
		 * Replace the list, dropping a selection that no longer exists.
		 *
		 * @since 1.0.0
		 *
		 * @param {CategoryItem[]} items Every category.
		 *
		 * @return {void}
		 */
		setItems( items: CategoryItem[] ): void {
			this.items = items;

			if (
				null !== this.selectedId &&
				! items.some( ( item ) => item.id === this.selectedId )
			) {
				this.selectedId = null;
			}
		},

		/**
		 * Create a category, then reload the tree.
		 *
		 * @since 1.0.0
		 *
		 * @param {CategoryInput} input The new category.
		 *
		 * @return {Promise<CategoryItem>} The created category.
		 */
		create( input: CategoryInput ): Promise< CategoryItem > {
			return this.reloadAfter( createCategory( input ) );
		},

		/**
		 * Change a category, then reload the tree.
		 *
		 * @since 1.0.0
		 *
		 * @param {number}        id    The category.
		 * @param {CategoryInput} input The fields to change.
		 *
		 * @return {Promise<CategoryItem>} The updated category.
		 */
		update( id: number, input: CategoryInput ): Promise< CategoryItem > {
			return this.reloadAfter( updateCategory( id, input ) );
		},

		/**
		 * Delete a category, then reload the tree.
		 *
		 * @since 1.0.0
		 *
		 * @param {number}              id       The category.
		 * @param {ArticleReassignment} articles Where its articles go.
		 * @param {ChildReassignment}   children Where its children go.
		 *
		 * @return {Promise<CategoryDeleted>} What was deleted and moved.
		 */
		remove(
			id: number,
			articles: ArticleReassignment,
			children: ChildReassignment
		): Promise< CategoryDeleted > {
			return this.reloadAfter( deleteCategory( id, articles, children ) );
		},

		/**
		 * Merge categories, then reload the tree.
		 *
		 * @since 1.0.0
		 *
		 * @param {CategoryMergeInput} input The sources and the target.
		 *
		 * @return {Promise<CategoryMerged>} What was moved and deleted.
		 */
		merge( input: CategoryMergeInput ): Promise< CategoryMerged > {
			return this.reloadAfter( mergeCategories( input ) );
		},

		/**
		 * Save one parent's child order; the answer replaces the list.
		 *
		 * @since 1.0.0
		 *
		 * @param {CategoryOrderInput} input The parent and its ordered children.
		 *
		 * @return {Promise<void>} Resolves once the list is replaced.
		 */
		async saveOrder( input: CategoryOrderInput ): Promise< void > {
			const items = await saveCategoryOrder( input );

			// Newer than any load still out, so those are dropped.
			++listTicket;
			this.loading = false;
			this.setItems( items );
		},
	},
} );
