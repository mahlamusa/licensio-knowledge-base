/**
 * Playwright helpers for the content manager apps.
 *
 * The apps are reached through `admin.php?page=licensio-kb-{app}`, which
 * works whether the pages are in the menu or, with the classic screens,
 * hidden. The slugs match the constants on
 * `Licensio_KB_Content_Manager` (ARTICLES_SLUG, CATEGORIES_SLUG, TAGS_SLUG).
 *
 * Each app mounts into `#licensio-kb-{app}` and renders the shared shell, whose
 * main region is `#lkb-main`. Every change goes through a Popconfirm
 * (`ConfirmButton`), which the two Popconfirm helpers answer.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * Consumed by the articles, categories and tags browser tests.
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
 * The Popconfirm that is open now.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Locator} The open Popconfirm.
 */
export function openPopconfirm( page: Page ): Locator {
	return page.locator( '.ant-popconfirm:not(.ant-popover-hidden)' ).last();
}

/**
 * Check that no Popconfirm is showing, for an action that runs on click.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once no Popconfirm is visible.
 */
export async function expectNoPopconfirm( page: Page ): Promise< void > {
	await expect( page.locator( '.ant-popconfirm:visible' ) ).toHaveCount( 0 );
}

/**
 * Answer the open Popconfirm with its confirm button.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the Popconfirm has closed.
 */
export async function confirmPopconfirm( page: Page ): Promise< void > {
	const popconfirm = openPopconfirm( page );

	await expect( popconfirm ).toBeVisible();
	await popconfirm
		.locator( '.ant-popconfirm-buttons button.ant-btn-primary' )
		.click();
	await expect( popconfirm ).toBeHidden();
}

/**
 * Answer the open Popconfirm with its cancel button.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the Popconfirm has closed.
 */
export async function cancelPopconfirm( page: Page ): Promise< void > {
	const popconfirm = openPopconfirm( page );

	await expect( popconfirm ).toBeVisible();
	await popconfirm
		.locator( '.ant-popconfirm-buttons button:not(.ant-btn-primary)' )
		.click();
	await expect( popconfirm ).toBeHidden();
}
