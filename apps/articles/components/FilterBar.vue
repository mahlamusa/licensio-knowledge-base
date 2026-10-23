<script setup lang="ts">
/**
 * The filters popover.
 *
 * Category (with its children), tag, author, date range, Uncategorised, No
 * tags and Needs attention. They combine with AND, apply as soon as they
 * change, and the trigger shows how many are set.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { FilterOutlined } from '@ant-design/icons-vue';
import TermSelect from '../../components/TermSelect.vue';
import { __, sprintf } from '../../components/i18n';
import { config } from '../api';
import { useArticlesStore } from '../store';
import AuthorSelect from './AuthorSelect.vue';
import type { TermValue } from '../../components/types/terms';

const store = useArticlesStore();
const lowRating = config().lowRating;

const dates = computed< [ string, string ] | undefined >( () =>
	store.query.after || store.query.before
		? [ store.query.after, store.query.before ]
		: undefined
);

/**
 * A single TermSelect value as a term ID.
 *
 * @since 1.0.0
 *
 * @param {unknown} value The picker value.
 *
 * @return {number} The ID, or 0.
 */
function termId( value: TermValue | TermValue[] | null ): number {
	return typeof value === 'number' ? value : 0;
}

/**
 * Apply a date range.
 *
 * @since 1.0.0
 *
 * @param {unknown} value The range, or null when cleared.
 *
 * @return {void}
 */
function onDates( value: unknown ): void {
	const range = Array.isArray( value ) ? value : [];

	store.setFilters( {
		after: typeof range[ 0 ] === 'string' ? range[ 0 ] : '',
		before: typeof range[ 1 ] === 'string' ? range[ 1 ] : '',
	} );
}

const attentionHelp = sprintf(
	/* translators: 1: helpful share threshold percentage, 2: minimum votes. */
	__(
		'Helpful share below %1$d%% with at least %2$d votes in the selected window.',
		'licensio-knowledge-base'
	),
	lowRating.threshold,
	lowRating.minVotes
);
</script>

<template>
	<a-popover trigger="click" placement="bottomLeft">
		<template #content>
			<div class="lkb-filters">
				<label class="lkb-filters__field">
					<span>{{ __( 'Category', 'licensio-knowledge-base' ) }}</span>
					<TermSelect
						:model-value="store.query.category || null"
						taxonomy="licensio_category"
						:aria-label="__( 'Filter by category', 'licensio-knowledge-base' )"
						:placeholder="__( 'Any category', 'licensio-knowledge-base' )"
						@update:model-value="( value ) => store.setFilters( { category: termId( value ) } )"
					/>
				</label>

				<label class="lkb-filters__field">
					<span>{{ __( 'Tag', 'licensio-knowledge-base' ) }}</span>
					<TermSelect
						:model-value="store.query.tag || null"
						taxonomy="licensio_tag"
						:aria-label="__( 'Filter by tag', 'licensio-knowledge-base' )"
						:placeholder="__( 'Any tag', 'licensio-knowledge-base' )"
						@update:model-value="( value ) => store.setFilters( { tag: termId( value ) } )"
					/>
				</label>

				<label class="lkb-filters__field">
					<span>{{ __( 'Author', 'licensio-knowledge-base' ) }}</span>
					<AuthorSelect
						:model-value="store.query.author"
						:aria-label="__( 'Filter by author', 'licensio-knowledge-base' )"
						@update:model-value="( value ) => store.setFilters( { author: value } )"
					/>
				</label>

				<div class="lkb-filters__field">
					<span id="lkb-filter-dates">{{ __( 'Published between', 'licensio-knowledge-base' ) }}</span>
					<a-range-picker
						:value="dates"
						value-format="YYYY-MM-DD"
						aria-labelledby="lkb-filter-dates"
						@change="onDates"
					/>
				</div>

				<a-checkbox
					:checked="store.query.uncategorised"
					@change="( event ) => store.setFilters( { uncategorised: event.target.checked } )"
				>
					{{ __( 'Uncategorised', 'licensio-knowledge-base' ) }}
				</a-checkbox>

				<a-checkbox
					:checked="store.query.untagged"
					@change="( event ) => store.setFilters( { untagged: event.target.checked } )"
				>
					{{ __( 'No tags', 'licensio-knowledge-base' ) }}
				</a-checkbox>

				<div>
					<a-checkbox
						:checked="store.query.needs_attention"
						@change="( event ) => store.setFilters( { needs_attention: event.target.checked } )"
					>
						{{ __( 'Needs attention', 'licensio-knowledge-base' ) }}
					</a-checkbox>
					<p class="lkb-filters__help">{{ attentionHelp }}</p>
				</div>

				<a-button
					size="small"
					:disabled="0 === store.activeFilterCount"
					@click="store.setFilters( { category: 0, tag: 0, author: 0, after: '', before: '', uncategorised: false, untagged: false, needs_attention: false } )"
				>
					{{ __( 'Clear filters', 'licensio-knowledge-base' ) }}
				</a-button>
			</div>
		</template>

		<a-badge :count="store.activeFilterCount" :offset="[ -4, 4 ]">
			<a-button>
				<template #icon><FilterOutlined /></template>
				{{ __( 'Filters', 'licensio-knowledge-base' ) }}
			</a-button>
		</a-badge>
	</a-popover>
</template>

<style scoped>
.lkb-filters {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 300px;
	max-width: calc(100vw - 48px);
}

.lkb-filters__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.lkb-filters__field > span {
	font-weight: 600;
}

.lkb-filters__help {
	margin: 2px 0 0 24px;
	color: var(--lkb-ink-muted);
	font-size: 12px;
}
</style>
