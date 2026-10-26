/**
 * Playwright helpers for the content manager apps.
 *
 * The apps are reached through `admin.php?page=licensio-kb-{app}`, which
 * works whether the pages are in the menu or, with the classic screens,
 * hidden. The slugs match the constants on
 * `Licensio_KB_Content_Manager` (ARTICLES_SLUG, CATEGORIES_SLUG, TAGS_SLUG).
 *
 * Each app mounts into `#licensio-kb-{app}` and renders the shared shell, whose
 * main region is `#lkb-main`. Destructive and bulk actions ask first in a
 * centred confirmation dialog (`ConfirmButton`), which `confirmDialog()`
 * finds and `answerConfirm()` answers in every app.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * Consumed by the articles, categories, tags, feedback, settings and tools
 * browser tests.
 *
 * @since 1.0.0
 */

import { expect, type Locator, type Page } from '@playwright/test';

export type ContentManagerApp = 'articles' | 'categories' | 'tags';

/** The admin page slug of each app. */
export const CONTENT_MANAGER_SLUGS: Record< ContentManagerApp, string > = {
	articles: 'licensio-kb-articles',
	categories: 'licensio-kb-categories',
	tags: 'licensio-kb-tags',
};

/**
 * The path and query of the page's URL.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {string} The path and query.
 */
export function pathOf( page: Page ): string {
	const url = new URL( page.url() );

	return url.pathname + url.search;
}

/**
 * The admin URL of an app, relative to the site root.
 *
 * @since 1.0.0
 *
 * @param {ContentManagerApp} app The app.
 *
 * @return {string} The URL.
 */
export function contentManagerUrl( app: ContentManagerApp ): string {
	return `/wp-admin/admin.php?page=${ CONTENT_MANAGER_SLUGS[ app ] }`;
}

/**
 * The element an app mounts into.
 *
 * @since 1.0.0
 *
 * @param {Page}              page The browser page.
 * @param {ContentManagerApp} app  The app.
 *
 * @return {Locator} The mount element.
 */
export function contentManagerMount(
	page: Page,
	app: ContentManagerApp
): Locator {
	return page.locator( `#${ CONTENT_MANAGER_SLUGS[ app ] }` );
}

/**
 * Open an app and wait until it has mounted.
 *
 * The caller must already be signed in.
 *
 * @since 1.0.0
 *
 * @param {Page}              page The browser page.
 * @param {ContentManagerApp} app  The app to open.
 *
 * @return {Promise<Locator>} The app's mount element.
 */
export async function openContentManager(
	page: Page,
	app: ContentManagerApp
): Promise< Locator > {
	await page.goto( contentManagerUrl( app ) );

	const mount = contentManagerMount( page, app );

	await expect( mount.locator( '#lkb-main' ) ).toBeVisible();

	return mount;
}

/**
 * The confirmation dialog that is open now.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Locator} The open dialog.
 */
export function confirmDialog( page: Page ): Locator {
	return page.getByRole( 'alertdialog' );
}

/**
 * Check that no confirmation dialog is showing, for an action that runs on
 * click.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once no dialog is visible.
 */
export async function expectNoConfirmDialog( page: Page ): Promise< void > {
	await expect( confirmDialog( page ) ).toHaveCount( 0 );
}

/**
 * Answer the open confirmation dialog and wait for it to close.
 *
 * @since 1.0.0
 *
 * @param {Page}                 page   The browser page.
 * @param {'confirm' | 'cancel'} answer Which button to press.
 *
 * @return {Promise<void>} Resolves once the dialog has closed.
 */
export async function answerConfirm(
	page: Page,
	answer: 'confirm' | 'cancel'
): Promise< void > {
	const dialog = confirmDialog( page );

	await expect( dialog ).toBeVisible();
	await dialog
		.locator(
			'confirm' === answer
				? '.ant-modal-footer .ant-btn-primary'
				: '.ant-modal-footer .ant-btn:not(.ant-btn-primary)'
		)
		.click();
	await expect( dialog ).toBeHidden();
}
