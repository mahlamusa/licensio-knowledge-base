/**
 * Running wp-cli against the site under test, shared by specs that seed data.
 *
 * The command comes from `LICENSIO_KB_WP_CLI`, for example
 * `echowp --dir <dir> wp <site> --` or `wp --path=<wp root>`; a spec skips
 * itself when it is empty.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * @since 1.0.0
 */

import { execFileSync } from 'node:child_process';

/** The command that runs wp-cli against the same site. */
export const wpCli = process.env.LICENSIO_KB_WP_CLI ?? '';

/**
 * Run PHP through wp-cli against the site under test.
 *
 * @since 1.0.0
 *
 * @param {string} php The PHP to run, without the opening tag.
 *
 * @return {string} What it printed.
 */
export function wpEval( php: string ): string {
	// The PHP goes in as a positional argument, so the shell never expands it.
	return execFileSync( 'sh', [ '-c', `${ wpCli } eval "$0"`, php ], {
		encoding: 'utf8',
	} ).trim();
}
