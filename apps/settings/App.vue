<script setup lang="ts">
/**
 * The settings screen.
 *
 * A category sidebar on the left, one group per entry, and that group's fields
 * on the right as label-and-control rows. A save is refused whole rather than
 * applied in part, a reset restores the registry defaults, and a guard stops the
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
const blocks = computed( () => store.categoryBlocks );

const activeGroupObject = computed( () =>
	groups.value.find( ( group ) => group.id === activeGroup.value ) ?? null
);

/**
 * The category label sitting above the page title.
 *
 * @since 1.0.0
 */
const activeCategoryLabel = computed( () => {
	const group = activeGroupObject.value;

	if ( ! group ) {
		return '';
	}

	return store.categories[ group.category ] ?? '';
} );

/**
 * Move to a group, keeping unsaved edits.
 *
 * Edits live in the draft rather than in the rendered controls, so switching
 * section never discards them; the dirty count in the footer stays accurate
 * across sections.
 *
 * @since 1.0.0
 *
 * @param {string} id The group id.
 *
 * @return {void}
 */
function selectGroup( id: string ): void {
	activeGroup.value = id;
}

/**
 * The number of unsaved edits sitting in one group.
 *
 * @since 1.0.0
 *
 * @param {string} id The group id.
 *
 * @return {number} How many of that group's fields are edited.
 */
function changedIn( id: string ): number {
	const group = groups.value.find( ( entry ) => entry.id === id );

	if ( ! group ) {
		return 0;
	}

	return group.fields.filter( ( field ) =>
		store.changedKeys.includes( field.key )
	).length;
}

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
	<div class="lkb-settings">
		<a-spin :spinning="store.loading || store.saving">
			<div v-if="store.loaded" class="lkb-settings__layout">
				<nav class="lkb-nav" :aria-label="translate( 'Settings sections' )">
					<template v-for="block in blocks" :key="block.id">
						<p class="lkb-nav__heading">{{ block.label }}</p>

						<ul class="lkb-nav__list">
							<li v-for="group in block.groups" :key="group.id">
								<button
									type="button"
									class="lkb-nav__item"
									:class="{
										'is-active': group.id === activeGroup,
										'is-dirty': changedIn( group.id ) > 0,
									}"
									:aria-current="group.id === activeGroup ? 'page' : undefined"
									@click="selectGroup( group.id )"
								>
									<span
										class="lkb-nav__icon dashicons"
										:class="'dashicons-' + group.icon"
										aria-hidden="true"
									/>
									<span class="lkb-nav__label">{{ group.label }}</span>
									<span class="lkb-nav__count">{{ group.count }}</span>
								</button>
							</li>
						</ul>
					</template>
				</nav>

				<div class="lkb-pane">
					<header class="lkb-pane__header">
						<p class="lkb-pane__eyebrow">{{ activeCategoryLabel }}</p>
						<h1 class="lkb-pane__title">{{ activeGroupObject?.label }}</h1>
					</header>

					<a-alert
						v-if="store.error"
						type="error"
						show-icon
						:message="store.error"
						class="lkb-pane__notice"
					/>

					<a-alert
						v-else-if="notice"
						type="success"
						show-icon
						:message="notice"
						class="lkb-pane__notice"
					/>

					<AppearancePreview
						v-if="activeGroupObject?.fields.some( ( field ) => field.preview )"
						:fields="activeGroupObject.fields"
						:draft="store.draft"
					/>

					<section v-if="activeGroupObject" class="lkb-card">
						<div class="lkb-card__head">
							<h2 class="lkb-card__title">{{ activeGroupObject.label }}</h2>
							<p class="lkb-card__description">
								{{ activeGroupObject.description }}
							</p>
						</div>

						<SettingField
							v-for="field in activeGroupObject.fields"
							:key="field.key"
							:field="field"
							:value="store.draft[ field.key ] ?? null"
							:error="store.fieldErrors[ field.key ] ?? ''"
							:changed="store.changedKeys.includes( field.key )"
							@update="update"
						/>
					</section>
				</div>
			</div>
		</a-spin>

		<footer class="lkb-bar" :class="{ 'is-dirty': store.dirty }">
			<p class="lkb-bar__status">
				<template v-if="store.dirty">
					{{ translate( 'Unsaved changes' ) }}
					<span class="lkb-bar__count">{{ changedCount }}</span>
				</template>
				<template v-else-if="store.loaded">
					{{ translate( 'All changes saved.' ) }}
				</template>
			</p>

			<div class="lkb-bar__actions">
				<a-button
					:disabled="! store.dirty || store.saving"
					@click="store.revert()"
				>
					{{ translate( 'Discard' ) }}
				</a-button>

				<a-button danger :disabled="store.saving" @click="confirmingReset = true">
					{{ translate( 'Reset all' ) }}
				</a-button>

				<a-button
					type="primary"
					:disabled="! store.dirty || store.saving"
					:loading="store.saving"
					@click="store.save()"
				>
					{{ translate( 'Save changes' ) }}
				</a-button>
			</div>
		</footer>

		<a-modal
			v-model:open="confirmingReset"
			:title="translate( 'Reset every setting?' )"
			:ok-text="translate( 'Reset everything' )"
			:cancel-text="translate( 'Cancel' )"
			ok-type="danger"
			@ok="confirmReset"
		>
			<p>
				{{
					translate(
						'Every setting goes back to its default. Stored articles, categories and feedback are untouched.'
					)
				}}
			</p>
		</a-modal>
	</div>
