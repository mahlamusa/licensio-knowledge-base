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
 * Waits for either the admin bar or the login error. A rejected sign-in
 * comes straight back with the error, so it fails at once with a clear
 * message instead of waiting out the cold first render allowance.
 *
 * @since 1.0.0
 * @since 1.0.0 Allows for a cold first admin render and fails fast on a rejected sign-in.
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

	const adminBar = page.locator( '#wpadminbar' );
	const loginError = page.locator( '#login_error' );

	await expect( adminBar.or( loginError ).first() ).toBeVisible( {
		timeout: FIRST_ADMIN_RENDER,
	} );
	await expect( loginError, 'WordPress rejected the sign-in.' ).toHaveCount(
		0
	);
	await expect( page ).toHaveURL( /\/wp-admin\// );
}
