<script setup lang="ts">
import { lkbTheme } from '../styles/theme';
/**
 * The Tools screen.
 *
 * A tab per tool, on the shared admin shell: the Migration wizard, the search
 * index rebuild, the data maintenance jobs, and the duplicate cleanup. A later
 * tool joins by adding
 * a tab pane here and its keys to the server-side
 * `licensio_kb_tools_app_data` filter.
 *
 * @since 1.0.0
 */

import { config } from './api';
import { __ } from '../components/i18n';
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
		<div class="lkb-page licensio-kb-tools">
			<AppHeader :settings-url="chrome.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Tools', 'licensio-knowledge-base' ) }}
					</h1>
				</div>

				<a-tabs class="licensio-kb-tools__tabs">
					<a-tab-pane
						v-if="hasImportSources"
						key="migration"
						:tab="__( 'Migration', 'licensio-knowledge-base' )"
					>
						<MigrationWizard />
					</a-tab-pane>
					<a-tab-pane
						key="search-index"
						:tab="__( 'Search Index', 'licensio-knowledge-base' )"
					>
						<SearchIndex />
					</a-tab-pane>
					<a-tab-pane
						key="data-maintenance"
						:tab="
							__( 'Data Maintenance', 'licensio-knowledge-base' )
						"
					>
						<DataMaintenance />
					</a-tab-pane>
					<a-tab-pane
						key="duplicates"
						:tab="__( 'Duplicates', 'licensio-knowledge-base' )"
					>
						<Duplicates />
					</a-tab-pane>
				</a-tabs>
			</div>
		</div>
	</a-config-provider>
</template>

<style scoped>
.licensio-kb-tools__tabs {
	background: var( --lkb-surface, #fff );
	border: 1px solid var( --lkb-border-wp, #dcdcde );
	border-radius: 8px;
	padding: 16px;
}
</style>
