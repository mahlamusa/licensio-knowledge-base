<script setup lang="ts">
/**
 * The tag table.
 *
 * Lists the page of tags with server-side sort and paging, row selection for
 * the bulk bar. Edit hands the tag to the page, which loads it into the left
 * form; Quick edit opens the row's name, slug and description in place. Save
 * sends without asking, and the row keeps
 * the user's input when the server refuses it, as it does a slug another tag
 * already uses. The slug the server settled on (made from the name if the
 * slug was cleared) is shown back once saved.
 *
 * Focus follows the row: Quick edit moves it to the Name field, and Cancel,
 * Escape or a save puts it back on the row's Quick edit button.
 *
 * A page opened for one tag (`?term=`) loads that tag into the left form,
 * as core's link to a tag's edit screen does.
 *
 * A tag's article count links to the Articles app filtered by that tag.
 *
 * Names and descriptions are rendered as text, never as HTML. The table
 * shows a description as plain text, without its markup and with entities
 * decoded, as the core tag list reads; the edit field keeps the stored value.
 * A description is sent on save only when it was changed, so a stored one
 * round-trips untouched.
 *
 * A save closes its row only if that row is still the one open: the user may
 * have cancelled and opened another tag while the request was on its way.
 *
 * @since 1.0.0
 */

