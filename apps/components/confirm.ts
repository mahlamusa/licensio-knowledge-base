/**
 * A confirmation asked from code, in the same dialog `ConfirmButton` opens.
 *
 * For a question that has no button of its own, such as whether to drop a
 * form's unsaved changes when the user loads something else into it. The app
 * renders one `ConfirmDialog` bound to `dialog` and answering through
 * `answer()`; any component then calls `ask()` and waits for the answer.
 *
 *     <ConfirmDialog
 *         v-bind="confirm.dialog"
 *         v-on:ok="confirm.answer( true )"
 *         v-on:cancel="confirm.answer( false )"
 *     />
 *
 * The state is shared by the whole page, so there is one dialog per app.
 * After the dialog closes, focus goes back to what had it when the question
 * was asked, if that is still on the page and visible; the caller then moves
 * it on if the answer leads somewhere else.
 *
 * @since 1.0.0
 */

import { reactive } from 'vue';
import { __ } from './i18n';

/** What the dialog asks. */
export interface ConfirmOptions {
	/** The question. */
	title: string;
	/** More about what confirming does, shown under the question. */
	description?: string;
	/** The confirm button label. */
	okText?: string;
	/** Whether confirming is destructive. */
	danger?: boolean;
}

const dialog = reactive( {
	open: false,
	title: '',
	description: '',
	okText: '',
	danger: false,
} );

let resolve: ( ( confirmed: boolean ) => void ) | null = null;
let returnTo: HTMLElement | null = null;

/**
 * Close the dialog with an answer.
 *
 * @since 1.0.0
 *
 * @param {boolean} confirmed Whether the user confirmed.
 *
 * @return {void}
 */
function answer( confirmed: boolean ): void {
	const settle = resolve;
	const el = returnTo;

	resolve = null;
	returnTo = null;
	dialog.open = false;

	if ( el?.isConnected && el.getClientRects().length > 0 ) {
		el.focus();
	}

	settle?.( confirmed );
}

/**
 * Ask a question in the confirmation dialog.
 *
 * A question still open is answered No first.
 *
 * @since 1.0.0
 *
 * @param {ConfirmOptions} options What to ask.
 *
 * @return {Promise<boolean>} Whether the user confirmed.
 */
function ask( options: ConfirmOptions ): Promise< boolean > {
	answer( false );

	// The element that has focus now, to give it back once answered.
	// eslint-disable-next-line @wordpress/no-global-active-element
	const active = document.activeElement;

	returnTo = active instanceof HTMLElement ? active : null;
	dialog.title = options.title;
	dialog.description = options.description ?? '';
	dialog.okText = options.okText ?? '';
	dialog.danger = options.danger ?? false;
	dialog.open = true;

	return new Promise( ( done ) => {
		resolve = done;
	} );
}

/**
 * Ask whether to drop a form's unsaved changes.
 *
 * @since 1.0.0
 *
 * @return {Promise<boolean>} Whether the user agreed to drop them.
 */
function askDiscard(): Promise< boolean > {
	return ask( {
		title: __( 'Discard your unsaved changes?', 'licensio-knowledge-base' ),
		okText: __( 'Discard', 'licensio-knowledge-base' ),
		danger: true,
	} );
}

/**
 * The page's confirmation dialog: its state, and the functions to ask and
 * answer.
 *
 * @since 1.0.0
 *
 * @return {Object} `dialog`, `ask`, `askDiscard` and `answer`.
 */
export function useConfirm() {
	return { dialog, ask, askDiscard, answer };
}
