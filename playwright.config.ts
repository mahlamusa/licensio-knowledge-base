/**
 * Playwright configuration.
 *
 * End-to-end specs live in `tests/Playwright/`. The base URL comes from
 * `LICENSIO_KB_BASE_URL` so the same suite runs against a local LocalWP site,
 * a Playground instance or CI. The `setup` project signs in once per user
 * before the specs run (see tests/Playwright/auth.setup.ts).
 *
 * @since 1.0.0
 */

import { defineConfig, devices } from '@playwright/test';
import { ADMIN_SESSION } from './tests/Playwright/helpers/session';

/** Specs that switch a site-wide setting; see the `site-wide` project. */
const SITE_WIDE = '**/classic-screens.spec.ts';

const baseURL =
	process.env.LICENSIO_KB_BASE_URL ?? 'http://lisenseserver.local';

export default defineConfig( {
	testDir: './tests/Playwright',
	testMatch: '**/*.spec.ts',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: process.env.CI ? 1 : undefined,
	reporter: process.env.CI
		? [ [ 'github' ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ] ],
	timeout: 30_000,
	expect: {
		timeout: 5_000,
	},
	use: {
		baseURL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		// Signs in once per user and saves the sessions the other projects
		// start from, so parallel workers never sign in as the same user at
		// once. A run with --no-deps reuses the sessions of the last run.
		{
			name: 'setup',
			testMatch: '**/auth.setup.ts',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			name: 'chromium',
			testIgnore: SITE_WIDE,
			dependencies: [ 'setup' ],
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: ADMIN_SESSION,
			},
		},
		// Specs that switch a site-wide setting the others rely on. They run
		// after every other spec, so no worker meets the setting half-way.
		// To run one on its own, add --no-deps.
		{
			name: 'site-wide',
			testMatch: SITE_WIDE,
			dependencies: [ 'chromium' ],
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: ADMIN_SESSION,
			},
		},
	],
	outputDir: './test-results',
} );
