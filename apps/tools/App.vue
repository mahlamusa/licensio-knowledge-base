<script setup lang="ts">
import { lkbTheme } from '../styles/theme';
/**
 * The Tools screen.
 *
 * A tab per tool, on the shared admin shell: the Migration wizard, the search
 * index rebuild, the data maintenance jobs, and the duplicate cleanup. A later
 * tool joins by adding
 * a tab pane here and its keys to the server-side
 * `lisensio_kb_tools_app_data` filter.
 *
 * @since 1.0.0
 */

import { config } from './api';
import AppHeader from '../components/AppHeader.vue';
import DataMaintenance from './components/DataMaintenance.vue';
import Duplicates from './components/Duplicates.vue';
import MigrationWizard from './components/MigrationWizard.vue';
import SearchIndex from './components/SearchIndex.vue';

const chrome = config();
const hasImportSources = chrome.wizard?.available === true;
</script>

<template>
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page lisensio-kb-tools">
			<AppHeader :settings-url="chrome.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">{{ 'Tools' }}</h1>
				</div>

				<a-tabs class="lisensio-kb-tools__tabs">
					<a-tab-pane v-if="hasImportSources" key="migration" :tab="'Migration'">
						<MigrationWizard />
					</a-tab-pane>
					<a-tab-pane key="search-index" :tab="'Search Index'">
						<SearchIndex />
					</a-tab-pane>
					<a-tab-pane key="data-maintenance" :tab="'Data Maintenance'">
						<DataMaintenance />
					</a-tab-pane>
					<a-tab-pane key="duplicates" :tab="'Duplicates'">
						<Duplicates />
					</a-tab-pane>
				</a-tabs>
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.lisensio-kb-tools__tabs {
	background: var( --lkb-surface, #fff );
	border: 1px solid var( --lkb-border-wp, #dcdcde );
	border-radius: 8px;
	padding: 16px;
}
</style>
