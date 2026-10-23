<script setup lang="ts">
/**
 * The category tree.
 *
 * Categories can be dragged to a new place, or moved with the Move up, Move
 * down and Move to… actions from the keyboard. A drag or a Move up/down is
 * shown in the tree at once, then a Popconfirm on the tree asks to confirm:
 * Confirm sends the destination parent's complete child order, Cancel (or
 * Escape, or a click elsewhere) puts the tree back and sends nothing. After
 * a refused move the tree is loaded again from the server.
 *
 * The other actions (edit, add child, move to, merge, delete) are handed to
 * the app, which owns the drawer and dialogs.
 *
 * @since 1.0.0
 */

import { computed, nextTick, ref } from 'vue';
import { message } from 'ant-design-vue';
import { __, sprintf } from '../../components/i18n';
import { errorMessage } from '../api';
import {
	applyMove,
	childIds,
	descendantIds,
	useCategoriesStore,
} from '../store';
import type { CategoryNode } from '../store';
import type { CategoryItem } from '../types';

export type TreeAction = 'edit' | 'child' | 'move' | 'merge' | 'delete';

interface TreeEventNode {
	key: string | number;
	pos?: string;
	expanded?: boolean;
	children?: unknown[];
}

interface DropInfo {
	node: TreeEventNode;
	dragNode: TreeEventNode;
	dropPosition: number;
	dropToGap: boolean;
}

interface PendingMove {
	snapshot: CategoryItem[];
	parent: number;
	ids: number[];
	title: string;
	/** The category whose actions button gets focus back, for keyboard moves. */
	returnTo: number | null;
}

const emit = defineEmits< {
	( event: 'action', action: TreeAction, item: CategoryItem ): void;
} >();

const store = useCategoriesStore();

const pending = ref< PendingMove | null >( null );
const saving = ref( false );
const collapsed = ref< number[] >( [] );

const searching = computed( () => '' !== store.search.trim() );

/**
 * Keep the nodes whose name matches, with their ancestors.
 *
 * @since 1.0.0
 *
 * @param {CategoryNode[]} nodes  The nodes.
 * @param {string}         needle The lowercase search text.
 *
 * @return {CategoryNode[]} The matching nodes.
 */
function filterNodes( nodes: CategoryNode[], needle: string ): CategoryNode[] {
	return nodes.reduce< CategoryNode[] >( ( kept, node ) => {
		const children = filterNodes( node.children, needle );

		if ( children.length || node.title.toLowerCase().includes( needle ) ) {
			kept.push( { ...node, children } );
		}

		return kept;
	}, [] );
}

const treeData = computed( () =>
	searching.value
		? filterNodes( store.tree, store.search.trim().toLowerCase() )
		: store.tree
);

const expandedKeys = computed( () => {
	const parents = store.items
		.filter( ( item ) =>
			store.items.some( ( child ) => child.parent === item.id )
		)
		.map( ( item ) => item.id );

	return searching.value
		? parents
		: parents.filter( ( id ) => ! collapsed.value.includes( id ) );
} );

const selectedKeys = computed( () =>
	null === store.selectedId ? [] : [ store.selectedId ]
);

const canDrag = computed(
	() => ! searching.value && null === pending.value && ! saving.value
);

/**
 * Remember which nodes the user collapsed.
 *
 * @since 1.0.0
 *
 * @param {Array<string|number>} keys The expanded keys.
 *
 * @return {void}
 */
function onExpand( keys: Array< string | number > ): void {
	if ( searching.value ) {
		return;
	}

	const open = keys.map( Number );

	collapsed.value = store.items
		.map( ( item ) => item.id )
		.filter( ( id ) => ! open.includes( id ) );
}

/**
 * Select a category for the article order pane.
 *
 * @since 1.0.0
 *
 * @param {Array<string|number>} keys The selected keys.
 *
 * @return {void}
 */
function onSelect( keys: Array< string | number > ): void {
	store.selectedId = keys.length ? Number( keys[ 0 ] ) : null;
}

