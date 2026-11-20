/**
 * The extension API's shared pieces, end to end.
 *
 * On the Articles, Categories and Tags pages the extension registry
 * (`window.licensioKb.extensions`) is loaded before the app, a script can
 * register handlers on it, and the app config carries the `extensions` the
 * current user may see. The articles list answers `include` with just those
 * rows. Rendering extension columns and actions is covered by the specs of
 * the features that wire them into the apps.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.1.0
 */

import { expect, test } from '@playwright/test';
import {
	openContentManager,
	type ContentManagerApp,
} from './helpers/content-manager';
import { password, user } from './helpers/session';

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password,
	'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
);

/** The global each app's config is localised as. */
const CONFIGS: Record< ContentManagerApp, string > = {
	articles: 'licensioKbArticles',
	categories: 'licensioKbCategories',
	tags: 'licensioKbTags',
};

for ( const app of Object.keys( CONFIGS ) as ContentManagerApp[] ) {
	test( `the ${ app } app loads the extension registry and config`, async ( {
		page,
	} ) => {
		await openContentManager( page, app );

		const result = await page.evaluate(
			( { screen, configName } ) => {
				const registry = (
					window as unknown as {
						licensioKb?: {
							extensions?: {
								register: ( s: string, d: unknown ) => boolean;
								get: ( s: string ) => {
									columns: Record< string, unknown >;
								};
							};
						};
					}
				 ).licensioKb?.extensions;
				const config = (
					window as unknown as Record<
						string,
						{ extensions?: Record< string, unknown > }
					>
				 )[ configName ];

				if ( ! registry ) {
					return null;
				}

				const render = () => undefined;

				return {
					registered: registry.register( screen, {
						columns: { 'e2e/probe': { render } },
					} ),
					refused: registry.register( 'posts', {} ),
					kept:
						registry.get( screen ).columns[ 'e2e/probe' ] !==
						undefined,
					sections: Object.keys( config?.extensions ?? {} ).sort(),
					lists: Object.values( config?.extensions ?? {} ).every(
						( list ) => Array.isArray( list )
					),
				};
			},
			{ screen: app, configName: CONFIGS[ app ] }
		);

		expect( result ).toEqual( {
			registered: true,
			refused: false,
			kept: true,
			sections: [ 'bulkActions', 'columns', 'filters', 'rowActions' ],
			lists: true,
		} );
	} );
}

test( 'the articles list answers include with just those rows', async ( {
	page,
} ) => {
	await openContentManager( page, 'articles' );

	const result = await page.evaluate( async () => {
		const { root, nonce } = (
			window as unknown as {
				licensioKbArticles: { root: string; nonce: string };
			}
		 ).licensioKbArticles;
		const list = async ( query: string ) => {
			const base = root.replace( /\/+$/, '' ) + '/articles';
			const response = await fetch(
				base + ( base.includes( '?' ) ? '&' : '?' ) + query,
				{ headers: { 'X-WP-Nonce': nonce } }
			);

			return ( await response.json() ) as {
				items: Array< { id: number } >;
				total: number;
			};
		};
		const first = await list( 'per_page=3' );
		const wanted = first.items.slice( 0, 2 ).map( ( item ) => item.id );
		const only = await list(
			wanted.map( ( id ) => `include[]=${ id }` ).join( '&' ) +
				'&per_page=1&page=4'
		);

		return {
			wanted: [ ...wanted ].sort(),
			got: only.items.map( ( item ) => item.id ).sort(),
			total: only.total,
		};
	} );

	expect( result.wanted ).toHaveLength( 2 );
	expect( result.got ).toEqual( result.wanted );
	expect( result.total ).toBe( 2 );
} );
