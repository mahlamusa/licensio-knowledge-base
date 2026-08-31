<script setup lang="ts">
/**
 * The documentation-gap table.
 *
 * Every row is a search that returned nothing, and every row carries a
 * "create an article for this" action that opens a new article pre-filled with
 * the term. That action is the whole point of the report: the competitor
 * measures deflection and never acts on it.
 *
 * @since 1.0.0
 */

import { translate } from '../api';
import type { SearchRow } from '../types';

defineProps<{ rows: SearchRow[] }>();

const columns = [
	{ title: translate( 'Search term' ), dataIndex: 'term', key: 'term' },
	{
		title: translate( 'Times searched' ),
		dataIndex: 'searches',
		key: 'searches',
		width: 160,
	},
	{ title: translate( 'Action' ), key: 'action', width: 220 },
];
</script>

<template>
	<a-table
		:columns="columns"
		:data-source="rows"
		:row-key="( row: SearchRow ) => row.normalized_term"
		:pagination="false"
		size="small"
	>
		<template #emptyText>
			{{ translate( 'No searches came back empty in this range.' ) }}
		</template>
		<template #bodyCell="{ column, record }">
			<template v-if="column.key === 'action'">
				<a-button
					v-if="record.create_article_url"
					type="primary"
					size="small"
					:href="record.create_article_url"
				>
					{{ translate( 'Create an article for this' ) }}
				</a-button>
			</template>
		</template>
	</a-table>
</template>
