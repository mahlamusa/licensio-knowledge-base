<script setup lang="ts">
/**
 * A live sample of the knowledge base under the draft appearance values.
 *
 * The markup carries the `licensio-kb` class and consumes the same custom
 * properties the classic and block template layers do, so what is drawn here is
 * the frontend's own styling rather than an approximation of it. The properties
 * are set inline on this element, which is exactly how they reach the frontend:
 * `Licensio_KB_Templates::inline_css()` writes them into a `.licensio-kb` rule.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { previewProperties } from '../preview';
import type { Field, SettingsMap } from '../types';
import { __ } from '../../components/i18n';

const props = defineProps< {
	fields: Field[];
	draft: SettingsMap;
} >();

const style = computed( () => previewProperties( props.fields, props.draft ) );

const declarations = computed( () =>
	Object.keys( style.value ).map(
		( name ) => `${ name }: ${ style.value[ name ] };`
	)
);
</script>

<template>
	<div class="licensio-kb licensio-kb-settings__preview" :style="style">
		<div class="licensio-kb-settings__preview-card">
			<h3>{{ __( 'Resetting your password', 'licensio-knowledge-base' ) }}</h3>
			<p>
				{{
					__(
						'A sample article card, drawn with the values above.',
						'licensio-knowledge-base'
					)
				}}
			</p>
			<a class="licensio-kb-settings__preview-link" href="#preview">
				{{ __( 'Read the article', 'licensio-knowledge-base' ) }}
			</a>
			<span class="licensio-kb-settings__preview-button">
				{{ __( 'Was this helpful?', 'licensio-knowledge-base' ) }}
			</span>
		</div>

		<pre class="licensio-kb-settings__preview-code">{{
			declarations.join( '\n' )
		}}</pre>
	</div>
</template>

<style scoped>
/*
 * The preview sits above the section whose values it is drawing, and the pane
 * stacks its children with each owning its own bottom margin - the way the
 * notice above it does. Without one the preview's lower edge landed exactly on
 * the section card's header strip and the two read as one collided block.
 */
.licensio-kb-settings__preview {
	--licensio-kb-surface: #ffffff;
	--licensio-kb-line: #e2e8f0;

	display: grid;
	gap: var(--lkb-gap);
	grid-template-columns: minmax(0, 1fr) minmax(0, 20rem);
	margin-bottom: var(--lkb-gap-lg);
}

.licensio-kb-settings__preview-card {
	background: var( --licensio-kb-card-bg, var( --licensio-kb-surface ) );
	border: var( --licensio-kb-card-border, 1px solid transparent );
	border-radius: 10px;
	box-shadow: var( --licensio-kb-card-shadow, none );
	max-inline-size: var( --licensio-kb-max-width, 72rem );
	padding: 20px;
}

.licensio-kb-settings__preview-card h3 {
	margin-block: 0 8px;
}

.licensio-kb-settings__preview-link {
	color: var( --licensio-kb-brand, #2ba377 );
	font-weight: 600;
	margin-inline-end: 16px;
	text-decoration: none;
}

.licensio-kb-settings__preview-button {
	background: var( --licensio-kb-brand, #2ba377 );
	border-radius: 6px;
	color: var( --licensio-kb-brand-contrast, #ffffff );
	display: inline-block;
	padding: 6px 14px;
}

/*
 * The published custom properties.
 *
 * These wrap rather than scroll. The longest declaration is wider than the
 * column it sits in, and a horizontal scrollbar hid the start of every line -
 * the property name, which is the half worth reading. A wrapped continuation
 * is indented so it is still obvious where one declaration ends and the next
 * begins.
 */
.licensio-kb-settings__preview-code {
	background: rgba( var( --licensio-kb-brand-rgb, 37, 99, 235 ), 0.06 );
	border-radius: 8px;
	font-size: 12px;
	line-height: 1.6;
	margin: 0;
	padding: 12px;
	padding-left: 2em;
	text-indent: -1.25em;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

@media ( max-width: 782px ) {
	.licensio-kb-settings__preview {
		grid-template-columns: minmax( 0, 1fr );
	}
}
</style>
