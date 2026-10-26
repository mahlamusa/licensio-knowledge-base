<script setup lang="ts">
/**
 * A button that asks before it acts.
 *
 * Destructive, hard-to-reverse and bulk actions go through this: clicking the
 * button only opens a confirmation dialog in the centre of the screen, over a
 * translucent mask that blocks the page, and `confirm` runs only when the
 * user confirms. Nothing should be sent to the server from a click handler;
 * send it from `@confirm`. Cancelling, or pressing Escape, emits `cancel` and
 * sends nothing.
 *
 * The dialog is an `alertdialog` labelled by the title and described by the
 * description. Opening it moves focus to Cancel, which is the first button,
 * so a second Enter cannot carry out a destructive change by accident, and
 * Tab stays inside the dialog until it closes.
 *
 * While a promise returned from the `confirm` handler is pending, the confirm
 * button shows a spinner and the dialog cannot be dismissed. If the promise
 * rejects, or resolves to a non-empty string or an `Error`, the dialog stays
 * open and shows that message; any other result closes it. A handler that
 * reports errors in its own place and returns nothing keeps working as
 * before. A long run with its own progress display should not hold the
 * dialog open; call it with `void` so the dialog closes at once.
 *
 * While its dialog is open the button carries `is-confirming`, so a row can
 * keep showing it.
 *
 * After the dialog closes, focus goes back to the button, so a keyboard user
 * stays where they were. If the change disabled or removed the button, as
 * discarding changes or trashing a row does, focus moves to the page title
 * instead of falling back to the top of the document. Inside a drawer or
 * dialog that closes itself, that one puts focus back on what opened it.
 *
 * Usage:
 *
 *     <ConfirmButton
 *         :title="__( 'Move 3 articles to the trash?', 'licensio-knowledge-base' )"
 *         :ok-text="__( 'Move to trash', 'licensio-knowledge-base' )"
 *         danger
 *         :loading="saving"
 *         v-on:confirm="trash"
 *     >
 *         {{ __( 'Trash', 'licensio-knowledge-base' ) }}
 *     </ConfirmButton>
 *
 * Consumed by every app that confirms an action: articles, categories, tags,
 * feedback, settings and tools.
 *
 * @since 1.0.0
 */

import { nextTick, onBeforeUnmount, ref, useId } from 'vue';
import {
	Alert as AAlert,
	Button as AButton,
	Modal as AModal,
} from 'ant-design-vue';
import { ExclamationCircleFilled } from '@ant-design/icons-vue';
import { __ } from './i18n';

const props = withDefaults(
	defineProps< {
		/** The question the dialog asks. */
		title: string;
		/** More about what confirming does, shown under the question. */
		description?: string;
		/** The confirm button label. Defaults to "Confirm". */
		okText?: string;
		/** Marks both the trigger and the confirm button as destructive. */
		danger?: boolean;
		/** Disables the trigger; the dialog cannot open. */
		disabled?: boolean;
		/** Shows a spinner on the trigger; the dialog cannot open. */
		loading?: boolean;
		/** The trigger's Ant Design button type. */
		type?: 'primary' | 'default' | 'dashed' | 'link' | 'text';
		/** The trigger's size. */
		size?: 'small' | 'middle' | 'large';
		/**
		 * The `@confirm` handler. Declared as a prop rather than an event so
		 * the dialog can wait for the promise it returns.
		 */
		onConfirm?: () => unknown;
	} >(),
	{
		description: '',
		okText: '',
		danger: false,
		disabled: false,
		loading: false,
		type: 'default',
		size: 'middle',
		onConfirm: undefined,
	}
);

// Two roots: the trigger and its dialog. Attributes go to the trigger.
defineOptions( { inheritAttrs: false } );

const emit = defineEmits< {
	( event: 'cancel' ): void;
} >();

/**
 * Above the WordPress admin bar (99999) and menu (9990), so the mask covers
 * the whole screen, as the media modal does.
 */
const Z_INDEX = 160000;

const id = useId();
const titleId = `lkb-confirm-title-${ id }`;
const descriptionId = `lkb-confirm-description-${ id }`;

const trigger = ref< { $el?: HTMLElement } | null >( null );
const cancelButton = ref< { $el?: HTMLElement } | null >( null );
const open = ref( false );
const busy = ref( false );
const error = ref( '' );
const reducedMotion =
	window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ?? false;

let unmounted = false;

/**
 * Focus the page title the button was on.
 *
 * Does nothing inside a drawer or dialog: those put focus back on what
 * opened them when they close.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} from The button that had focus.
 *
 * @return {void}
 */
function focusTitle( from: HTMLElement ): void {
	if ( from.closest( '.ant-modal, .ant-drawer' ) ) {
		return;
	}

	const title = from
		.closest( '.lkb-page' )
		?.querySelector< HTMLElement >( '.lkb-page__title' );

	if ( title ) {
		title.tabIndex = -1;
		title.focus();
	}
}

/**
 * Put focus back on the button, or on the page title when it cannot take it.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} el The button that opened the dialog.
 *
 * @return {void}
 */
function returnFocus( el: HTMLElement ): void {
	const usable =
		el.isConnected &&
		! el.hasAttribute( 'disabled' ) &&
		el.getClientRects().length > 0;

	if ( usable ) {
		el.focus();
		return;
	}

	focusTitle( el );
}

