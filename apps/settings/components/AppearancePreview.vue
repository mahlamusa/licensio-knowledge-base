<script setup lang="ts">
/**
 * A live sample of the knowledge base under the draft appearance values.
 *
 * The markup carries the `lisensio-kb` class and consumes the same custom
 * properties the classic and block template layers do, so what is drawn here is
 * the frontend's own styling rather than an approximation of it. The properties
 * are set inline on this element, which is exactly how they reach the frontend:
 * `Lisensio_KB_Templates::inline_css()` writes them into a `.lisensio-kb` rule.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { translate } from '../api';
import { previewProperties } from '../preview';
import type { Field, SettingsMap } from '../types';

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
	<div class="lisensio-kb lisensio-kb-settings__preview" :style="style">
		<div class="lisensio-kb-settings__preview-card">
			<h3>{{ translate( 'Resetting your password' ) }}</h3>
			<p>
				{{
					translate(
						'A sample article card, drawn with the values above.'
					)
				}}
			</p>
			<a class="lisensio-kb-settings__preview-link" href="#preview">
				{{ translate( 'Read the article' ) }}
			</a>
			<span class="lisensio-kb-settings__preview-button">
				{{ translate( 'Was this helpful?' ) }}
			</span>
		</div>

		<pre class="lisensio-kb-settings__preview-code">{{
			declarations.join( '\n' )
		}}</pre>
	</div>
</template>

<style scoped>
.lisensio-kb-settings__preview {
	--lisensio-kb-surface: #ffffff;
	--lisensio-kb-line: #e2e8f0;

	display: grid;
	gap: 16px;
	grid-template-columns: minmax(0, 1fr) minmax(0, 20rem);
}

.lisensio-kb-settings__preview-card {
	background: var( --lisensio-kb-card-bg, var( --lisensio-kb-surface ) );
	border: var( --lisensio-kb-card-border, 1px solid transparent );
	border-radius: 10px;
	box-shadow: var( --lisensio-kb-card-shadow, none );
	max-inline-size: var( --lisensio-kb-max-width, 72rem );
	padding: 20px;
}

.lisensio-kb-settings__preview-card h3 {
	margin-block: 0 8px;
}

.lisensio-kb-settings__preview-link {
	color: var( --lisensio-kb-brand, #2ba377 );
	font-weight: 600;
	margin-inline-end: 16px;
	text-decoration: none;
}

.lisensio-kb-settings__preview-button {
	background: var( --lisensio-kb-brand, #2ba377 );
	border-radius: 6px;
	color: var( --lisensio-kb-brand-contrast, #ffffff );
	display: inline-block;
	padding: 6px 14px;
}

.lisensio-kb-settings__preview-code {
	background: rgba( var( --lisensio-kb-brand-rgb, 37, 99, 235 ), 0.06 );
	border-radius: 8px;
	font-size: 12px;
	margin: 0;
	overflow-x: auto;
	padding: 12px;
}

@media ( max-width: 782px ) {
	.lisensio-kb-settings__preview {
		grid-template-columns: minmax( 0, 1fr );
	}
}
</style>
