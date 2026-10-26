/**
 * Playwright configuration.
 *
 * End-to-end specs live in `tests/Playwright/`. The base URL comes from
 * `LICENSIO_KB_BASE_URL` so the same suite runs against a local LocalWP site,
 * a Playground instance or CI.
 *
 * @since 1.0.0
 */

import { defineConfig, devices } from '@playwright/test';

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
		{
			name: 'chromium',
			testIgnore: SITE_WIDE,
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		// Specs that switch a site-wide setting the others rely on. They run
		// after every other spec, so no worker meets the setting half-way.
		// To run one on its own, add --no-deps.
		{
			name: 'site-wide',
			testMatch: SITE_WIDE,
			dependencies: [ 'chromium' ],
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	outputDir: './test-results',
} );
