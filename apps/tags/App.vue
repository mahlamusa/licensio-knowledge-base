<script setup lang="ts">
/**
 * Tags app.
 *
 * Laid out as core's tag screen: the Add New Tag form in a left column and
 * the list in a right column, stacked with the form first on narrow screens.
 * The list has search, an Unused filter, sort and paging; Edit on a row loads
 * the tag into the left form as Edit Tag, and Quick edit edits name, slug and
 * description inline. The bulk bar deletes, merges or converts the selected
 * tags. Adding and saving a tag send at once; delete, merge and convert ask
 * in a confirmation dialog first, and Cancel sends nothing.
 *
 * Edit, and Quick edit of the tag in the form, ask first when the form has
 * unsaved changes (see the form's `leave()`). When the tag in the form is
 * deleted, merged or converted, the form goes back to Add; a search or page
 * change that only hides it leaves the form alone.
 *
 * Bulk actions work on the selected rows of the current page, at most 100,
 * so each is a single request.
 *
 * Back and forward restore the filters, sort and page the store keeps in the
 * URL.
 *
 * @since 1.0.0
 */

import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { SearchOutlined } from '@ant-design/icons-vue';
import { lkbTheme } from '../styles/theme';
import AppHeader from '../components/AppHeader.vue';
import ConfirmButton from '../components/ConfirmButton.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { useConfirm } from '../components/confirm';
import { __, _n, sprintf } from '../components/i18n';
import { settings } from './api';
import { useTagsStore } from './store';
import ConvertDialog from './components/ConvertDialog.vue';
import MergeDialog from './components/MergeDialog.vue';
import TagForm from './components/TagForm.vue';
import TagTable from './components/TagTable.vue';
import type { TagItem } from './types';

const store = useTagsStore();
const chrome = settings();
const confirm = useConfirm();

const form = ref< InstanceType< typeof TagForm > | null >( null );

const searchText = ref( store.search );
const editing = ref< TagItem | null >( null );
const mergeOpen = ref( false );
const convertOpen = ref( false );

const deleteTitle = computed( () =>
	sprintf(
		/* translators: %d: how many tags will be deleted. */
		_n(
			'Delete %d tag? Its articles lose it.',
			'Delete %d tags? Their articles lose them.',
			store.selected.length,
			'licensio-knowledge-base'
		),
		store.selected.length
	)
);

const selectedLabel = computed( () =>
	sprintf(
		/* translators: %d: how many tags are selected. */
		_n(
			'%d selected',
			'%d selected',
			store.selected.length,
			'licensio-knowledge-base'
		),
		store.selected.length
	)
);

/**
 * Load a tag into the left form as Edit Tag.
 *
 * The page scrolls to the form, which on a phone sits above the list, and
 * focus moves to its Name field.
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag The tag.
 *
 * @return {void}
 */
function editTag( tag: TagItem ): void {
	editing.value = tag;

	nextTick( () => {
		const name = document.getElementById( 'lkb-tag-name' );

		name
			?.closest( '.lkb-tag-form' )
			?.scrollIntoView( { block: 'nearest' } );
		name?.focus();
	} );
}

/**
 * Return the left form to Add New Tag after an update or a cancel.
 *
 * Focus goes back to the Edit button of the tag's row when it is still
 * listed, otherwise to the form's Name field.
 *
 * @since 1.0.0
 *
 * @param {number} id The tag that was edited.
 *
 * @return {void}
 */
function closeEdit( id: number ): void {
	editing.value = null;

	nextTick( () => {
		const button = document.querySelector< HTMLElement >(
			`.lkb-tablecard [data-row-key="${ id }"] [data-lkb-edit]`
		);

		( button ?? document.getElementById( 'lkb-tag-name' ) )?.focus();
	} );
}

/**
 * Whether Edit or Quick edit may open a tag.
 *
 * Edit replaces what the form holds, and Quick edit of the tag in the form
 * returns the form to Add, so both ask first when the form has unsaved
 * changes. Quick edit of another tag leaves the form alone.
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag   The tag to open.
 * @param {boolean} quick Whether it opens for Quick edit.
 *
 * @return {Promise<boolean>} Whether it may open.
 */
async function canOpen( tag: TagItem, quick: boolean ): Promise< boolean > {
	if ( quick && editing.value?.id !== tag.id ) {
		return true;
	}

	return ( await form.value?.leave() ) ?? true;
}

/**
 * Keep one tag open in one place: a Quick edit of the tag in the left form
 * returns the form to Add New Tag.
 *
 * @since 1.0.0
 *
 * @param {number} id The tag opened for Quick edit.
 *
 * @return {void}
 */
function onQuickEdit( id: number ): void {
	if ( editing.value?.id === id ) {
		editing.value = null;
	}
}

/**
 * Delete the selected tags. Called only after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once deleted, or once the error is shown.
 */
async function deleteSelected(): Promise< void > {
	try {
		const result = await store.removeSelected();

		message.success(
			sprintf(
				/* translators: %d: how many tags were deleted. */
				_n(
					'%d tag deleted.',
					'%d tags deleted.',
					result.deleted.length,
					'licensio-knowledge-base'
				),
				result.deleted.length
			)
		);

		result.failed.forEach( ( failure ) => {
			message.error( failure.message );
		} );
	} catch {
		// The store shows the error.
	}
}

