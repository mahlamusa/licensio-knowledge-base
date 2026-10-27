/**
 * Playwright configuration.
 *
 * End-to-end specs live in `tests/Playwright/`. The base URL comes from
 * `LICENSIO_KB_BASE_URL` so the same suite runs against a local LocalWP site,
 * a Playground instance or CI. The `setup` project signs in once per user
 * before the specs run (see tests/Playwright/auth.setup.ts).
 *
 * @since 1.0.0
 * @since 1.0.0 Runs tests tagged `@one-at-a-time` alone, after the others.
 */

import { defineConfig, devices } from '@playwright/test';
import { ADMIN_SESSION } from './tests/Playwright/helpers/session';

/** Specs that switch a site-wide setting; see the `site-wide` project. */
const SITE_WIDE = '**/classic-screens.spec.ts';

/** Tests that read state the whole site shares; see the `one-at-a-time` project. */
const ONE_AT_A_TIME = /@one-at-a-time/;

/** Desktop Chrome with the administrator's saved session. */
const signedIn = {
	...devices[ 'Desktop Chrome' ],
	storageState: ADMIN_SESSION,
};

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
			grepInvert: ONE_AT_A_TIME,
			dependencies: [ 'setup' ],
			use: signedIn,
		},
		// Tests that read state the whole site shares, such as the article
		// status counts or the order of every top-level category, which other
		// specs change as they run. They run one at a time once the other
		// specs are done.
		{
			name: 'one-at-a-time',
			testIgnore: SITE_WIDE,
			grep: ONE_AT_A_TIME,
			workers: 1,
			dependencies: [ 'chromium' ],
			use: signedIn,
		},
		// Specs that switch a site-wide setting the others rely on. They run
		// after every other spec, so no worker meets the setting half-way.
		// To run one on its own, add --no-deps.
		{
			name: 'site-wide',
			testMatch: SITE_WIDE,
			dependencies: [ 'one-at-a-time' ],
			use: signedIn,
		},
	],
	outputDir: './test-results',
} );
