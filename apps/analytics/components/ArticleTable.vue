<script setup lang="ts">
/**
 * An article report table.
 *
 * The `metric` prop selects between the view columns and the usefulness
 * columns, so the most-viewed, most-useful and least-useful reports are one
 * component rather than three near-identical ones.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import { translate } from '../api';
import type { ArticleRow } from '../types';

const props = defineProps<{ rows: ArticleRow[]; metric: 'views' | 'usefulness' }>();

const columns = computed( () => {
	const title = { title: translate( 'Article' ), key: 'article' };

	if ( props.metric === 'views' ) {
		return [
			title,
			{ title: translate( 'Views' ), dataIndex: 'views', key: 'views', width: 120 },
			{
				title: translate( 'Unique visitors' ),
				dataIndex: 'visitors',
				key: 'visitors',
				width: 160,
			},
		];
	}

	return [
		title,
		{
			title: translate( 'Useful' ),
			dataIndex: 'usefulness',
			key: 'usefulness',
			width: 120,
		},
		{ title: translate( 'Yes' ), dataIndex: 'positive', key: 'positive', width: 90 },
		{ title: translate( 'No' ), dataIndex: 'negative', key: 'negative', width: 90 },
	];
} );
</script>

<template>
	<a-table
		:columns="columns"
		:data-source="rows"
		:row-key="( row: ArticleRow ) => row.article_id"
		:pagination="false"
		size="small"
	>
		<template #emptyText>
			{{ translate( 'There is nothing to report for this range yet.' ) }}
		</template>
		<template #bodyCell="{ column, record }">
			<template v-if="column.key === 'article'">
				<a v-if="record.edit_link" :href="record.edit_link">{{
					record.title
				}}</a>
				<span v-else>{{ record.title }}</span>
			</template>
			<template v-else-if="column.key === 'usefulness'">
				{{ record.usefulness }}%
			</template>
		</template>
	</a-table>
</template>
