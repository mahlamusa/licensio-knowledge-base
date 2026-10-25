/**
 * Translated labels for article statuses.
 *
 * One map for every place that shows an article's status, so the article
 * list and the category article order read the same words.
 *
 * Consumed by the articles and categories apps.
 *
 * @since 1.0.0
 */

import { __ } from './i18n';

/**
 * The translated label of an article status.
 *
 * Built on each call, so the labels are translated once `wp.i18n` holds the
 * page's strings.
 *
 * @since 1.0.0
 *
 * @param {string} status The post status, as WordPress stores it.
 *
 * @return {string} The label, or the status itself when it is not a known one.
 */
export function statusLabel( status: string ): string {
	const labels: Record< string, string > = {
		publish: __( 'Published', 'licensio-knowledge-base' ),
		future: __( 'Scheduled', 'licensio-knowledge-base' ),
		draft: __( 'Draft', 'licensio-knowledge-base' ),
		pending: __( 'Pending', 'licensio-knowledge-base' ),
		private: __( 'Private', 'licensio-knowledge-base' ),
		trash: __( 'Trash', 'licensio-knowledge-base' ),
	};

	return labels[ status ] ?? status;
}
