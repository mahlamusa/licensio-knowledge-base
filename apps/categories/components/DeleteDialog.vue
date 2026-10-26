<script setup lang="ts">
/**
 * The delete dialog: where the articles and child categories go.
 *
 * "Move to parent category" is offered only for a category that has a
 * parent, because the server refuses it at the top level. The child choice
 * is shown only when there are children. Delete is a danger
 * `ConfirmButton`; nothing is sent before the user confirms.
 *
 * @since 1.0.0
 */

import { computed, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import TermSelect from '../../components/TermSelect.vue';
import { useEscapeClose } from '../../components/popover-focus';
import { __, sprintf } from '../../components/i18n';
import type { TermValue } from '../../components/types/terms';
import { errorMessage } from '../../components/rest';
import { childIds, selfAndDescendantIds, useCategoriesStore } from '../store';
import type {
	ArticleReassignment,
	CategoryItem,
	ChildReassignment,
} from '../types';

const props = defineProps< {
	/** Whether the dialog is open. */
	open: boolean;
	/** The category to delete. */
	category: CategoryItem | null;
} >();

const emit = defineEmits< {
	( event: 'close' ): void;
	( event: 'closed' ): void;
} >();

// Escape on an open select closes only the select, not the dialog.
useEscapeClose(
	() => props.open,
	'.lkb-delete-dialog',
	() => emit( 'close' )
);

const store = useCategoriesStore();

const articleChoice = ref< 'parent' | 'other' | 'none' >( 'none' );
const articleTarget = ref< number | null >( null );
const childChoice = ref< 'parent' | 'other' >( 'parent' );
const childTarget = ref< number | null >( null );
const saving = ref( false );
const error = ref( '' );

const hasParent = computed( () => ( props.category?.parent ?? 0 ) > 0 );

const hasChildren = computed(
	() =>
		!! props.category &&
		childIds( store.items, props.category.id ).length > 0
);

const childExclude = computed( () =>
	props.category ? selfAndDescendantIds( store.items, props.category.id ) : []
);

const incomplete = computed(
	() =>
		( 'other' === articleChoice.value && ! articleTarget.value ) ||
		( hasChildren.value &&
			'other' === childChoice.value &&
			! childTarget.value )
);

const confirmTitle = computed( () =>
	sprintf(
		/* translators: %s: category name. */
		__( 'Delete “%s”?', 'licensio-knowledge-base' ),
		props.category?.name ?? ''
	)
);

watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			articleChoice.value = hasParent.value ? 'parent' : 'none';
			articleTarget.value = null;
			childChoice.value = 'parent';
			childTarget.value = null;
			error.value = '';
		}
	},
	{ immediate: true }
);

/**
 * A single picked category ID from a picker's value.
 *
 * @since 1.0.0
 *
 * @param {TermValue|TermValue[]|null} value The picker's value.
 *
 * @return {number|null} The ID, or null.
 */
function idOf( value: TermValue | TermValue[] | null ): number | null {
	return typeof value === 'number' ? value : null;
}

/**
 * Delete the category, after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once deleted or failed.
 */
