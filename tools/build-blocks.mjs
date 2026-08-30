/**
 * Block build wrapper.
 *
 * Runs `wp-scripts build` over `blocks/` and emits into `dist/blocks/`, but
 * only when there is at least one block to build. Until the blocks land (#7)
 * the directory is empty and wp-scripts would fail on a missing entry, so an
 * empty `blocks/` is reported as a skip rather than a build failure.
 *
 * @since 1.0.0
 */

import { existsSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const blocksDir = path.join( root, 'blocks' );

const hasBlocks =
	existsSync( blocksDir ) &&
	readdirSync( blocksDir ).some( ( entry ) => ! entry.startsWith( '.' ) );

if ( ! hasBlocks ) {
	process.stdout.write( 'No blocks in blocks/ yet - skipping the wp-scripts build.\n' );
	process.exit( 0 );
}

const result = spawnSync(
	'npx',
	[
		'wp-scripts',
		'build',
		'--webpack-src-dir=blocks',
		'--output-path=dist/blocks',
	],
	{ cwd: root, stdio: 'inherit', shell: process.platform === 'win32' }
);

process.exit( result.status === null ? 1 : result.status );
