<script setup lang="ts">
/**
 * Merge the selected tags into another tag.
 *
 * The dialog only chooses the target. The merge is sent from the Popconfirm
 * of its Merge button, so closing the dialog or cancelling the Popconfirm
 * sends nothing. The selected tags cannot be picked as the target.
 *
 * @since 1.0.0
 */

import { computed, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import TermSelect from '../../components/TermSelect.vue';
import { __, sprintf } from '../../components/i18n';
import type { TermValue } from '../../components/types/terms';
import { useTagsStore } from '../store';

const props = defineProps< { open: boolean } >();

const emit = defineEmits< {
	( event: 'update:open', value: boolean ): void;
} >();

const store = useTagsStore();
const target = ref< TermValue | TermValue[] | null >( null );

const targetId = computed( () =>
	typeof target.value === 'number' ? target.value : 0
);

const intro = computed( () =>
	sprintf(
		/* translators: %d: how many tags are selected. */
		__(
			'The articles of the %d selected tags get the tag you choose, then the selected tags are deleted.',
			'licensio-knowledge-base'
		),
		store.selected.length
	)
);

const confirmTitle = computed( () =>
	sprintf(
		/* translators: %d: how many tags will be merged. */
		__(
			'Merge %d tags? They are deleted after their articles move.',
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
	try {
		const result = await store.mergeSelected( targetId.value );

		close();
		message.success(
			sprintf(
				/* translators: 1: how many tags were merged, 2: the target tag's name. */
				__( '%1$d tags merged into “%2$s”.', 'licensio-knowledge-base' ),
				result.deleted.length,
				result.target?.name ?? ''
			)
		);
	} catch {
		// The store shows the error.
	}
}
</script>

<template>
	<a-modal
		:open="props.open"
		:title="__( 'Merge tags', 'licensio-knowledge-base' )"
		@cancel="close"
	>
		<p>{{ intro }}</p>

		<TermSelect
			v-model="target"
			taxonomy="licensio_tag"
			:exclude="store.selected"
			:placeholder="__( 'Search for the tag to keep…', 'licensio-knowledge-base' )"
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