async function remove(): Promise< void > {
	if ( ! props.category || incomplete.value ) {
		return;
	}

	const articles: ArticleReassignment =
		'other' === articleChoice.value
			? ( articleTarget.value as number )
			: articleChoice.value;
	const children: ChildReassignment =
		hasChildren.value && 'other' === childChoice.value
			? ( childTarget.value as number )
			: 'parent';

	saving.value = true;
	error.value = '';

	try {
		const result = await store.remove(
			props.category.id,
			articles,
			children
		);

		message.success(
			sprintf(
				/* translators: 1: category name, 2: number of articles moved, 3: number of child categories moved. */
				__(
					'Deleted “%1$s”. Articles moved: %2$d. Child categories moved: %3$d.',
					'licensio-knowledge-base'
				),
				props.category.name,
				result.moved,
				result.reparented.length
			)
		);
		emit( 'close' );
	} catch ( caught ) {
		error.value = errorMessage( caught );
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<a-modal
		:open="props.open"
		:title="
			sprintf(
				/* translators: %s: category name. */
				__( 'Delete “%s”', 'licensio-knowledge-base' ),
				props.category?.name ?? ''
			)
		"
		wrap-class-name="lkb-delete-dialog"
		:keyboard="false"
		@cancel="emit( 'close' )"
		:after-close="() => emit( 'closed' )"
	>
		<a-alert
			v-if="error"
			type="error"
			show-icon
			:message="error"
			class="lkb-dialog__error"
		/>

		<fieldset class="lkb-dialog__group">
			<legend>
				{{
					__(
						'What should happen to its articles?',
						'licensio-knowledge-base'
					)
				}}
			</legend>
			<a-radio-group
				v-model:value="articleChoice"
				class="lkb-dialog__radios"
			>
				<a-radio v-if="hasParent" value="parent">
					{{
						__(
							'Move to parent category',
							'licensio-knowledge-base'
						)
					}}
				</a-radio>
				<a-radio value="other">
					{{
						__(
							'Move to another category',
							'licensio-knowledge-base'
						)
					}}
				</a-radio>
				<a-radio value="none">
					{{
						__(
							'Leave them without this category',
							'licensio-knowledge-base'
						)
					}}
				</a-radio>
			</a-radio-group>
			<TermSelect
				v-if="'other' === articleChoice"
				:model-value="articleTarget"
				taxonomy="licensio_category"
				:exclude="props.category ? [ props.category.id ] : []"
				:aria-label="
					__(
						'Category to move the articles to',
						'licensio-knowledge-base'
					)
				"
				class="lkb-dialog__picker"
				@update:model-value="
					( value ) => ( articleTarget = idOf( value ) )
				"
			/>
		</fieldset>

		<fieldset v-if="hasChildren" class="lkb-dialog__group">
			<legend>
				{{
					__(
						'What should happen to its child categories?',
						'licensio-knowledge-base'
					)
				}}
			</legend>
			<a-radio-group
				v-model:value="childChoice"
				class="lkb-dialog__radios"
			>
				<a-radio value="parent">
					{{
						__(
							'Move to parent (or top level)',
							'licensio-knowledge-base'
						)
					}}
				</a-radio>
				<a-radio value="other">
					{{
						__(
							'Move under another category',
							'licensio-knowledge-base'
						)
					}}
				</a-radio>
			</a-radio-group>
			<TermSelect
				v-if="'other' === childChoice"
				:model-value="childTarget"
				taxonomy="licensio_category"
				:exclude="childExclude"
				:aria-label="
					__(
						'Category to move the child categories under',
						'licensio-knowledge-base'
					)
				"
				class="lkb-dialog__picker"
				@update:model-value="
					( value ) => ( childTarget = idOf( value ) )
				"
			/>
		</fieldset>

		<template #footer>
			<div class="lkb-dialog__footer">
				<a-button @click="emit( 'close' )">
					{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
				</a-button>
				<ConfirmButton
					danger
					type="primary"
					:title="confirmTitle"
					:ok-text="__( 'Delete', 'licensio-knowledge-base' )"
					:disabled="incomplete"
					:loading="saving"
					@confirm="remove"
				>
					{{ __( 'Delete', 'licensio-knowledge-base' ) }}
				</ConfirmButton>
			</div>
		</template>
	</a-modal>
</template>

<style scoped>
.lkb-dialog__error {
	margin-bottom: var( --lkb-gap );
}

.lkb-dialog__group {
	margin: 0 0 var( --lkb-gap );
	padding: 0;
	border: 0;
}

.lkb-dialog__group legend {
	margin-bottom: 6px;
	font-weight: 600;
}

.lkb-dialog__radios {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.lkb-dialog__picker {
	width: 100%;
	margin-top: 8px;
}

.lkb-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