/**
 * Open the dialog.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function show(): void {
	if ( props.disabled || props.loading ) {
		return;
	}

	error.value = '';
	open.value = true;

	// The dialog is shown after this tick; Ant Design then leaves focus alone
	// because it is already inside.
	nextTick( () => {
		window.setTimeout( () => cancelButton.value?.$el?.focus(), 0 );
	} );
}

/**
 * Close the dialog and put focus back.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function close(): void {
	const el = trigger.value?.$el;

	open.value = false;

	if ( el ) {
		// Wait for the change to reach the button: it may now be disabled or gone.
		nextTick( () => returnFocus( el ) );
	}
}

/**
 * The message a failed handler reported, or an empty string.
 *
 * @since 1.0.0
 *
 * @param {unknown} result What the handler returned or threw.
 *
 * @return {string} The message to show.
 */
function failureOf( result: unknown ): string {
	if ( result instanceof Error ) {
		return (
			result.message ||
			__( 'Something went wrong.', 'licensio-knowledge-base' )
		);
	}

	return 'string' === typeof result ? result : '';
}

/**
 * The user confirmed: run the handler and close unless it failed.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once the handler has settled.
 */
async function onOk(): Promise< void > {
	if ( busy.value ) {
		return;
	}

	busy.value = true;
	error.value = '';

	let failure = '';

	try {
		failure = failureOf( await props.onConfirm?.() );
	} catch ( caught ) {
		failure =
			failureOf( caught ) ||
			__( 'Something went wrong.', 'licensio-knowledge-base' );
	} finally {
		busy.value = false;
	}

	// The change removed the button, and the dialog with it.
	if ( unmounted ) {
		return;
	}

	if ( failure ) {
		error.value = failure;
		return;
	}

	close();
}

/**
 * The user cancelled, with the Cancel button or Escape.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function onCancel(): void {
	if ( busy.value ) {
		return;
	}

	emit( 'cancel' );
	close();
}

/**
 * Move focus to the page title when the button goes away with focus on it
 * or on its dialog.
 *
 * Runs before the button leaves the page, while it is still in it.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function keepFocusOnPage(): void {
	unmounted = true;

	const el = trigger.value?.$el;

	// The button is still in the page; there is no other node to ask for its document.
	// eslint-disable-next-line @wordpress/no-global-active-element
	const active = document.activeElement;

	if ( ! el || ! ( open.value || el.contains( active ) ) ) {
		return;
	}

	focusTitle( el );
}

onBeforeUnmount( keepFocusOnPage );
</script>

<template>
	<a-button
		ref="trigger"
		v-bind="$attrs"
		:type="props.type"
		:size="props.size"
		:danger="props.danger"
		:disabled="props.disabled"
		:loading="props.loading"
		:class="{ 'is-confirming': open }"
		aria-haspopup="dialog"
		@click="show"
	>
		<slot />
	</a-button>

	<a-modal
		:open="open"
		centered
		:closable="false"
		:mask-closable="false"
		:keyboard="! busy"
		:focus-trigger-after-close="false"
		destroy-on-close
		:z-index="Z_INDEX"
		:width="420"
		:transition-name="reducedMotion ? '' : undefined"
		:mask-transition-name="reducedMotion ? '' : undefined"
		wrap-class-name="lkb-confirm"
		:wrap-props="{
			role: 'alertdialog',
			'aria-modal': 'true',
			'aria-labelledby': titleId,
			'aria-describedby': props.description ? descriptionId : undefined,
		}"
		@cancel="onCancel"
	>
		<div class="lkb-confirm__body">
			<ExclamationCircleFilled
				class="lkb-confirm__icon"
				:class="{ 'is-danger': props.danger }"
				aria-hidden="true"
			/>
			<div class="lkb-confirm__text">
				<h2 :id="titleId" class="lkb-confirm__title">
					{{ props.title }}
				</h2>
				<p
					v-if="props.description"
					:id="descriptionId"
					class="lkb-confirm__description"
				>
					{{ props.description }}
				</p>
			</div>
		</div>

		<a-alert
			v-if="error"
			class="lkb-confirm__error"
			type="error"
			show-icon
			role="alert"
			:message="error"
		/>

		<template #footer>
			<a-button ref="cancelButton" :disabled="busy" @click="onCancel">
				{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
			</a-button>
			<a-button
				type="primary"
				:danger="props.danger"
				:loading="busy"
				@click="onOk"
			>
				{{ props.okText || __( 'Confirm', 'licensio-knowledge-base' ) }}
			</a-button>
		</template>
	</a-modal>
</template>

<style>
.lkb-confirm .lkb-confirm__body {
	display: flex;
	gap: 12px;
	align-items: flex-start;
}

.lkb-confirm .lkb-confirm__icon {
	flex: none;
	margin-top: 3px;
	font-size: 20px;
	color: var( --lkb-warning );
}

.lkb-confirm .lkb-confirm__icon.is-danger {
	color: var( --lkb-error );
}

.lkb-confirm .lkb-confirm__title {
	margin: 0;
	padding: 0;
	font-size: 16px;
	font-weight: 600;
	line-height: 1.5;
	color: var( --lkb-ink );
}

.lkb-confirm .lkb-confirm__description {
	margin: 8px 0 0;
}

.lkb-confirm .lkb-confirm__error {
	margin-top: 16px;
}
</style>
