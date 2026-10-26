<script setup lang="ts">
/**
 * A category's icon: one source, picked first, and only its controls shown.
 *
 * - Upload: an image or SVG file from the media library.
 * - Inline: pasted SVG markup, or a Base64 image (`data:image/…;base64,…`).
 * - Dashicon: a Dashicons name, previewed as the glyph.
 *
 * The drawer keeps only the picked source and clears the others on save, so
 * no hidden icon is left to win. The inline value is edited and shown as
 * text; its preview is an `<img>` with a data URL, which a browser never runs
 * scripts in, and there is no `v-html` here.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { __ } from '../../components/i18n';
import type { IconSource } from '../icon';
import MediaField from './MediaField.vue';

const props = defineProps< {
	/** The picked icon source. */
	source: IconSource;
	/** The Dashicon name. */
	icon: string;
	/** The uploaded icon's attachment ID. */
	iconId: number;
	/** The uploaded icon's URL. */
	iconUrl: string;
	/** The inline SVG markup or Base64 image. */
	inline: string;
	/** The server's errors for the icon fields, by REST field name. */
	errors: Record< string, string >;
} >();

const emit = defineEmits< {
	( event: 'update:source', value: IconSource ): void;
	( event: 'update:icon', value: string ): void;
	( event: 'update:iconId', value: number ): void;
	( event: 'update:iconUrl', value: string ): void;
	( event: 'update:inline', value: string ): void;
} >();

const DASHICON = /^dashicons-[a-z0-9]+(?:-[a-z0-9]+)*$/;
const BASE64_IMAGE = /^data:image\/(?:png|jpeg|gif|webp|svg\+xml);base64,/i;

const sources = computed( () => [
	{
		value: 'upload',
		label: __( 'Upload', 'licensio-knowledge-base' ),
	},
	{
		value: 'inline',
		label: __( 'Inline', 'licensio-knowledge-base' ),
	},
	{
		value: 'dashicon',
		label: __( 'Dashicon', 'licensio-knowledge-base' ),
	},
] );

const dashicon = computed( () => props.icon.trim() );

const iconLooksWrong = computed(
	() => '' !== dashicon.value && ! DASHICON.test( dashicon.value )
);

const inlineError = computed(
	() => props.errors.icon_svg || props.errors.icon_data || ''
);

/**
 * The inline icon as an image URL, for the preview.
 *
 * A Base64 image is its own URL. SVG markup is turned into one; an `<img>`
 * only renders an SVG that declares its namespace, so one is added when the
 * markup leaves it out.
 */
const inlinePreview = computed( () => {
	const value = props.inline.trim();

	if ( BASE64_IMAGE.test( value ) ) {
		return value.replace( /\s+/g, '' );
	}

	if ( ! value.startsWith( '<svg' ) ) {
		return '';
	}

	const withNamespace = value.includes( 'xmlns=' )
		? value
		: value.replace( '<svg', '<svg xmlns="http://www.w3.org/2000/svg"' );

	return (
		'data:image/svg+xml;charset=utf-8,' +
		encodeURIComponent( withNamespace )
	);
} );
</script>

<template>
	<fieldset class="lkb-icon-field">
		<legend>
			{{ __( 'Icon', 'licensio-knowledge-base' ) }}
		</legend>

		<a-radio-group
			:value="props.source"
			option-type="button"
			:options="sources"
			class="lkb-icon-field__sources"
			@update:value="
				( value: IconSource ) => emit( 'update:source', value )
			"
		/>

		<a-form-item
			v-if="'upload' === props.source"
			:label="__( 'Icon file', 'licensio-knowledge-base' )"
			:validate-status="props.errors.icon_id ? 'error' : undefined"
			:help="props.errors.icon_id"
			:extra="
				__(
					'An image or SVG file from the media library.',
					'licensio-knowledge-base'
				)
			"
		>
			<MediaField
				:id="props.iconId"
				:url="props.iconUrl"
				:label="__( 'Icon file', 'licensio-knowledge-base' )"
				:title="__( 'Select an icon', 'licensio-knowledge-base' )"
				@update:id="( value: number ) => emit( 'update:iconId', value )"
				@update:url="
					( value: string ) => emit( 'update:iconUrl', value )
				"
			/>
		</a-form-item>

		<a-form-item
			v-else-if="'inline' === props.source"
			:label="__( 'SVG or Base64 image', 'licensio-knowledge-base' )"
			html-for="lkb-category-icon-inline"
			:validate-status="inlineError ? 'error' : undefined"
			:help="inlineError || undefined"
			:extra="
				__(
					'Paste SVG markup, or a Base64 PNG, JPEG, GIF, WebP or SVG image starting with data:image/. SVG is cleaned on save, so the preview after saving shows what was kept. An SVG drawn with currentColor follows the knowledge base colour.',
					'licensio-knowledge-base'
				)
			"
		>
			<div class="lkb-icon-field__inline">
				<a-textarea
					id="lkb-category-icon-inline"
					:value="props.inline"
					:rows="4"
					class="lkb-icon-field__code"
					:placeholder="
						__(
							'<svg …> or data:image/png;base64,…',
							'licensio-knowledge-base'
						)
					"
					@update:value="
						( value: string ) => emit( 'update:inline', value )
					"
				/>
				<img
					v-if="inlinePreview"
					class="lkb-icon-field__preview"
					:src="inlinePreview"
					:alt="__( 'Icon preview', 'licensio-knowledge-base' )"
				/>
			</div>
		</a-form-item>

		<a-form-item
			v-else
			:label="__( 'Dashicon name', 'licensio-knowledge-base' )"
			html-for="lkb-category-icon"
			:validate-status="
				props.errors.icon
					? 'error'
					: iconLooksWrong
					? 'warning'
					: undefined
			"
			:help="
				props.errors.icon ||
				( iconLooksWrong
					? __(
							'Dashicons names start with dashicons- and use lowercase letters, digits and hyphens.',
							'licensio-knowledge-base'
					  )
					: undefined )
			"
			:extra="
				__(
					'For example dashicons-book-alt. Browse the names at developer.wordpress.org/resource/dashicons.',
					'licensio-knowledge-base'
				)
			"
		>
			<div class="lkb-icon-field__inline">
				<a-input
					id="lkb-category-icon"
					:value="props.icon"
					placeholder="dashicons-book-alt"
					@update:value="
						( value: string ) => emit( 'update:icon', value )
					"
				/>
				<span
					v-if="'' !== dashicon && ! iconLooksWrong"
					:class="[ 'lkb-icon-field__glyph', 'dashicons', dashicon ]"
					aria-hidden="true"
				/>
			</div>
		</a-form-item>
	</fieldset>
</template>

<style scoped>
.lkb-icon-field {
	min-width: 0;
	margin: 0 0 var( --lkb-gap );
	padding: 0;
	border: 0;
}


.lkb-icon-field__sources {
	margin-bottom: 16px;
}

.lkb-icon-field__inline {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.lkb-icon-field__code {
	font-family: Menlo, Consolas, monospace;
	font-size: 12px;
}

.lkb-icon-field__preview {
	flex: none;
	width: 48px;
	height: 48px;
	padding: 6px;
	object-fit: contain;
	border: 1px solid var( --lkb-border-wp );
	border-radius: 6px;
	background: var( --lkb-sunken );
}

.lkb-icon-field__glyph {
	flex: none;
	width: 32px;
	height: 32px;
	font-size: 32px;
	color: var( --lkb-ink );
}
</style>