/**
 * The category a tree node stands for.
 *
 * @since 1.0.0
 *
 * @param {Object} node The slot's node data.
 *
 * @return {CategoryItem} The category.
 */
function itemOf( node: {
	item?: CategoryItem;
	dataRef?: { item?: CategoryItem };
} ): CategoryItem {
	return ( node.dataRef?.item ?? node.item ) as CategoryItem;
}

/**
 * Whether a drop would put a category inside itself.
 *
 * @since 1.0.0
 *
 * @param {Object} options          The drop being considered.
 * @param {Object} options.dragNode The dragged node.
 * @param {Object} options.dropNode The node under the pointer.
 *
 * @return {boolean} True when the drop is allowed.
 */
function allowDrop( {
	dragNode,
	dropNode,
}: {
	dragNode: TreeEventNode;
	dropNode: TreeEventNode;
} ): boolean {
	const dragId = Number( dragNode.key );
	const dropId = Number( dropNode.key );

	return (
		dragId !== dropId &&
		! descendantIds( store.items, dragId ).includes( dropId )
	);
}

/**
 * Show a move in the tree and ask to confirm it.
 *
 * @since 1.0.0
 *
 * @param {number}      id       The category to move.
 * @param {number}      parent   Its new parent, 0 for the top level.
 * @param {number}      index    Its position among the new siblings.
 * @param {string}      title    The question to ask.
 * @param {number|null} returnTo Whose actions button gets focus back.
 *
 * @return {void}
 */
function propose(
	id: number,
	parent: number,
	index: number,
	title: string,
	returnTo: number | null
): void {
	const snapshot = [ ...store.items ];
	const before = snapshot.find( ( item ) => item.id === id );
	const next = applyMove( snapshot, id, parent, index );
	const ids = childIds( next, parent );

	// Dropped back where it was: nothing to ask.
	if (
		before?.parent === parent &&
		ids.join( ',' ) === childIds( snapshot, parent ).join( ',' )
	) {
		return;
	}

	store.items = next;
	pending.value = { snapshot, parent, ids, title, returnTo };

	// Show where it went, even when that parent was collapsed.
	collapsed.value = collapsed.value.filter( ( key ) => key !== parent );

	focusConfirm();
}

/**
 * Move focus into the open Popconfirm, so a keyboard user can answer it.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function focusConfirm(): void {
	nextTick( () => {
		window.setTimeout( () => {
			document
				.querySelector< HTMLElement >(
					'.lkb-move-confirm .ant-popconfirm-buttons .ant-btn-primary'
				)
				?.focus();
		}, 50 );
	} );
}

/**
 * Put focus back on a category's actions button.
 *
 * @since 1.0.0
 *
 * @param {number|null} id The category.
 *
 * @return {void}
 */
function returnFocus( id: number | null ): void {
	if ( null === id ) {
		return;
	}

	nextTick( () => {
		document
			.querySelector< HTMLElement >( `[data-lkb-actions="${ id }"]` )
			?.focus();
	} );
}

/**
 * Turn a tree drop into a proposed move.
 *
 * `dropPosition` here is the drop node's index among its siblings plus -1,
 * 0 or 1, so the node's own index is taken off first.
 *
 * @since 1.0.0
 *
 * @param {DropInfo} info The drop.
 *
 * @return {void}
 */
