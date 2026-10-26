<script setup lang="ts">
import { lkbTheme } from '../styles/theme';
/**
 * Feedback inbox.
 *
 * Lists every vote with its article, comment and date; filters by vote and
 * status; marks rows reviewed or actioned one at a time or in bulk; and links
 * each row to the article editor. Every action goes through the Pinia store,
 * so nothing here reloads the page. Every status change is sent only after
 * the user confirmed it in a Popconfirm.
 *
 * Comments are rendered with text interpolation. There is deliberately no
 * `v-html` anywhere in this file: a reader-supplied comment must never be
 * parsed as markup, even though the REST layer already strips tags on input.
 *
 * @since 1.0.0
 */

import { computed, onMounted, ref, watch } from 'vue';
import { articleFilter, settings } from './api';
import { useFeedbackStore } from './store';
import AppHeader from '../components/AppHeader.vue';
import ConfirmButton from '../components/ConfirmButton.vue';
import { __, _n, sprintf } from '../components/i18n';
import { usePopoverFocus } from '../components/popover-focus';
import type { FeedbackItem, FeedbackStatus } from './types';

const store = useFeedbackStore();
const chrome = settings();

const voteOptions = [
	{ value: 0, label: __( 'All votes', 'licensio-knowledge-base' ) },
	{ value: 1, label: __( 'Helpful', 'licensio-knowledge-base' ) },
	{ value: -1, label: __( 'Not helpful', 'licensio-knowledge-base' ) },
];

/**
 * The status filter, as a row of pills carrying their own counts.
 *
 * A triager's first question is how much is waiting, so the count belongs on
 * the control that answers it rather than in a total off to one side. The dot
 * colour is the same one the status wears in the table, so the filter and the
 * rows it produces read as the same vocabulary.
 */
const statusFilters = computed( () => [
	{
		value: '' as const,
		label: __( 'All', 'licensio-knowledge-base' ),
		key: 'all',
		tone: 'all',
	},
	{
		value: 'new' as const,
		label: __( 'New', 'licensio-knowledge-base' ),
		key: 'new',
		tone: 'new',
	},
	{
		value: 'reviewed' as const,
		label: __( 'Reviewed', 'licensio-knowledge-base' ),
		key: 'reviewed',
		tone: 'reviewed',
	},
	{
		value: 'actioned' as const,
		label: __( 'Actioned', 'licensio-knowledge-base' ),
		key: 'actioned',
		tone: 'actioned',
	},
].map( ( filter ) => ( {
	...filter,
	count: store.counts[ filter.key ] ?? 0,
} ) ) );

const columns = [
	{
		title: __( 'Article', 'licensio-knowledge-base' ),
		dataIndex: 'articleName',
		key: 'article',
	},
	{
		title: __( 'Vote', 'licensio-knowledge-base' ),
		dataIndex: 'vote',
		key: 'vote',
		width: 120,
	},
	{
		title: __( 'Comment', 'licensio-knowledge-base' ),
		dataIndex: 'comment',
		key: 'comment',
	},
	{
		title: __( 'Date', 'licensio-knowledge-base' ),
		dataIndex: 'createdAt',
		key: 'createdAt',
		width: 180,
	},
	{
		title: __( 'Status', 'licensio-knowledge-base' ),
		dataIndex: 'status',
		key: 'status',
		width: 220,
	},
];

/**
 * Whether a row is a positive vote.
 *
 * @since 1.0.0
 *
 * @param {FeedbackItem} item The row.
 *
 * @return {boolean} True for a helpful vote.
 */
function isHelpful( item: FeedbackItem ): boolean {
	return item.vote > 0;
}

/**
 * The article the inbox is filtered to, from the `article_id` the Articles
 * app links here with. 0 when it shows every article.
 */
const articleId = ref( articleFilter() );

/** The filtered article's title, once a row of it has loaded. */
const articleName = computed(
	() =>
		store.items.find( ( item ) => item.articleId === articleId.value )
			?.articleName ?? ''
);

/** The notice naming the article the inbox is filtered to. */
const articleNotice = computed( () =>
	articleName.value
		? sprintf(
				/* translators: %s: article title. */
				__( 'Filtered to article: %s', 'licensio-knowledge-base' ),
				articleName.value
		  )
		: sprintf(
				/* translators: %d: article ID. */
				__( 'Filtered to article #%d', 'licensio-knowledge-base' ),
				articleId.value
		  )
);

/**
 * Show every article's feedback again.
 *
 * Drops `article_id` from the URL, which is where the list request reads it.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once the first page is loaded.
 */
async function clearArticle(): Promise< void > {
	const url = new URL( window.location.href );

	url.searchParams.delete( 'article_id' );
	window.history.replaceState( null, '', url.toString() );
	articleId.value = 0;

	await store.filter( {} );
}

/** A row's status the user picked and has not confirmed yet. */
const pendingStatus = ref< { id: number; status: FeedbackStatus } | null >(
	null
);

