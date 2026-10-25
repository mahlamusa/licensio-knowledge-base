<script setup lang="ts">
/**
 * The article order pane for the selected category.
 *
 * Articles can be dragged, or moved with the Move up and Move down buttons
 * from the keyboard. Nothing is sent until the user confirms "Save order";
 * Reset puts the loaded order back. While the order is unsaved, choosing
 * another category asks first (see the store's `confirmDiscardOrder()`) and
 * leaving the page brings up the browser's leave warning.
 *
 * @since 1.0.0
 */

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import { __, sprintf } from '../../components/i18n';
import type { CategoryOrderArticle } from '../../components/types/category-order';
import { errorMessage } from '../../components/rest';
import { statusLabel } from '../../components/status';
import { fetchCategoryArticles, saveArticleOrder } from '../api';
import { useCategoriesStore } from '../store';
import type { CategoryItem } from '../types';

const props = defineProps< {
	/** The selected category, or null. */
	category: CategoryItem | null;
} >();

const items = ref< CategoryOrderArticle[] >( [] );
const loadedIds = ref< number[] >( [] );
const total = ref( 0 );
const loading = ref( false );
const saving = ref( false );
const error = ref( '' );
const dragIndex = ref< number | null >( null );

let latest = 0;

const store = useCategoriesStore();

const dirty = computed(
	() => items.value.map( ( item ) => item.id ).join( ',' ) !==
		loadedIds.value.join( ',' )
);

watch( dirty, ( value ) => {
	store.orderDirty = value;
} );

/**
 * Warn before the page is left while the order is unsaved.
 *
 * Browsers ignore the message and show their own wording, but the event has
 * to be cancelled for the warning to appear at all.
 *
 * @since 1.0.0
 *
 * @param {BeforeUnloadEvent} event The unload event.
 *
 * @return {void}
 */
function guardUnload( event: BeforeUnloadEvent ): void {
	if ( ! dirty.value ) {
		return;
	}

	event.preventDefault();
	event.returnValue = '';
}

onMounted( () => window.addEventListener( 'beforeunload', guardUnload ) );

onBeforeUnmount( () => {
	window.removeEventListener( 'beforeunload', guardUnload );
	store.orderDirty = false;
} );

/**
 * Load the selected category's articles.
 *
 * Only the latest request's answer is kept.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once loaded or failed.
 */
async function load(): Promise< void > {
	const ticket = ++latest;

	items.value = [];
	loadedIds.value = [];
	total.value = 0;
	error.value = '';

	if ( ! props.category ) {
		return;
	}

	loading.value = true;

	try {
		const page = await fetchCategoryArticles( props.category.id );

		if ( ticket !== latest ) {
			return;
		}

		items.value = page.items;
		loadedIds.value = page.items.map( ( item ) => item.id );
		total.value = page.total;
	} catch ( caught ) {
		if ( ticket !== latest ) {
			return;
		}

		error.value = errorMessage( caught );
	} finally {
		if ( ticket === latest ) {
			loading.value = false;
		}
	}
}

// The count changes when a delete or a merge moves articles into the
// selected category; the list is stale then, so it is loaded again.
watch( [ () => props.category?.id, () => props.category?.count ], load, {
	immediate: true,
} );

/**
 * Move an article from one position to another.
 *
 * @since 1.0.0
 *
 * @param {number} from Its position now.
 * @param {number} to   Its new position.
 *
 * @return {void}
 */
function move( from: number, to: number ): void {
	if ( to < 0 || to >= items.value.length || from === to ) {
		return;
	}

	const next = [ ...items.value ];
	const [ moved ] = next.splice( from, 1 );

	next.splice( to, 0, moved );
	items.value = next;
}

/**
 * Remember which article is being dragged.
 *
 * @since 1.0.0
 *
 * @param {DragEvent} event The drag event.
 * @param {number}    index The article's position.
 *
 * @return {void}
 */
function onDragStart( event: DragEvent, index: number ): void {
	dragIndex.value = index;

	if ( event.dataTransfer ) {
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData( 'text/plain', String( index ) );
	}
}

/**
 * Drop the dragged article at a position.
 *
 * @since 1.0.0
 *
 * @param {number} index The position dropped on.
 *
 * @return {void}
 */
function onDrop( index: number ): void {
	if ( null !== dragIndex.value ) {
		move( dragIndex.value, index );
	}

	dragIndex.value = null;
}

/**
 * Put the loaded order back.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function reset(): void {
	const byId = new Map( items.value.map( ( item ) => [ item.id, item ] ) );

	items.value = loadedIds.value
		.map( ( id ) => byId.get( id ) )
		.filter( ( item ): item is CategoryOrderArticle => !! item );
}

/**
 * Save the order, after the user confirmed.
 *
 * The answer is applied only if the pane still shows the list that was
 * saved: when another category was selected (or the list reloaded) while the
 * request was out, its answer would otherwise filter the new list.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once saved or failed.
 */
