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
		width: 160,
	},
	{
		title: __( 'Action', 'licensio-knowledge-base' ),
		key: 'action',
		width: 220,
	},
];
</script>

<template>
	<a-table
		:columns="columns"
		:data-source="rows"
		:row-key="( row: SearchRow ) => row.normalized_term"
		:pagination="false"
		:scroll="{ x: true }"
		size="small"
	>
		<template #emptyText>
			{{
				__(
					'No searches came back empty in this range.',
					'licensio-knowledge-base'
				)
			}}
		</template>
		<template #bodyCell="{ column, record }">
			<template v-if="column.key === 'action'">
				<a-button
					v-if="record.create_article_url"
					type="primary"
					size="small"
					:href="record.create_article_url"
				>
					{{
						__(
							'Create an article for this',
							'licensio-knowledge-base'
						)
					}}
				</a-button>
			</template>
		</template>
	</a-table>
</template>
