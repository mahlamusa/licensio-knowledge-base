<script setup lang="ts">
/**
 * The settings screen.
 *
 * One tab per schema group, a save that is refused whole rather than applied in
 * part, a reset that restores the registry defaults, and a guard that stops the
 * browser leaving with unsaved edits on the page.
 *
 * @since 1.0.0
 */

import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { translate } from './api';
import { useSettingsStore } from './store';
import AppearancePreview from './components/AppearancePreview.vue';
import SettingField from './components/SettingField.vue';
import type { SettingValue } from './types';

const store = useSettingsStore();
const activeGroup = ref( '' );
const confirmingReset = ref( false );

const groups = computed( () => store.schema );

const notice = computed( () => {
	if ( store.notice === 'saved' ) {
		return translate( 'Settings saved.' );
	}

	if ( store.notice === 'reset' ) {
		return translate( 'Every setting is back to its default.' );
	}

	return '';
} );

const changedCount = computed( () => store.changedKeys.length );

/**
 * Warn the browser that leaving now would discard unsaved edits.
 *
 * Browsers ignore the message and show their own wording, but the event has to
 * be cancelled for the prompt to appear at all.
 *
 * @since 1.0.0
 *
 * @param {BeforeUnloadEvent} event The unload event.
 *
 * @return {void}
 */
function guardUnload( event: BeforeUnloadEvent ): void {
	if ( ! store.dirty ) {
		return;
	}

	event.preventDefault();
	event.returnValue = '';
}

/**
 * Intercept a click on any link that would leave the screen.
 *
 * `beforeunload` covers closing the tab and typing a new address; this covers
 * clicking away to another admin screen, which is how an administrator actually
 * loses work here.
 *
 * @since 1.0.0
 *
 * @param {MouseEvent} event The click event.
 *
 * @return {void}
 */
function guardNavigation( event: MouseEvent ): void {
	if ( ! store.dirty || event.defaultPrevented ) {
		return;
	}

	const link = ( event.target as HTMLElement | null )?.closest?.( 'a[href]' );

	if ( ! ( link instanceof HTMLAnchorElement ) ) {
		return;
	}

	const href = link.getAttribute( 'href' ) ?? '';

	if ( href.startsWith( '#' ) || link.target === '_blank' ) {
		return;
	}

	// eslint-disable-next-line no-alert
	const leave = window.confirm(
		translate(
			'You have unsaved knowledge base settings. Leave this page and lose them?'
		)
	);

	if ( ! leave ) {
		event.preventDefault();
	}
}

/**
 * Apply an edit.
 *
 * @since 1.0.0
 *
 * @param {string}       key   The setting key.
 * @param {SettingValue} value The new value.
 *
 * @return {void}
 */
function update( key: string, value: SettingValue ): void {
	store.setValue( key, value );
}

/**
 * Reset every setting, once confirmed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once the reset has settled.
 */
async function confirmReset(): Promise< void > {
	confirmingReset.value = false;

	await store.reset();
}

onMounted( async () => {
	await store.load();

	activeGroup.value = groups.value.length > 0 ? groups.value[ 0 ].id : '';

	window.addEventListener( 'beforeunload', guardUnload );
	document.addEventListener( 'click', guardNavigation, true );
} );

onBeforeUnmount( () => {
	window.removeEventListener( 'beforeunload', guardUnload );
	document.removeEventListener( 'click', guardNavigation, true );
} );
</script>

<template>
	<div class="lisensio-kb-settings">
		<h1>{{ translate( 'Knowledge Base Settings' ) }}</h1>

		<a-alert
			v-if="store.error"
			type="error"
			show-icon
			:message="store.error"
			class="lisensio-kb-settings__notice"
		/>

		<a-alert
			v-else-if="notice"
			type="success"
			show-icon
			:message="notice"
			class="lisensio-kb-settings__notice"
		/>

		<a-alert
			v-if="store.dirty"
			type="warning"
			show-icon
			:message="
				translate( 'You have unsaved changes.' ) + ' (' + changedCount + ')'
			"
			class="lisensio-kb-settings__notice"
		/>

		<a-spin :spinning="store.loading || store.saving">
			<a-tabs v-if="store.loaded" v-model:active-key="activeGroup">
				<a-tab-pane
					v-for="group in groups"
					:key="group.id"
					:tab="group.label"
				>
					<p class="lisensio-kb-settings__description">
						{{ group.description }}
					</p>

					<a-form layout="vertical">
						<SettingField
							v-for="field in group.fields"
							:key="field.key"
							:field="field"
							:value="store.draft[ field.key ] ?? null"
							:error="store.fieldErrors[ field.key ] ?? ''"
							:changed="store.changedKeys.includes( field.key )"
							@update="update"
						/>
					</a-form>

					<AppearancePreview
						v-if="group.fields.some( ( field ) => field.preview )"
						:fields="group.fields"
						:draft="store.draft"
					/>
				</a-tab-pane>
			</a-tabs>
		</a-spin>

		<div class="lisensio-kb-settings__actions">
			<a-button
				type="primary"
				:disabled="! store.dirty || store.saving"
				:loading="store.saving"
				@click="store.save()"
			>
				{{ translate( 'Save changes' ) }}
			</a-button>

			<a-button :disabled="! store.dirty || store.saving" @click="store.revert()">
				{{ translate( 'Discard changes' ) }}
			</a-button>

			<a-button danger :disabled="store.saving" @click="confirmingReset = true">
				{{ translate( 'Reset to defaults' ) }}
			</a-button>
		</div>

		<a-modal
			v-model:open="confirmingReset"
			:title="translate( 'Reset every setting?' )"
			:ok-text="translate( 'Reset to defaults' )"
			:cancel-text="translate( 'Cancel' )"
			@ok="confirmReset"
		>
			<p>
				{{
					translate(
						'Every knowledge base setting goes back to the value the plugin ships with. Your articles, categories and feedback are not touched.'
					)
				}}
			</p>
		</a-modal>
	</div>
</template>

<style scoped>
.lisensio-kb-settings__notice {
	margin-block-end: 16px;
}

.lisensio-kb-settings__description {
	color: #646970;
	margin-block-start: 0;
	max-inline-size: 46rem;
}

.lisensio-kb-settings__actions {
	display: flex;
	gap: 8px;
	margin-block-start: 16px;
}
</style>
