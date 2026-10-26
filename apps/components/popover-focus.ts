/**
 * Keyboard access for a click-opened Ant Design popover or Popconfirm.
 *
 * Ant Design renders the overlay at the end of the page and leaves focus on
 * the trigger, so a keyboard user cannot tab into it, and only a Popconfirm
 * closes on Escape, and only while focus is still on its trigger. Bound to
 * the overlay, this moves focus to the first control in it when it opens,
 * and closes it on Escape from anywhere, putting focus back where it was.
 * Escape on an open select or date picker inside the overlay closes that
 * select or picker first.
 *
 * Usage:
 *
 *     const { open, overlayClass, setOpen } = usePopoverFocus();
 *
 *     <a-popover
 *         trigger="click"
 *         :open="open"
 *         :overlay-class-name="overlayClass"
 *         v-on:open-change="setOpen"
 *     >
 *
 * Consumed by the articles app's filters and columns popovers and the
 * category tree's move Popconfirm. Confirmations no longer use it:
 * ConfirmButton opens a modal dialog, which traps focus itself. `FOCUSABLE`
 * is shared with the category drawer's focus trap. `useEscapeClose()` applies
 * the same rule for open selects to a drawer or dialog.
 *
 * @since 1.0.0
 */

import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import type { Ref } from 'vue';

/** What a keyboard user can tab to. */
export const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Whether a key press belongs to a date picker whose calendar is open.
 *
 * Ant Design's picker input has no `aria-expanded`, and its calendar is
 * rendered at the end of the page and hidden with `display: none` once
 * closed. The picker closes its own calendar on Escape, so the overlay
 * should leave that press alone.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} target The element the key was pressed on.
 *
 * @return {boolean} True while the target's picker shows its calendar.
 */
function inOpenPicker( target: HTMLElement ): boolean {
	if ( ! target.closest( '.ant-picker' ) ) {
		return false;
	}

	return Array.from(
		document.querySelectorAll< HTMLElement >( '.ant-picker-dropdown' )
	).some( ( dropdown ) => dropdown.getClientRects().length > 0 );
}

/**
 * Whether Escape on a control closes a list or calendar of its own.
 *
 * Asked before the control has handled the key: an open select still
 * reports itself expanded, and an open picker still shows its calendar.
 * Only a combobox (the input of a select, tree select or autocomplete) and a
 * date picker count. Other expanded elements, such as a tree node or a
 * collapse header, do not use Escape, so it still closes the dialog.
 *
 * @since 1.0.0
 *
 * @param {EventTarget|null} target The element the key was pressed on.
 *
 * @return {boolean} True for an open select or date picker.
 */
function ownsEscape( target: EventTarget | null ): boolean {
	if ( ! ( target instanceof HTMLElement ) ) {
		return false;
	}

	return (
		( 'combobox' === target.getAttribute( 'role' ) &&
			'true' === target.getAttribute( 'aria-expanded' ) ) ||
		inOpenPicker( target )
	);
}

/**
 * Close a drawer or dialog on Escape, but not while a select in it is open.
 *
 * Ant Design's drawer and modal close on any Escape pressed inside them,
 * even one that an open select uses to close its list, so the user loses
 * what they typed. Give the drawer or modal `:keyboard="false"` and call
 * this instead: Escape on an open select or date picker closes only that,
 * and any other Escape inside `container` calls `close`.
 *
 * Whether the control owns the key is noted in the capture phase, before it
 * handles it; the drawer or dialog closes in the bubble phase, so an open
 * popover that stopped the key (see `usePopoverFocus`) closes alone. A
 * confirmation dialog opened from the drawer is rendered outside it and
 * stops the key itself, so it also closes alone.
 *
 * Consumed by the category drawer and the Categories and Tags dialogs.
 *
 * @since 1.0.0
 *
 * @param {Function} isOpen    Whether the drawer or dialog is open now.
 * @param {string}   container A selector matching the drawer's or dialog's root.
 * @param {Function} close     Closes it.
 *
 * @return {void}
 */
