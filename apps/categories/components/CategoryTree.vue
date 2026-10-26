<script setup lang="ts">
/**
 * The category tree.
 *
 * Categories can be dragged to a new place, or moved with the Move up, Move
 * down and Move to… actions from the keyboard. A move is not destructive, so
 * it is not confirmed: a drag or a Move up/down is shown in the tree at once
 * and the destination parent's complete child order is sent straight away.
 * After a refused move the tree is loaded again from the server.
 *
 * The other actions (edit, add child, move to, merge, delete) are handed to
 * the app, which owns the form and dialogs. A category's article count
 * links to the Articles app filtered by that category. The category the
 * app's form holds is marked with `aria-current` and an accent bar.
 *
 * @since 1.0.0
 */

import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { __, sprintf } from '../../components/i18n';
import { errorMessage } from '../../components/rest';
import { settings } from '../api';
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

const props = defineProps< {
	/** The category the form is editing, or null. */
	editingId: number | null;
} >();

const emit = defineEmits< {
	( event: 'action', action: TreeAction, item: CategoryItem ): void;
} >();

const store = useCategoriesStore();
const { articlesUrl } = settings();

const saving = ref( false );
const collapsed = ref< number[] >( [] );
const menuFor = ref< number | null >( null );

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

// Every category counts as expanded unless the user collapsed it, leaves
// included: dropping a parent from this list in the same update that removes
// its last child makes the tree animate a node that no longer exists.
const expandedKeys = computed( () => {
	const ids = store.items.map( ( item ) => item.id );

	return searching.value
		? ids
		: ids.filter( ( id ) => ! collapsed.value.includes( id ) );
} );

const selectedKeys = computed( () =>
	null === store.selectedId ? [] : [ store.selectedId ]
);

/*
 * The node the arrow keys are on. Held here because Ant Design's tree only
 * moves its keyboard cursor when `activeKey` is bound: left unbound, the
 * arrow keys and Enter do nothing.
 */
const activeKey = ref< string | number | null >( null );

const canDrag = computed( () => ! searching.value && ! saving.value );

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
 * Clicking the selected category again unselects it. Either way an unsaved
 * article order is only thrown away once the user agreed.
 *
 * @since 1.0.0
 *
 * @param {Array<string|number>} keys The selected keys.
 *
 * @return {void}
 */
function onSelect( keys: Array< string | number > ): void {
	const next = keys.length ? Number( keys[ 0 ] ) : null;

	if ( next === store.selectedId || ! store.confirmDiscardOrder() ) {
		return;
	}

	store.selectedId = next;
}

/**
 * The category a tree node stands for.
 *
 * @since 1.0.0
 *
 * @param {Object}       node                The slot's node data.
 * @param {CategoryItem} [node.item]         The category, on a plain node.
 * @param {Object}       [node.dataRef]      The source data, on a tree node.
 * @param {CategoryItem} [node.dataRef.item] The category, on a tree node.
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
 * Show a move in the tree and save it.
 *
 * Moves are not destructive, so nothing asks first: the tree shows the move
 * at once and the destination parent's child order is sent. The server's
 * answer replaces the list; after a refusal the list is loaded again, since
 * part of the move may have been applied.
 *
 * @since 1.0.0
 *
 * @param {number}      id       The category to move.
 * @param {number}      parent   Its new parent, 0 for the top level.
 * @param {number}      index    Its position among the new siblings.
 * @param {number|null} returnTo Whose actions button gets focus back.
 *
 * @return {void}
 */
