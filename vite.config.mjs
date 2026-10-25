/**
 * Vite configuration for the Vue admin apps.
 *
 * Every directory under `apps/` that contains an `index.ts` or `index.js`
 * becomes a build entry.
 *
 * `assets/js/licensio-kb.js` is deliberately NOT an entry. Vite entries are ES
 * modules, and module scripts execute after every classic script on the page,
 * so the plugin's own dependency-free classic frontend scripts could never
 * depend on it. It is a classic script served straight from `assets/js/`, and
 * `tools/build-apps.mjs` skips this build entirely while `apps/` is empty.
 *
 * Development runs on port 6913 (a sibling plugin uses 6912). PHP only reaches for the
 * dev server when the `HMR_DEV` constant is set to `licensio-kb`, so the port
 * being up is not enough on its own to change how a site loads assets.
 *
 * Production output goes to `dist/`, with the manifest at
 * `dist/.vite/manifest.json`, which `Licensio_KB_Assets` reads.
 *
 * The built JavaScript is what `wp i18n make-pot` reads, both for the plugin's
 * own template and on WordPress.org, and the JSON translation files it leads to
 * are keyed by the built file paths. So the build keeps every translation call
 * recognisable: `__`, `_x`, `_n` and `_nx` keep their names, translator comments
 * survive minification, and `keepTranslationCalls()` unwraps the calls in
 * compiled templates.
 *
 * @since 1.0.0
 */

import { existsSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

const root = path.dirname( fileURLToPath( import.meta.url ) );

const DEV_SERVER_PORT = 6913;

/**
 * Collect the build entries.
 *
 * @since 1.0.0
 *
 * @return {Object<string,string>} Entry name to absolute source path.
 */
export function collectEntries() {
	const entries = {};
	const appsDir = path.resolve( root, 'apps' );

	if ( ! existsSync( appsDir ) ) {
		return entries;
	}

	for ( const app of readdirSync( appsDir ) ) {
		const appPath = path.resolve( appsDir, app );

		if ( ! statSync( appPath ).isDirectory() ) {
			continue;
		}

		for ( const candidate of [ 'index.ts', 'index.js' ] ) {
			const entry = path.resolve( appPath, candidate );

			if ( existsSync( entry ) ) {
				entries[ `apps/${ app }/index` ] = entry;
				break;
			}
		}
	}

	return entries;
}

/**
 * Keep translation calls in compiled Vue templates plain.
 *
 * A template that calls an imported `__()` compiles to `_unref(__)( ... )`,
 * which `wp i18n make-pot` does not recognise as a translation call. The
 * helpers in `apps/components/i18n.ts` are plain functions, never refs, so
 * dropping the `_unref()` wrapper changes nothing at runtime.
 *
 * @since 1.0.0
 *
 * @return {import('vite').Plugin} The Vite plugin.
 */
function keepTranslationCalls() {
	return {
		name: 'licensio-kb-keep-translation-calls',
		apply: 'build',
		enforce: 'post',
		transform( code, id ) {
			if ( ! /\.vue($|\?)/.test( id ) ) {
				return null;
			}

			return {
				code: code.replace( /\b_unref\((__|_x|_n|_nx)\)/g, '$1' ),
				map: null,
			};
		},
	};
}

export default defineConfig( ( { command } ) => ( {
	root,
	base: 'build' === command ? '' : `http://localhost:${ DEV_SERVER_PORT }/`,
	plugins: [ vue(), keepTranslationCalls() ],
	resolve: {
		alias: {
			'@': path.resolve( root, 'apps' ),
		},
	},
	server: {
		host: 'localhost',
		port: DEV_SERVER_PORT,
		strictPort: true,
		origin: `http://localhost:${ DEV_SERVER_PORT }`,
		cors: true,
	},
	build: {
		outDir: 'dist',
		// wp-scripts also writes into dist/, so Vite must not clear it.
		emptyOutDir: false,
		manifest: true,
		sourcemap: false,
		minify: 'terser',
		terserOptions: {
			// `x ? __( 'a' ) : __( 'b' )` would become `__( x ? 'a' : 'b' )`,
			// which hides both strings from the extractor.
			compress: { conditionals: false },
			mangle: { reserved: [ '__', '_x', '_n', '_nx' ] },
			format: { comments: /translators:/i },
		},
		rollupOptions: {
			input: collectEntries(),
			output: {
				entryFileNames: '[name].[hash].js',
				chunkFileNames: 'chunks/[name].[hash].js',
				assetFileNames: 'assets/[name].[hash].[ext]',
			},
		},
	},
} ) );