const {
	open: statusConfirmOpen,
	overlayClass: statusConfirmClass,
	setOpen: setStatusConfirmOpen,
} = usePopoverFocus();

// Escape closes the confirmation through `setOpen()` alone, without an
// `openChange`, so the picked status is dropped however it was closed.
watch( statusConfirmOpen, ( open ) => {
	if ( ! open ) {
		pendingStatus.value = null;
	}
} );

/**
 * Ask before a row's new status is saved.
 *
 * @since 1.0.0
 *
 * @param {number}         id     The feedback row.
 * @param {FeedbackStatus} status The status picked.
 *
 * @return {void}
 */
function pickStatus( id: number, status: FeedbackStatus ): void {
	pendingStatus.value = { id, status };
	setStatusConfirmOpen( true );
}

/**
 * Close the status confirmation, dropping the picked status unless saved.
 *
 * @since 1.0.0
 *
 * @param {boolean} open Whether the confirmation should stay open.
 *
 * @return {void}
 */
function onStatusConfirmOpen( open: boolean ): void {
	if ( open ) {
		return;
	}

	setStatusConfirmOpen( false );
	pendingStatus.value = null;
}

/**
 * Save the picked status, once confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once saved or failed.
 */
async function confirmStatus(): Promise< void > {
	const pending = pendingStatus.value;

	onStatusConfirmOpen( false );

	if ( pending ) {
		await store.setStatus( pending.id, pending.status );
	}
}

onMounted( () => {
	store.load();
} );
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page licensio-kb-inbox">
			<AppHeader :settings-url="chrome.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Feedback', 'licensio-knowledge-base' ) }}
					</h1>
				</div>

				<a-alert
					v-if="store.error"
					type="error"
					show-icon
					:message="store.error"
					class="licensio-kb-inbox__error"
				/>

				<a-alert
					v-if="articleId > 0"
					type="info"
					show-icon
					closable
					:message="articleNotice"
					class="licensio-kb-inbox__article"
					@close="clearArticle"
				>
					<template #action>
						<a-button size="small" @click="clearArticle">
							{{ __( 'Show all articles', 'licensio-knowledge-base' ) }}
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
							v-for="filter in statusFilters"
							:key="filter.key"
							type="button"
							class="lkb-pill"
							:class="[
								'lkb-pill--' + filter.tone,
								{ 'is-active': store.status === filter.value },
							]"
							:aria-pressed="store.status === filter.value"
							@click="store.filter( { status: filter.value } )"
						>
							<span v-if="'all' !== filter.tone" class="lkb-pill__dot" aria-hidden="true" />
							{{ filter.label }} ({{ filter.count }})
						</button>
					</div>

					<a-select
						:value="store.vote"
						style="width: 170px"
						:aria-label="__( 'Filter by vote', 'licensio-knowledge-base' )"
						:options="voteOptions"
						@change="( value ) => store.filter( { vote: Number( value ) } )"
					/>
				</div>

				<!--
					The bulk bar always occupies the same row, but it says what it is
					for when nothing is selected instead of showing two dead buttons.
					A disabled control that is disabled almost all the time reads as
					broken rather than as conditional.
				-->
				<div class="lkb-bulk" :class="{ 'is-armed': store.selected.length > 0 }">
					<template v-if="store.selected.length">
						<span class="lkb-bulk__count">
							{{
								sprintf(
									/* translators: %d: number of selected feedback entries. */
									_n( '%d selected', '%d selected', store.selected.length, 'licensio-knowledge-base' ),
									store.selected.length
								)
							}}
						</span>

						<div class="lkb-bulk__actions">
							<ConfirmButton
								size="small"
								:loading="store.saving"
								:title="
									sprintf(
										/* translators: %d: number of selected feedback items. */
										_n( 'Mark %d feedback item as reviewed?', 'Mark %d feedback items as reviewed?', store.selected.length, 'licensio-knowledge-base' ),
										store.selected.length
									)
								"
								:ok-text="__( 'Mark reviewed', 'licensio-knowledge-base' )"
								@confirm="store.bulkSetStatus( 'reviewed' )"
							>
								{{ __( 'Mark reviewed', 'licensio-knowledge-base' ) }}
							</ConfirmButton>
							<ConfirmButton
								size="small"
								:loading="store.saving"
								:title="
									sprintf(
										/* translators: %d: number of selected feedback items. */
										_n( 'Mark %d feedback item as actioned?', 'Mark %d feedback items as actioned?', store.selected.length, 'licensio-knowledge-base' ),
										store.selected.length
									)
								"
								:ok-text="__( 'Mark actioned', 'licensio-knowledge-base' )"
								@confirm="store.bulkSetStatus( 'actioned' )"
							>
								{{ __( 'Mark actioned', 'licensio-knowledge-base' ) }}
							</ConfirmButton>
						</div>
					</template>

					<span v-else class="lkb-bulk__hint">
						{{
							__(
								'Select feedback below to perform bulk actions',
								'licensio-knowledge-base'
							)
						}}
					</span>
				</div>

				<div class="lkb-tablecard">
			<a-table
				row-key="id"
				:columns="columns"
				:data-source="store.items"
				:loading="store.loading"
				:row-selection="{
					selectedRowKeys: store.selected,
					onChange: ( keys ) => store.select( keys as number[] ),
				}"
				:pagination="{
					current: store.page,
					pageSize: store.perPage,
					total: store.total,
					showSizeChanger: false,
					onChange: ( page: number ) => store.goToPage( page ),
				}"
			>
				<template #bodyCell="{ column, record }">
					<template v-if="'article' === column.key">
						<a v-if="record.editUrl" :href="record.editUrl">
							{{ record.articleName || __( '(untitled)', 'licensio-knowledge-base' ) }}
						</a>
						<span v-else>{{ record.articleName || __( '(untitled)', 'licensio-knowledge-base' ) }}</span>
					</template>

					<template v-else-if="'vote' === column.key">
						<a-tag :color="isHelpful( record ) ? 'green' : 'red'">
							{{ isHelpful( record )
								? __( 'Helpful', 'licensio-knowledge-base' )
								: __( 'Not helpful', 'licensio-knowledge-base' ) }}
						</a-tag>
					</template>

					<template v-else-if="'comment' === column.key">
						<span class="licensio-kb-inbox__comment">{{
							record.comment || '—'
						}}</span>
					</template>

					<template v-else-if="'status' === column.key">
						<a-popconfirm
							:open="statusConfirmOpen && pendingStatus?.id === record.id"
							:title="__( 'Change the status of this feedback?', 'licensio-knowledge-base' )"
							:ok-text="__( 'Change status', 'licensio-knowledge-base' )"
							:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
							:overlay-class-name="statusConfirmClass"
							@open-change="onStatusConfirmOpen"
							@confirm="confirmStatus"
						>
							<a-select
								:value="pendingStatus?.id === record.id ? pendingStatus.status : record.status"
								style="width: 160px"
								:disabled="store.saving"
								:options="[
									{ value: 'new', label: __( 'New', 'licensio-knowledge-base' ) },
									{ value: 'reviewed', label: __( 'Reviewed', 'licensio-knowledge-base' ) },
									{ value: 'actioned', label: __( 'Actioned', 'licensio-knowledge-base' ) },
								]"
								@change="
									( value ) =>
										pickStatus( record.id, value as FeedbackStatus )
								"
							/>
						</a-popconfirm>
					</template>
				</template>
			</a-table>
				</div>
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.licensio-kb-inbox__error,
.licensio-kb-inbox__article {
	margin-bottom: var(--lkb-gap);
}

