/**
 * Signs in once per user before any spec runs.
 *
 * Run by the `setup` project, which every other project depends on. Signs
 * in as the administrator, then creates the roles spec's test users (or
 * resets their passwords) and signs in as each, saving every session to its
 * `sessionFile()`. Specs start from those sessions instead of signing in
 * themselves; see `helpers/session.ts` for why.
 *
 * The users are created here rather than in the roles spec because setting
 * a password signs that user out everywhere, which would throw away the
 * session saved for them.
 *
 * Without `LICENSIO_KB_E2E=1` and the admin credentials (or, for the test
 * users, `LICENSIO_KB_WP_CLI`) the sessions are saved empty, so the specs
 * can still open their pages and skip themselves.
 *
 * @since 1.0.0
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { test as setup } from '@playwright/test';
import {
	ADMIN_SESSION,
	ROLE_PASSWORD,
	ROLES,
	SIGNED_OUT,
	logIn,
	password,
	roleLogin,
	sessionFile,
	user,
} from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

const enabled = process.env.LICENSIO_KB_E2E === '1';

setup( 'sign in once per user', async ( { page } ) => {
	setup.setTimeout( 120_000 );

	for ( const file of [ ADMIN_SESSION, ...ROLES.map( sessionFile ) ] ) {
		mkdirSync( dirname( file ), { recursive: true } );
		writeFileSync( file, JSON.stringify( SIGNED_OUT ) );
	}

	if ( ! enabled || '' === user || '' === password ) {
		return;
	}

	await logIn( page );
	await page.context().storageState( { path: ADMIN_SESSION } );

	if ( '' === wpCli ) {
		return;
	}

	wpEval( `
		foreach ( array( '${ ROLES.join( "', '" ) }' ) as $role ) {
			$login = 'lkb_e2e_' . $role;
			$user  = get_user_by( 'login', $login );
			$id    = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => '${ ROLE_PASSWORD }', 'user_email' => $login . '@example.test', 'role' => $role, 'display_name' => 'E2E ' . $role ) );
			wp_set_password( '${ ROLE_PASSWORD }', $id );
			( new WP_User( $id ) )->set_role( $role );
		}
	` );

	for ( const role of ROLES ) {
		await page.context().clearCookies();
		await logIn( page, roleLogin( role ), ROLE_PASSWORD );
		await page.context().storageState( { path: sessionFile( role ) } );
	}
} );
