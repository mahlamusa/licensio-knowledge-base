<script setup lang="ts">
/**
 * The top-searches table.
 *
 * @since 1.0.0
 */

import type { SearchRow } from '../types';
import { __ } from '../../components/i18n';

defineProps< { rows: SearchRow[] } >();

const columns = [
	{
		title: __( 'Search term', 'licensio-knowledge-base' ),
		dataIndex: 'term',
		key: 'term',
	},
	{
		title: __( 'Times searched', 'licensio-knowledge-base' ),
		dataIndex: 'searches',
		key: 'searches',
		width: 150,
	},
	{
		title: __( 'Empty results', 'licensio-knowledge-base' ),
		dataIndex: 'zero_results',
		key: 'zero_results',
		width: 150,
	},
	{
		title: __( 'Average results', 'licensio-knowledge-base' ),
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
			{{
				__(
					'Nothing was searched for in this range.',
					'licensio-knowledge-base'
				)
			}}
		</template>
	</a-table>
</template>