function move(
	id: number,
	parent: number,
	index: number,
	returnTo: number | null
): void {
	const before = store.find( id );
	const next = applyMove( store.items, id, parent, index );
	const ids = childIds( next, parent );

	// Dropped back where it was: nothing to send.
	if (
		before?.parent === parent &&
		ids.join( ',' ) === childIds( store.items, parent ).join( ',' )
	) {
		return;
	}

	store.items = next;

	// Show where it went, even when that parent was collapsed.
	collapsed.value = collapsed.value.filter( ( key ) => key !== parent );

	saving.value = true;

	store
		.saveOrder( { parent, ids } )
		.then( () => {
			message.success(
				__( 'Category moved.', 'licensio-knowledge-base' )
			);
		} )
		.catch( ( error: unknown ) => {
			// The server may have applied part of the move, so the tree is
			// read again rather than put back.
			message.error( errorMessage( error ) );
			store.load();
		} )
		.finally( () => {
			saving.value = false;
			returnFocus( returnTo );
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
 * Turn a tree drop into a move.
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

	move( dragId, parent, index, null );
}

/**
 * Move a category one place up or down among its siblings.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem} item      The category.
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

	move( item.id, item.parent, index, item.id );
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
 * Name the tree's hidden keyboard input.
 *
 * Ant Design's tree takes keys through a hidden input it labels "for screen
 * reader" in English, with no prop to change it. Vue leaves the attribute
 * alone once set, and the input is made again whenever the tree is.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function nameTreeInput(): void {
	document
		.querySelector(
			'.lkb-tree__body input[aria-label="for screen reader"]'
		)
		?.setAttribute(
			'aria-label',
			__( 'Category tree', 'licensio-knowledge-base' )
		);
}

onMounted( nameTreeInput );
watch( treeData, nameTreeInput, { flush: 'post' } );

/**
 * The enabled items of the open actions menu.
 *
 * @since 1.0.0
 *
 * @return {HTMLElement[]} The menu items, in order.
 */
function menuItems(): HTMLElement[] {
	return [
		...document.querySelectorAll< HTMLElement >(
			'.lkb-node-menu:not(.ant-dropdown-hidden) .ant-dropdown-menu-item:not(.ant-dropdown-menu-item-disabled)'
		),
	];
}

/**
 * Open or close a category's actions menu.
 *
 * Ant Design's menu does not move focus itself, so on opening the first
 * item gets focus, which lets a keyboard user reach the actions.
 *
 * @since 1.0.0
 *
 * @param {number}  id   The category.
 * @param {boolean} open Whether the menu wants to be open.
 *
 * @return {void}
 */
function onMenuOpen( id: number, open: boolean ): void {
	if ( ! open ) {
		if ( menuFor.value === id ) {
			menuFor.value = null;
		}

		return;
	}

	menuFor.value = id;

	nextTick( () => {
		window.setTimeout( () => menuItems()[ 0 ]?.focus(), 0 );
	} );
}

/**
 * Move through the open actions menu from the keyboard.
 *
 * Up and Down (and Home and End) move between the items, Enter runs one,
 * and Escape or Tab closes the menu and puts focus back on its button.
 *
 * @since 1.0.0
 *
 * @param {KeyboardEvent} event The key press.
 * @param {number}        id    The category whose menu is open.
 *
 * @return {void}
 */
function onMenuKey( event: KeyboardEvent, id: number ): void {
	if ( 'Escape' === event.key || 'Tab' === event.key ) {
		event.preventDefault();
		menuFor.value = null;
		returnFocus( id );
		return;
	}

	const items = menuItems();
	const at = items.indexOf(
		items[ 0 ]?.ownerDocument.activeElement as HTMLElement
	);
	const next: Record< string, number > = {
		ArrowDown: ( at + 1 ) % items.length,
		ArrowUp: ( at - 1 + items.length ) % items.length,
		Home: 0,
		End: items.length - 1,
	};

	if ( items.length && event.key in next ) {
		event.preventDefault();
		items[ next[ event.key ] ]?.focus();
	}
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
	menuFor.value = null;

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
				:active-key="activeKey"
				:draggable="canDrag"
				:allow-drop="allowDrop"
				block-node
				@expand="onExpand"
				@select="onSelect"
				@active-change="
					( key: string | number | null ) => ( activeKey = key )
				"
				@drop="onDrop"
			>
				<template #title="node">
					<span
						class="lkb-tree__node"
						:class="{
							'is-editing': props.editingId === itemOf( node ).id,
						}"
						:aria-current="
							props.editingId === itemOf( node ).id
								? 'true'
								: undefined
						"
					>
						<span class="lkb-tree__name">{{
							itemOf( node ).name
						}}</span>
						<a
							class="lkb-tree__count"
							draggable="false"
							:href="`${ articlesUrl }&category=${
								itemOf( node ).id
							}`"
							:aria-label="
								sprintf(
									/* translators: %d: number of articles. */
									__(
										'Articles: %d',
										'licensio-knowledge-base'
									),
									itemOf( node ).count
								)
							"
							@click.stop
						>
							{{ itemOf( node ).count }}
						</a>

						<a-dropdown
							:trigger="[ 'click' ]"
							:open="menuFor === itemOf( node ).id"
							overlay-class-name="lkb-node-menu"
							@open-change="
								( open: boolean ) =>
									onMenuOpen( itemOf( node ).id, open )
							"
						>
							<a-button
								type="text"
								size="small"
								class="lkb-tree__actions"
								aria-haspopup="menu"
								:aria-expanded="menuFor === itemOf( node ).id"
								:data-lkb-actions="itemOf( node ).id"
								:aria-label="
									sprintf(
										/* translators: %s: category name. */
										__(
											'Actions for %s',
											'licensio-knowledge-base'
										),
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
											onMenu(
												itemOf( node ),
												String( key )
											)
									"
									@keydown="
										( event: KeyboardEvent ) =>
											onMenuKey(
												event,
												itemOf( node ).id
											)
									"
								>
									<a-menu-item key="edit">
										{{
											__(
												'Edit',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
									<a-menu-item key="child">
										{{
											__(
												'Add child',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
									<a-menu-item
										key="up"
										:disabled="
											saving ||
											isAt( itemOf( node ), 'first' )
										"
									>
										{{
											__(
												'Move up',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
									<a-menu-item
										key="down"
										:disabled="
											saving ||
											isAt( itemOf( node ), 'last' )
										"
									>
										{{
											__(
												'Move down',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
									<a-menu-item key="move">
										{{
											__(
												'Move to…',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
									<a-menu-item key="merge">
										{{
											__(
												'Merge…',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
									<a-menu-item key="delete" danger>
										{{
											__(
												'Delete…',
												'licensio-knowledge-base'
											)
										}}
									</a-menu-item>
								</a-menu>
							</template>
						</a-dropdown>
					</span>
				</template>
			</a-tree>
		</div>
	</div>
</template>

<style scoped>
.lkb-tree__search {
	margin-bottom: 8px;
}

.lkb-tree__hint {
	margin: 0 0 8px;
	color: var( --lkb-ink-muted );
	font-size: 12px;
}

/*
 * Rows sized like a core list table row: 8px 10px padding, a 1.5 line height
 * and a 14px, weight 600 name, as core's row titles. Child levels are
 * indented a full step and a row shows a background on hover.
 */
.lkb-tree__body :deep( .ant-tree-treenode ) {
	align-items: center;
	padding: 0 0 2px;
	border-radius: 6px;
}

.lkb-tree__body :deep( .ant-tree-treenode:hover ) {
	background: var( --lkb-hover );
}

.lkb-tree__body :deep( .ant-tree-node-content-wrapper ) {
	padding: 8px 10px;
	line-height: 1.5;
}

.lkb-tree__body :deep( .ant-tree-switcher ) {
	align-self: center;
}

.lkb-tree__body :deep( .ant-tree-indent-unit ) {
	width: 24px;
}

.lkb-tree__node {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

/* The category in the form: an accent bar beside it and its name in the accent. */
.lkb-tree__node.is-editing {
	box-shadow: inset 3px 0 0 var( --lkb-accent );
	margin-left: -10px;
	padding-left: 10px;
}

.lkb-tree__node.is-editing .lkb-tree__name {
	color: var( --lkb-accent );
}

.lkb-tree__name {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
	font-size: 14px;
	font-weight: 600;
}

.lkb-tree__count {
	min-width: 28px;
	padding: 0 8px;
	border-radius: 10px;
	background: var( --lkb-sunken );
	color: var( --lkb-ink-muted );
	font-size: 12px;
	text-align: center;
	text-decoration: none;
}

.lkb-tree__count:hover,
.lkb-tree__count:focus {
	text-decoration: underline;
}

.lkb-tree__actions {
	flex: none;
}
</style>
