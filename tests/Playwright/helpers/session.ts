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
 * How long the first admin page may take to render after signing in.
 *
 * The first wp-admin request on a fresh site loads and compiles the admin code
 * for the first time: about 2.5 seconds on an idle machine against 0.2 seconds
 * once warm, and past the 5 second default expect timeout when other sites
 * share the machine. That cold render, not the sign-in, is what the first spec
 * of a run used to time out on.
 */
const FIRST_ADMIN_RENDER = 30_000;

/**
 * Sign in as the administrator.
 *
 * Waits for the redirect into wp-admin before looking for the admin bar, so a
 * rejected sign-in fails here on the URL rather than on a missing admin bar.
 *
 * @since 1.0.0
 * @since 1.0.0 Waits for the redirect and allows for a cold first admin render.
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the admin bar is visible.
 */
export async function logIn( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await Promise.all( [
		page.waitForURL( /\/wp-admin\//, { timeout: FIRST_ADMIN_RENDER } ),
		page.click( '#wp-submit' ),
	] );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible( {
		timeout: FIRST_ADMIN_RENDER,
	} );
}