export function useEscapeClose(
	isOpen: () => boolean,
	container: string,
	close: () => void
): void {
	let owned = false;

	/**
	 * Note whether the control the key was pressed on uses Escape itself.
	 *
	 * @since 1.0.0
	 *
	 * @param {KeyboardEvent} event The key press.
	 *
	 * @return {void}
	 */
	function onCapture( event: KeyboardEvent ): void {
		owned = 'Escape' === event.key && ownsEscape( event.target );
	}

	/**
	 * Close on an Escape nothing inside used.
	 *
	 * @since 1.0.0
	 *
	 * @param {KeyboardEvent} event The key press.
	 *
	 * @return {void}
	 */
	function onBubble( event: KeyboardEvent ): void {
		if ( 'Escape' !== event.key || owned || ! isOpen() ) {
			return;
		}

		const root = document.querySelector( container );

		if (
			root &&
			event.target instanceof Node &&
			root.contains( event.target )
		) {
			close();
		}
	}

	onMounted( () => {
		document.addEventListener( 'keydown', onCapture, true );
		document.addEventListener( 'keydown', onBubble );
	} );

	onBeforeUnmount( () => {
		document.removeEventListener( 'keydown', onCapture, true );
		document.removeEventListener( 'keydown', onBubble );
	} );
}

/**
 * The open state and focus handling of one overlay.
 *
 * @since 1.0.0
 *
 * @return {{open: Ref<boolean>, overlayClass: string, setOpen: Function}} Bind
 *         `open` and `overlayClass` to the overlay and call `setOpen` from its
 *         `openChange` event.
 */
export function usePopoverFocus(): {
	open: Ref< boolean >;
	overlayClass: string;
	setOpen: ( value: boolean ) => void;
} {
	const open = ref( false );
	const overlayClass = `lkb-overlay-${ useId().replace(
		/[^a-z0-9-]/gi,
		'-'
	) }`;
	let returnTo: HTMLElement | null = null;

	/**
	 * Close the overlay on Escape and put focus back.
	 *
	 * @since 1.0.0
	 *
	 * @param {KeyboardEvent} event The key press.
	 *
	 * @return {void}
	 */
	function onKeydown( event: KeyboardEvent ): void {
		if ( 'Escape' !== event.key ) {
			return;
		}

		/*
		 * An open select inside the overlay closes on this Escape instead.
		 * The listener runs in the capture phase, before the select has
		 * handled the key, so it still reports itself expanded.
		 */
		if ( ownsEscape( event.target ) ) {
			return;
		}

		// Only this overlay closes, not a drawer or dialog it was opened from.
		event.stopPropagation();

		const target = returnTo;

		setOpen( false );
		target?.focus();
	}

	/**
	 * Open or close the overlay.
	 *
	 * @since 1.0.0
	 *
	 * @param {boolean} value Whether it should be open.
	 *
	 * @return {void}
	 */
	function setOpen( value: boolean ): void {
		open.value = value;

		if ( ! value ) {
			document.removeEventListener( 'keydown', onKeydown, true );
			returnTo = null;
			return;
		}

		// The trigger has focus; there is no rendered node to ask for its document yet.
		// eslint-disable-next-line @wordpress/no-global-active-element
		const active = document.activeElement;

		returnTo = active instanceof HTMLElement ? active : null;
		document.addEventListener( 'keydown', onKeydown, true );

		nextTick( () => {
			window.setTimeout( () => {
				document
					.querySelector( `.${ overlayClass }` )
					?.querySelector< HTMLElement >( FOCUSABLE )
					?.focus();
			}, 50 );
		} );
	}

	onBeforeUnmount( () => {
		document.removeEventListener( 'keydown', onKeydown, true );
	} );

	return { open, overlayClass, setOpen };
}
