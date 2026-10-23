<script setup lang="ts">
/**
 * Categories screen.
 *
 * The category tree on the left and the selected category's article order on
 * the right (stacked on narrow screens). The tree hands its actions here,
 * and this component owns the add/edit drawer and the move, merge and delete
 * dialogs, so focus can go back to whatever opened them once they close.
 *
 * `?term={id}` opens that category's edit drawer on load and `?search=`
 * fills the tree filter.
 *
 * There is no `v-html` anywhere in this app.
 *
 * @since 1.0.0
 */

import { nextTick, onMounted, reactive, ref } from 'vue';
import { lkbTheme } from '../styles/theme';
import AppHeader from '../components/AppHeader.vue';
import { __ } from '../components/i18n';
import { settings } from './api';
import { useCategoriesStore } from './store';
import type { CategoryItem } from './types';
import ArticleOrder from './components/ArticleOrder.vue';
import CategoryDrawer from './components/CategoryDrawer.vue';
import CategoryTree from './components/CategoryTree.vue';
import type { TreeAction } from './components/CategoryTree.vue';
import DeleteDialog from './components/DeleteDialog.vue';
import MergeDialog from './components/MergeDialog.vue';
import MoveDialog from './components/MoveDialog.vue';

const store = useCategoriesStore();
const chrome = settings();

const addButton = ref< { $el?: HTMLElement } | null >( null );

const drawer = reactive( {
	open: false,
	category: null as CategoryItem | null,
	parent: 0,
} );

const dialog = reactive( {
	kind: null as 'move' | 'merge' | 'delete' | null,
	category: null as CategoryItem | null,
} );

// The category whose actions button opened the drawer or dialog.
let returnTo: number | null = null;

/**
 * Open the drawer to add a top-level category.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function addCategory(): void {
	returnTo = null;
	drawer.category = null;
	drawer.parent = 0;
	drawer.open = true;
}

/**
 * Open the drawer to edit a category.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem} item The category.
 *
 * @return {void}
 */
function editCategory( item: CategoryItem ): void {
	drawer.category = item;
	drawer.parent = item.parent;
	drawer.open = true;
}

/**
 * Run an action the tree handed over.
 *
 * @since 1.0.0
 *
 * @param {TreeAction}   action The action.
 * @param {CategoryItem} item   The category it is for.
 *
 * @return {void}
 */
function onAction( action: TreeAction, item: CategoryItem ): void {
	returnTo = item.id;

	if ( 'edit' === action ) {
		editCategory( item );
		return;
	}

	if ( 'child' === action ) {
		drawer.category = null;
		drawer.parent = item.id;
		drawer.open = true;
		return;
	}

	dialog.kind = action;
	dialog.category = item;
}

/**
 * Close the open dialog.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function closeDialog(): void {
	dialog.kind = null;
}

/**
 * Put focus back on what opened the drawer or dialog.
 *
 * Its category's actions button when it is still there, otherwise the Add
 * category button.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function restoreFocus(): void {
	nextTick( () => {
		const actions =
			null === returnTo
				? null
				: document.querySelector< HTMLElement >(
						`[data-lkb-actions="${ returnTo }"]`
				  );

		( actions ?? addButton.value?.$el )?.focus();
	} );
}

onMounted( async () => {
	const params = new URLSearchParams( window.location.search );
	const search = params.get( 'search' );

	if ( search ) {
		store.search = search;
	}

	await store.load();

	const term = store.find( Number( params.get( 'term' ) ) );

	if ( term ) {
		returnTo = null;
		store.selectedId = term.id;
		editCategory( term );
	}
} );
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page licensio-kb-categories">
			<AppHeader :settings-url="chrome.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Categories', 'licensio-knowledge-base' ) }}
					</h1>
					<a-button ref="addButton" type="primary" @click="addCategory">
						{{ __( 'Add category', 'licensio-knowledge-base' ) }}
					</a-button>
				</div>

				<a-alert
					v-if="store.error"
					type="error"
					show-icon
					:message="store.error"
					class="lkb-categories__error"
				>
					<template #action>
						<a-button size="small" :loading="store.loading" @click="store.load()">
							{{ __( 'Retry', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</a-alert>

				<div v-if="! store.loaded && store.loading" class="lkb-categories__card">
					<a-skeleton active :paragraph="{ rows: 6 }" />
				</div>

				<div
					v-else-if="store.loaded && 0 === store.items.length"
					class="lkb-categories__card lkb-categories__empty"
				>
					<h2>{{ __( 'No categories yet', 'licensio-knowledge-base' ) }}</h2>
					<p>
						{{
							__(
								'Categories group your articles. Add the first one to get started.',
								'licensio-knowledge-base'
							)
						}}
					</p>
					<a-button type="primary" @click="addCategory">
						{{ __( 'Add category', 'licensio-knowledge-base' ) }}
					</a-button>
				</div>

				<div v-else-if="store.loaded" class="lkb-categories">
					<section
						class="lkb-categories__card"
						:aria-label="__( 'Category tree', 'licensio-knowledge-base' )"
					>
						<CategoryTree @action="onAction" />
					</section>

					<div class="lkb-categories__card">
						<ArticleOrder :category="store.selected" />
					</div>
				</div>
			</div>

			<CategoryDrawer
				:open="drawer.open"
				:category="drawer.category"
				:parent="drawer.parent"
				@close="drawer.open = false"
				@closed="restoreFocus"
			/>

			<MoveDialog
				:open="'move' === dialog.kind"
				:category="dialog.category"
				@close="closeDialog"
				@closed="restoreFocus"
			/>

			<MergeDialog
				:open="'merge' === dialog.kind"
				:category="dialog.category"
				@close="closeDialog"
				@closed="restoreFocus"
			/>

			<DeleteDialog
				:open="'delete' === dialog.kind"
				:category="dialog.category"
				@close="closeDialog"
				@closed="restoreFocus"
			/>
		</div>
	</a-config-provider>
</template>

<style scoped>
.lkb-categories__error {
	margin-bottom: var(--lkb-gap);
}

.lkb-categories {
	display: grid;
	grid-template-columns: minmax( 0, 1fr ) minmax( 0, 1fr );
	gap: var(--lkb-gap);
	align-items: start;
}

.lkb-categories__card {
	padding: var(--lkb-gap);
	border: 1px solid var(--lkb-border-wp);
	border-radius: var(--lkb-radius);
	background: var(--lkb-surface);
	box-shadow: var(--lkb-shadow-sm);
}

.lkb-categories__empty {
	text-align: center;
}

.lkb-categories__empty h2 {
	margin: 0 0 8px;
	font-size: 16px;
}

.lkb-categories__empty p {
	color: var(--lkb-ink-muted);
}

@media ( max-width: 782px ) {
	.lkb-categories {
		grid-template-columns: minmax( 0, 1fr );
	}
}
</style>
