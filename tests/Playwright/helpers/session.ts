/**
 * Signing in, shared by every spec that needs the admin.
 *
 * The credentials come from `LICENSIO_KB_ADMIN_USER` and
 * `LICENSIO_KB_ADMIN_PASSWORD`; a spec skips itself when either is empty.
 *
 * The `setup` project (`auth.setup.ts`) signs in once per user before any
 * spec runs and saves each session to a file. Specs start with the admin's
 * saved session and open the dashboard with `openDashboard()` instead of
 * signing in again: WordPress keeps a user's sessions in one user meta row
 * that every sign-in reads and writes back, so workers signing in as the
 * same user at the same moment overwrite each other's session and one of
 * them lands on the login form. `logIn()` stays for a spec that needs a
 * fresh sign-in.
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

/** The roles the roles spec signs in as, each with its own test user. */
export const ROLES = [ 'author', 'contributor', 'editor' ] as const;

/** One of the test users' roles. */
export type Role = ( typeof ROLES )[ number ];

/** The password every test user gets. */
export const ROLE_PASSWORD = 'lkb-e2e-Roles-2026';

/**
 * The login name of the test user with a role.
 *
 * @since 1.0.0
 *
 * @param {Role} role The role.
 *
 * @return {string} The login name.
 */
export function roleLogin( role: Role ): string {
	return `lkb_e2e_${ role }`;
}

/**
 * Where the setup project saves a user's session.
 *
 * Relative to the directory the suite runs from, like `outputDir`.
 *
 * @since 1.0.0
 *
 * @param {string} name `admin`, or a role.
 *
 * @return {string} The session file.
 */
export function sessionFile( name: 'admin' | Role ): string {
	return `.tmp/playwright/auth/${ name }.json`;
}

/** The administrator's saved session, every project's default. */
export const ADMIN_SESSION = sessionFile( 'admin' );

/** No session, for a spec that visits the site signed out. */
export const SIGNED_OUT = { cookies: [], origins: [] };

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
 * Sign in through the login form.
 *
 * Waits for either the admin bar or the login error. A rejected sign-in
 * comes straight back with the error, so it fails at once with a clear
 * message instead of waiting out the cold first render allowance.
 *
 * @since 1.0.0
 * @since 1.0.0 Allows for a cold first admin render and fails fast on a rejected sign-in.
 * @since 1.0.0 Takes the login and password, for the setup project's test users.
 *
 * @param {Page}   page  The browser page.
 * @param {string} login Optional. The login name; the administrator's by default.
 * @param {string} pass  Optional. The password; the administrator's by default.
 *
 * @return {Promise<void>} Resolves once the admin bar is visible.
 */
export async function logIn(
	page: Page,
	login: string = user,
	pass: string = password
): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', pass );
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

/**
 * Open the dashboard with the session the page already holds.
 *
 * Ends where `logIn()` ends, on wp-admin with the admin bar showing, without
 * signing in again. Fails with a clear message when the saved session was
 * not accepted.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once the admin bar is visible.
 */
export async function openDashboard( page: Page ): Promise< void > {
	await page.goto( '/wp-admin/' );

	await expect(
		page,
		'The saved session was not accepted; the setup project signs in first.'
	).toHaveURL( /\/wp-admin\// );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible( {
		timeout: FIRST_ADMIN_RENDER,
	} );
}
