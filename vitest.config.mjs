/**
 * Vitest configuration for the admin apps' unit tests.
 *
 * Runs the specs in `tests/Vitest/` in happy-dom, with the Vue plugin so
 * single file components compile. Separate from `vite.config.mjs`, so the
 * apps' build entries and dev server settings play no part, and from
 * Playwright, which `npm test` runs on `tests/Playwright/`.
 *
 * @since 1.1.0
 */

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	plugins: [ vue() ],
	test: {
		environment: 'happy-dom',
		include: [ 'tests/Vitest/**/*.test.ts' ],
	},
} );
