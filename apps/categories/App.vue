<script setup lang="ts">
/**
 * Categories screen.
 *
 * Laid out as core's category screen: the Add New Category form in a left
 * column, and the category tree with the selected category's article order
 * below it in a right column (stacked, form first, on narrow screens). The
 * tree hands its actions here: Edit loads the category into the form as Edit
 * Category and Add child presets its parent, scrolling to the form and
 * focusing its Name field; this component also owns the move, merge and
 * delete dialogs, so focus can go back to whatever opened them once they
 * close.
 *
 * Edit and Add child ask first when the form has unsaved changes (see the
 * form's `leave()`), in the page's confirmation dialog. When the category in
 * the form is deleted or merged away, the form goes back to Add. The tree
 * marks the category the form holds.
 *
 * `?term={id}` loads that category into the form on load and `?search=`
 * fills the tree filter.
 *
 * There is no `v-html` anywhere in this app.
 *
 * @since 1.0.0
 */

import { nextTick, onMounted, reactive, ref, watch } from 'vue';
import { lkbTheme } from '../styles/theme';
import AppHeader from '../components/AppHeader.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { useConfirm } from '../components/confirm';
import { __ } from '../components/i18n';
import { settings } from './api';
import { useCategoriesStore } from './store';
import type { CategoryItem } from './types';
import ArticleOrder from './components/ArticleOrder.vue';
import CategoryForm from './components/CategoryForm.vue';
import CategoryTree from './components/CategoryTree.vue';
import type { TreeAction } from './components/CategoryTree.vue';
import DeleteDialog from './components/DeleteDialog.vue';
import MergeDialog from './components/MergeDialog.vue';
import MoveDialog from './components/MoveDialog.vue';

const store = useCategoriesStore();
const chrome = settings();
const confirm = useConfirm();

const form = ref< InstanceType< typeof CategoryForm > | null >( null );

// What the left form holds. A new key mounts a fresh form, filled once.
const editor = reactive( {
	key: 0,
	category: null as CategoryItem | null,
	parent: 0,
} );

const dialog = reactive( {
	kind: null as 'move' | 'merge' | 'delete' | null,
	category: null as CategoryItem | null,
} );

// The category whose actions button opened the form or dialog.
let returnTo: number | null = null;

/**
 * Load the left form, then scroll to it and focus its Name field.
 *
 * @since 1.0.0
 *
 * @param {CategoryItem|null} category The category to edit, or null to add one.
 * @param {number}            parent   The parent a new category starts under.
 *
 * @return {void}
 */
function loadForm( category: CategoryItem | null, parent: number ): void {
	editor.category = category;
	editor.parent = parent;
	editor.key++;

	nextTick( () => {
		const name = document.getElementById( 'lkb-category-name' );

		name
			?.closest( '.lkb-category-form' )
			?.scrollIntoView( { block: 'nearest' } );
		name?.focus();
	} );
}

/**
 * Return the form to Add New Category after an add, an update or a cancel.
 *
 * After an update or a cancel, focus goes back to the actions button of the
 * category that was edited when it is still there; otherwise, and after an
 * add, it stays in the form, on its Name field, ready for the next category.
 *
 * @since 1.0.0
 *
 * @param {number|null} id The category that was edited, or null after an add.
 *
 * @return {void}
 */
function onFormDone( id: number | null ): void {
	returnTo = id;
	editor.category = null;
	editor.parent = 0;
	editor.key++;
	restoreFocus();
}

/**
 * Run an action the tree handed over.
 *
 * Merge and delete ask first when the article order has unsaved changes;
 * Edit and Add child when the form has. When the user keeps the changes,
 * focus goes back to the category's actions button.
 *
 * @since 1.0.0
 *
 * @param {TreeAction}   action The action.
 * @param {CategoryItem} item   The category it is for.
 *
 * @return {Promise<void>} Resolves once the action has started or was dropped.
 */
async function onAction(
	action: TreeAction,
	item: CategoryItem
): Promise< void > {
	// A merge or delete can move articles and reload the order pane.
	if (
		( 'merge' === action || 'delete' === action ) &&
		! ( await store.confirmDiscardOrder() )
	) {
		returnTo = item.id;
		restoreFocus();
		return;
	}

	if (
		( 'edit' === action || 'child' === action ) &&
		! ( await ( form.value?.leave() ?? true ) )
	) {
		returnTo = item.id;
		restoreFocus();
		return;
	}

	returnTo = item.id;

	if ( 'edit' === action ) {
		loadForm( item, item.parent );
		return;
	}

	if ( 'child' === action ) {
		loadForm( null, item.id );
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
 * Put focus back on what opened the form or dialog.
 *
 * Its category's actions button when it is still there, otherwise the form's
 * Name field.
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

		( actions ?? document.getElementById( 'lkb-category-name' ) )?.focus();
	} );
}

// The category in the form was deleted or merged away: back to Add.
watch(
	() => editor.category && ! store.find( editor.category.id ),
	( gone ) => {
		if ( gone ) {
			editor.category = null;
			editor.parent = 0;
			editor.key++;
		}
	}
);

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
		loadForm( term, term.parent );
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
				</div>

				<a-alert
					v-if="store.error"
					type="error"
					show-icon
					:message="store.error"
					class="lkb-categories__error"
				>
					<template #action>
						<a-button
							size="small"
							:loading="store.loading"
							@click="store.load()"
						>
							{{ __( 'Retry', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</a-alert>

				<div class="lkb-terms">
					<CategoryForm
						:key="editor.key"
						ref="form"
						:category="editor.category"
						:parent="editor.parent"
						@done="onFormDone"
					/>

					<div class="lkb-terms__col lkb-categories">
						<div
							v-if="! store.loaded && store.loading"
							class="lkb-terms__card"
						>
							<a-skeleton active :paragraph="{ rows: 6 }" />
						</div>

						<div
							v-else-if="store.loaded && 0 === store.items.length"
							class="lkb-terms__card lkb-categories__empty"
						>
							<h2>
								{{
									__(
										'No categories yet',
										'licensio-knowledge-base'
									)
								}}
							</h2>
							<p>
								{{
									__(
										'Categories group your articles. Add the first one with the form.',
										'licensio-knowledge-base'
									)
								}}
							</p>
						</div>

						<template v-else-if="store.loaded">
							<section
								class="lkb-terms__card"
								aria-labelledby="lkb-category-tree-title"
							>
								<h2
									id="lkb-category-tree-title"
									class="screen-reader-text"
								>
									{{
										__(
											'Category tree',
											'licensio-knowledge-base'
										)
									}}
								</h2>
								<CategoryTree
									:editing-id="editor.category?.id ?? null"
									@action="onAction"
								/>
							</section>

							<div class="lkb-terms__card">
								<ArticleOrder :category="store.selected" />
							</div>
						</template>
					</div>
				</div>
			</div>

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

			<ConfirmDialog
				v-bind="confirm.dialog"
				@ok="confirm.answer( true )"
				@cancel="confirm.answer( false )"
			/>
		</div>
	</a-config-provider>
</template>

<style scoped>
.lkb-categories__error {
	margin-bottom: var( --lkb-gap );
}

.lkb-categories__empty {
	text-align: center;
}

.lkb-categories__empty h2 {
	margin: 0 0 8px;
	font-size: 16px;
}

.lkb-categories__empty p {
	color: var( --lkb-ink-muted );
}
</style>
