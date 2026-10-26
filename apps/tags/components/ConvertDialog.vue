<script setup lang="ts">
/**
 * Turn the selected tags into categories.
 *
 * The dialog only chooses the parent; leaving it empty puts new categories at
 * the top level. Each tag becomes a category with its name and slug, or joins
 * the category that already has its slug or the parent's child that already
 * has its name, and its articles are kept. Once done, each tag that joined an
 * existing category is named with that category, since it may sit somewhere
 * other than the chosen parent. The conversion is sent from the Popconfirm of
 * the Convert button, so closing the dialog or cancelling the Popconfirm sends
 * nothing. A failed conversion shows its error inside the dialog.
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
useEscapeClose( () => props.open, '.lkb-tag-convert-dialog', close );

const store = useTagsStore();
const parent = ref< TermValue | TermValue[] | null >( null );
const error = ref( '' );

const parentId = computed( () =>
	typeof parent.value === 'number' ? parent.value : 0
);

const confirmTitle = computed( () =>
	sprintf(
		/* translators: %d: how many tags will be converted. */
		_n(
			'Convert %d tag to a category? The tag is deleted.',
			'Convert %d tags to categories? The tags are deleted.',
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
			parent.value = null;
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
 * Convert. Called only after the user confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once converted, or once the error is shown.
 */
async function convert(): Promise< void > {
	// The tags are gone once the list reloads, so keep their names now.
	const names = new Map(
		store.items.map( ( item ) => [ item.id, item.name ] )
	);

	try {
		const result = await store.convertSelected( parentId.value );

		close();
		message.success(
			sprintf(
				/* translators: %d: how many tags were converted. */
				_n(
					'%d tag converted to a category.',
					'%d tags converted to categories.',
					result.converted.length,
					'licensio-knowledge-base'
				),
				result.converted.length
			)
		);

		result.converted
			.filter( ( entry ) => entry.reused )
			.forEach( ( entry ) => {
				message.info(
					sprintf(
						/* translators: 1: the tag's name, 2: the existing category's name. */
						__(
							'“%1$s” was added to the existing category “%2$s”.',
							'licensio-knowledge-base'
						),
						names.get( entry.tag ) ?? entry.category.name,
						entry.category.name
					),
					6
				);
			} );

		result.failed.forEach( ( failure ) => {
			message.error( failure.message );
		} );
	} catch ( caught ) {
		// Shown in the dialog, as the page alert is behind its mask.
		error.value = errorMessage( caught );
	}
}
</script>

<template>
	<a-modal
		:open="props.open"
		:title="__( 'Convert to categories', 'licensio-knowledge-base' )"
		wrap-class-name="lkb-tag-convert-dialog"
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

		<p>
			{{
				__(
					'Each tag becomes a category with the same name and slug, or joins the category that already has its slug or the same name under the chosen parent. Its articles keep it as a category, and the tag is deleted.',
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

<style scoped>
.lkb-dialog__error {
	margin-bottom: var(--lkb-gap);
}
</style>
