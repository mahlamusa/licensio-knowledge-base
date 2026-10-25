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

import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { __, sprintf } from '../../components/i18n';
import { usePopoverFocus } from '../../components/popover-focus';
import { errorMessage } from '../../components/rest';
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
const menuFor = ref< number | null >( null );

const {
	open: confirmOpen,
	overlayClass: confirmClass,
	setOpen: setConfirmOpen,
} = usePopoverFocus();

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

	// Opens the Popconfirm with focus on Cancel, and lets Escape answer it.
	setConfirmOpen( true );
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
	setConfirmOpen( false );

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
	setConfirmOpen( false );
	store.items = move.snapshot;
	returnFocus( move.returnTo );
}

// Escape closes the Popconfirm through `usePopoverFocus`, which is a cancel too.
watch( confirmOpen, onOpenChange );

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
		.querySelector( '.lkb-tree__body input[aria-label="for screen reader"]' )
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
	const at = items.indexOf( document.activeElement as HTMLElement );
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

		<a-popconfirm
			:open="confirmOpen"
			:title="pending?.title ?? ''"
			:ok-text="__( 'Move', 'licensio-knowledge-base' )"
			:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
			:overlay-class-name="`lkb-move-confirm ${ confirmClass }`"
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
					:active-key="activeKey"
					:draggable="canDrag"
					:allow-drop="allowDrop"
					block-node
					@expand="onExpand"
					@select="onSelect"
					@active-change="( key: string | number | null ) => ( activeKey = key )"
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

							<a-dropdown
								:trigger="[ 'click' ]"
								:open="menuFor === itemOf( node ).id"
								overlay-class-name="lkb-node-menu"
								@open-change="
									( open: boolean ) => onMenuOpen( itemOf( node ).id, open )
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
										@keydown="
											( event: KeyboardEvent ) =>
												onMenuKey( event, itemOf( node ).id )
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
