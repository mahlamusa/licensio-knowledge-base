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
import type { ArticleRow } from '../types';
import { __ } from '../../components/i18n';

const props = defineProps< {
	rows: ArticleRow[];
	metric: 'views' | 'usefulness';
} >();

const columns = computed( () => {
	const title = {
		title: __( 'Article', 'licensio-knowledge-base' ),
		key: 'article',
	};

	if ( props.metric === 'views' ) {
		return [
			title,
			{
				title: __( 'Views', 'licensio-knowledge-base' ),
				dataIndex: 'views',
				key: 'views',
				width: 120,
			},
			{
				title: __( 'Unique visitors', 'licensio-knowledge-base' ),
				dataIndex: 'visitors',
				key: 'visitors',
				width: 160,
			},
		];
	}

	return [
		title,
		{
			title: __( 'Useful', 'licensio-knowledge-base' ),
			dataIndex: 'usefulness',
			key: 'usefulness',
			width: 120,
		},
		{
			title: __( 'Yes', 'licensio-knowledge-base' ),
			dataIndex: 'positive',
			key: 'positive',
			width: 90,
		},
		{
			title: __( 'No', 'licensio-knowledge-base' ),
			dataIndex: 'negative',
			key: 'negative',
			width: 90,
		},
	];
} );
</script>

<template>
	<a-table
		:columns="columns"
		:data-source="rows"
		:row-key="( row: ArticleRow ) => row.article_id"
		:pagination="false"
		:scroll="{ x: true }"
		size="small"
	>
		<template #emptyText>
			{{
				__(
					'There is nothing to report for this range yet.',
					'licensio-knowledge-base'
				)
			}}
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
