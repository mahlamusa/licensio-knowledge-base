<script setup lang="ts">
/**
 * The bulk action bar.
 *
 * Arms when rows are selected. It offers "Select all N matching" once the
 * whole page is selected and more articles match, and says when every
 * matching article is selected across pages. Then an action chooser,
 * the action's own argument (a status, terms or an author) and an Apply
 * button that asks before anything is sent, naming the action and the count.
 * Replace with no terms chosen removes every category or tag, and its
 * confirmation says so.
 *
 * Ant Design puts an `aria-label` on a select's wrapper, not on the combobox
 * a screen reader announces, so the plain selects are named by visually
 * hidden labels instead.
 *
 * @since 1.0.0
 */

import { computed, ref, watch } from 'vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import TermSelect from '../../components/TermSelect.vue';
import { __, _n, sprintf } from '../../components/i18n';
import { config } from '../api';
import { confirmTitle, performAction } from '../actions';
import { useArticlesStore } from '../store';
import AuthorSelect from './AuthorSelect.vue';
import type { TermValue } from '../../components/types/terms';
import type { BulkAction, BulkArgs, TermsMode } from '../types';

type Choice =
	| 'trash'
	| 'restore'
	| 'delete'
	| 'status'
	| 'cat_add'
	| 'cat_remove'
	| 'cat_replace'
	| 'tag_add'
	| 'tag_remove'
	| 'tag_replace'
	| 'author'
	| 'comments_open'
	| 'comments_close';

const store = useArticlesStore();
const can = config().can;

const choice = ref< Choice | undefined >( undefined );
const status = ref< BulkArgs[ 'status' ] >( undefined );
const terms = ref< TermValue[] >( [] );
const author = ref( 0 );

const inTrash = computed( () => 'trash' === store.query.status );

const choices = computed( () =>
	inTrash.value
		? [
				{
					value: 'restore',
					label: __( 'Restore', 'licensio-knowledge-base' ),
				},
				{
					value: 'delete',
					label: __(
						'Delete permanently',
						'licensio-knowledge-base'
					),
				},
			]
		: [
				{
					value: 'trash',
					label: __( 'Move to trash', 'licensio-knowledge-base' ),
				},
				{
					value: 'status',
					label: __( 'Change status', 'licensio-knowledge-base' ),
				},
				{
					value: 'cat_add',
					label: __( 'Add categories', 'licensio-knowledge-base' ),
				},
				{
					value: 'cat_remove',
					label: __( 'Remove categories', 'licensio-knowledge-base' ),
				},
				{
					value: 'cat_replace',
					label: __(
						'Replace categories',
						'licensio-knowledge-base'
					),
				},
				{
					value: 'tag_add',
					label: __( 'Add tags', 'licensio-knowledge-base' ),
				},
				{
					value: 'tag_remove',
					label: __( 'Remove tags', 'licensio-knowledge-base' ),
				},
				{
					value: 'tag_replace',
					label: __( 'Replace tags', 'licensio-knowledge-base' ),
				},
				...( can.editOthers
					? [
							{
								value: 'author',
								label: __(
									'Change author',
									'licensio-knowledge-base'
								),
							},
						]
					: [] ),
				{
					value: 'comments_open',
					label: __( 'Open comments', 'licensio-knowledge-base' ),
				},
				{
					value: 'comments_close',
					label: __( 'Close comments', 'licensio-knowledge-base' ),
				},
			]
);

// Publishing and making private need the publish capability.
const statusOptions = [
	{ value: 'publish', label: __( 'Published', 'licensio-knowledge-base' ) },
	{ value: 'draft', label: __( 'Draft', 'licensio-knowledge-base' ) },
	{
		value: 'pending',
		label: __( 'Pending review', 'licensio-knowledge-base' ),
	},
	{ value: 'private', label: __( 'Private', 'licensio-knowledge-base' ) },
].filter(
	( option ) =>
		can.publish || ! [ 'publish', 'private' ].includes( option.value )
);

const taxonomy = computed( () =>
	choice.value?.startsWith( 'tag_' ) ? 'licensio_tag' : 'licensio_category'
);

const isTerms = computed(
	() =>
		!! choice.value &&
		( choice.value.startsWith( 'cat_' ) ||
			choice.value.startsWith( 'tag_' ) )
);

/**
 * The chosen action as the request's action and args.
 */
const request = computed< { action: BulkAction; args: BulkArgs } | null >(
	() => {
		switch ( choice.value ) {
			case 'trash':
			case 'restore':
			case 'delete':
				return { action: choice.value, args: {} };
			case 'status':
				return status.value
					? { action: 'status', args: { status: status.value } }
					: null;
			case 'author':
				return author.value > 0
					? { action: 'author', args: { author: author.value } }
					: null;
			case 'comments_open':
				return { action: 'comments', args: { comment_status: 'open' } };
			case 'comments_close':
				return {
					action: 'comments',
					args: { comment_status: 'closed' },
				};
			case undefined:
				return null;
			default: {
				const ids = terms.value.filter(
					( value ): value is number => typeof value === 'number'
				);
				const mode = choice.value.split( '_' )[ 1 ] as TermsMode;

				// Replace with nothing clears the terms; add and remove need some.
				if ( 0 === ids.length && 'replace' !== mode ) {
					return null;
				}

				return {
					action: 'terms',
					args: {
						taxonomy: taxonomy.value,
						mode,
						term_ids: ids,
					},
				};
			}
		}
	}
);