// The tag in the form was deleted, merged or converted: back to Add.
watch(
	() => store.removed,
	( removed ) => {
		if ( editing.value && removed.includes( editing.value.id ) ) {
			editing.value = null;
		}
	}
);

// The search box follows the store when back or forward changes the search.
watch(
	() => store.search,
	( search ) => {
		searchText.value = search;
	}
);

onMounted( () => {
	// Back and forward restore the view from the URL.
	window.addEventListener( 'popstate', store.restore );
	store.load();
} );

onUnmounted( () => {
	window.removeEventListener( 'popstate', store.restore );
} );
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page licensio-kb-tags">
			<AppHeader :settings-url="chrome.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Tags', 'licensio-knowledge-base' ) }}
					</h1>
				</div>

				<a-alert
					v-if="store.error"
					type="error"
					show-icon
					:message="store.error"
					class="lkb-tags__notice"
				>
					<template #action>
						<a-button size="small" @click="store.load()">
							{{ __( 'Retry', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</a-alert>

				<div class="lkb-terms">
					<TagForm ref="form" :tag="editing" @done="closeEdit" />

					<div class="lkb-tags__list">
						<h2 class="screen-reader-text">
							{{ __( 'Tags list', 'licensio-knowledge-base' ) }}
						</h2>

						<a-alert
							v-if="store.include.length"
							type="info"
							show-icon
							:message="
								__(
									'Showing one tag.',
									'licensio-knowledge-base'
								)
							"
							class="lkb-tags__notice"
						>
							<template #action>
								<a-button
									size="small"
									@click="store.filter( { include: [] } )"
								>
									{{
										__(
											'Show all tags',
											'licensio-knowledge-base'
										)
									}}
								</a-button>
							</template>
						</a-alert>

						<div class="lkb-toolbar">
							<a-input-search
								v-model:value="searchText"
								class="lkb-tags__search"
								allow-clear
								:placeholder="
									__(
										'Search tags',
										'licensio-knowledge-base'
									)
								"
								:aria-label="
									__(
										'Search tags',
										'licensio-knowledge-base'
									)
								"
								@search="
									( value: string ) =>
										store.filter( { search: value.trim() } )
								"
							>
								<template #enterButton>
									<a-button
										:aria-label="
											__(
												'Search',
												'licensio-knowledge-base'
											)
										"
									>
										<template #icon
											><SearchOutlined
										/></template>
									</a-button>
								</template>
							</a-input-search>

							<label
								class="lkb-tags__toggle"
								:title="
									__(
										'Tags with no articles in any status, drafts included',
										'licensio-knowledge-base'
									)
								"
							>
								<a-switch
									:checked="store.unused"
									@change="
										( checked: unknown ) =>
											store.filter( {
												unused: Boolean( checked ),
											} )
									"
								/>
								{{
									__(
										'Unused only',
										'licensio-knowledge-base'
									)
								}}
							</label>
						</div>

						<div
							class="lkb-bulk"
							:class="{ 'is-armed': store.selected.length > 0 }"
						>
							<template v-if="store.selected.length">
								<span class="lkb-bulk__count">{{
									selectedLabel
								}}</span>

								<div class="lkb-bulk__actions">
									<ConfirmButton
										size="small"
										danger
										:title="deleteTitle"
										:ok-text="
											__(
												'Delete',
												'licensio-knowledge-base'
											)
										"
										:loading="store.saving"
										@confirm="deleteSelected"
									>
										{{
											__(
												'Delete',
												'licensio-knowledge-base'
											)
										}}
									</ConfirmButton>
									<a-button
										size="small"
										:disabled="store.saving"
										@click="mergeOpen = true"
									>
										{{
											__(
												'Merge into…',
												'licensio-knowledge-base'
											)
										}}
									</a-button>
									<a-button
										size="small"
										:disabled="store.saving"
										@click="convertOpen = true"
									>
										{{
											__(
												'Convert to category',
												'licensio-knowledge-base'
											)
										}}
									</a-button>
								</div>
							</template>

							<span v-else class="lkb-bulk__hint">
								{{
									__(
										'Select tags below to delete, merge or convert them',
										'licensio-knowledge-base'
									)
								}}
							</span>
						</div>

						<TagTable
							:before-open="canOpen"
							@edit="editTag"
							@quick-edit="onQuickEdit"
						/>
					</div>
				</div>

				<MergeDialog v-model:open="mergeOpen" />
				<ConvertDialog v-model:open="convertOpen" />
				<ConfirmDialog
					v-bind="confirm.dialog"
					@ok="confirm.answer( true )"
					@cancel="confirm.answer( false )"
				/>
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.lkb-tags__notice {
	margin-bottom: var( --lkb-gap );
}

.lkb-tags__list {
	min-width: 0;
}

.lkb-tags__search {
	max-width: 320px;
}

.lkb-tags__toggle {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	color: var( --lkb-ink-wp-secondary );
	cursor: pointer;
}
</style>
