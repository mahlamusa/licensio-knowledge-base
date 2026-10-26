/**
 * Vue app build wrapper.
 *
 * Runs `vite build` over `apps/`, but only when there is at least one app to
 * build. Until the admin apps land (#8, #9, #10) the directory is empty and
 * Vite fails on an empty input set, so an empty `apps/` is reported as a skip
 * rather than a build failure.
 *
 * This mirrors `build-blocks.mjs`. It exists because the frontend namespace
 * bootstrap used to be carried as a permanent Vite entry purely to keep the
 * input set non-empty, which forced it to be an ES module and made it unusable
 * as a dependency of the plugin's classic frontend scripts.
 *
 * @since 1.0.0
 */

import { existsSync, readdirSync, statSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const appsDir = path.join( root, 'apps' );

const hasApps =
	existsSync( appsDir ) &&
	readdirSync( appsDir ).some( ( entry ) => {
		const appPath = path.join( appsDir, entry );

		if ( entry.startsWith( '.' ) || ! statSync( appPath ).isDirectory() ) {
			return false;
		}

		return [ 'index.ts', 'index.js' ].some( ( candidate ) =>
			existsSync( path.join( appPath, candidate ) )
		);
	} );

if ( ! hasApps ) {
	process.stdout.write( 'No apps in apps/ yet - skipping the Vite build.\n' );
	process.exit( 0 );
}

const result = spawnSync( 'npx', [ 'vite', 'build' ], {
	cwd: root,
	stdio: 'inherit',
	shell: process.platform === 'win32',
} );

process.exit( result.status === null ? 1 : result.status );