import { computed, nextTick, reactive, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { __, sprintf } from '../../components/i18n';
import { settings } from '../api';
import { useTagsStore } from '../store';
import type { TagFields, TagItem, TagOrder, TagOrderBy } from '../types';

const emit = defineEmits< {
	( event: 'edit', tag: TagItem ): void;
	( event: 'quick-edit', id: number ): void;
} >();

const store = useTagsStore();
const { articlesUrl } = settings();

const editingId = ref< number | null >( null );
const draft = reactive( { name: '', slug: '', description: '' } );

// Whether the tag the page was opened for has been opened for editing.
let openedFromUrl = false;

/**
 * A stored description as the plain text a reader sees.
 *
 * The markup is parsed into a detached document, which runs no scripts and
 * loads nothing, and only its text is kept. Script and style elements are
 * dropped first: their contents are code, not text a reader sees.
 *
 * @since 1.0.0
 *
 * @param {string} html The stored description.
 *
 * @return {string} The text, with entities decoded and whitespace collapsed.
 */
function plainText( html: string ): string {
	const body = new DOMParser().parseFromString( html, 'text/html' ).body;

	body.querySelectorAll( 'script, style' ).forEach( ( element ) =>
		element.remove()
	);

	const text = body.textContent ?? '';

	return text.replace( /\s+/g, ' ' ).trim();
}

// Parsed once per page of tags, not on every render of a row.
const descriptions = computed(
	() =>
		new Map(
			store.items.map( ( tag ) => [
				tag.id,
				plainText( tag.description ),
			] )
		)
);

/**
 * The Ant Design sort order of a column.
 *
 * @since 1.0.0
 *
 * @param {TagOrderBy} key The column.
 *
 * @return {string|null} `ascend`, `descend`, or null when not sorted by it.
 */
function sortOrder( key: TagOrderBy ): 'ascend' | 'descend' | null {
	if ( store.orderby !== key ) {
		return null;
	}

	return 'asc' === store.order ? 'ascend' : 'descend';
}

const columns = computed( () => [
	{
		title: __( 'Name', 'licensio-knowledge-base' ),
		key: 'name',
		sorter: true,
		sortOrder: sortOrder( 'name' ),
		sortDirections: [ 'ascend', 'descend', 'ascend' ],
	},
	{ title: __( 'Slug', 'licensio-knowledge-base' ), key: 'slug' },
	{
		title: __( 'Description', 'licensio-knowledge-base' ),
		key: 'description',
	},
	{
		title: __( 'Articles', 'licensio-knowledge-base' ),
		key: 'count',
		width: 110,
		sorter: true,
		sortOrder: sortOrder( 'count' ),
		sortDirections: [ 'descend', 'ascend', 'descend' ],
	},
	{
		title: __( 'Actions', 'licensio-knowledge-base' ),
		key: 'actions',
		width: 200,
	},
] );

/**
 * Pass a page or sort change to the store.
 *
 * @since 1.0.0
 *
 * @param {Object}  pagination           The table's pagination.
 * @param {number}  [pagination.current] The page now shown.
 * @param {unknown} filters              The table's filters, unused.
 * @param {Object}  sorter               The table's sorter.
 *
 * @return {void}
 */
function onChange(
	pagination: { current?: number },
	filters: unknown,
	sorter: { columnKey?: unknown; order?: string | null } | unknown
): void {
	const sort = ( Array.isArray( sorter ) ? sorter[ 0 ] : sorter ) as {
		columnKey?: unknown;
		order?: string | null;
	};
	const orderby: TagOrderBy = 'count' === sort?.columnKey ? 'count' : 'name';
	const order: TagOrder = 'descend' === sort?.order ? 'desc' : 'asc';

	store.view( pagination.current ?? 1, orderby, order );
}

/**
 * Focus a row: its Name field while it is open, otherwise its Quick edit
 * button.
 *
 * @since 1.0.0
 *
 * @param {number} id The tag.
 *
 * @return {void}
 */
function focusRow( id: number ): void {
	nextTick( () => {
		const target =
			editingId.value === id
				? 'input:not([type="checkbox"])'
				: '[data-lkb-quick]';

		document
			.querySelector< HTMLElement >(
				`.lkb-tablecard [data-row-key="${ id }"] ${ target }`
			)
			?.focus();
	} );
}

/**
 * Open a row's fields for editing (Quick edit).
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag The row.
 *
 * @return {void}
 */
function startEdit( tag: TagItem ): void {
	editingId.value = tag.id;
	emit( 'quick-edit', tag.id );
	draft.name = tag.name;
	draft.slug = tag.slug;
	draft.description = tag.description;
	focusRow( tag.id );
}

/**
 * Close the open row without saving.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function cancelEdit(): void {
	const id = editingId.value;

	editingId.value = null;

	if ( null !== id ) {
		focusRow( id );
	}
}

/**
 * The fields of the open row that differ from the saved tag.
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag The saved tag.
 *
 * @return {TagFields} The changed fields.
 */
function changes( tag: TagItem ): TagFields {
	const fields: TagFields = {};

	if ( draft.name.trim() !== tag.name ) {
		fields.name = draft.name.trim();
	}

	if ( draft.slug.trim() !== tag.slug ) {
		fields.slug = draft.slug.trim();
	}

	if ( draft.description !== tag.description ) {
		fields.description = draft.description;
	}

	return fields;
}

/**
 * Save the open row.
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag The saved tag.
 *
 * @return {Promise<void>} Resolves once saved, or once the error is shown.
 */
async function save( tag: TagItem ): Promise< void > {
	try {
		const updated = await store.update( tag.id, changes( tag ) );

		// Leave another row the user opened while this one was saving.
		if ( editingId.value === tag.id ) {
			editingId.value = null;
			focusRow( tag.id );
		}

		message.success(
			sprintf(
				/* translators: %s: the tag's slug. */
				__( 'Tag saved. Its slug is “%s”.', 'licensio-knowledge-base' ),
				updated.slug
			)
		);
	} catch {
		// The store shows the error; the row keeps the input.
	}
}

/**
 * Whether the open row can be saved.
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag The saved tag.
 *
 * @return {boolean} True when it has a name and a change.
 */
function canSave( tag: TagItem ): boolean {
	return '' !== draft.name.trim() && Object.keys( changes( tag ) ).length > 0;
}

/**
 * Hand a tag to the page's left form, closing any Quick edit first so a tag
 * is open in one place only.
 *
 * @since 1.0.0
 *
 * @param {TagItem} tag The tag.
 *
 * @return {void}
 */
function edit( tag: TagItem ): void {
	editingId.value = null;
	emit( 'edit', tag );
}

// When the open row leaves the list (paged, filtered, deleted, merged or
// converted away), close it so the other rows can be edited again. Then a
// page opened for one tag (`?term=`) loads that tag into the form, once.
watch(
	() => store.items,
	( items ) => {
		if (
			null !== editingId.value &&
			! items.some( ( item ) => item.id === editingId.value )
		) {
			cancelEdit();
		}

		if (
			! openedFromUrl &&
			1 === store.include.length &&
			1 === items.length &&
			items[ 0 ].id === store.include[ 0 ] &&
			null === editingId.value
		) {
			openedFromUrl = true;
			edit( items[ 0 ] );
		}
	}
);
</script>

<template>
	<div class="lkb-tablecard" @keydown.esc="cancelEdit">
		<a-table
			row-key="id"
			:columns="columns"
			:data-source="store.items"
			:loading="store.loading"
			:scroll="{ x: true }"
			:row-selection="{
				selectedRowKeys: store.selected,
				onChange: ( keys: unknown[] ) =>
					( store.selected = keys as number[] ),
				getCheckboxProps: ( record: TagItem ) => ( {
					/* translators: %s: the tag's name. */
					'aria-label': sprintf(
						__( 'Select “%s”', 'licensio-knowledge-base' ),
						record.name
					),
				} ),
			}"
			:pagination="{
				current: store.page,
				pageSize: store.perPage,
				total: store.total,
				showSizeChanger: false,
			}"
			:locale="{
				emptyText:
					store.unused || store.search || store.include.length
						? __(
								'No tags match these filters.',
								'licensio-knowledge-base'
						  )
						: __(
								'No tags yet. Add your first tag above.',
								'licensio-knowledge-base'
						  ),
			}"
			@change="onChange"
		>
			<template #bodyCell="{ column, record }">
				<template v-if="'name' === column.key">
					<a-input
						v-if="editingId === record.id"
						v-model:value="draft.name"
						:aria-label="__( 'Name', 'licensio-knowledge-base' )"
					/>
					<strong v-else>{{ record.name }}</strong>
				</template>

				<template v-else-if="'slug' === column.key">
					<a-input
						v-if="editingId === record.id"
						v-model:value="draft.slug"
						:aria-label="__( 'Slug', 'licensio-knowledge-base' )"
					/>
					<code v-else class="lkb-tags__slug">{{ record.slug }}</code>
				</template>

				<template v-else-if="'description' === column.key">
					<a-textarea
						v-if="editingId === record.id"
						v-model:value="draft.description"
						:auto-size="{ minRows: 1, maxRows: 4 }"
						:aria-label="
							__( 'Description', 'licensio-knowledge-base' )
						"
					/>
					<span v-else class="lkb-tags__description">{{
						descriptions.get( record.id ) || '—'
					}}</span>
				</template>

				<template v-else-if="'count' === column.key">
					<a
						:href="`${ articlesUrl }&tag=${ record.id }`"
						:aria-label="
							sprintf(
								/* translators: %d: number of articles. */
								__( 'Articles: %d', 'licensio-knowledge-base' ),
								record.count
							)
						"
					>
						{{ record.count }}
					</a>
				</template>

				<template v-else-if="'actions' === column.key">
					<div
						v-if="editingId === record.id"
						class="lkb-tags__actions"
					>
						<a-button
							type="primary"
							size="small"
							:disabled="! canSave( record as TagItem )"
							:loading="store.saving"
							@click="save( record as TagItem )"
						>
							{{ __( 'Save', 'licensio-knowledge-base' ) }}
						</a-button>
						<a-button size="small" @click="cancelEdit">
							{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
						</a-button>
					</div>
					<div v-else class="lkb-tags__actions">
						<a-button
							size="small"
							data-lkb-edit
							:disabled="null !== editingId"
							:aria-label="
								sprintf(
									/* translators: %s: the tag's name. */
									__(
										'Edit “%s”',
										'licensio-knowledge-base'
									),
									record.name
								)
							"
							@click="edit( record as TagItem )"
						>
							{{ __( 'Edit', 'licensio-knowledge-base' ) }}
						</a-button>
						<a-button
							size="small"
							data-lkb-quick
							:disabled="null !== editingId"
							:aria-label="
								sprintf(
									/* translators: %s: the tag's name. */
									__(
										'Quick edit “%s”',
										'licensio-knowledge-base'
									),
									record.name
								)
							"
							@click="startEdit( record as TagItem )"
						>
							{{ __( 'Quick edit', 'licensio-knowledge-base' ) }}
						</a-button>
					</div>
				</template>
			</template>
		</a-table>
	</div>
</template>

<style scoped>
.lkb-tablecard {
	overflow: hidden;
	border: 1px solid var( --lkb-border-wp );
	border-radius: var( --lkb-radius );
	background: var( --lkb-surface );
	box-shadow: var( --lkb-shadow-sm );
}

/*
 * Ant lets cell text break anywhere, which on a phone splits a slug letter by
 * letter. Slugs break only at their hyphens, and the table scrolls inside
 * itself when they do not fit.
 */
.lkb-tags__slug {
	overflow-wrap: normal;
	word-break: normal;
}

.lkb-tags__description {
	color: var( --lkb-ink-muted );
}

.lkb-tags__actions {
	display: flex;
	gap: 8px;
}

:deep( .ant-table-tbody > tr.ant-table-row-selected > td ) {
	background: var( --lkb-brand-tint );
}
</style>
