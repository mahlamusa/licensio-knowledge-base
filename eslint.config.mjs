/**
 * ESLint flat config.
 *
 * The WordPress recommended preset for every linted file, the plugin text
 * domain for the i18n rule, and Vue single file components parsed as
 * TypeScript with the same TypeScript rule set the preset applies to .ts files.
 *
 * @since 1.0.0
 */

import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';
import pluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';

const WORDPRESS_EXTERNALS = '^@wordpress/(?!(icons|interface|style-engine))';

export default [
	{
		ignores: [ '**/build/**', '**/node_modules/**', '**/vendor/**' ],
	},

	// ESLint only discovers .js, .mjs and .cjs files on its own.
	{ files: [ '**/*.ts', '**/*.vue' ] },

	...wordpress.configs.recommended,

	{
		// The blocks import @wordpress/* packages that WordPress provides at
		// runtime (webpack externals), so they are not npm dependencies here.
		// Earlier presets treated them as internal modules; keep doing so.
		settings: {
			'import/internal-regex': WORDPRESS_EXTERNALS,
		},
		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{ allowedTextDomain: [ 'licensio-knowledge-base' ] },
			],
			// Rules the earlier presets had on that the current ones switch
			// off, kept so the rule set stays the same.
			'jsx-a11y/heading-has-content': 'error',
			'no-with': 'error',
			// New in the current jsdoc preset and only about doc type style;
			// left off as before rather than rewriting existing doc types.
			'jsdoc/reject-any-type': 'off',
			'jsdoc/reject-function-type': 'off',
		},
	},

	{
		// TypeScript and Vue files leave import resolution to the compiler.
		files: [ '**/*.js', '**/*.mjs', '**/*.cjs' ],
		rules: {
			'import/no-unresolved': [
				'error',
				{
					ignore: [ WORDPRESS_EXTERNALS ],
				},
			],
		},
	},

	...pluginVue.configs[ 'flat/essential' ].map( ( config ) => ( {
		...config,
		files: [ '**/*.vue' ],
	} ) ),

	{
		files: [ '**/*.vue' ],
		languageOptions: {
			// Earlier eslint-plugin-vue presets enabled the browser environment.
			globals: globals.browser,
			parser: vueParser,
			parserOptions: {
				parser: tseslint.parser,
				extraFileExtensions: [ '.vue' ],
				ecmaVersion: 'latest',
				sourceType: 'module',
			},
		},
		plugins: {
			'@typescript-eslint': tseslint.plugin,
		},
		rules: {
			'no-duplicate-imports': 'off',
			'import/no-duplicates': 'error',
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-returns-type': 'off',
			'no-unused-vars': 'off',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{ ignoreRestSiblings: true },
			],
			'no-shadow': 'off',
			'@typescript-eslint/no-shadow': 'error',
			'import/no-unresolved': 'off',
			'import/default': 'off',
			'import/named': 'off',
			'react-hooks/rules-of-hooks': 'off',
			'vue/multi-word-component-names': 'off',
		},
	},
];
