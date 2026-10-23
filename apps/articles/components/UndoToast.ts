/**
 * The Undo toast shown after a reversible change.
 *
 * Trash, restore, status and term changes show a notification with an Undo
 * button for about ten seconds. Clicking Undo closes it and runs the undo
 * callback; letting it time out does nothing. Author and comment changes and
 * permanent deletes never show it.
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

	notification.open( {
		key,
		message,
		description: __(
			'You can undo this for a few seconds.',
			'licensio-knowledge-base'
		),
		duration: UNDO_SECONDS,
		placement: 'bottomRight',
		btn: () =>
			h(
				Button,
				{
					type: 'primary',
					size: 'small',
					onClick: () => {
						notification.close( key );
						onUndo();
					},
				},
				() => __( 'Undo', 'licensio-knowledge-base' )
			),
	} );
}
