<script setup lang="ts">
/**
 * The article table.
 *
 * Server-side paging and sort, the optional columns the user picked, row
 * selection for the bulk bar, and the row actions: Edit · Quick edit · View ·
 * Trash, or Restore · Delete permanently in the Trash view. Quick edit opens
 * in place under the row. A locked row shows who is editing it and cannot be
 * quick edited.
 *
 * @since 1.0.0
 */

import { computed, nextTick } from 'vue';
import { LockOutlined } from '@ant-design/icons-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import { __, sprintf } from '../../components/i18n';
import { statusLabel } from '../../components/status';
import { config } from '../api';
import { confirmTitle, performAction } from '../actions';
import { useArticlesStore } from '../store';
import { ORDER_BY } from '../url';
import QuickEditRow from './QuickEditRow.vue';
import type {
	ArticleColumn,
	ArticleItem,
	ArticleOrderBy,
} from '../types';

const store = useArticlesStore();
const settings = config();

/**
 * The current sort of a column, in Ant's terms.
 *
 * @since 1.0.0
 *
 * @param {string} key The column key.
 *
 * @return {string|null} `ascend`, `descend` or null.
 */
function sortOrder( key: string ): 'ascend' | 'descend' | null {
	if ( key !== store.query.orderby ) {
		return null;
	}

	return 'asc' === store.query.order ? 'ascend' : 'descend';
}

/**
 * One column definition.
 *
 * @since 1.0.0
 *
 * @param {string} key   The column key.
 * @param {string} title The heading.
 * @param {number} width An optional width.
 *
 * @return {Object} The column.
 */
function column( key: string, title: string, width?: number ) {
	const sortable = ORDER_BY.includes( key as ArticleOrderBy );

	return {
		key,
		title,
		width,
		sorter: sortable,
		sortOrder: sortable ? sortOrder( key ) : undefined,
		sortDirections: [ 'descend', 'ascend', 'descend' ],
	};
}

const columns = computed( () => {
	const optional: Array< [ ArticleColumn, string, number? ] > = [
		[ 'author', __( 'Author', 'licensio-knowledge-base' ), 140 ],
		[ 'categories', __( 'Categories', 'licensio-knowledge-base' ) ],
		[ 'tags', __( 'Tags', 'licensio-knowledge-base' ) ],
		[ 'attachments', __( 'Attachments', 'licensio-knowledge-base' ), 110 ],
		[ 'rating', __( 'Rating', 'licensio-knowledge-base' ), 120 ],
		[ 'views', __( 'Views', 'licensio-knowledge-base' ), 90 ],
		[ 'reading_time', __( 'Reading time', 'licensio-knowledge-base' ), 110 ],
		[ 'date', __( 'Date', 'licensio-knowledge-base' ), 150 ],
		[ 'modified', __( 'Modified', 'licensio-knowledge-base' ), 150 ],
	];

	return [
		column( 'title', __( 'Title', 'licensio-knowledge-base' ) ),
		...optional
			.filter( ( [ key ] ) => store.shows( key ) )
			.map( ( [ key, title, width ] ) => column( key, title, width ) ),
	];
} );

const inTrash = computed( () => 'trash' === store.query.status );

/**
 * A site-local date for display.
 *
 * @since 1.0.0
 *
 * @param {string} value `YYYY-MM-DDTHH:mm:ss`.
 *
 * @return {string} `YYYY-MM-DD HH:mm`.
 */
function displayDate( value: string ): string {
	return value ? value.replace( 'T', ' ' ).slice( 0, 16 ) : '—';
}

/**
 * The Feedback inbox, filtered to one article.
 *
 * @since 1.0.0
 *
 * @param {number} id The article.
 *
 * @return {string} The URL.
 */
function feedbackUrl( id: number ): string {
	return `${ settings.feedbackUrl }&article_id=${ id }`;
}

/**
 * Handle a paging or sort change from the table.
 *
 * @since 1.0.0
 *
 * @param {Object} pagination The table pagination.
 * @param {unknown} filters   Unused column filters.
 * @param {Object} sorter     The sorted column.
 *
 * @return {void}
 */
function onChange(
	pagination: { current?: number },
	filters: unknown,
	sorter: { columnKey?: unknown; order?: string | null } | unknown[]
): void {
	const sort = Array.isArray( sorter ) ? sorter[ 0 ] : sorter;
	const key = ( sort as { columnKey?: unknown } )?.columnKey;
	const order = ( sort as { order?: string | null } )?.order;
	const direction = 'ascend' === order ? 'asc' : 'desc';

	if (
		typeof key === 'string' &&
		ORDER_BY.includes( key as ArticleOrderBy ) &&
		order &&
		( key !== store.query.orderby || direction !== store.query.order )
	) {
		store.setSort( key as ArticleOrderBy, direction );
		return;
	}

	if ( pagination.current && pagination.current !== store.query.page ) {
		store.setPage( pagination.current );
	}
}

