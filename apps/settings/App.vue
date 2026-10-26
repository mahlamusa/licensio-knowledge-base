<script setup lang="ts">
import { lkbTheme } from '../styles/theme';
/**
 * The settings screen.
 *
 * A category sidebar on the left, one group per entry, and that group's fields
 * on the right as label-and-control rows. A save runs on click and is refused
 * whole rather than applied in part; discarding edits and a reset, which
 * restores the registry defaults, ask first; and a guard stops the browser
 * leaving with unsaved edits on the page.
 *
 * @since 1.0.0
 */

import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { config } from './api';
import { __ } from '../components/i18n';
import { useSettingsStore } from './store';
import AppHeader from '../components/AppHeader.vue';
import ConfirmButton from '../components/ConfirmButton.vue';
import AppearancePreview from './components/AppearancePreview.vue';
import SettingField from './components/SettingField.vue';
import type { SettingValue } from './types';

const store = useSettingsStore();
const activeGroup = ref( '' );

const groups = computed( () => store.schema );

const activeGroupObject = computed(
	() =>
		groups.value.find( ( group ) => group.id === activeGroup.value ) ?? null
);

const chrome = config();

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
		return __( 'Settings saved.', 'licensio-knowledge-base' );
	}

	if ( store.notice === 'reset' ) {
		return __(
			'Every setting is back to its default.',
			'licensio-knowledge-base'
		);
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
		__(
			'You have unsaved knowledge base settings. Leave this page and lose them?',
			'licensio-knowledge-base'
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
 * A failed reset hands its error to the confirmation dialog, which stays
 * open and shows it, and clears the page alert behind the dialog, so
 * screen readers announce the error once.
 *
 * @since 1.0.0
 *
 * @return {Promise<string>} Resolves once the reset has settled, with the
 *                           error message, or an empty string on success.
 */
async function confirmReset(): Promise< string > {
	if ( await store.reset() ) {
		return '';
	}

	const message = store.error;

	store.error = '';

	return message;
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
	<a-config-provider :theme="lkbTheme">
		<div class="lkb-page lkb-settings">
			<AppHeader :settings-url="chrome.settingsUrl" />

			<div id="lkb-main" class="lkb-page__body lkb-measure">
				<div class="lkb-page__head">
					<h1 class="lkb-page__title">
						{{ __( 'Settings', 'licensio-knowledge-base' ) }}
					</h1>

					<a-button
						v-if="chrome.archiveUrl"
						size="small"
						:href="chrome.archiveUrl"
						target="_blank"
						rel="noreferrer noopener"
					>
						{{
							__(
								'View documentation',
								'licensio-knowledge-base'
							)
						}}
					</a-button>
				</div>

				<a-spin :spinning="store.loading || store.saving">
					<div v-if="store.loaded" class="lkb-settings__layout">
						<nav
							class="lkb-nav"
							:aria-label="
								__(
									'Settings sections',
									'licensio-knowledge-base'
								)
							"
						>
							<ul class="lkb-nav__list">
								<li v-for="group in groups" :key="group.id">
									<button
										type="button"
										class="lkb-nav__item"
										:class="{
											'is-active':
												group.id === activeGroup,
											'is-dirty':
												changedIn( group.id ) > 0,
										}"
										:aria-current="
											group.id === activeGroup
												? 'page'
												: undefined
										"
										@click="selectGroup( group.id )"
									>
										<span class="lkb-nav__icon-wrap">
											<span
												class="lkb-nav__icon dashicons"
												:class="
													'dashicons-' + group.icon
												"
												aria-hidden="true"
											/>
										</span>
										<span class="lkb-nav__label">{{
											group.label
										}}</span>
									</button>
								</li>
							</ul>
						</nav>

						<div class="lkb-pane">
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
								v-if="
									activeGroupObject?.fields.some(
										( field ) => field.preview
									)
								"
								:fields="activeGroupObject.fields"
								:draft="store.draft"
							/>

							<section v-if="activeGroupObject" class="lkb-card">
								<div class="lkb-card__head">
									<h2 class="lkb-card__title">
										{{ activeGroupObject.label }}
									</h2>
									<p class="lkb-card__description">
										{{ activeGroupObject.description }}
									</p>
								</div>

								<div class="lkb-card__body">
									<SettingField
										v-for="field in activeGroupObject.fields"
										:key="field.key"
										:field="field"
										:value="
											store.draft[ field.key ] ?? null
										"
										:error="
											store.fieldErrors[ field.key ] ?? ''
										"
										:changed="
											store.changedKeys.includes(
												field.key
											)
										"
										@update="update"
									/>
								</div>
							</section>
						</div>
					</div>
				</a-spin>
			</div>

			<footer class="lkb-bar" :class="{ 'is-dirty': store.dirty }">
				<p class="lkb-bar__status">
					<template v-if="store.dirty">
						{{ __( 'Unsaved changes', 'licensio-knowledge-base' ) }}
						<span class="lkb-bar__count">{{ changedCount }}</span>
					</template>
					<template v-else-if="store.loaded">
						{{
							__(
								'All changes saved.',
								'licensio-knowledge-base'
							)
						}}
					</template>
				</p>

				<div class="lkb-bar__actions">
					<ConfirmButton
						danger
						:disabled="! store.dirty || store.saving"
						:title="
							__(
								'Discard your unsaved changes?',
								'licensio-knowledge-base'
							)
						"
						:ok-text="__( 'Discard', 'licensio-knowledge-base' )"
						@confirm="store.revert()"
					>
						{{ __( 'Discard', 'licensio-knowledge-base' ) }}
					</ConfirmButton>

					<ConfirmButton
						danger
						:disabled="store.saving"
						:title="
							__(
								'Reset every setting?',
								'licensio-knowledge-base'
							)
						"
						:description="
							__(
								'Every setting goes back to its default. Stored articles, categories and feedback are untouched.',
								'licensio-knowledge-base'
							)
						"
						:ok-text="
							__( 'Reset everything', 'licensio-knowledge-base' )
						"
						@confirm="confirmReset"
					>
						{{ __( 'Reset all', 'licensio-knowledge-base' ) }}
					</ConfirmButton>

					<a-button
						type="primary"
						:disabled="! store.dirty || store.saving"
						:loading="store.saving"
						@click="store.save()"
					>
						{{ __( 'Save changes', 'licensio-knowledge-base' ) }}
					</a-button>
				</div>
			</footer>
		</div>
	</a-config-provider>
</template>

<style>
/*
 * The screen paints its own ground because wp-admin's is white and the layout
 * reads as a single flat sheet without it. Everything is scoped under
 * .lkb-settings so nothing leaks into the rest of wp-admin.
 */
/*
 * The five custom properties that were declared here are gone. They shadowed
 * the shared tokens with WordPress admin values - `--lkb-accent` was
 * `#3858e9`, WordPress blue - so this screen quietly ignored the plugin's own
 * palette. Everything now comes from `apps/styles/tokens.css`.
 */
.lkb-settings {
	/* Shell (ground, measure, centring) comes from `.lkb-page` in
	   apps/styles/admin.css; only settings-specific rules live here. */
	font-size: 13px;
}

.lkb-settings__layout {
	display: grid;
	grid-template-columns: 200px minmax( 0, 1fr );
	gap: var( --lkb-gap-lg );
	align-items: start;
}

/* ── Sidebar ─────────────────────────────────────────────── */

.lkb-nav {
	position: sticky;
	top: 102px;
	padding: 4px;
	border: 1px solid var( --lkb-border-wp );
	border-radius: var( --lkb-radius );
	background: var( --lkb-surface );
	box-shadow: var( --lkb-shadow-sm );
}

.lkb-nav__list {
	display: flex;
	flex-direction: column;
	gap: 1px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.lkb-nav__item {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 9px 12px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var( --lkb-ink-wp-secondary );
	font-size: 13px;
	line-height: 1;
	text-align: left;
	cursor: pointer;
	transition:
		background 0.12s ease,
		color 0.12s ease;
}

.lkb-nav__item:hover {
	background: var( --lkb-hover );
	color: var( --lkb-ink-wp );
}

/*
 * The active entry is carried by a tinted ground and a filled icon badge
 * rather than by weight alone, so the current section is findable in a glance
 * down the rail rather than by reading it.
 */
.lkb-nav__item.is-active {
	background: var( --lkb-brand-tint );
	color: var( --lkb-accent );
	font-weight: 600;
}

.lkb-nav__item:focus-visible {
	outline: 2px solid var( --lkb-accent );
	outline-offset: -2px;
}

.lkb-nav__icon-wrap {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 5px;
	transition:
		background 0.12s ease,
		color 0.12s ease;
}

.lkb-nav__item.is-active .lkb-nav__icon-wrap {
	background: var( --lkb-accent );
	color: #fff;
}

.lkb-nav__icon {
	width: 15px;
	height: 15px;
	font-size: 15px;
}

.lkb-nav__item:not( .is-active ) .lkb-nav__icon {
	opacity: 0.55;
}

.lkb-nav__label {
	flex: 1 1 auto;
	min-width: 0;
}

/*
 * A section holding unsaved edits is marked, so switching away does not hide
 * them. The dot is the only thing on the row besides the label, so it reads
 * as "something here" rather than as a count.
 */
.lkb-nav__item.is-dirty .lkb-nav__label::after {
	content: '';
	display: inline-block;
	width: 6px;
	height: 6px;
	margin-left: 6px;
	border-radius: 50%;
	background: var( --lkb-error );
	vertical-align: middle;
}

/* ── Content pane ────────────────────────────────────────── */

.lkb-pane__notice {
	margin-bottom: var( --lkb-gap );
}

/*
 * Section cards.
 *
 * The head is a tinted strip carrying the section name in small caps and one
 * line saying what the section governs. The name is set small and spaced
 * rather than large: it labels a group of rows, and a 20px heading over a
 * 13px row reads as a page title over a page.
 */
.lkb-card {
	overflow: hidden;
	border: 1px solid var( --lkb-border-wp );
	border-radius: var( --lkb-radius );
	background: var( --lkb-surface );
	box-shadow: var( --lkb-shadow-sm );
}

.lkb-card + .lkb-card {
	margin-top: var( --lkb-gap );
}

.lkb-card__head {
	padding: var( --lkb-gap ) var( --lkb-gap-lg );
	border-bottom: 1px solid var( --lkb-border-light );
	background: var( --lkb-sunken );
}

.lkb-card__title {
	margin: 0;
	color: var( --lkb-ink-wp );
	font-size: 13px;
	font-weight: 700;
	letter-spacing: 0.04em;
	line-height: 1.3;
	text-transform: uppercase;
}

.lkb-card__description {
	margin: 4px 0 0;
	color: var( --lkb-ink-wp-muted );
	font-size: 12.5px;
	font-weight: 400;
	letter-spacing: 0;
	line-height: 1.5;
	text-transform: none;
}

.lkb-card__body {
	padding: 4px var( --lkb-gap-lg ) 12px;
}

/*
 * Sticky action bar.
 *
 * It spans the ground rather than the content gutters, so the save controls
 * are always in the same place regardless of how far the pane scrolls. The
 * bar is a child of `.lkb-page`, which already carries the -20px that cancels
 * `#wpcontent`'s padding; repeating it here pulled the bar a second 20px left
 * and cut the first characters of its status line off the screen.
 */
.lkb-bar {
	position: sticky;
	bottom: 0;
	z-index: 20;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var( --lkb-gap );
	max-width: 1600px;
	margin: 0 auto;
	padding: 12px var( --lkb-gap-lg );
	background: var( --lkb-surface );
	border-top: 1px solid var( --lkb-border-wp );
	box-shadow: 0 -2px 8px rgba( 0, 0, 0, 0.06 );
}

.lkb-bar__status {
	margin: 0;
	font-size: 13px;
	color: var( --lkb-ink-muted );
}

.lkb-bar.is-dirty .lkb-bar__status {
	color: var( --lkb-ink );
	font-weight: 600;
}

.lkb-bar__count {
	display: inline-block;
	min-width: 20px;
	margin-left: 6px;
	padding: 1px 6px;
	border-radius: 10px;
	background: var( --lkb-error );
	color: #fff;
	font-size: 11px;
	text-align: center;
	font-variant-numeric: tabular-nums;
}

.lkb-bar__actions {
	display: flex;
	gap: 8px;
}

@media ( max-width: 782px ) {
	.lkb-settings__layout {
		grid-template-columns: minmax( 0, 1fr );
	}

	.lkb-nav {
		position: static;
	}
}
</style>
