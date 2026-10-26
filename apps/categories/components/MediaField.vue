<script setup lang="ts">
/**
 * An image picked from the media library: preview, Select and Remove.
 *
 * Used for a category's uploaded icon and its image. `v-model:id` is the
 * attachment ID (0 for none) and `v-model:url` the URL the preview shows.
 * When `wp.media` is not on the page the buttons are disabled and say why.
 *
 * @since 1.0.0
 */

import { __ } from '../../components/i18n';
import { hasMedia, pickMedia } from '../media';

const props = defineProps< {
	/** The attachment ID, 0 for none. */
	id: number;
	/** The attachment URL for the preview. */
	url: string;
	/** What the field is, for the buttons' accessible names. */
	label: string;
	/** The media modal title. */
	title: string;
} >();

const emit = defineEmits< {
	( event: 'update:id', value: number ): void;
	( event: 'update:url', value: string ): void;
} >();

const available = hasMedia();

/**
 * Open the media modal and keep the picked image.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once the modal has closed.
 */
async function select(): Promise< void > {
	const picked = await pickMedia(
		props.title,
		__( 'Use this image', 'licensio-knowledge-base' )
	);

	if ( picked ) {
		emit( 'update:id', picked.id );
		emit( 'update:url', picked.url );
	}
}

/**
 * Clear the image.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function remove(): void {
	emit( 'update:id', 0 );
	emit( 'update:url', '' );
}
</script>

<template>
	<div class="lkb-media">
		<img
			v-if="props.id && props.url"
			class="lkb-media__preview"
			:src="props.url"
			alt=""
		/>
		<span v-else-if="props.id" class="lkb-media__none">
			{{ __( 'Image selected', 'licensio-knowledge-base' ) }}
		</span>

		<div class="lkb-media__actions">
			<a-button
				size="small"
				:disabled="! available"
				:aria-label="
					props.label +
					': ' +
					__( 'Select', 'licensio-knowledge-base' )
				"
				@click="select"
			>
				{{ __( 'Select', 'licensio-knowledge-base' ) }}
			</a-button>
			<a-button
				v-if="props.id"
				size="small"
				:aria-label="
					props.label +
					': ' +
					__( 'Remove', 'licensio-knowledge-base' )
				"
				@click="remove"
			>
				{{ __( 'Remove', 'licensio-knowledge-base' ) }}
			</a-button>
		</div>

		<p v-if="! available" class="lkb-media__note">
			{{
				__(
					'The media library is not available on this page, so images cannot be picked.',
					'licensio-knowledge-base'
				)
			}}
		</p>
	</div>
</template>

<style scoped>
.lkb-media {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.lkb-media__preview {
	width: 48px;
	height: 48px;
	object-fit: contain;
	border: 1px solid var( --lkb-border-wp );
	border-radius: 6px;
	background: var( --lkb-sunken );
}

.lkb-media__none,
.lkb-media__note {
	color: var( --lkb-ink-muted );
	font-size: 12px;
}

.lkb-media__note {
	flex-basis: 100%;
	margin: 0;
}

.lkb-media__actions {
	display: flex;
	gap: 8px;
}
</style>