/**
 * Open or close quick edit on a row.
 *
 * @since 1.0.0
 *
 * @param {ArticleItem} item The row.
 *
 * @return {void}
 */
function toggleQuickEdit( item: ArticleItem ): void {
	store.quickEditId = store.quickEditId === item.id ? 0 : item.id;
}

/**
 * Close quick edit and put focus back on the row's Quick edit button, so a
 * keyboard user carries on from the row they were editing.
 *
 * @since 1.0.0
 *
 * @param {number} id The row.
 *
 * @return {void}
 */
function closeQuickEdit( id: number ): void {
	store.quickEditId = 0;

	nextTick( () =>
		document
			.querySelector< HTMLElement >( `[data-quick-edit="${ id }"]` )
			?.focus()
	);
}

const expandedKeys = computed( () =>
	store.quickEditId ? [ store.quickEditId ] : []
);
</script>

<template>
	<div class="lkb-tablecard">
		<a-table
			row-key="id"
			:columns="columns"
			:data-source="store.items"
			:loading="store.loading"
			:scroll="{ x: 'max-content' }"
			:expanded-row-keys="expandedKeys"
			:show-expand-column="false"
			:row-selection="{
				selectedRowKeys: store.selected,
				preserveSelectedRowKeys: store.allMatching,
				onChange: ( keys: Array<string | number> ) => store.select( keys.map( Number ) ),
				getCheckboxProps: ( record: ArticleItem ) => ( {
					/* translators: %s: article title. */
					'aria-label': sprintf( __( 'Select “%s”', 'licensio-knowledge-base' ), record.title ),
				} ),
			}"
			:pagination="{
				current: store.query.page,
				pageSize: store.query.per_page,
				total: store.total,
				showSizeChanger: false,
				hideOnSinglePage: false,
			}"
			@change="onChange"
		>
			<template #emptyText>
				<div v-if="! store.loading && store.loaded && ! store.error" class="lkb-empty">
					<template v-if="store.isFiltered">
						<p>{{ __( 'No articles match these filters.', 'licensio-knowledge-base' ) }}</p>
						<a-button @click="store.clearFilters()">
							{{ __( 'Clear filters', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
					<template v-else>
						<p>{{ __( 'No articles yet.', 'licensio-knowledge-base' ) }}</p>
						<a-button type="primary" :href="settings.newUrl">
							{{ __( 'Create your first article', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</div>
			</template>

			<template #bodyCell="{ column: col, record }">
				<template v-if="'title' === col.key">
					<div class="lkb-title">
						<a v-if="record.edit_url" :href="record.edit_url" class="lkb-title__link">
							{{ record.title || __( '(no title)', 'licensio-knowledge-base' ) }}
						</a>
						<span v-else class="lkb-title__link">
							{{ record.title || __( '(no title)', 'licensio-knowledge-base' ) }}
						</span>

						<a-tag v-if="'publish' !== record.status" class="lkb-title__status">
							{{ statusLabel( record.status ) }}
						</a-tag>

						<a-tag v-if="record.has_password" class="lkb-title__status">
							{{ __( 'Password protected', 'licensio-knowledge-base' ) }}
						</a-tag>

						<a-tooltip
							v-if="record.lock"
							:title="/* translators: %s: name of the user editing the article. */ sprintf( __( '%s is editing', 'licensio-knowledge-base' ), record.lock.name )"
						>
							<span class="lkb-title__lock" tabindex="0">
								<LockOutlined aria-hidden="true" />
								{{ /* translators: %s: name of the user editing the article. */ sprintf( __( '%s is editing', 'licensio-knowledge-base' ), record.lock.name ) }}
							</span>
						</a-tooltip>
					</div>

					<div class="lkb-rowactions">
						<template v-if="! inTrash">
							<a v-if="record.edit_url" :href="record.edit_url">
								{{ __( 'Edit', 'licensio-knowledge-base' ) }}
							</a>
							<a-button
								type="link"
								size="small"
								class="lkb-rowactions__button"
								:disabled="!! record.lock || ! record.edit_url"
								:aria-expanded="store.quickEditId === record.id"
								:data-quick-edit="record.id"
								@click="toggleQuickEdit( record as ArticleItem )"
							>
								{{ __( 'Quick edit', 'licensio-knowledge-base' ) }}
							</a-button>
							<a v-if="record.view_url" :href="record.view_url">
								{{ 'publish' === record.status ? __( 'View', 'licensio-knowledge-base' ) : __( 'Preview', 'licensio-knowledge-base' ) }}
							</a>
							<a v-if="record.needs_attention || store.query.needs_attention" :href="feedbackUrl( record.id )">
								{{ __( 'View feedback', 'licensio-knowledge-base' ) }}
							</a>
							<ConfirmButton
								type="link"
								size="small"
								danger
								:disabled="!! record.lock"
								:title="confirmTitle( 'trash', {}, 1 )"
								:ok-text="__( 'Move to trash', 'licensio-knowledge-base' )"
								@confirm="performAction( 'trash', {}, [ record.id ] )"
							>
								{{ __( 'Trash', 'licensio-knowledge-base' ) }}
							</ConfirmButton>
						</template>
						<template v-else>
							<ConfirmButton
								type="link"
								size="small"
								:title="confirmTitle( 'restore', {}, 1 )"
								:ok-text="__( 'Restore', 'licensio-knowledge-base' )"
								@confirm="performAction( 'restore', {}, [ record.id ] )"
							>
								{{ __( 'Restore', 'licensio-knowledge-base' ) }}
							</ConfirmButton>
							<ConfirmButton
								type="link"
								size="small"
								danger
								:title="confirmTitle( 'delete', {}, 1 )"
								:ok-text="__( 'Delete permanently', 'licensio-knowledge-base' )"
								@confirm="performAction( 'delete', {}, [ record.id ] )"
							>
								{{ __( 'Delete permanently', 'licensio-knowledge-base' ) }}
							</ConfirmButton>
						</template>
					</div>
				</template>

				<template v-else-if="'author' === col.key">
					{{ record.author.name }}
				</template>

				<template v-else-if="'categories' === col.key || 'tags' === col.key">
					<span v-if="0 === record[ col.key ].length" class="lkb-muted">—</span>
					<span v-else>{{ record[ col.key ].map( ( term: { name: string } ) => term.name ).join( ', ' ) }}</span>
				</template>

				<template v-else-if="'rating' === col.key">
					<span v-if="null === record.rating.share" class="lkb-muted">—</span>
					<span
						v-else
						:class="{ 'lkb-rating--low': record.needs_attention }"
						:title="/* translators: 1: helpful votes, 2: not helpful votes. */ sprintf( __( '%1$d helpful, %2$d not helpful', 'licensio-knowledge-base' ), record.rating.up, record.rating.down )"
					>
						{{ record.rating.share }}%
						<span class="lkb-muted">({{ record.rating.up }}/{{ record.rating.down }})</span>
					</span>
				</template>

				<template v-else-if="'reading_time' === col.key">
					{{ /* translators: %d: reading time in minutes. */ record.reading_time ? sprintf( __( '%d min', 'licensio-knowledge-base' ), record.reading_time ) : '—' }}
				</template>

				<template v-else-if="'date' === col.key || 'modified' === col.key">
					{{ displayDate( record[ col.key ] ) }}
				</template>

				<template v-else>
					{{ record[ col.key as string ] }}
				</template>
			</template>

			<template #expandedRowRender="{ record }">
				<QuickEditRow
					:key="record.id"
					:item="record as ArticleItem"
					@close="closeQuickEdit( record.id )"
				/>
			</template>
		</a-table>
	</div>
</template>

<style scoped>
.lkb-tablecard {
	overflow: hidden;
	border: 1px solid var(--lkb-border-wp);
	border-radius: var(--lkb-radius);
	background: var(--lkb-surface);
	box-shadow: var(--lkb-shadow-sm);
}

.lkb-title {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}

.lkb-title__link {
	font-weight: 600;
}

.lkb-title__status {
	margin: 0;
}

.lkb-title__lock {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--lkb-warning);
	font-size: 12px;
}

.lkb-rowactions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0 10px;
	margin-top: 4px;
	font-size: 12px;
}

.lkb-rowactions :deep( .ant-btn-link ) {
	height: auto;
	padding: 0;
	font-size: 12px;
}

.lkb-muted {
	color: var(--lkb-ink-faint);
}

.lkb-rating--low {
	color: var(--lkb-danger, #b32d2e);
	font-weight: 600;
}

.lkb-empty {
	padding: 24px 0;
	color: var(--lkb-ink-muted);
}

:deep( .ant-table-tbody > tr.ant-table-row-selected > td ) {
	background: var(--lkb-brand-tint);
}

:deep( .ant-table-tbody > tr.ant-table-row-selected:hover > td ) {
	background: #e3f4ec;
}
</style>
