<script setup lang="ts">
/**
 * The Add New Tag and Edit Tag form, in the left column as on core's tag
 * screen.
 *
 * With no tag it adds one: the submit button sends at once, with no
 * confirmation, and the form clears for the next tag with focus back on Name.
 * With a tag it edits that tag: Update sends only the changed fields, and
 * Update, Cancel or Escape return the form to Add. The page decides where
 * focus goes then, through the `done` event.
 *
 * A server error is shown by the page above the columns, and the form keeps
 * the user's input. Escape on an open control that uses it itself, such as
 * an autocomplete, does not cancel the edit.
 *
 * @since 1.0.0
 */

import { computed, nextTick, reactive, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useEscapeClose } from '../../components/popover-focus';
import { __, sprintf } from '../../components/i18n';
import { useTagsStore } from '../store';
import type { TagFields, TagItem } from '../types';

const props = defineProps< {
	/** The tag to edit, or null to add one. */
	tag: TagItem | null;
} >();

const emit = defineEmits< {
	( event: 'done', id: number ): void;
} >();

const store = useTagsStore();

const form = reactive( { name: '', slug: '', description: '' } );

const heading = computed( () =>
	props.tag
		? __( 'Edit Tag', 'licensio-knowledge-base' )
		: __( 'Add New Tag', 'licensio-knowledge-base' )
);

/**
 * The fields that differ from the tag being edited, or every filled field of
 * a new tag.
 *
 * @since 1.0.0
 *
 * @return {TagFields} The fields to send.
 */
function changes(): TagFields {
	const tag = props.tag;
	const fields: TagFields = {};

	if ( ! tag ) {
		return {
			name: form.name.trim(),
			...( '' !== form.slug.trim() ? { slug: form.slug.trim() } : {} ),
			...( '' !== form.description
				? { description: form.description }
				: {} ),
		};
	}

	if ( form.name.trim() !== tag.name ) {
		fields.name = form.name.trim();
	}

	if ( form.slug.trim() !== tag.slug ) {
		fields.slug = form.slug.trim();
	}

	// Sent only when changed, so a stored description round-trips untouched.
	if ( form.description !== tag.description ) {
		fields.description = form.description;
	}

	return fields;
}

const canSubmit = computed(
	() =>
		'' !== form.name.trim() &&
		( ! props.tag || Object.keys( changes() ).length > 0 )
);

/**
 * Fill the form from the tag being edited, or blank it for a new one.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function reset(): void {
	form.name = props.tag?.name ?? '';
	form.slug = props.tag?.slug ?? '';
	form.description = props.tag?.description ?? '';
}

watch( () => props.tag, reset, { immediate: true } );

/**
 * Put focus on the Name field.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function focusName(): void {
	nextTick( () => {
		document.getElementById( 'lkb-tag-name' )?.focus();
	} );
}

/**
 * Leave the edit without saving.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function cancel(): void {
	if ( props.tag ) {
		emit( 'done', props.tag.id );
	}
}

// Escape in the form cancels an edit, unless an open control used it.
useEscapeClose( () => null !== props.tag, '.lkb-tag-form', cancel );

/**
 * Add the tag, or update the one being edited.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once saved, or once the error is shown.
 */
async function submit(): Promise< void > {
	if ( ! canSubmit.value ) {
		return;
	}

	const tag = props.tag;

	try {
		if ( tag ) {
			const updated = await store.update( tag.id, changes() );

			message.success(
				sprintf(
					/* translators: %s: the tag's slug. */
					__(
						'Tag saved. Its slug is “%s”.',
						'licensio-knowledge-base'
					),
					updated.slug
				)
			);
			emit( 'done', tag.id );
			return;
		}

		const created = await store.create( changes() );

		message.success(
			sprintf(
				/* translators: %s: the new tag's name. */
				__( 'Tag “%s” added.', 'licensio-knowledge-base' ),
				created.name
			)
		);
		reset();
		focusName();
	} catch {
		// The store shows the error; the form keeps the input.
	}
}
</script>

<template>
	<section
		class="lkb-terms__card lkb-tag-form"
		aria-labelledby="lkb-tag-form-title"
	>
		<h2 id="lkb-tag-form-title" class="lkb-terms__heading">
			{{ heading }}
		</h2>

		<a-form layout="vertical" @submit.prevent="submit">
			<a-form-item
				:label="__( 'Name', 'licensio-knowledge-base' )"
				html-for="lkb-tag-name"
				required
				:extra="
					__(
						'The name is how it appears on your site.',
						'licensio-knowledge-base'
					)
				"
			>
				<a-input id="lkb-tag-name" v-model:value="form.name" />
			</a-form-item>

			<a-form-item
				:label="__( 'Slug', 'licensio-knowledge-base' )"
				html-for="lkb-tag-slug"
				:extra="
					__(
						'Part of the tag’s web address. Leave it empty to make one from the name.',
						'licensio-knowledge-base'
					)
				"
			>
				<a-input id="lkb-tag-slug" v-model:value="form.slug" />
			</a-form-item>

			<a-form-item
				:label="__( 'Description', 'licensio-knowledge-base' )"
				html-for="lkb-tag-description"
			>
				<a-textarea
					id="lkb-tag-description"
					v-model:value="form.description"
					:rows="4"
				/>
			</a-form-item>

			<div class="lkb-terms__actions">
				<a-button
					type="primary"
					html-type="submit"
					:disabled="! canSubmit"
					:loading="store.saving"
				>
					{{
						props.tag
							? __( 'Update', 'licensio-knowledge-base' )
							: __( 'Add New Tag', 'licensio-knowledge-base' )
					}}
				</a-button>
				<a-button v-if="props.tag" @click="cancel">
					{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
				</a-button>
			</div>
		</a-form>
	</section>
</template>