/* ── Filter row ──────────────────────────────────────────── */

.lkb-toolbar {
	justify-content: space-between;
}

.lkb-pills {
	display: inline-flex;
	padding: 3px;
	border: 1px solid var(--lkb-border-wp);
	border-radius: var(--lkb-radius);
	background: var(--lkb-surface);
	box-shadow: var(--lkb-shadow-sm);
}

.lkb-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var(--lkb-ink-wp-secondary);
	font-size: 13px;
	line-height: 1;
	white-space: nowrap;
	cursor: pointer;
	transition: background 0.12s ease, color 0.12s ease;
}

.lkb-pill:hover {
	background: var(--lkb-hover);
	color: var(--lkb-ink-wp);
}

.lkb-pill.is-active {
	background: var(--lkb-brand-tint);
	color: var(--lkb-accent);
	font-weight: 600;
}

.lkb-pill:focus-visible {
	outline: 2px solid var(--lkb-accent);
	outline-offset: -2px;
}

/*
 * The dot repeats the colour the status wears in the table, so the filter and
 * the rows it produces are visibly the same vocabulary.
 */
.lkb-pill__dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background: currentColor;
}

.lkb-pill--new .lkb-pill__dot {
	background: var(--lkb-info);
}

.lkb-pill--reviewed .lkb-pill__dot {
	background: var(--lkb-warning);
}

.lkb-pill--actioned .lkb-pill__dot {
	background: var(--lkb-brand);
}

/* ── Table ───────────────────────────────────────────────── */

.lkb-tablecard {
	overflow: hidden;
	border: 1px solid var(--lkb-border-wp);
	border-radius: var(--lkb-radius);
	background: var(--lkb-surface);
	box-shadow: var(--lkb-shadow-sm);
}

.licensio-kb-inbox__comment {
	color: var(--lkb-ink-muted);
}

/*
 * A selected row.
 *
 * Ant derives this from the primary, and the accent green is dark enough that
 * the derived tint came out a muddy grey-green that read as "disabled" rather
 * than "chosen". Set explicitly to the same tint the active filter pill uses,
 * so selection looks like the rest of the screen's selected state.
 */
:deep( .ant-table-tbody > tr.ant-table-row-selected > td ) {
	background: var(--lkb-brand-tint);
}

:deep( .ant-table-tbody > tr.ant-table-row-selected:hover > td ) {
	background: #e3f4ec;
}
</style>
