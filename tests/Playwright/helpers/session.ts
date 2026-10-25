/**
 * Signing in, shared by every spec that needs the admin.
 *
 * The credentials come from `LICENSIO_KB_ADMIN_USER` and
 * `LICENSIO_KB_ADMIN_PASSWORD`; a spec skips itself when either is empty.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * @since 1.0.0
 */

import { expect, type Page } from '@playwright/test';

/** The administrator's login name. */
export const user = process.env.LICENSIO_KB_ADMIN_USER ?? '';

/** The administrator's password. */
export const password = process.env.LICENSIO_KB_ADMIN_PASSWORD ?? '';

/**
 * Sign in as the administrator.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the admin bar is visible.
 */
export async function logIn( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}