function onDrop( info: DropInfo ): void {
	const dragId = Number( info.dragNode.key );
	const dropId = Number( info.node.key );
	const dropItem = store.find( dropId );
	const dragItem = store.find( dragId );

	if ( ! dropItem || ! dragItem ) {
		return;
	}

	const position = ( info.node.pos ?? '' ).split( '-' );
	const relative =
		info.dropPosition - Number( position[ position.length - 1 ] );
	const opensInto =
		! info.dropToGap ||
		( 1 === relative &&
			!! info.node.expanded &&
			( info.node.children?.length ?? 0 ) > 0 );

	let parent: number;
	let index: number;

	if ( opensInto ) {
		parent = dropId;
		index = 0;
	} else {
		parent = dropItem.parent;

		const siblings = childIds( store.items, parent ).filter(
			( id ) => id !== dragId
		);
		const at = siblings.indexOf( dropId );

		index = -1 === relative ? at : at + 1;
	}

	propose(
		dragId,
		parent,
		index,
		sprintf(
			/* translators: %s: category name. */
			__( 'Move “%s” here?', 'licensio-knowledge-base' ),
			dragItem.name
		),
		null
	);
}

/**
 * Move a category one place up or down among its siblings.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem} item The category.
 * @param {number}       direction -1 for up, 1 for down.
 *
 * @return {void}
 */
function step( item: CategoryItem, direction: -1 | 1 ): void {
	const siblings = childIds( store.items, item.parent );
	const index = siblings.indexOf( item.id ) + direction;

	if ( index < 0 || index >= siblings.length ) {
		return;
	}

	propose(
		item.id,
		item.parent,
		index,
		-1 === direction
			? sprintf(
					/* translators: %s: category name. */
					__( 'Move “%s” up?', 'licensio-knowledge-base' ),
					item.name
			  )
			: sprintf(
					/* translators: %s: category name. */
					__( 'Move “%s” down?', 'licensio-knowledge-base' ),
					item.name
			  ),
		item.id
	);
}

/**
 * Whether a category is first or last among its siblings.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem} item The category.
 * @param {string}       end  'first' or 'last'.
 *
 * @return {boolean} True at that end.
 */
function isAt( item: CategoryItem, end: 'first' | 'last' ): boolean {
	const siblings = childIds( store.items, item.parent );

	return 'first' === end
		? siblings[ 0 ] === item.id
		: siblings[ siblings.length - 1 ] === item.id;
}

/**
 * Send the proposed move, after the user confirmed.
 *
 * The pending move is cleared first: the Popconfirm reports itself closed
 * right after confirming, and that must not be taken for a cancel.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function confirmMove(): void {
	const move = pending.value;

	pending.value = null;

	if ( ! move ) {
		return;
	}

	saving.value = true;

	store
		.saveOrder( { parent: move.parent, ids: move.ids } )
		.then( () => {
			message.success( __( 'Category moved.', 'licensio-knowledge-base' ) );
		} )
		.catch( ( error: unknown ) => {
			// The server may have applied part of the move, so the tree is
			// read again rather than put back from the snapshot.
			message.error( errorMessage( error ) );
			store.load();
		} )
		.finally( () => {
			saving.value = false;
			returnFocus( move.returnTo );
		} );
}

/**
 * Put the tree back when the Popconfirm closes without confirming.
 *
 * Covers Cancel, Escape and a click outside.
 *
 * @since 1.0.0
 *
 * @param {boolean} open Whether the Popconfirm wants to be open.
 *
 * @return {void}
 */
function onOpenChange( open: boolean ): void {
	const move = pending.value;

	if ( open || ! move ) {
		return;
	}

	pending.value = null;
	store.items = move.snapshot;
	returnFocus( move.returnTo );
}

/**
 * Run an item from a category's actions menu.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem} item The category.
 * @param {string}       key  The menu item.
 *
 * @return {void}
 */
function onMenu( item: CategoryItem, key: string ): void {
	if ( 'up' === key ) {
		step( item, -1 );
		return;
	}

	if ( 'down' === key ) {
		step( item, 1 );
		return;
	}

	emit( 'action', key as TreeAction, item );
}
</script>

