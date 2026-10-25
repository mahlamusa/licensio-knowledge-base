/**
 * Fail when a Vue app uses an Ant Design tag its entry does not register.
 *
 * Apps that import named components from `ant-design-vue` in their `index.ts`
 * register only those, so a template tag left off the list renders as an
 * unknown element instead of throwing. The end-to-end specs catch that only
 * for markup a test happens to render; this reads every template instead.
 *
 * Each imported component's own `install` is run against a stand-in app, so a
 * plugin's sub-components (`Form` brings `a-form-item`) count as registered.
 * Apps still on the default `Antd` import are skipped, but finding no app
 * with named imports at all fails, so a changed import style cannot turn the
 * check into a silent pass. Shared components in `apps/components` import
 * what they render themselves and are not scanned.
 *
 * Usage: node tools/check-ant-components.mjs [apps-directory]
 *
 * @since 1.0.0
 */

import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const appsDir = resolve( process.argv[ 2 ] || join( root, 'apps' ) );
const antd = createRequire( join( root, 'package.json' ) )( 'ant-design-vue' );

/**
 * List the component names a set of Ant Design exports registers.
 *
 * @since 1.0.0
 *
 * @param {string[]} names Named exports imported from ant-design-vue.
 *
 * @return {Set<string>} Registered component names, such as `AFormItem`.
 */
function registeredBy( names ) {
	const registered = new Set();
	const app = {
		config: { globalProperties: {} },
		component: ( name ) => registered.add( name ) && app,
		directive: () => app,
		provide: () => app,
		use: () => app,
	};

	names.forEach( ( name ) => antd[ name ]?.install?.( app ) );

	return registered;
}

/**
 * List the `.vue` files under a directory.
 *
 * @since 1.0.0
 *
 * @param {string} dir Directory to walk.
 *
 * @return {string[]} Absolute paths.
 */
function vueFiles( dir ) {
	return readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const path = join( dir, entry.name );

		if ( entry.isDirectory() ) {
			return vueFiles( path );
		}

		return entry.name.endsWith( '.vue' ) ? [ path ] : [];
	} );
}

const problems = [];
let checked = 0;

for ( const app of readdirSync( appsDir ) ) {
	const entry = join( appsDir, app, 'index.ts' );

	if ( ! existsSync( entry ) ) {
		continue;
	}

	const named = readFileSync( entry, 'utf8' ).match(
		/import\s*\{([^}]*)\}\s*from\s*['"]ant-design-vue['"]/
	);

	if ( ! named ) {
		continue;
	}

	checked++;

	const registered = registeredBy(
		named[ 1 ]
			.split( ',' )
			.map( ( name ) => name.split( ' as ' )[ 0 ].trim() )
	);

	for ( const file of vueFiles( join( appsDir, app ) ) ) {
		const source = readFileSync( file, 'utf8' );

		for ( const [ , tag ] of source.matchAll( /<(a-[a-z-]+)/g ) ) {
			const pascal = tag.replace( /(^|-)([a-z])/g, ( m, d, c ) =>
				c.toUpperCase()
			);

			if ( ! registered.has( pascal ) ) {
				problems.push( `${ relative( appsDir, file ) }  <${ tag }>` );
			}
		}
	}
}

if ( problems.length ) {
	process.stderr.write(
		`Ant Design tags the app entry does not register:\n  ${ [
			...new Set( problems ),
		].join( '\n  ' ) }\n`
	);
	process.exit( 1 );
}

if ( ! checked ) {
	process.stderr.write(
		`No app in ${ appsDir } imports named Ant Design components.\n`
	);
	process.exit( 1 );
}

process.stdout.write(
	`Every Ant Design tag in the ${ checked } apps checked is registered.\n`
);
