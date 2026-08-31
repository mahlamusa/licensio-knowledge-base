<script setup lang="ts">
/**
 * The top-searches table.
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
		width: 150,
	},
	{
		title: translate( 'Empty results' ),
		dataIndex: 'zero_results',
		key: 'zero_results',
		width: 150,
	},
	{
		title: translate( 'Average results' ),
		dataIndex: 'average_results',
		key: 'average_results',
		width: 160,
	},
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
			{{ translate( 'Nothing was searched for in this range.' ) }}
		</template>
	</a-table>
</template>
