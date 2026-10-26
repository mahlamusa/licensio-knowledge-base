/**
 * Layout measurements shared by the specs that check the admin apps' frame.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * @since 1.0.0
 */

import type { Page } from '@playwright/test';

/** How far a screen's frame runs past its box, and where that box sits. */
export interface FrameMeasure {
	/** Pixels the document scrolls sideways; 0 when it does not. */
	page: number;
	/** Pixels the app's main region scrolls sideways; 0 when it does not. */
	container: number;
	/** Most pixels any scroll box inside the main region (a table) scrolls sideways. */
	inner: number;
	/** Gap between the admin menu and the main region's left edge. */
	left: number;
	/** Gap between the main region's right edge and the window edge. */
	right: number;
	/** Width of the main region. */
	width: number;
}

/**
 * Fold or unfold the admin menu, as its Collapse Menu button does.
 *
 * Only the body class is set; the user setting the button also saves is left
 * alone, so the choice does not outlive the test.
 *
 * @since 1.0.0
 *
 * @param {Page}    page   The browser page.
 * @param {boolean} folded True to fold the menu.
 *
 * @return {Promise<void>} Resolves once the class is set.
 */
export async function foldAdminMenu(
	page: Page,
	folded: boolean
): Promise< void > {
	await page.evaluate(
		( fold ) => document.body.classList.toggle( 'folded', fold ),
		folded
	);
}

/**
 * Measure the sideways overflow of the page, of the app's main region and of
 * the scroll boxes inside it.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page, on an app screen.
 *
 * @return {Promise<FrameMeasure>} The measurements.
 */
export async function measureFrame( page: Page ): Promise< FrameMeasure > {
	return page.evaluate( () => {
		const root = document.documentElement;
		const main = document.querySelector( '#lkb-main' ) as HTMLElement;
		const menu = document.querySelector( '#adminmenuback' );
		const box = main.getBoundingClientRect();
		const menuRight =
			menu && 'none' !== getComputedStyle( menu ).display
				? menu.getBoundingClientRect().right
				: 0;

		const inner = Array.from( main.querySelectorAll( '*' ) )
			.filter(
				( el ) =>
					( el as HTMLElement ).offsetParent &&
					[ 'auto', 'scroll' ].includes(
						getComputedStyle( el ).overflowX
					)
			)
			.map( ( el ) => el.scrollWidth - el.clientWidth );

		return {
			page: root.scrollWidth - root.clientWidth,
			container: main.scrollWidth - main.clientWidth,
			inner: Math.max( 0, ...inner ),
			left: Math.round( box.left - menuRight ),
			right: Math.round( root.clientWidth - box.right ),
			width: Math.round( box.width ),
		};
	} );
}
