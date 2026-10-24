/**
 * Keyboard access for a click-opened Ant Design popover or Popconfirm.
 *
 * Ant Design renders the overlay at the end of the page and leaves focus on
 * the trigger, so a keyboard user cannot tab into it, and only a Popconfirm
 * closes on Escape, and only while focus is still on its trigger. Bound to
 * the overlay, this moves focus to the first control in it when it opens,
 * and closes it on Escape from anywhere, putting focus back where it was.
 * Escape on an open select inside the overlay closes that select first.
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
 * Consumed by ConfirmButton and the articles app's filters and columns
 * popovers.
 *
 * @since 1.0.0
 */

import { nextTick, onBeforeUnmount, ref, useId } from 'vue';
import type { Ref } from 'vue';

/** What a keyboard user can tab to. */
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

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
		if (
			event.target instanceof HTMLElement &&
			'true' === event.target.getAttribute( 'aria-expanded' )
		) {
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
					.querySelector< HTMLElement >(
						FOCUSABLE.split( ', ' )
							.map(
								( selector ) =>
									`.${ overlayClass } ${ selector }`
							)
							.join( ', ' )
					)
					?.focus();
			}, 50 );
		} );
	}

	onBeforeUnmount( () => {
		document.removeEventListener( 'keydown', onKeydown, true );
	} );

	return { open, overlayClass, setOpen };
}
