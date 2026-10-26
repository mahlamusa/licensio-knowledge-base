<script setup lang="ts">
/**
 * The column picker.
 *
 * Shows or hides the optional columns and sets the rows per page. Both are
 * the user's own view preferences, saved per user straight away; they change
 * no content, so they do not ask for confirmation.
 *
 * @since 1.0.0
 */

import { useId } from 'vue';
import { SettingOutlined } from '@ant-design/icons-vue';
import type { RadioChangeEvent } from 'ant-design-vue';
import { __ } from '../../components/i18n';
import { usePopoverFocus } from '../../components/popover-focus';
import { useArticlesStore } from '../store';
import type { ArticleColumn, PerPage } from '../types';

const store = useArticlesStore();
const { open, overlayClass, setOpen } = usePopoverFocus();

// Names its radio group: radios that share a name are one tab stop, with
// the arrow keys moving between them.
const perPageName = useId();

const columns: Array< { value: ArticleColumn; label: string } > = [
	{ value: 'author', label: __( 'Author', 'licensio-knowledge-base' ) },
	{
		value: 'categories',
		label: __( 'Categories', 'licensio-knowledge-base' ),
	},
	{ value: 'tags', label: __( 'Tags', 'licensio-knowledge-base' ) },
	{
		value: 'attachments',
		label: __( 'Attachments', 'licensio-knowledge-base' ),
	},
	{ value: 'rating', label: __( 'Rating', 'licensio-knowledge-base' ) },
	{ value: 'views', label: __( 'Views', 'licensio-knowledge-base' ) },
	{
		value: 'reading_time',
		label: __( 'Reading time', 'licensio-knowledge-base' ),
	},
	{ value: 'date', label: __( 'Date', 'licensio-knowledge-base' ) },
	{ value: 'modified', label: __( 'Modified', 'licensio-knowledge-base' ) },
];

const perPageOptions: PerPage[] = [ 20, 50, 100 ];
</script>

<template>
	<a-popover
		trigger="click"
		placement="bottomRight"
		:open="open"
		:overlay-class-name="overlayClass"
		@open-change="setOpen"
	>
		<template #content>
			<fieldset class="lkb-columns">
				<legend>
					{{ __( 'Columns', 'licensio-knowledge-base' ) }}
				</legend>
				<a-checkbox-group
					:value="store.preferences.columns"
					:options="columns"
					@change="
						( value: unknown ) =>
							store.setPreferences( {
								columns: value as ArticleColumn[],
							} )
					"
				/>
			</fieldset>

			<fieldset class="lkb-columns">
				<legend>
					{{ __( 'Rows per page', 'licensio-knowledge-base' ) }}
				</legend>
				<a-radio-group
					:value="store.preferences.per_page"
					:name="perPageName"
					option-type="button"
					size="small"
					:options="
						perPageOptions.map( ( value ) => ( {
							value,
							label: String( value ),
						} ) )
					"
					@change="
						( event: RadioChangeEvent ) =>
							store.setPreferences( {
								per_page: Number(
									event.target.value
								) as PerPage,
							} )
					"
				/>
			</fieldset>
		</template>

		<a-button
			:aria-label="
				__( 'Columns and rows per page', 'licensio-knowledge-base' )
			"
		>
			<template #icon><SettingOutlined /></template>
			{{ __( 'Columns', 'licensio-knowledge-base' ) }}
		</a-button>
	</a-popover>
</template>

<style scoped>
.lkb-columns {
	margin: 0 0 12px;
	padding: 0;
	border: 0;
}

.lkb-columns legend {
	margin-bottom: 6px;
	font-size: 13px;
	font-weight: 600;
}

.lkb-columns :deep( .ant-checkbox-group ) {
	display: grid;
	grid-template-columns: repeat( 2, minmax( 0, 1fr ) );
	gap: 4px 12px;
}
</style>
