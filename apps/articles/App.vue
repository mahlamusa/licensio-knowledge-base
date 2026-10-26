<script setup lang="ts">
/**
 * Articles.
 *
 * The knowledge base article list: status pills with counts, title search,
 * the filters popover, the 7/30/90-day metrics window, the column picker,
 * the bulk bar and the table. Everything goes through the Pinia store, which
 * keeps the view in the URL.
 *
 * Titles, names and terms are rendered with text interpolation. There is no
 * `v-html` anywhere in this app.
 *
 * @since 1.0.0
 */

import {
	computed,
	onBeforeUnmount,
	onMounted,
	ref,
	useId,
	watch,
} from 'vue';
import { PlusOutlined, SearchOutlined } from '@ant-design/icons-vue';
import type { RadioChangeEvent } from 'ant-design-vue';
import { lkbTheme } from '../styles/theme';
import AppHeader from '../components/AppHeader.vue';
import { __, sprintf } from '../components/i18n';
import { config } from './api';
import { useArticlesStore } from './store';
import ArticleTable from './components/ArticleTable.vue';
import BulkBar from './components/BulkBar.vue';
import BulkProgress from './components/BulkProgress.vue';
import ColumnPicker from './components/ColumnPicker.vue';
import FilterBar from './components/FilterBar.vue';
import type { MetricsWindow, StatusFilter } from './types';

const store = useArticlesStore();
const settings = config();

const SEARCH_DELAY = 350;

// Names its radio group: radios that share a name are one tab stop, with
// the arrow keys moving between them.
const windowName = useId();

const search = ref( '' );
let searchTimer: ReturnType< typeof setTimeout > | undefined;

const pills = computed( () =>
	(
		[
			[ 'all', __( 'All', 'licensio-knowledge-base' ) ],
			[ 'publish', __( 'Published', 'licensio-knowledge-base' ) ],
			[ 'draft', __( 'Drafts', 'licensio-knowledge-base' ) ],
			[ 'pending', __( 'Pending', 'licensio-knowledge-base' ) ],
			[ 'private', __( 'Private', 'licensio-knowledge-base' ) ],
			[ 'future', __( 'Scheduled', 'licensio-knowledge-base' ) ],
			[ 'trash', __( 'Trash', 'licensio-knowledge-base' ) ],
		] as Array< [ StatusFilter, string ] >
	 ).map( ( [ value, label ] ) => ( {
		value,
		label,
		count: store.counts[ value ] ?? 0,
	} ) )
);

const windows = [
	{ value: 7, label: __( '7 days', 'licensio-knowledge-base' ) },
	{ value: 30, label: __( '30 days', 'licensio-knowledge-base' ) },
	{ value: 90, label: __( '90 days', 'licensio-knowledge-base' ) },
];

/**
 * Search after the user stops typing.
 *
 * @since 1.0.0
 *
 * @param {string} value The search text.
 *
 * @return {void}
 */
function onSearch( value: string ): void {
	search.value = value;
	clearTimeout( searchTimer );
	searchTimer = setTimeout( () => {
		if ( search.value.trim() !== store.query.search.trim() ) {
			store.setFilters( { search: search.value.trim() } );
		}
	}, SEARCH_DELAY );
}

// Keep the box in step when back/forward restores another search.
watch(
	() => store.query.search,
	( value ) => {
		if ( value.trim() !== search.value.trim() ) {
			search.value = value;
		}
	}
);

onMounted( async () => {
	await store.init();
	search.value = store.query.search;
} );

onBeforeUnmount( () => clearTimeout( searchTimer ) );
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page licensio-kb-articles">
			<AppHeader :settings-url="settings.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Articles', 'licensio-knowledge-base' ) }}
					</h1>
					<a-button type="primary" :href="settings.newUrl">
						<template #icon
							><PlusOutlined aria-hidden="true"
						/></template>
						{{ __( 'New article', 'licensio-knowledge-base' ) }}
					</a-button>
				</div>

				<a-alert
					v-if="store.error"
					type="error"
					show-icon
					:message="store.error"
					class="licensio-kb-articles__error"
				>
					<template #action>
						<a-button size="small" @click="store.load()">
							{{ __( 'Retry', 'licensio-knowledge-base' ) }}
						</a-button>
					</template>
				</a-alert>

				<div class="lkb-toolbar">
					<div
						class="lkb-pills"
						role="group"
						:aria-label="
							__( 'Filter by status', 'licensio-knowledge-base' )
						"
					>
						<button
							v-for="pill in pills"
							:key="pill.value"
							type="button"
							class="lkb-pill"
							:class="{
								'is-active': store.query.status === pill.value,
							}"
							:aria-pressed="store.query.status === pill.value"
							@click="store.setFilters( { status: pill.value } )"
						>
							{{
								sprintf(
									/* translators: 1: status name, 2: number of articles with that status. */
									__(
										'%1$s (%2$d)',
										'licensio-knowledge-base'
									),
									pill.label,
									pill.count
								)
							}}
						</button>
					</div>

					<div class="lkb-toolbar__controls">
						<a-input-search
							:value="search"
							class="lkb-toolbar__search"
							allow-clear
							:placeholder="
								__( 'Search titles', 'licensio-knowledge-base' )
							"
							:aria-label="
								__(
									'Search articles by title',
									'licensio-knowledge-base'
								)
							"
							@change="
								( event: Event ) =>
									onSearch(
										( event.target as HTMLInputElement )
											.value
									)
							"
							@search="onSearch"
						>
							<template #enterButton>
								<a-button
									:aria-label="
										__(
											'Search',
											'licensio-knowledge-base'
										)
									"
								>
									<template #icon
										><SearchOutlined
									/></template>
								</a-button>
							</template>
						</a-input-search>

						<FilterBar />

						<a-radio-group
							:value="store.query.window"
							:name="windowName"
							option-type="button"
							:options="windows"
							:aria-label="
								__(
									'Views and rating period',
									'licensio-knowledge-base'
								)
							"
							@change="
								( event: RadioChangeEvent ) =>
									store.setFilters( {
										window: Number(
											event.target.value
										) as MetricsWindow,
									} )
							"
						/>

						<ColumnPicker />
					</div>
				</div>

				<BulkBar />

				<a-skeleton
					v-if="! store.loaded && store.loading"
					active
					:paragraph="{ rows: 8 }"
				/>
				<ArticleTable v-else />

				<BulkProgress />
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.licensio-kb-articles__error {
	margin-bottom: var( --lkb-gap );
}

.lkb-toolbar {
	justify-content: space-between;
}

.lkb-toolbar__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.lkb-toolbar__search {
	width: 220px;
	max-width: 100%;
}

.lkb-pills {
	display: inline-flex;
	flex-wrap: wrap;
	padding: 3px;
	border: 1px solid var( --lkb-border-wp );
	border-radius: var( --lkb-radius );
	background: var( --lkb-surface );
	box-shadow: var( --lkb-shadow-sm );
}

.lkb-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var( --lkb-ink-wp-secondary );
	font-size: 13px;
	line-height: 1;
	white-space: nowrap;
	cursor: pointer;
	transition:
		background 0.12s ease,
		color 0.12s ease;
}

.lkb-pill:hover {
	background: var( --lkb-hover );
	color: var( --lkb-ink-wp );
}

.lkb-pill.is-active {
	background: var( --lkb-brand-tint );
	color: var( --lkb-accent );
	font-weight: 600;
}

.lkb-pill:focus-visible {
	outline: 2px solid var( --lkb-accent );
	outline-offset: -2px;
}
</style>
