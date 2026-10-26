<script setup lang="ts">
/**
 * The "Move to…" dialog: the keyboard way to give a category a new parent.
 *
 * The category goes to the end of the new parent's children. A move is not
 * destructive, so it is sent when the user clicks Move, with no confirmation.
 *
 * @since 1.0.0
 */

import { computed, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import TermSelect from '../../components/TermSelect.vue';
import { useEscapeClose } from '../../components/popover-focus';
import { __, sprintf } from '../../components/i18n';
import type { TermValue } from '../../components/types/terms';
import { errorMessage } from '../../components/rest';
import { childIds, selfAndDescendantIds, useCategoriesStore } from '../store';
import type { CategoryItem } from '../types';

const props = defineProps< {
	/** Whether the dialog is open. */
	open: boolean;
	/** The category to move. */
	category: CategoryItem | null;
} >();

const emit = defineEmits< {
	( event: 'close' ): void;
	( event: 'closed' ): void;
} >();

// Escape on an open select closes only the select, not the dialog.
useEscapeClose(
	() => props.open,
	'.lkb-move-dialog',
	() => emit( 'close' )
);

const store = useCategoriesStore();

const where = ref< 'top' | 'under' >( 'top' );
const parent = ref< number | null >( null );
const saving = ref( false );
const error = ref( '' );

const exclude = computed( () =>
	props.category ? selfAndDescendantIds( store.items, props.category.id ) : []
);

const destination = computed( () =>
	'top' === where.value ? 0 : parent.value
);

watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			const current = props.category?.parent ?? 0;

			where.value = current ? 'under' : 'top';
			parent.value = current || null;
			error.value = '';
		}
	},
	{ immediate: true }
);

/**
 * Keep the picked parent.
 *
 * @since 1.0.0
 *
 * @param {TermValue|TermValue[]|null} value The picker's value.
 *
 * @return {void}
 */
function onParent( value: TermValue | TermValue[] | null ): void {
	parent.value = typeof value === 'number' ? value : null;
}

/**
 * Move the category.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once moved or failed.
 */
async function moveCategory(): Promise< void > {
	const target = destination.value;

	if ( ! props.category || null === target ) {
		return;
	}

	const id = props.category.id;
	const ids = [
		...childIds( store.items, target ).filter( ( child ) => child !== id ),
		id,
	];

	saving.value = true;
	error.value = '';

	try {
		await store.saveOrder( { parent: target, ids } );
		message.success( __( 'Category moved.', 'licensio-knowledge-base' ) );
		emit( 'close' );
	} catch ( caught ) {
		error.value = errorMessage( caught );
		// The server may have applied part of the move; show what it holds.
		store.load();
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
				__( 'Move “%s”', 'licensio-knowledge-base' ),
				props.category?.name ?? ''
			)
		"
		wrap-class-name="lkb-move-dialog"
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
			<legend>{{ __( 'New place', 'licensio-knowledge-base' ) }}</legend>
			<a-radio-group v-model:value="where" class="lkb-dialog__radios">
				<a-radio value="top">
					{{ __( 'Top level', 'licensio-knowledge-base' ) }}
				</a-radio>
				<a-radio value="under">
					{{ __( 'Under a category', 'licensio-knowledge-base' ) }}
				</a-radio>
			</a-radio-group>
			<TermSelect
				v-if="'under' === where"
				:model-value="parent"
				taxonomy="licensio_category"
				:exclude="exclude"
				:aria-label="
					__( 'New parent category', 'licensio-knowledge-base' )
				"
				class="lkb-dialog__picker"
				@update:model-value="onParent"
			/>
		</fieldset>

		<template #footer>
			<div class="lkb-dialog__footer">
				<a-button @click="emit( 'close' )">
					{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
				</a-button>
				<a-button
					type="primary"
					:disabled="null === destination"
					:loading="saving"
					@click="moveCategory"
				>
					{{ __( 'Move', 'licensio-knowledge-base' ) }}
				</a-button>
			</div>
		</template>
	</a-modal>
</template>

<style scoped>
.lkb-dialog__group {
	margin: 0;
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
