/**
 * Bulk action wording and the run-then-offer-Undo flow.
 *
 * The bulk bar and the row actions (a single-row trash, restore or delete is
 * a bulk action over one ID) share these, so a change reads the same wherever
 * it starts.
 *
 * @since 1.0.0
 */

import { message } from 'ant-design-vue';
import { __, _n, sprintf } from '../components/i18n';
import { showUndoToast } from './components/UndoToast';
import { messageOf, useArticlesStore as articlesStore } from './store';
import type { BulkAction, BulkArgs, SkipReason } from './types';

/** The actions that can be undone. Author and comments are left out on purpose. */
const UNDOABLE: BulkAction[] = [ 'trash', 'restore', 'status', 'terms' ];

/**
 * Why an article was skipped, in words.
 *
 * @since 1.0.0
 *
 * @param {SkipReason} reason The reason code.
 *
 * @return {string} The reason.
 */
export function reasonLabel( reason: SkipReason ): string {
	switch ( reason ) {
		case 'not_found':
			return __( 'Not found', 'licensio-knowledge-base' );
		case 'forbidden':
			return __( 'No permission', 'licensio-knowledge-base' );
		case 'locked':
			return __(
				'Being edited by someone else',
				'licensio-knowledge-base'
			);
		case 'not_in_trash':
			return __( 'Not in the trash', 'licensio-knowledge-base' );
		case 'in_trash':
			return __( 'In the trash', 'licensio-knowledge-base' );
		default:
			return reason;
	}
}

/**
 * The Popconfirm question for an action over a number of articles.
 *
 * @since 1.0.0
 *
 * @param {BulkAction} action The action.
 * @param {BulkArgs}   args   Its arguments.
 * @param {number}     count  How many articles.
 *
 * @return {string} The question.
 */
export function confirmTitle(
	action: BulkAction,
	args: BulkArgs,
	count: number
): string {
	switch ( action ) {
		case 'trash':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'Move %d article to the trash?',
					'Move %d articles to the trash?',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'restore':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'Restore %d article from the trash?',
					'Restore %d articles from the trash?',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'delete':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'Permanently delete %d article? This cannot be undone.',
					'Permanently delete %d articles? This cannot be undone.',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'status':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'Change the status of %d article?',
					'Change the status of %d articles?',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'author':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'Change the author of %d article?',
					'Change the author of %d articles?',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'comments':
			return 'open' === args.comment_status
				? sprintf(
						/* translators: %d: number of articles. */
						_n(
							'Open comments on %d article?',
							'Open comments on %d articles?',
							count,
							'licensio-knowledge-base'
						),
						count
				  )
				: sprintf(
						/* translators: %d: number of articles. */
						_n(
							'Close comments on %d article?',
							'Close comments on %d articles?',
							count,
							'licensio-knowledge-base'
						),
						count
				  );
		case 'terms':
			if ( 'add' === args.mode ) {
				return sprintf(
					/* translators: %d: number of articles. */
					_n(
						'Add the chosen terms to %d article?',
						'Add the chosen terms to %d articles?',
						count,
						'licensio-knowledge-base'
					),
					count
				);
			}

			if ( 'remove' === args.mode ) {
				return sprintf(
					/* translators: %d: number of articles. */
					_n(
						'Remove the chosen terms from %d article?',
						'Remove the chosen terms from %d articles?',
						count,
						'licensio-knowledge-base'
					),
					count
				);
			}

			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'Replace the terms of %d article with the chosen ones?',
					'Replace the terms of %d articles with the chosen ones?',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		default:
			return '';
	}
}

/**
 * The toast text after an action changed some articles.
 *
 * @since 1.0.0
 *
 * @param {BulkAction} action The action.
 * @param {number}     count  How many articles changed.
 *
 * @return {string} The text.
 */
function doneText( action: BulkAction, count: number ): string {
	switch ( action ) {
		case 'trash':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'%d article moved to the trash.',
					'%d articles moved to the trash.',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'restore':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'%d article restored.',
					'%d articles restored.',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		case 'delete':
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'%d article permanently deleted.',
					'%d articles permanently deleted.',
					count,
					'licensio-knowledge-base'
				),
				count
			);
		default:
			return sprintf(
				/* translators: %d: number of articles. */
				_n(
					'%d article updated.',
					'%d articles updated.',
					count,
					'licensio-knowledge-base'
				),
				count
			);
	}
}

/**
 * Run an action, then report it and offer Undo when it can be undone.
 *
 * The progress modal shows when there is more than one batch, or when the
 * run stopped or skipped anything, so a single-row action stays quiet.
 *
 * @since 1.0.0
 *
 * @param {BulkAction} action The action.
 * @param {BulkArgs}   args   Its arguments.
 * @param {number[]}   ids    The articles.
 *
 * @return {Promise<void>} Resolves once the run and the reload are done.
 */
export async function performAction(
	action: BulkAction,
	args: BulkArgs,
	ids: number[]
): Promise< void > {
	const store = articlesStore();
	const run = await store.runBulk( action, args, ids, ids.length > 1 );

	if ( '' !== run.error || run.skipped.length || run.failed.length ) {
		store.bulk.open = true;
	}

	if ( 0 === run.done.length ) {
		return;
	}

	const text = doneText( action, run.done.length );

	if ( ! UNDOABLE.includes( action ) ) {
		message.success( text );
		return;
	}

	const results = run.results;

	showUndoToast( text, async () => {
		try {
			const reverted = await store.undo( results );
			const count = reverted.done.length;

			if ( count > 0 ) {
				message.success(
					sprintf(
						/* translators: %d: number of articles. */
						_n(
							'%d article put back.',
							'%d articles put back.',
							count,
							'licensio-knowledge-base'
						),
						count
					)
				);
			}

			const missed = reverted.skipped.length + reverted.failed.length;

			if ( missed > 0 ) {
				const reasons = [
					...new Set( [
						...reverted.skipped.map( ( { reason } ) =>
							reasonLabel( reason )
						),
						...reverted.failed.map( ( { message: why } ) => why ),
					] ),
				].join( '; ' );

				message.warning(
					sprintf(
						/* translators: 1: number of articles, 2: the reasons. */
						_n(
							'%1$d article could not be put back: %2$s',
							'%1$d articles could not be put back: %2$s',
							missed,
							'licensio-knowledge-base'
						),
						missed,
						reasons
					),
					8
				);
			}
		} catch ( error ) {
			message.error( messageOf( error ) );
		}
	} );
}