async function save(): Promise< void > {
	if ( ! props.category ) {
		return;
	}

	const ticket = latest;

	saving.value = true;
	error.value = '';

	try {
		const saved = await saveArticleOrder(
			props.category.id,
			items.value.map( ( item ) => item.id )
		);

		message.success( __( 'Article order saved.', 'licensio-knowledge-base' ) );

		if ( ticket !== latest ) {
			return;
		}

		loadedIds.value = saved.ids;
		reset();
	} catch ( caught ) {
		if ( ticket !== latest ) {
			return;
		}

		error.value = errorMessage( caught );
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<section
		class="lkb-order"
		:aria-label="__( 'Article order', 'licensio-knowledge-base' )"
	>
		<h2 class="lkb-order__title">
			{{
				props.category
					? sprintf(
							/* translators: %s: category name. */
							__( 'Articles in “%s”', 'licensio-knowledge-base' ),
							props.category.name
					  )
					: __( 'Article order', 'licensio-knowledge-base' )
			}}
		</h2>

		<p v-if="! props.category" class="lkb-order__hint">
			{{
				__(
					'Select a category to order its articles.',
					'licensio-knowledge-base'
				)
			}}
		</p>

		<a-skeleton v-else-if="loading" active :paragraph="{ rows: 4 }" />

		<template v-else>
			<a-alert
				v-if="error"
				type="error"
				show-icon
				:message="error"
				class="lkb-order__error"
			>
				<template #action>
					<a-button size="small" @click="load">
						{{ __( 'Retry', 'licensio-knowledge-base' ) }}
					</a-button>
				</template>
			</a-alert>

			<p v-if="! error && 0 === items.length" class="lkb-order__hint">
				{{
					__( 'This category has no articles.', 'licensio-knowledge-base' )
				}}
			</p>

			<p v-if="total > items.length" class="lkb-order__hint">
				{{
					sprintf(
						/* translators: 1: number of articles listed, 2: number of articles in the category. */
						__(
							'Only the first %1$d of %2$d articles are listed.',
							'licensio-knowledge-base'
						),
						items.length,
						total
					)
				}}
			</p>

			<ol v-if="items.length" class="lkb-order__list">
				<li
					v-for="( article, index ) in items"
					:key="article.id"
					class="lkb-order__item"
					:class="{ 'is-dragging': dragIndex === index }"
					draggable="true"
					@dragstart="onDragStart( $event, index )"
					@dragover.prevent
					@drop.prevent="onDrop( index )"
					@dragend="dragIndex = null"
				>
					<span class="lkb-order__handle" aria-hidden="true">⋮⋮</span>
					<span class="lkb-order__name">
						<a v-if="article.edit_url" :href="article.edit_url">
							{{
								article.title ||
								__( '(no title)', 'licensio-knowledge-base' )
							}}
						</a>
						<span v-else>
							{{
								article.title ||
								__( '(no title)', 'licensio-knowledge-base' )
							}}
						</span>
						<a-tag v-if="'publish' !== article.status" class="lkb-order__status">
							{{ statusLabel( article.status ) }}
						</a-tag>
					</span>
					<span class="lkb-order__buttons">
						<a-button
							size="small"
							:disabled="0 === index"
							:aria-label="
								sprintf(
									/* translators: %s: article title. */
									__( 'Move “%s” up', 'licensio-knowledge-base' ),
									article.title
								)
							"
							@click="move( index, index - 1 )"
						>
							↑
						</a-button>
						<a-button
							size="small"
							:disabled="index === items.length - 1"
							:aria-label="
								sprintf(
									/* translators: %s: article title. */
									__( 'Move “%s” down', 'licensio-knowledge-base' ),
									article.title
								)
							"
							@click="move( index, index + 1 )"
						>
							↓
						</a-button>
					</span>
				</li>
			</ol>

			<div v-if="dirty" class="lkb-order__actions">
				<a-button :disabled="saving" @click="reset">
					{{ __( 'Reset', 'licensio-knowledge-base' ) }}
				</a-button>
				<ConfirmButton
					type="primary"
					:title="__( 'Save this article order?', 'licensio-knowledge-base' )"
					:ok-text="__( 'Save order', 'licensio-knowledge-base' )"
					:loading="saving"
					@confirm="save"
				>
					{{ __( 'Save order', 'licensio-knowledge-base' ) }}
				</ConfirmButton>
			</div>
		</template>
	</section>
</template>

<style scoped>
.lkb-order__title {
	margin: 0 0 12px;
	font-size: 15px;
}

.lkb-order__hint {
	color: var(--lkb-ink-muted);
}

.lkb-order__error {
	margin-bottom: 12px;
}

.lkb-order__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.lkb-order__item {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 6px;
	padding: 6px 8px;
	border: 1px solid var(--lkb-border-wp);
	border-radius: 6px;
	background: var(--lkb-surface);
	cursor: grab;
}

.lkb-order__item.is-dragging {
	opacity: 0.5;
}

.lkb-order__handle {
	color: var(--lkb-ink-faint);
}

.lkb-order__name {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
}

.lkb-order__status {
	margin-left: 6px;
}

.lkb-order__buttons {
	display: flex;
	gap: 4px;
}

.lkb-order__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}
</style>
