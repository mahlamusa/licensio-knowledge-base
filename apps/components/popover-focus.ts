/**
 * Keyboard access for a click-opened Ant Design popover.
 *
 * Ant Design renders the overlay at the end of the page and leaves focus on
 * the trigger, so a keyboard user cannot tab into it, and it does not close
 * on Escape. Bound to
 * the overlay, this moves focus to the first control in it when it opens,
 * and closes it on Escape from anywhere, putting focus back where it was.
 * Tab and Shift+Tab stay inside the open overlay, going round from its last
 * control to its first and back, instead of leaving it open behind focus
 * that moved on to the page.
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
 * Consumed by the articles app's filters and columns popovers.
 * Confirmations do not use it: ConfirmButton opens a modal dialog, which
 * traps focus itself. `useEscapeClose()` applies the same rule for open
 * selects to a dialog or an edit form.
 *
 * @since 1.0.0
 */

import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import type { Ref } from 'vue';

/** What a keyboard user can tab to. */
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * The controls in an overlay that Tab stops on, in order.
 *
 * Only shown controls count, and of a named radio group only the one Tab
 * stops on: the checked radio, or the first while none is checked.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} root The overlay.
 *
 * @return {HTMLElement[]} The controls.
 */
function tabStops( root: HTMLElement ): HTMLElement[] {
	return Array.from(
		root.querySelectorAll< HTMLElement >( FOCUSABLE )
	).filter( ( element ) => {
		if ( 0 === element.getClientRects().length ) {
			return false;
		}

		if (
			! ( element instanceof HTMLInputElement ) ||
			'radio' !== element.type ||
			! element.name
		) {
			return true;
		}

		const group = Array.from(
			root.querySelectorAll< HTMLInputElement >( 'input[type="radio"]' )
		).filter( ( radio ) => radio.name === element.name );

		return group.some( ( radio ) => radio.checked )
			? element.checked
			: group[ 0 ] === element;
	} );
}

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
 * Close a dialog, or leave an edit form, on Escape, but not while a select
 * in it is open.
 *
 * Ant Design's modal closes on any Escape pressed inside it, even one that
 * an open select uses to close its list, so the user loses what they typed.
 * Give the modal `:keyboard="false"` and call this instead: Escape on an open
 * select or date picker closes only that, and any other Escape inside
 * `container` calls `close`.
 *
 * Whether the control owns the key is noted in the capture phase, before it
 * handles it; the dialog or form closes in the bubble phase, so an open
 * popover that stopped the key (see `usePopoverFocus`) closes alone. A
 * confirmation dialog opened from inside is rendered outside it and stops
 * the key itself, so it also closes alone.
 *
 * Consumed by the Categories and Tags dialogs and their add and edit forms,
 * and the Articles quick edit form.
 *
 * @since 1.0.0
 *
 * @param {Function} isOpen    Whether the dialog or edit is open now.
 * @param {string}   container A selector matching the dialog's or form's root.
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
	 * Keep Tab inside the overlay.
	 *
	 * From its last control Tab goes to its first, and from its first
	 * Shift+Tab goes to its last. Tab between the two is left to the browser.
	 *
	 * @since 1.0.0
	 *
	 * @param {KeyboardEvent} event The Tab key press.
	 *
	 * @return {void}
	 */
	function wrapTab( event: KeyboardEvent ): void {
		const root = document.querySelector< HTMLElement >(
			`.${ overlayClass }`
		);

		if (
			! root ||
			! ( event.target instanceof Node ) ||
			! root.contains( event.target )
		) {
			return;
		}

		const stops = tabStops( root );

		if ( 0 === stops.length ) {
			return;
		}

		const first = stops[ 0 ];
		const last = stops[ stops.length - 1 ];

		if ( event.shiftKey && event.target === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && event.target === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Close the overlay on Escape and put focus back, and keep Tab inside it.
	 *
	 * @since 1.0.0
	 *
	 * @param {KeyboardEvent} event The key press.
	 *
	 * @return {void}
	 */
	function onKeydown( event: KeyboardEvent ): void {
		if ( 'Tab' === event.key ) {
			wrapTab( event );
			return;
		}

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

		// Only this overlay closes, not a dialog or form it was opened from.
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
