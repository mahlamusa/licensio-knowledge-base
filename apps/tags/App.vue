<script setup lang="ts">
/**
 * Tags app.
 *
 * Lists the knowledge base tags with search, an Unused filter, sort and
 * paging; adds a tag; edits name, slug and description inline; and deletes,
 * merges or converts the selected tags from the bulk bar. Every change asks
 * for confirmation in a Popconfirm before anything is sent, and Cancel sends
 * nothing.
 *
 * Bulk actions work on the selected rows of the current page, at most 100,
 * so each is a single request.
 *
 * Back and forward restore the filters, sort and page the store keeps in the
 * URL.
 *
 * @since 1.0.0
 */

import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { SearchOutlined } from '@ant-design/icons-vue';
import { lkbTheme } from '../styles/theme';
import AppHeader from '../components/AppHeader.vue';
import ConfirmButton from '../components/ConfirmButton.vue';
import { __, _n, sprintf } from '../components/i18n';
import { settings } from './api';
import { useTagsStore } from './store';
import ConvertDialog from './components/ConvertDialog.vue';
import MergeDialog from './components/MergeDialog.vue';
import TagTable from './components/TagTable.vue';

const store = useTagsStore();
const chrome = settings();

const searchText = ref( store.search );
const adding = ref( false );
const addButton = ref< { $el?: HTMLElement } | null >( null );
const newTag = reactive( { name: '', slug: '', description: '' } );
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
		_n( '%d selected', '%d selected', store.selected.length, 'licensio-knowledge-base' ),
		store.selected.length
	)
);

/**
 * Open or close the Add tag form, clearing it.
 *
 * Focus moves to the Name field when it opens, and back to Add tag when it
 * closes. Escape in the form closes it like Cancel.
 *
 * @since 1.0.0
 *
 * @param {boolean} open Whether the form should be open.
 *
 * @return {void}
 */
function toggleAdd( open: boolean ): void {
	adding.value = open;
	newTag.name = '';
	newTag.slug = '';
	newTag.description = '';

	nextTick( () => {
		if ( open ) {
			document.querySelector< HTMLElement >( '.lkb-tags__add input' )?.focus();
		} else {
			addButton.value?.$el?.focus();
		}
	} );
}

/**
 * Add the tag. Called only after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once added, or once the error is shown.
 */
