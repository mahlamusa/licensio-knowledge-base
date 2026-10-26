/**
 * Keep keyboard focus in an Ant Design table pager across a page change.
 *
 * Next page is disabled on the last page and Previous page on the first,
 * and focus on a disabled one falls back to the page. After a page change
 * made from the pager, focus moves to the current page instead.
 *
 * Consumed by the Articles, Tags and Feedback tables.
 *
 * @since 1.0.0
 */

import { nextTick } from 'vue';

/**
 * Change the page, keeping focus in the pager when it was there.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement|null} root   An element holding the table.
 * @param {Function}         change Changes the page.
 *
 * @return {void}
 */
export function changePageKeepingFocus(
	root: HTMLElement | null,
	change: () => void
): void {
	const pager = () => root?.querySelector( '.ant-pagination' );
	// The pager has no other node to ask for its document.
	// eslint-disable-next-line @wordpress/no-global-active-element
	const fromPager = !! pager()?.contains( document.activeElement );

	change();

	if ( ! fromPager ) {
		return;
	}

	nextTick( () => {
		// eslint-disable-next-line @wordpress/no-global-active-element
		const active = document.activeElement;

		if (
			pager()?.contains( active ) &&
			! active?.closest( '[aria-disabled="true"]' )
		) {
			return;
		}

		pager()
			?.querySelector< HTMLElement >( '.ant-pagination-item-active' )
			?.focus();
	} );
}
