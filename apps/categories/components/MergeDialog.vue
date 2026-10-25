<script setup lang="ts">
/**
 * The merge dialog: several categories into one.
 *
 * The sources start with the category the dialog was opened from; more can
 * be added, up to 50. The target cannot be a source or below one, so those
 * are excluded from its picker. Merge is a danger `ConfirmButton`; nothing is
 * sent before the user confirms. It opens with focus on its first field.
 *
 * @since 1.0.0
 */

import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import TermSelect from '../../components/TermSelect.vue';
import { __, _n, sprintf } from '../../components/i18n';
import type { TermValue } from '../../components/types/terms';
import { errorMessage } from '../../components/rest';
import { selfAndDescendantIds, useCategoriesStore } from '../store';
import type { CategoryItem } from '../types';

const MAX_SOURCES = 50;

const props = defineProps< {
	/** Whether the dialog is open. */
	open: boolean;
	/** The category the dialog was opened from. */
	category: CategoryItem | null;
} >();

const emit = defineEmits< {
	( event: 'close' ): void;
	( event: 'closed' ): void;
} >();

const store = useCategoriesStore();

const sources = ref< number[] >( [] );
const target = ref< number | null >( null );
const saving = ref( false );
const error = ref( '' );

let focusTimer = 0;

const tooMany = computed( () => sources.value.length > MAX_SOURCES );

const targetExclude = computed( () => [
	...new Set(
		sources.value.flatMap( ( id ) =>
			selfAndDescendantIds( store.items, id )
		)
	),
] );

const targetName = computed( () =>
	target.value ? store.find( target.value )?.name ?? '' : ''
);

const confirmTitle = computed( () =>
	sprintf(
		/* translators: 1: number of categories, 2: target category name. */
		_n(
			'Merge %1$d category into “%2$s”?',
			'Merge %1$d categories into “%2$s”?',
			sources.value.length,
			'licensio-knowledge-base'
		),
		sources.value.length,
		targetName.value
	)
);

watch(
	() => props.open,
	( open ) => {
		window.clearTimeout( focusTimer );

		if ( open ) {
			sources.value = props.category ? [ props.category.id ] : [];
			target.value = null;
			error.value = '';

			// The dialog would otherwise focus itself, so the first Tab
			// lands on its close button rather than a field.
			nextTick( () => {
				focusTimer = window.setTimeout( () => {
					document
						.querySelector< HTMLElement >( '.lkb-merge-dialog input' )
						?.focus();
				}, 50 );
			} );
		}
	},
	{ immediate: true }
);

onBeforeUnmount( () => window.clearTimeout( focusTimer ) );

// A target that became a source, or sits below one, is no longer valid.
watch( targetExclude, ( excluded ) => {
	if ( target.value && excluded.includes( target.value ) ) {
		target.value = null;
	}
} );

/**
 * Keep the picked sources.
 *
 * @since 1.0.0
 *
 * @param {TermValue|TermValue[]|null} value The picker's value.
 *
 * @return {void}
 */
function onSources( value: TermValue | TermValue[] | null ): void {
	sources.value = ( Array.isArray( value ) ? value : [] ).filter(
		( id ): id is number => typeof id === 'number'
	);
}

/**
 * Keep the picked target.
 *
 * @since 1.0.0
 *
 * @param {TermValue|TermValue[]|null} value The picker's value.
 *
 * @return {void}
 */
function onTarget( value: TermValue | TermValue[] | null ): void {
	target.value = typeof value === 'number' ? value : null;
}

/**
 * Merge, after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once merged or failed.
 */
async function merge(): Promise< void > {
	if ( ! target.value || 0 === sources.value.length || tooMany.value ) {
		return;
	}

	saving.value = true;
	error.value = '';

	try {
		const result = await store.merge( {
			sources: sources.value,
			target: target.value,
		} );

		message.success(
			sprintf(
				/* translators: 1: number of articles moved, 2: number of categories deleted. */
				__(
					'Merged. Articles moved: %1$d. Categories deleted: %2$d.',
					'licensio-knowledge-base'
				),
				result.moved,
				result.deleted.length
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
		:title="__( 'Merge categories', 'licensio-knowledge-base' )"
		wrap-class-name="lkb-merge-dialog"
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

		<a-form layout="vertical" @submit.prevent>
			<a-form-item
				:label="__( 'Categories to merge', 'licensio-knowledge-base' )"
				:validate-status="tooMany ? 'error' : undefined"
				:help="
					tooMany
						? __(
								'At most 50 categories can be merged at once.',
								'licensio-knowledge-base'
						  )
						: undefined
				"
				:extra="
					__(
						'Their articles move to the target, and they are deleted.',
						'licensio-knowledge-base'
					)
				"
			>
				<TermSelect
					:model-value="sources"
					taxonomy="licensio_category"
					multiple
					:aria-label="__( 'Categories to merge', 'licensio-knowledge-base' )"
					@update:model-value="onSources"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Merge into', 'licensio-knowledge-base' )"
				required
			>
				<TermSelect
					:model-value="target"
					taxonomy="licensio_category"
					:exclude="targetExclude"
					:aria-label="__( 'Merge into', 'licensio-knowledge-base' )"
					@update:model-value="onTarget"
				/>
			</a-form-item>
		</a-form>

		<template #footer>
			<div class="lkb-dialog__footer">
				<a-button @click="emit( 'close' )">
					{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
				</a-button>
				<ConfirmButton
					danger
					type="primary"
					:title="confirmTitle"
					:ok-text="__( 'Merge', 'licensio-knowledge-base' )"
					:disabled="! target || 0 === sources.length || tooMany"
					:loading="saving"
					@confirm="merge"
				>
					{{ __( 'Merge', 'licensio-knowledge-base' ) }}
				</ConfirmButton>
			</div>
		</template>
	</a-modal>
</template>

<style scoped>
.lkb-dialog__error {
	margin-bottom: var(--lkb-gap);
}

.lkb-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
