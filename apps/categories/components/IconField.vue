<script setup lang="ts">
/**
 * A category's icon: inline SVG, uploaded icon and Dashicon name.
 *
 * The front end uses the first that is set, in that order (see
 * `Licensio_KB_Term_Meta`), and the help texts say so. The SVG markup is
 * edited and shown as text. Its preview is an `<img>` with a data URL, which
 * a browser never runs scripts in; there is no `v-html` here.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { __ } from '../../components/i18n';
import MediaField from './MediaField.vue';

const props = defineProps< {
	/** The Dashicon name. */
	icon: string;
	/** The uploaded icon's attachment ID. */
	iconId: number;
	/** The uploaded icon's URL. */
	iconUrl: string;
	/** The inline SVG markup. */
	iconSvg: string;
} >();

const emit = defineEmits< {
	( event: 'update:icon', value: string ): void;
	( event: 'update:iconId', value: number ): void;
	( event: 'update:iconUrl', value: string ): void;
	( event: 'update:iconSvg', value: string ): void;
} >();

const DASHICON = /^dashicons-[a-z0-9-]+$/;

const iconLooksWrong = computed(
	() => '' !== props.icon.trim() && ! DASHICON.test( props.icon.trim() )
);

/**
 * The SVG as an image URL, for the preview.
 *
 * An `<img>` only renders an SVG that declares its namespace, so one is
 * added when the markup leaves it out.
 */
const svgPreview = computed( () => {
	const markup = props.iconSvg.trim();

	if ( ! markup.startsWith( '<svg' ) ) {
		return '';
	}

	const withNamespace = markup.includes( 'xmlns=' )
		? markup
		: markup.replace( '<svg', '<svg xmlns="http://www.w3.org/2000/svg"' );

	return (
		'data:image/svg+xml;charset=utf-8,' +
		encodeURIComponent( withNamespace )
	);
} );
</script>

<template>
	<div class="lkb-icon-field">
		<a-form-item
			:label="__( 'Inline SVG icon', 'licensio-knowledge-base' )"
			html-for="lkb-category-icon-svg"
			:extra="
				__(
					'Paste an SVG icon. Everything outside a small allowlist of shape elements and presentation attributes is removed on save, so the preview shows what was actually kept. Takes precedence over the uploaded icon and the Dashicons name. An icon drawn with currentColor follows the knowledge base colour.',
					'licensio-knowledge-base'
				)
			"
		>
			<div class="lkb-icon-field__svg">
				<a-textarea
					id="lkb-category-icon-svg"
					:value="props.iconSvg"
					:rows="4"
					class="lkb-icon-field__code"
					:placeholder="
						__( 'Paste SVG markup here', 'licensio-knowledge-base' )
					"
					@update:value="
						( value: string ) => emit( 'update:iconSvg', value )
					"
				/>
				<img
					v-if="svgPreview"
					class="lkb-icon-field__preview"
					:src="svgPreview"
					:alt="__( 'SVG icon preview', 'licensio-knowledge-base' )"
				/>
			</div>
		</a-form-item>

		<a-form-item
			:label="__( 'Uploaded icon', 'licensio-knowledge-base' )"
			:extra="
				__(
					'An uploaded icon, for example an SVG file. Takes precedence over the Dashicons name, and is itself ignored when an SVG icon is pasted above.',
					'licensio-knowledge-base'
				)
			"
		>
			<MediaField
				:id="props.iconId"
				:url="props.iconUrl"
				:label="__( 'Uploaded icon', 'licensio-knowledge-base' )"
				:title="__( 'Select an icon', 'licensio-knowledge-base' )"
				@update:id="( value: number ) => emit( 'update:iconId', value )"
				@update:url="
					( value: string ) => emit( 'update:iconUrl', value )
				"
			/>
		</a-form-item>

		<a-form-item
			:label="__( 'Icon', 'licensio-knowledge-base' )"
			html-for="lkb-category-icon"
			:validate-status="iconLooksWrong ? 'warning' : undefined"
			:help="
				iconLooksWrong
					? __(
							'Dashicons names start with dashicons- and use lowercase letters, digits and hyphens.',
							'licensio-knowledge-base'
					  )
					: undefined
			"
			:extra="
				__(
					'A Dashicons name, for example dashicons-book-alt. Ignored when an SVG icon or an uploaded icon is set.',
					'licensio-knowledge-base'
				)
			"
		>
			<a-input
				id="lkb-category-icon"
				:value="props.icon"
				placeholder="dashicons-book-alt"
				@update:value="
					( value: string ) => emit( 'update:icon', value )
				"
			/>
		</a-form-item>
	</div>
</template>

<style scoped>
.lkb-icon-field__svg {
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
	border: 1px solid var( --lkb-border-wp );
	border-radius: 6px;
	background: var( --lkb-sunken );
}
</style>