</template>

<style>
/*
 * The screen paints its own ground because wp-admin's is white and the layout
 * reads as a single flat sheet without it. Everything is scoped under
 * .lkb-settings so nothing leaks into the rest of wp-admin.
 */
.lkb-settings {
	--lkb-ink: #1d2327;
	--lkb-muted: #6b7280;
	--lkb-line: #e5e7eb;
	--lkb-ground: #f0f0f1;
	--lkb-accent: #3858e9;
	margin: 0 0 0 -20px;
	padding: 24px 24px 96px;
	background: var(--lkb-ground);
	min-height: calc(100vh - 32px);
	color: var(--lkb-ink);
}

.lkb-settings__layout {
	display: grid;
	grid-template-columns: 260px minmax(0, 1fr);
	gap: 24px;
	align-items: start;
	max-width: 1240px;
}

/* Sidebar */
.lkb-nav {
	background: #fff;
	border: 1px solid var(--lkb-line);
	border-radius: 8px;
	padding: 16px 12px;
	position: sticky;
	top: 46px;
}

.lkb-nav__heading {
	margin: 16px 8px 8px;
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--lkb-muted);
}

.lkb-nav__heading:first-child {
	margin-top: 0;
}

.lkb-nav__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.lkb-nav__item {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 9px 10px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var(--lkb-ink);
	font-size: 13px;
	line-height: 1.4;
	text-align: left;
	cursor: pointer;
}

.lkb-nav__item:hover {
	background: #f6f7f7;
}

.lkb-nav__item.is-active {
	background: #e8eaed;
	font-weight: 600;
}

.lkb-nav__item:focus-visible {
	outline: 2px solid var(--lkb-accent);
	outline-offset: -2px;
}

.lkb-nav__icon {
	font-size: 17px;
	width: 17px;
	height: 17px;
	color: var(--lkb-muted);
	flex: 0 0 auto;
}

.lkb-nav__item.is-active .lkb-nav__icon {
	color: var(--lkb-accent);
}

.lkb-nav__label {
	flex: 1 1 auto;
	min-width: 0;
}

.lkb-nav__count {
	flex: 0 0 auto;
	font-size: 12px;
	color: var(--lkb-muted);
	font-variant-numeric: tabular-nums;
}

/* A section with unsaved edits is marked, so switching away does not hide them. */
.lkb-nav__item.is-dirty .lkb-nav__count::after {
	content: '';
	display: inline-block;
	width: 6px;
	height: 6px;
	margin-left: 6px;
	border-radius: 50%;
	background: #d63638;
	vertical-align: middle;
}

/* Content pane */
.lkb-pane__header {
	margin: 0 0 20px;
}

.lkb-pane__eyebrow {
	margin: 0 0 2px;
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--lkb-muted);
}

.lkb-pane__title {
	margin: 0;
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 30px;
	font-weight: 400;
	font-style: italic;
	line-height: 1.2;
}

.lkb-pane__notice {
	margin-bottom: 16px;
}

/* Cards */
.lkb-card {
	background: #fff;
	border: 1px solid var(--lkb-line);
	border-radius: 8px;
	padding: 20px 24px 8px;
}

.lkb-card__head {
	padding-bottom: 16px;
	border-bottom: 1px solid var(--lkb-line);
}

.lkb-card__title {
	margin: 0 0 4px;
	font-size: 15px;
	font-weight: 600;
}

.lkb-card__description {
	margin: 0;
	color: var(--lkb-muted);
	font-size: 13px;
	line-height: 1.5;
}

/* Sticky action bar */
.lkb-bar {
	position: sticky;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin: 16px 0 0 -20px;
	padding: 12px 24px;
	background: #fff;
	border-top: 1px solid var(--lkb-line);
}

.lkb-bar__status {
	margin: 0;
	font-size: 13px;
	color: var(--lkb-muted);
}

.lkb-bar.is-dirty .lkb-bar__status {
	color: var(--lkb-ink);
	font-weight: 600;
}

.lkb-bar__count {
	display: inline-block;
	min-width: 20px;
	margin-left: 6px;
	padding: 1px 6px;
	border-radius: 10px;
	background: #d63638;
	color: #fff;
	font-size: 11px;
	text-align: center;
	font-variant-numeric: tabular-nums;
}

.lkb-bar__actions {
	display: flex;
	gap: 8px;
}

@media (max-width: 782px) {
	.lkb-settings__layout {
		grid-template-columns: minmax(0, 1fr);
	}

	.lkb-nav {
		position: static;
	}
}
</style>
