<script setup lang="ts">
/**
 * The confirmation dialog itself, without a trigger.
 *
 * A centred dialog over a translucent mask that blocks the page. It is an
 * `alertdialog` labelled by the title and described by the description.
 * Opening it moves focus to Cancel, which is the first button, so a second
 * Enter cannot carry out a destructive change by accident, and Tab stays
 * inside the dialog until it closes. While `busy`, the confirm button shows
 * a spinner and the dialog cannot be dismissed.
 *
 * It holds no state of its own: the caller opens and closes it and puts focus
 * back. `ConfirmButton` uses it for a button that asks before it acts, and
 * `useConfirm()` (see `./confirm`) for a question asked from code.
 *
 * @since 1.0.0
 */

import { nextTick, ref, useId, watch } from 'vue';
import {
	Alert as AAlert,
	Button as AButton,
	Modal as AModal,
} from 'ant-design-vue';
import { ExclamationCircleFilled } from '@ant-design/icons-vue';
import { __ } from './i18n';

const props = withDefaults(
	defineProps< {
		/** Whether the dialog is shown. */
		open: boolean;
		/** The question the dialog asks. */
		title: string;
		/** More about what confirming does, shown under the question. */
		description?: string;
		/** The confirm button label. Defaults to "Confirm". */
		okText?: string;
		/** Marks the confirm button as destructive. */
		danger?: boolean;
		/** Shows a spinner on the confirm button and blocks dismissing. */
		busy?: boolean;
		/** A message shown inside the dialog, under the question. */
		error?: string;
	} >(),
	{
		description: '',
		okText: '',
		danger: false,
		busy: false,
		error: '',
	}
);

const emit = defineEmits< {
	( event: 'ok' ): void;
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

const cancelButton = ref< { $el?: HTMLElement } | null >( null );
const reducedMotion =
	window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ?? false;

// The dialog is shown after this tick; Ant Design then leaves focus alone
// because it is already inside.
watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			nextTick( () => {
				window.setTimeout( () => cancelButton.value?.$el?.focus(), 0 );
			} );
		}
	}
);

/**
 * The user cancelled, with the Cancel button or Escape.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function onCancel(): void {
	if ( ! props.busy ) {
		emit( 'cancel' );
	}
}
</script>

<template>
	<a-modal
		:open="props.open"
		centered
		:closable="false"
		:mask-closable="false"
		:keyboard="! props.busy"
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
			v-if="props.error"
			class="lkb-confirm__error"
			type="error"
			show-icon
			role="alert"
			:message="props.error"
		/>

		<template #footer>
			<a-button
				ref="cancelButton"
				:disabled="props.busy"
				@click="onCancel"
			>
				{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
			</a-button>
			<a-button
				type="primary"
				:danger="props.danger"
				:loading="props.busy"
				@click="emit( 'ok' )"
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
