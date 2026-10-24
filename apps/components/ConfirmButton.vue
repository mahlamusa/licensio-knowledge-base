<script setup lang="ts">
/**
 * A button that asks before it acts.
 *
 * Every change in the content manager goes through this: clicking the button
 * only opens a Popconfirm, and `confirm` is emitted only when the user
 * confirms. Nothing should be sent to the server from a click handler; send
 * it from `@confirm`. Cancelling emits `cancel` and sends nothing.
 *
 * Opening it moves focus to its Cancel button (see `usePopoverFocus()`), so
 * a keyboard user can answer it, and Escape closes it from there. Cancel is
 * the first button, which also means a second Enter cannot carry out a
 * destructive change by accident. After any answer, focus goes back to the
 * button, so a keyboard user stays where they were.
 *
 * Usage:
 *
 *     <ConfirmButton
 *         :title="__( 'Move 3 articles to the trash?', 'licensio-knowledge-base' )"
 *         :ok-text="__( 'Move to trash', 'licensio-knowledge-base' )"
 *         danger
 *         :loading="saving"
 *         @confirm="trash"
 *     >
 *         {{ __( 'Trash', 'licensio-knowledge-base' ) }}
 *     </ConfirmButton>
 *
 * Consumed by the articles, categories and tags apps.
 *
 * @since 1.0.0
 */

import { nextTick, ref } from 'vue';
import { Button as AButton, Popconfirm as APopconfirm } from 'ant-design-vue';
import { __ } from './i18n';
import { usePopoverFocus } from './popover-focus';

const props = withDefaults(
	defineProps< {
		/** The question the Popconfirm asks. */
		title: string;
		/** The confirm button label. Defaults to "Confirm". */
		okText?: string;
		/** Marks both the trigger and the confirm button as destructive. */
		danger?: boolean;
		/** Disables the trigger; the Popconfirm cannot open. */
		disabled?: boolean;
		/** Shows a spinner on the trigger; the Popconfirm cannot open. */
		loading?: boolean;
		/** The trigger's Ant Design button type. */
		type?: 'primary' | 'default' | 'dashed' | 'link' | 'text';
		/** The trigger's size. */
		size?: 'small' | 'middle' | 'large';
	} >(),
	{
		okText: '',
		danger: false,
		disabled: false,
		loading: false,
		type: 'default',
		size: 'middle',
	}
);

const emit = defineEmits< {
	( event: 'confirm' ): void;
	( event: 'cancel' ): void;
} >();

const trigger = ref< { $el?: HTMLElement } | null >( null );

const { open, overlayClass, setOpen } = usePopoverFocus();

/**
 * Put focus back on the trigger once the Popconfirm has closed.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function returnFocus(): void {
	nextTick( () => {
		trigger.value?.$el?.focus();
	} );
}

/**
 * The user confirmed.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function onConfirm(): void {
	emit( 'confirm' );
	returnFocus();
}

/**
 * The user cancelled.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function onCancel(): void {
	emit( 'cancel' );
	returnFocus();
}
</script>

<template>
	<a-popconfirm
		:title="props.title"
		:ok-text="props.okText || __( 'Confirm', 'licensio-knowledge-base' )"
		:cancel-text="__( 'Cancel', 'licensio-knowledge-base' )"
		:ok-button-props="{ danger: props.danger }"
		:disabled="props.disabled || props.loading"
		:open="open"
		:overlay-class-name="overlayClass"
		@open-change="setOpen"
		@confirm="onConfirm"
		@cancel="onCancel"
	>
		<a-button
			ref="trigger"
			:type="props.type"
			:size="props.size"
			:danger="props.danger"
			:disabled="props.disabled"
			:loading="props.loading"
		>
			<slot />
		</a-button>
	</a-popconfirm>
</template>
