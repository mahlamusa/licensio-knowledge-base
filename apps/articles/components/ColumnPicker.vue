<script setup lang="ts">
/**
 * The column picker.
 *
 * Shows or hides the optional columns and sets the rows per page. Both are
 * the user's own view preferences, saved per user straight away; they change
 * no content, so they do not ask for confirmation.
 *
 * The boxes ticked are the columns shown at the current width: on medium
 * screens some default columns are left out until the user turns them on.
 * A pick on a medium screen changes only the column clicked, so wider
 * screens keep their columns; see the store's `pickColumns()`.
 *
 * Extension columns follow the core ones: those declared without a group
 * among them, the others under a heading per group.
 *
 * @since 1.0.0
 * @since 1.1.0 Lists the extension columns, by group.
 */

import { useId } from 'vue';
import { SettingOutlined } from '@ant-design/icons-vue';
import type { RadioChangeEvent } from 'ant-design-vue';
import { __ } from '../../components/i18n';
import { usePopoverFocus } from '../../components/popover-focus';
import { config } from '../api';
import { groupColumns } from '../columns';
import { useArticlesStore } from '../store';
import type { ArticleColumn, ListColumn, PerPage } from '../types';

const store = useArticlesStore();
const groups = groupColumns( config().extensions.columns );
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
					:value="store.visibleColumns"
					@change="
						( value: unknown ) =>
							store.pickColumns( value as ListColumn[] )
					"
				>
					<a-checkbox
						v-for="option in columns"
						:key="option.value"
						:value="option.value"
					>
						{{ option.label }}
					</a-checkbox>
					<template v-for="group in groups" :key="group.label">
						<span
							v-if="group.label"
							class="lkb-columns__group"
							role="presentation"
						>
							{{ group.label }}
						</span>
						<a-checkbox
							v-for="option in group.columns"
							:key="option.key"
							:value="option.key"
						>
							{{ option.label }}
						</a-checkbox>
					</template>
				</a-checkbox-group>
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

.lkb-columns__group {
	grid-column: 1 / -1;
	margin-top: 6px;
	color: var( --lkb-ink-muted );
	font-size: 12px;
	font-weight: 600;
}
</style>
