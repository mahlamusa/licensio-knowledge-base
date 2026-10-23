<script setup lang="ts">
/**
 * Turn the selected tags into categories.
 *
 * The dialog only chooses the parent; leaving it empty puts new categories at
 * the top level. Each tag becomes a category with its name and slug, or joins
 * the category that already has its slug, and its articles are kept. The
 * conversion is sent from the Popconfirm of the Convert button, so closing the
 * dialog or cancelling the Popconfirm sends nothing.
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
const parent = ref< TermValue | TermValue[] | null >( null );

const parentId = computed( () =>
	typeof parent.value === 'number' ? parent.value : 0
);

const confirmTitle = computed( () =>
	sprintf(
		/* translators: %d: how many tags will be converted. */
		__(
			'Convert %d tags to categories? The tags are deleted.',
			'licensio-knowledge-base'
		),
		store.selected.length
	)
);

watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			parent.value = null;
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
 * Convert. Called only after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once converted, or once the error is shown.
 */
async function convert(): Promise< void > {
	try {
		const result = await store.convertSelected( parentId.value );

		close();
		message.success(
			sprintf(
				/* translators: %d: how many tags were converted. */
				__(
					'%d tags converted to categories.',
					'licensio-knowledge-base'
				),
				result.converted.length
			)
		);

		result.failed.forEach( ( failure ) => {
			message.error( failure.message );
		} );
	} catch {
		// The store shows the error.
	}
}
</script>

<template>
	<a-modal
		:open="props.open"
		:title="__( 'Convert to categories', 'licensio-knowledge-base' )"
		@cancel="close"
	>
		<p>
			{{
				__(
					'Each tag becomes a category with the same name and slug, or joins the category that already has its slug. Its articles keep it as a category, and the tag is deleted.',
					'licensio-knowledge-base'
				)
			}}
		</p>

		<TermSelect
			v-model="parent"
			taxonomy="licensio_category"
			:placeholder="__( 'Top level', 'licensio-knowledge-base' )"
			:aria-label="__( 'Parent category', 'licensio-knowledge-base' )"
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
				:ok-text="__( 'Convert', 'licensio-knowledge-base' )"
				:loading="store.saving"
				@confirm="convert"
			>
				{{ __( 'Convert', 'licensio-knowledge-base' ) }}
			</ConfirmButton>
		</template>
	</a-modal>
</template>