const count = computed( () => store.selected.length );

/** Whether the selection is every matching article, across pages. */
const allSelected = computed(
	() => store.allMatching && count.value === store.total
);

const pageFullySelected = computed( () => {
	const selected = new Set( store.selected );

	return (
		store.items.length > 0 &&
		store.items.every( ( item ) => selected.has( item.id ) )
	);
} );

const canSelectAll = computed(
	() =>
		! store.allMatching &&
		pageFullySelected.value &&
		store.total > store.items.length
);

const title = computed( () =>
	request.value
		? confirmTitle( request.value.action, request.value.args, count.value )
		: ''
);

/**
 * Run the chosen action over the selection.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once the run is done.
 */
async function apply(): Promise< void > {
	if ( ! request.value ) {
		return;
	}

	const { action, args } = request.value;

	await performAction( action, args, [ ...store.selected ] );

	choice.value = undefined;
}

watch( choice, () => {
	terms.value = [];
	status.value = undefined;
	author.value = 0;
} );

watch( inTrash, () => {
	choice.value = undefined;
} );
</script>

<template>
	<div class="lkb-bulk" :class="{ 'is-armed': count > 0 }">
		<template v-if="count > 0">
			<span class="lkb-bulk__count">
				{{
					allSelected
						? sprintf(
								/* translators: %d: number of matching articles, all of them selected. */
								_n(
									'All %d matching article is selected',
									'All %d matching articles are selected',
									count,
									'licensio-knowledge-base'
								),
								count
							)
						: sprintf(
								/* translators: %d: number of selected articles. */
								_n(
									'%d selected',
									'%d selected',
									count,
									'licensio-knowledge-base'
								),
								count
							)
				}}
			</span>

			<a-button
				v-if="canSelectAll"
				type="link"
				size="small"
				:loading="store.selecting"
				@click="store.selectAllMatching()"
			>
				{{
					sprintf(
						/* translators: %d: number of matching articles. */
						_n(
							'Select all %d matching',
							'Select all %d matching',
							store.total,
							'licensio-knowledge-base'
						),
						store.total
					)
				}}
			</a-button>

			<a-button type="link" size="small" @click="store.clearSelection()">
				{{ __( 'Clear selection', 'licensio-knowledge-base' ) }}
			</a-button>

			<span
				v-if="store.truncated"
				class="lkb-bulk__warning"
				role="status"
			>
				{{
					__(
						'Only the first 10,000 matching articles were selected.',
						'licensio-knowledge-base'
					)
				}}
			</span>

			<div class="lkb-bulk__actions">
				<label for="lkb-bulk-action" class="screen-reader-text">
					{{ __( 'Bulk action', 'licensio-knowledge-base' ) }}
				</label>
				<a-select
					id="lkb-bulk-action"
					v-model:value="choice"
					size="small"
					class="lkb-bulk__choice"
					:options="choices"
					:placeholder="
						__( 'Bulk action', 'licensio-knowledge-base' )
					"
				/>

				<label
					v-if="'status' === choice"
					for="lkb-bulk-status"
					class="screen-reader-text"
				>
					{{ __( 'New status', 'licensio-knowledge-base' ) }}
				</label>
				<a-select
					v-if="'status' === choice"
					id="lkb-bulk-status"
					v-model:value="status"
					size="small"
					class="lkb-bulk__arg"
					:options="statusOptions"
					:placeholder="__( 'New status', 'licensio-knowledge-base' )"
				/>

				<TermSelect
					v-if="isTerms"
					:key="taxonomy"
					v-model="terms"
					class="lkb-bulk__arg"
					:taxonomy="taxonomy"
					multiple
					:aria-label="
						'licensio_tag' === taxonomy
							? __(
									'Tags for the bulk action',
									'licensio-knowledge-base'
								)
							: __(
									'Categories for the bulk action',
									'licensio-knowledge-base'
								)
					"
				/>

				<AuthorSelect
					v-if="'author' === choice"
					v-model="author"
					class="lkb-bulk__arg"
					:placeholder="__( 'New author', 'licensio-knowledge-base' )"
					:aria-label="__( 'New author', 'licensio-knowledge-base' )"
				/>

				<ConfirmButton
					size="small"
					type="primary"
					:title="title"
					:ok-text="__( 'Apply', 'licensio-knowledge-base' )"
					:danger="'delete' === choice"
					:disabled="! request || store.bulk.running"
					:loading="store.bulk.running"
					@confirm="void apply()"
				>
					{{ __( 'Apply', 'licensio-knowledge-base' ) }}
				</ConfirmButton>
			</div>
		</template>

		<span v-else class="lkb-bulk__hint">
			{{
				__(
					'Select articles below to perform bulk actions',
					'licensio-knowledge-base'
				)
			}}
		</span>
	</div>
</template>

<style scoped>
.lkb-bulk {
	gap: 8px var( --lkb-gap );
}

.lkb-bulk__warning {
	color: var( --lkb-warning );
	font-size: 13px;
}

.lkb-bulk__actions {
	align-items: center;
	margin-left: auto;
}

.lkb-bulk__choice {
	width: 190px;
}

.lkb-bulk__arg {
	width: 220px;
}
</style>
