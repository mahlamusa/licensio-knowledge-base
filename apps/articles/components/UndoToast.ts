/**
 * The Undo toast shown after a reversible change.
 *
 * Trash, restore, status and term changes show a notification with an Undo
 * button for about ten seconds. Clicking Undo closes it and runs the undo
 * callback; letting it time out does nothing. Author and comment changes and
 * permanent deletes never show it.
 *
 * Keyboard and screen reader users get the same chance to undo: the toast is
 * announced politely, the countdown pauses while the toast has the pointer or
 * focus, and when the change took away the control that had focus, focus
 * moves to Undo. Focus goes to the page title when the toast closes from
 * under it.
 *
 * Used by the Articles app only. If another app needs it, it moves to the
 * shared components.
 *
 * @since 1.0.0
 */

import { h } from 'vue';
import { Button, notification } from 'ant-design-vue';
import { __ } from '../../components/i18n';

/** How long the toast stays, in seconds. */
export const UNDO_SECONDS = 10;

let sequence = 0;

/**
 * Announce a message politely to screen readers, through `wp.a11y` when present.
 *
 * @since 1.0.0
 *
 * @param {string} text The message.
 *
 * @return {void}
 */
function speak( text: string ): void {
	(
		window as unknown as {
			wp?: {
				a11y?: {
					speak?: ( text: string, politeness?: string ) => void;
				};
			};
		}
	 ).wp?.a11y?.speak?.( text, 'polite' );
}

/**
 * Whether focus has fallen out of the page's controls.
 *
 * It falls to the body when the focused control is removed, or to the page
 * title when `ConfirmButton` caught it as its button went away.
 *
 * @since 1.0.0
 *
 * @return {boolean} True when nothing useful has focus.
 */
function focusWasLost(): boolean {
	// The toast is rendered at the end of the page; there is no node to ask for its document.
	// eslint-disable-next-line @wordpress/no-global-active-element
	const active = document.activeElement;

	return (
		! active ||
		active === document.body ||
		active.classList.contains( 'lkb-page__title' )
	);
}

/**
 * Show the Undo toast.
 *
 * @since 1.0.0
 *
 * @param {string}     message The change that was made, e.g. "3 articles moved to the trash."
 * @param {() => void} onUndo  Runs when the user clicks Undo.
 *
 * @return {void}
 */
export function showUndoToast( message: string, onUndo: () => void ): void {
	const key = `lkb-undo-${ ++sequence }`;
	let timer = 0;

	/**
	 * The toast's element, once rendered.
	 *
	 * @since 1.0.0
	 *
	 * @return {HTMLElement|null} The notice, or null.
	 */
	const element = (): HTMLElement | null =>
		document.querySelector< HTMLElement >( `.${ key }` );

	/**
	 * Close the toast, keeping focus on the page if it was inside.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	const close = (): void => {
		window.clearTimeout( timer );

		// eslint-disable-next-line @wordpress/no-global-active-element
		if ( element()?.contains( document.activeElement ) ) {
			const title =
				document.querySelector< HTMLElement >( '.lkb-page__title' );

			if ( title ) {
				title.tabIndex = -1;
				title.focus();
			}
		}

		notification.close( key );
	};

	/**
	 * Start, or restart, the countdown.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	const start = (): void => {
		window.clearTimeout( timer );
		timer = window.setTimeout( close, UNDO_SECONDS * 1000 );
	};

	/**
	 * Pause the countdown.
	 *
	 * @since 1.0.0
	 *
	 * @return {void}
	 */
	const pause = (): void => window.clearTimeout( timer );

	/**
	 * Restart the countdown once neither the pointer nor focus is in the toast.
	 *
	 * @since 1.0.0
	 *
	 * @param {Event} event The mouse leave or focus out.
	 *
	 * @return {void}
	 */
	const resume = ( event: Event ): void => {
		const toast = element();

		if ( ! toast ) {
			return;
		}

		if ( 'focusout' === event.type ) {
			const next = ( event as FocusEvent ).relatedTarget as Node | null;

			if ( toast.contains( next ) || toast.matches( ':hover' ) ) {
				return;
			}
			// eslint-disable-next-line @wordpress/no-global-active-element
		} else if ( toast.contains( document.activeElement ) ) {
			return;
		}

		start();
	};

	notification.open( {
		key,
		class: key,
		message: h( 'span', { id: `${ key }-message` }, message ),
		description: __(
			'You can undo this for a few seconds.',
			'licensio-knowledge-base'
		),
		// The countdown is kept here, so it can pause for focus too.
		duration: 0,
		placement: 'bottomRight',
		onClose: () => window.clearTimeout( timer ),
		btn: () =>
			h(
				Button,
				{
					type: 'primary',
					size: 'small',
					'aria-describedby': `${ key }-message`,
					onClick: () => {
						close();
						onUndo();
					},
				},
				() => __( 'Undo', 'licensio-knowledge-base' )
			),
	} );

	speak( message );
	start();

	window.setTimeout( () => {
		const toast = element();

		if ( ! toast ) {
			return;
		}

		toast.addEventListener( 'mouseenter', pause );
		toast.addEventListener( 'focusin', pause );
		toast.addEventListener( 'mouseleave', resume );
		toast.addEventListener( 'focusout', resume );

		// Ant Design's close link is named only by its icon and ignores keys.
		const dismiss = toast.querySelector< HTMLElement >(
			'.ant-notification-notice-close'
		);

		dismiss?.setAttribute( 'role', 'button' );
		dismiss?.setAttribute(
			'aria-label',
			__( 'Dismiss', 'licensio-knowledge-base' )
		);
		dismiss?.addEventListener( 'keydown', ( event: KeyboardEvent ) => {
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				close();
			}
		} );

		// Checked once the list has re-rendered without the changed rows.
		if ( focusWasLost() ) {
			toast.querySelector< HTMLElement >( '.ant-btn' )?.focus();
		}
	}, 50 );
}
