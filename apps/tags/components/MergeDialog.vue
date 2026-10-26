<script setup lang="ts">
/**
 * Merge the selected tags into another tag.
 *
 * The dialog only chooses the target. The merge is sent from the confirmation
 * of its Merge button, so closing the dialog or cancelling the confirmation
 * sends nothing. The selected tags cannot be picked as the target.
 *
 * The toast counts every selected tag as merged, since its articles moved,
 * and warns apart about any the server could not delete afterwards.
 * A failed merge shows its error inside the dialog.
 *
 * @since 1.0.0
 */

import { computed, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import TermSelect from '../../components/TermSelect.vue';
import { useEscapeClose } from '../../components/popover-focus';
import { __, _n, sprintf } from '../../components/i18n';
import { errorMessage } from '../../components/rest';
import type { TermValue } from '../../components/types/terms';
import { useTagsStore } from '../store';

const props = defineProps< { open: boolean } >();

const emit = defineEmits< {
	( event: 'update:open', value: boolean ): void;
} >();

// Escape on an open select closes only the select, not the dialog.
useEscapeClose( () => props.open, '.lkb-tag-merge-dialog', close );

const store = useTagsStore();
const target = ref< TermValue | TermValue[] | null >( null );
const error = ref( '' );

const targetId = computed( () =>
	typeof target.value === 'number' ? target.value : 0
);

const intro = computed( () =>
	sprintf(
		/* translators: %d: how many tags are selected. */
		_n(
			'The articles of the %d selected tag get the tag you choose, then the selected tag is deleted.',
			'The articles of the %d selected tags get the tag you choose, then the selected tags are deleted.',
			store.selected.length,
			'licensio-knowledge-base'
		),
		store.selected.length
	)
);

const confirmTitle = computed( () =>
	sprintf(
		/* translators: %d: how many tags will be merged. */
		_n(
			'Merge %d tag? It is deleted after its articles move.',
			'Merge %d tags? They are deleted after their articles move.',
			store.selected.length,
			'licensio-knowledge-base'
		),
		store.selected.length
	)
);

watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			target.value = null;
			error.value = '';
		}
	}
);

/**
 * Close the dialog.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function close(): void {
	emit( 'update:open', false );
}

/**
 * Merge. Called only after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once merged, or once the error is shown.
 */
async function merge(): Promise< void > {
	// Counted before the merge, which re-reads the page and the selection.
	const merged = store.selected.filter(
		( id ) => id !== targetId.value
	).length;

	error.value = '';

	try {
		const result = await store.mergeSelected( targetId.value );
		const kept = merged - result.deleted.length;

		close();
		message.success(
			sprintf(
				/* translators: 1: how many tags were merged, 2: the target tag's name. */
				_n(
					'%1$d tag merged into “%2$s”.',
					'%1$d tags merged into “%2$s”.',
					merged,
					'licensio-knowledge-base'
				),
				merged,
				result.target?.name ?? ''
			)
		);

		if ( kept > 0 ) {
			message.warning(
				sprintf(
					/* translators: %d: how many merged tags could not be deleted. */
					_n(
						'%d merged tag could not be deleted. Its articles have the chosen tag; delete it from the list.',
						'%d merged tags could not be deleted. Their articles have the chosen tag; delete them from the list.',
						kept,
						'licensio-knowledge-base'
					),
					kept
				)
			);
		}
	} catch ( caught ) {
		// Shown in the dialog, as the page alert is behind its mask.
		error.value = errorMessage( caught );
	}
}
</script>

<template>
	<a-modal
		:open="props.open"
		:title="__( 'Merge tags', 'licensio-knowledge-base' )"
		wrap-class-name="lkb-tag-merge-dialog"
		:keyboard="false"
		@cancel="close"
	>
		<a-alert
			v-if="error"
			type="error"
			show-icon
			:message="error"
			class="lkb-dialog__error"
		/>

		<p>{{ intro }}</p>

		<TermSelect
			v-model="target"
			taxonomy="licensio_tag"
			:exclude="store.selected"
			:placeholder="
				__( 'Search for the tag to keep…', 'licensio-knowledge-base' )
			"
			:aria-label="__( 'Tag to merge into', 'licensio-knowledge-base' )"
			style="width: 100%"
		/>

		<template #footer>
			<a-button @click="close">
				{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
			</a-button>
			<ConfirmButton
				type="primary"
				danger
				:title="confirmTitle"
				:ok-text="__( 'Merge', 'licensio-knowledge-base' )"
				:disabled="0 === targetId"
				:loading="store.saving"
				@confirm="merge"
			>
				{{ __( 'Merge', 'licensio-knowledge-base' ) }}
			</ConfirmButton>
		</template>
	</a-modal>
</template>
