/**
 * Playwright helpers for the block and site editors.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * @since 1.0.0
 */

import { type Page } from '@playwright/test';

/**
 * Switch off editor welcome guides through the editor's own preferences.
 *
 * The guides open over the editor some time after load and are not under
 * test, so they are turned off as soon as the preferences store exists.
 *
 * @since 1.0.0
 *
 * @param {Page}     page  The browser page.
 * @param {string}   scope The preferences scope, such as `core/edit-post`.
 * @param {string[]} names The preference names of the guides.
 *
 * @return {Promise<void>} Resolves once the preferences are set.
 */
export async function switchOffWelcomeGuides(
	page: Page,
	scope: string,
	names: string[]
): Promise< void > {
	await page.waitForFunction(
		() => !! ( window as any ).wp?.data?.dispatch( 'core/preferences' )
	);
	await page.evaluate(
		( [ prefScope, prefNames ] ) => {
			const preferences = ( window as any ).wp.data.dispatch(
				'core/preferences'
			);

			for ( const name of prefNames ) {
				preferences.set( prefScope, name, false );
			}
		},
		[ scope, names ] as const
	);
}
