<script setup lang="ts">
import { lkbTheme } from '../styles/theme';
/**
 * Feedback inbox.
 *
 * Lists every vote with its article, comment and date; filters by vote and
 * status; marks rows reviewed or actioned one at a time or in bulk; and links
 * each row to the article editor. Every action goes through the Pinia store,
 * so nothing here reloads the page.
 *
 * Comments are rendered with text interpolation. There is deliberately no
 * `v-html` anywhere in this file: a reader-supplied comment must never be
 * parsed as markup, even though the REST layer already strips tags on input.
 *
 * @since 1.0.0
 */

import { onMounted } from 'vue';
import { useFeedbackStore } from './store';
import type { FeedbackItem, FeedbackStatus } from './types';

const store = useFeedbackStore();

const voteOptions = [
	{ value: 0, label: 'All votes' },
	{ value: 1, label: 'Helpful' },
	{ value: -1, label: 'Not helpful' },
];

const statusOptions: { value: FeedbackStatus | ''; label: string }[] = [
	{ value: '', label: 'All statuses' },
	{ value: 'new', label: 'New' },
	{ value: 'reviewed', label: 'Reviewed' },
	{ value: 'actioned', label: 'Actioned' },
];

const columns = [
	{ title: 'Article', dataIndex: 'articleName', key: 'article' },
	{ title: 'Vote', dataIndex: 'vote', key: 'vote', width: 120 },
	{ title: 'Comment', dataIndex: 'comment', key: 'comment' },
	{ title: 'Date', dataIndex: 'createdAt', key: 'createdAt', width: 180 },
	{ title: 'Status', dataIndex: 'status', key: 'status', width: 220 },
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

onMounted( () => {
	store.load();
} );
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lisensio-kb-inbox">
			<a-alert
				v-if="store.error"
				type="error"
				show-icon
				:message="store.error"
				class="lisensio-kb-inbox__error"
			/>

			<a-space wrap class="lisensio-kb-inbox__filters">
				<a-select
					:value="store.vote"
					style="width: 180px"
					aria-label="Filter by vote"
					:options="voteOptions"
					@change="( value ) => store.filter( { vote: Number( value ) } )"
				/>
				<a-select
					:value="store.status"
					style="width: 180px"
					aria-label="Filter by status"
					:options="statusOptions"
					@change="( value ) => store.filter( { status: value } )"
				/>

				<a-button
					:disabled="! store.selected.length || store.saving"
					@click="store.bulkSetStatus( 'reviewed' )"
				>
					Mark reviewed
				</a-button>
				<a-button
					:disabled="! store.selected.length || store.saving"
					@click="store.bulkSetStatus( 'actioned' )"
				>
					Mark actioned
				</a-button>

				<span class="lisensio-kb-inbox__count">
					{{ store.total }} entries
				</span>
			</a-space>

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
							{{ record.articleName || '(untitled)' }}
						</a>
						<span v-else>{{ record.articleName || '(untitled)' }}</span>
					</template>

					<template v-else-if="'vote' === column.key">
						<a-tag :color="isHelpful( record ) ? 'green' : 'red'">
							{{ isHelpful( record ) ? 'Helpful' : 'Not helpful' }}
						</a-tag>
					</template>

					<template v-else-if="'comment' === column.key">
						<span class="lisensio-kb-inbox__comment">{{
							record.comment || '—'
						}}</span>
					</template>

					<template v-else-if="'status' === column.key">
						<a-select
							:value="record.status"
							style="width: 160px"
							:disabled="store.saving"
							:options="[
								{ value: 'new', label: 'New' },
								{ value: 'reviewed', label: 'Reviewed' },
								{ value: 'actioned', label: 'Actioned' },
							]"
							@change="
								( value ) =>
									store.setStatus( record.id, value as FeedbackStatus )
							"
						/>
					</template>
				</template>
			</a-table>
		</div>
	</a-config-provider>
</template>

<style scoped>
.lisensio-kb-inbox__filters {
	margin-bottom: 1rem;
}

.lisensio-kb-inbox__error {
	margin-bottom: 1rem;
}

.lisensio-kb-inbox__comment {
	white-space: pre-wrap;
	word-break: break-word;
}

.lisensio-kb-inbox__count {
	color: rgba( 0, 0, 0, 0.55 );
}
</style>