async function addTag(): Promise< void > {
	try {
		const created = await store.create( {
			name: newTag.name.trim(),
			...( '' !== newTag.slug.trim()
				? { slug: newTag.slug.trim() }
				: {} ),
			...( '' !== newTag.description
				? { description: newTag.description }
				: {} ),
		} );

		toggleAdd( false );
		message.success(
			sprintf(
				/* translators: %s: the new tag's name. */
				__( 'Tag “%s” added.', 'licensio-knowledge-base' ),
				created.name
			)
		);
	} catch {
		// The store shows the error; the form keeps the input.
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
					<a-button
						v-if="! adding"
						ref="addButton"
						type="primary"
						@click="toggleAdd( true )"
					>
						{{ __( 'Add tag', 'licensio-knowledge-base' ) }}
					</a-button>
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

				<div
					v-if="adding"
					class="lkb-tags__add"
					@keydown.esc="toggleAdd( false )"
				>
					<a-input
						v-model:value="newTag.name"
						:placeholder="__( 'Name', 'licensio-knowledge-base' )"
						:aria-label="__( 'Name', 'licensio-knowledge-base' )"
					/>
					<a-input
						v-model:value="newTag.slug"
						:placeholder="__( 'Slug (optional)', 'licensio-knowledge-base' )"
						:aria-label="__( 'Slug', 'licensio-knowledge-base' )"
					/>
					<a-textarea
						v-model:value="newTag.description"
						:auto-size="{ minRows: 1, maxRows: 4 }"
						:placeholder="
							__( 'Description (optional)', 'licensio-knowledge-base' )
						"
						:aria-label="__( 'Description', 'licensio-knowledge-base' )"
					/>
					<div class="lkb-tags__add-actions">
						<ConfirmButton
							type="primary"
							:title="__( 'Add this tag?', 'licensio-knowledge-base' )"
							:ok-text="__( 'Add tag', 'licensio-knowledge-base' )"
							:disabled="'' === newTag.name.trim()"
							:loading="store.saving"
							@confirm="addTag"
						>
							{{ __( 'Add tag', 'licensio-knowledge-base' ) }}
						</ConfirmButton>
						<a-button @click="toggleAdd( false )">
							{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
						</a-button>
					</div>
				</div>

				<a-alert
					v-if="store.include.length"
					type="info"
					show-icon
					:message="__( 'Showing one tag.', 'licensio-knowledge-base' )"
					class="lkb-tags__notice"
				>
					<template #action>
						<a-button size="small" @click="store.filter( { include: [] } )">
							{{ __( 'Show all tags', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</a-alert>

				<div class="lkb-toolbar">
					<a-input-search
						v-model:value="searchText"
						class="lkb-tags__search"
						allow-clear
						:placeholder="__( 'Search tags', 'licensio-knowledge-base' )"
						:aria-label="__( 'Search tags', 'licensio-knowledge-base' )"
						@search="( value: string ) => store.filter( { search: value.trim() } )"
					>
						<template #enterButton>
							<a-button :aria-label="__( 'Search', 'licensio-knowledge-base' )">
								<template #icon><SearchOutlined /></template>
							</a-button>
						</template>
					</a-input-search>

					<label class="lkb-tags__toggle">
						<a-switch
							:checked="store.unused"
							@change="( checked: unknown ) => store.filter( { unused: Boolean( checked ) } )"
						/>
						{{ __( 'Unused only', 'licensio-knowledge-base' ) }}
					</label>
				</div>

				<div class="lkb-bulk" :class="{ 'is-armed': store.selected.length > 0 }">
					<template v-if="store.selected.length">
						<span class="lkb-bulk__count">{{ selectedLabel }}</span>

						<div class="lkb-bulk__actions">
							<ConfirmButton
								size="small"
								danger
								:title="deleteTitle"
								:ok-text="__( 'Delete', 'licensio-knowledge-base' )"
								:loading="store.saving"
								@confirm="deleteSelected"
							>
								{{ __( 'Delete', 'licensio-knowledge-base' ) }}
							</ConfirmButton>
							<a-button
								size="small"
								:disabled="store.saving"
								@click="mergeOpen = true"
							>
								{{ __( 'Merge into…', 'licensio-knowledge-base' ) }}
							</a-button>
							<a-button
								size="small"
								:disabled="store.saving"
								@click="convertOpen = true"
							>
								{{ __( 'Convert to category', 'licensio-knowledge-base' ) }}
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

				<TagTable />

				<MergeDialog v-model:open="mergeOpen" />
				<ConvertDialog v-model:open="convertOpen" />
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.lkb-tags__notice {
	margin-bottom: var(--lkb-gap);
}

.lkb-tags__add {
	display: grid;
	grid-template-columns: minmax( 0, 1fr ) minmax( 0, 1fr ) minmax( 0, 2fr ) auto;
	gap: 8px;
	align-items: start;
	margin-bottom: var(--lkb-gap);
	padding: var(--lkb-gap);
	border: 1px solid var(--lkb-border-wp);
	border-radius: var(--lkb-radius);
	background: var(--lkb-surface);
}

.lkb-tags__add-actions {
	display: flex;
	gap: 8px;
}

.lkb-tags__search {
	max-width: 320px;
}

.lkb-tags__toggle {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	color: var(--lkb-ink-wp-secondary);
	cursor: pointer;
}

@media ( max-width: 782px ) {
	.lkb-tags__add {
		grid-template-columns: 1fr;
	}
}
</style>