<template>
	<div class="lkb-tree">
		<a-input
			v-model:value="store.search"
			allow-clear
			class="lkb-tree__search"
			:placeholder="__( 'Filter categories', 'licensio-knowledge-base' )"
			:aria-label="__( 'Filter categories', 'licensio-knowledge-base' )"
		/>

		<p v-if="searching" class="lkb-tree__hint">
			{{
				__(
					'Clear the filter to drag categories.',
					'licensio-knowledge-base'
				)
			}}
		</p>

		<a-popconfirm
			:open="null !== pending"
			:title="pending?.title ?? ''"
			:ok-text="__( 'Move', 'licensio-knowledge-base' )"
			:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
			overlay-class-name="lkb-move-confirm"
			placement="top"
			@confirm="confirmMove"
			@open-change="onOpenChange"
		>
			<div class="lkb-tree__body" :aria-busy="saving">
				<p v-if="0 === treeData.length" class="lkb-tree__hint">
					{{
						__(
							'No categories match the filter.',
							'licensio-knowledge-base'
						)
					}}
				</p>

				<a-tree
					v-else
					:tree-data="treeData"
					:expanded-keys="expandedKeys"
					:selected-keys="selectedKeys"
					:draggable="canDrag"
					:allow-drop="allowDrop"
					block-node
					@expand="onExpand"
					@select="onSelect"
					@drop="onDrop"
				>
					<template #title="node">
						<span class="lkb-tree__node">
							<span class="lkb-tree__name">{{ itemOf( node ).name }}</span>
							<span class="lkb-tree__count" aria-hidden="true">
								{{ itemOf( node ).count }}
							</span>
							<span class="screen-reader-text">
								{{
									sprintf(
										/* translators: %d: number of articles. */
										__( 'Articles: %d', 'licensio-knowledge-base' ),
										itemOf( node ).count
									)
								}}
							</span>

							<a-dropdown :trigger="[ 'click' ]">
								<a-button
									type="text"
									size="small"
									class="lkb-tree__actions"
									:data-lkb-actions="itemOf( node ).id"
									:aria-label="
										sprintf(
											/* translators: %s: category name. */
											__( 'Actions for %s', 'licensio-knowledge-base' ),
											itemOf( node ).name
										)
									"
									@click.stop
								>
									⋯
								</a-button>
								<template #overlay>
									<a-menu
										@click="
											( { key }: { key: string | number } ) =>
												onMenu( itemOf( node ), String( key ) )
										"
									>
										<a-menu-item key="edit">
											{{ __( 'Edit', 'licensio-knowledge-base' ) }}
										</a-menu-item>
										<a-menu-item key="child">
											{{ __( 'Add child', 'licensio-knowledge-base' ) }}
										</a-menu-item>
										<a-menu-item
											key="up"
											:disabled="saving || isAt( itemOf( node ), 'first' )"
										>
											{{ __( 'Move up', 'licensio-knowledge-base' ) }}
										</a-menu-item>
										<a-menu-item
											key="down"
											:disabled="saving || isAt( itemOf( node ), 'last' )"
										>
											{{ __( 'Move down', 'licensio-knowledge-base' ) }}
										</a-menu-item>
										<a-menu-item key="move">
											{{ __( 'Move to…', 'licensio-knowledge-base' ) }}
										</a-menu-item>
										<a-menu-item key="merge">
											{{ __( 'Merge…', 'licensio-knowledge-base' ) }}
										</a-menu-item>
										<a-menu-item key="delete" danger>
											{{ __( 'Delete…', 'licensio-knowledge-base' ) }}
										</a-menu-item>
									</a-menu>
								</template>
							</a-dropdown>
						</span>
					</template>
				</a-tree>
			</div>
		</a-popconfirm>
	</div>
</template>

<style scoped>
.lkb-tree__search {
	margin-bottom: 8px;
}

.lkb-tree__hint {
	margin: 0 0 8px;
	color: var(--lkb-ink-muted);
	font-size: 12px;
}

.lkb-tree__node {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.lkb-tree__name {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
}

.lkb-tree__count {
	min-width: 24px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--lkb-sunken);
	color: var(--lkb-ink-muted);
	font-size: 12px;
	text-align: center;
}

.lkb-tree__actions {
	flex: none;
}
</style>
