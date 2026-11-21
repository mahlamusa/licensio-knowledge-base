/**
 * Extension filters in the Articles app, end to end.
 *
 * The example extension (`tests/fixtures/extension-example/features/filters.php`)
 * declares the "Example flag" filter, with `example_flag` as its core screen
 * argument, and narrows the list by the `_licensio_kb_example_flag` meta on
 * `licensio_kb_articles_query_args`. These tests check that its dropdown
 * narrows the list together with the core filters, that the choice lives in
 * the URL through reload, back and forward and can be cleared, that "Select
 * all matching" gets the same rows, and that a core screen link with the
 * filter's argument opens the app with the filter set.
 *
 * Each run seeds its own articles under a unique title prefix and only ever
 * looks at those, so the specs that run alongside cannot change what it sees.
 * Needs the example extension active, the admin credentials,
 * `LICENSIO_KB_E2E=1` and `LICENSIO_KB_WP_CLI`.
 *
 * @since 1.1.0
 */

import { expect, test, type Locator, type Page } from '@playwright/test';
import {
	contentManagerUrl,
	openContentManager,
} from './helpers/content-manager';
import { openDashboard, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

const enabled = process.env.LICENSIO_KB_E2E === '1';

/** The URL state of the example filter set to Flagged, as the app writes it. */
const FLAGGED_URL = /ext_filters(%5B|\[)example(%2F|\/)flag(%5D|\])=flagged/;

/** What the seed made: the prefix, the article IDs, and the terms and author used. */
interface Seed {
	prefix: string;
	ids: Record< 'a' | 'b' | 'c' | 'd', number >;
	category: number;
	tag: number;
	author: number;
}

test.describe( 'Articles app extension filters', () => {
	test.skip(
		! enabled || '' === user || '' === password || '' === wpCli,
		'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
	);

	// One seed for the three tests, made once.
	test.describe.configure( { mode: 'serial' } );

	let seed: Seed;

	test.beforeAll( () => {
		const prefix = `ExtFilter ${ Date.now() }`;

		// A: published, flagged, in the category and tag.
		// B: draft, flagged.
		// C: published, not flagged, in the category and tag.
		// D: published, flagged, by another author.
		seed = JSON.parse(
			wpEval( `
				$prefix   = ${ JSON.stringify( prefix ) };
				$admin    = get_user_by( 'login', ${ JSON.stringify( user ) } );
				$other    = wp_insert_user( array( 'user_login' => 'lkb_e2e_extf_' . substr( md5( $prefix ), 0, 10 ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
				$category = wp_insert_term( $prefix . ' category', 'licensio_category' );
				$tag      = wp_insert_term( $prefix . ' tag', 'licensio_tag' );
				$make     = function ( $name, $status, $flag, $author, $terms ) use ( $prefix, $category, $tag ) {
					$id = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => $status, 'post_title' => $prefix . ' ' . $name, 'post_author' => $author ) );
					if ( $flag ) { update_post_meta( $id, '_licensio_kb_example_flag', '1' ); }
					if ( $terms ) {
						wp_set_object_terms( $id, array( (int) $category['term_id'] ), 'licensio_category' );
						wp_set_object_terms( $id, array( (int) $tag['term_id'] ), 'licensio_tag' );
					}
					return $id;
				};
				echo wp_json_encode( array(
					'prefix'   => $prefix,
					'ids'      => array(
						'a' => $make( 'A', 'publish', true, $admin->ID, true ),
						'b' => $make( 'B', 'draft', true, $admin->ID, false ),
						'c' => $make( 'C', 'publish', false, $admin->ID, true ),
						'd' => $make( 'D', 'publish', true, $other, false ),
					),
					'category' => (int) $category['term_id'],
					'tag'      => (int) $tag['term_id'],
					'author'   => (int) $other,
				) );
			` )
		) as Seed;
	} );

	test.afterAll( () => {
		if ( ! seed ) {
			return;
		}

		wpEval( `
			foreach ( ${ JSON.stringify( Object.values( seed.ids ) ) } as $id ) { wp_delete_post( $id, true ); }
			wp_delete_term( ${ seed.category }, 'licensio_category' );
			wp_delete_term( ${ seed.tag }, 'licensio_tag' );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( ${ seed.author } );
		` );
	} );

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	/**
	 * The seeded titles the table shows, by their letter, sorted.
	 *
	 * @param {Locator} app The app's mount element.
	 *
	 * @return {Promise<string[]>} The letters.
	 */
	async function shownLetters( app: Locator ): Promise< string[] > {
		const titles = await app
			.locator( '.ant-table-row .lkb-title__link' )
			.allInnerTexts();

		return titles
			.map( ( title ) => title.replace( `${ seed.prefix } `, '' ).trim() )
			.sort();
	}

	/**
	 * Wait until the table shows exactly these seeded articles.
	 *
	 * @param {Locator}  app     The app's mount element.
	 * @param {string[]} letters The letters, sorted.
	 *
	 * @return {Promise<void>} Resolves once they show.
	 */
	async function expectRows(
		app: Locator,
		letters: string[]
	): Promise< void > {
		await expect.poll( () => shownLetters( app ) ).toEqual( letters );
	}

	/**
	 * The Example flag dropdown in the open Filters popover.
	 *
	 * @param {Page} page The browser page.
	 *
	 * @return {Locator} The dropdown.
	 */
	function flagSelect( page: Page ): Locator {
		return page
			.locator( '.ant-popover .lkb-filters__field' )
			.filter( { hasText: 'Example flag' } )
			.locator( '.ant-select' );
	}

	test( 'the dropdown narrows the list, stays in the URL and clears', async ( {
		page,
	} ) => {
		await page.goto(
			contentManagerUrl( 'articles' ) +
				'&search=' +
				encodeURIComponent( seed.prefix )
		);

		const app = page.locator( '#licensio-kb-articles' );

		await expectRows( app, [ 'A', 'B', 'C', 'D' ] );

		await app.getByRole( 'button', { name: 'Filters' } ).click();
		await flagSelect( page ).click();
		await page
			.locator( '.ant-select-dropdown:visible .ant-select-item-option', {
				hasText: /^Flagged$/,
			} )
			.click();

		await expect( page ).toHaveURL( FLAGGED_URL );
		await expectRows( app, [ 'A', 'B', 'D' ] );
		await expect( app.locator( '.ant-badge-count' ) ).toHaveText( '1' );
		await page.keyboard.press( 'Escape' );

		// With a status.
		await app.locator( '.lkb-pill', { hasText: 'Drafts' } ).click();
		await expectRows( app, [ 'B' ] );

		// Reload keeps it.
		await page.reload();
		await expect( page ).toHaveURL( FLAGGED_URL );
		await expectRows( app, [ 'B' ] );

		// Back and forward keep it.
		await openDashboard( page );
		await page.goBack();
		await expect( page ).toHaveURL( FLAGGED_URL );
		await expectRows( app, [ 'B' ] );
		await page.goForward();
		await page.goBack();
		await expectRows( app, [ 'B' ] );

		await app.locator( '.lkb-pill', { hasText: 'All' } ).click();
		await expectRows( app, [ 'A', 'B', 'D' ] );

		// The dropdown shows the choice, and Clear filters drops it.
		await app.getByRole( 'button', { name: 'Filters' } ).click();
		await expect( flagSelect( page ) ).toContainText( 'Flagged' );
		await page
			.locator( '.ant-popover' )
			.getByRole( 'button', { name: 'Clear filters' } )
			.click();

		await expect( page ).not.toHaveURL( /ext_filters/ );
		await expectRows( app, [ 'A', 'B', 'C', 'D' ] );

		await page.reload();
		await expectRows( app, [ 'A', 'B', 'C', 'D' ] );
	} );

	test( 'a core screen link with the query argument opens the app with the filter set', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=licensio_article&example_flag=flagged&s=' +
				encodeURIComponent( seed.prefix )
		);

		await expect( page ).toHaveURL( /page=licensio-kb-articles/ );
		await expect( page ).toHaveURL( FLAGGED_URL );

		const app = page.locator( '#licensio-kb-articles' );

		await expectRows( app, [ 'A', 'B', 'D' ] );

		await app.getByRole( 'button', { name: 'Filters' } ).click();
		await expect( flagSelect( page ) ).toContainText( 'Flagged' );

		// An option the filter does not have is dropped by the redirect.
		await page.goto(
			'/wp-admin/edit.php?post_type=licensio_article&example_flag=nope&s=' +
				encodeURIComponent( seed.prefix )
		);
		await expect( page ).toHaveURL( /page=licensio-kb-articles/ );
		await expect( page ).not.toHaveURL( /ext_filters/ );
		await expectRows( app, [ 'A', 'B', 'C', 'D' ] );
	} );

	test( 'the list and Select all matching combine the filter with every core filter', async ( {
		page,
	} ) => {
		await openContentManager( page, 'articles' );

		const results = await page.evaluate(
			async ( { prefix, category, tag, author } ) => {
				const { root, nonce } = (
					window as unknown as {
						licensioKbArticles: { root: string; nonce: string };
					}
				 ).licensioKbArticles;
				const call = async ( path: string, query: string ) => {
					const base = root.replace( /\/+$/, '' ) + '/' + path;
					const response = await fetch(
						base +
							( base.includes( '?' ) ? '&' : '?' ) +
							'search=' +
							encodeURIComponent( prefix ) +
							'&' +
							query,
						{ headers: { 'X-WP-Nonce': nonce } }
					);

					return response.json();
				};
				const flag = ( value: string ) =>
					'ext_filters%5Bexample%2Fflag%5D=' + value;
				const list = async ( query: string ) => {
					const page_ = ( await call( 'articles', query ) ) as {
						items: Array< { id: number } >;
						total: number;
					};

					return {
						ids: page_.items.map( ( item ) => item.id ).sort(),
						total: page_.total,
					};
				};

				return {
					flagged: await list( flag( 'flagged' ) ),
					unflagged: await list( flag( 'unflagged' ) ),
					draft: await list( flag( 'flagged' ) + '&status=draft' ),
					category: await list(
						flag( 'flagged' ) + '&category=' + category
					),
					tag: await list( flag( 'flagged' ) + '&tag=' + tag ),
					author: await list(
						flag( 'flagged' ) + '&author=' + author
					),
					paged: await list(
						flag( 'flagged' ) + '&per_page=1&page=2'
					),
					unknown: await list( flag( 'nope' ) ),
					ids: (
						( await call( 'articles/ids', flag( 'flagged' ) ) ) as {
							ids: number[];
						}
					 ).ids.sort(),
				};
			},
			{
				prefix: seed.prefix,
				category: seed.category,
				tag: seed.tag,
				author: seed.author,
			}
		);

		const { a, b, c, d } = seed.ids;
		const sorted = ( ids: number[] ) => [ ...ids ].sort();

		expect( results.flagged ).toEqual( {
			ids: sorted( [ a, b, d ] ),
			total: 3,
		} );
		expect( results.unflagged ).toEqual( { ids: [ c ], total: 1 } );
		expect( results.draft ).toEqual( { ids: [ b ], total: 1 } );
		expect( results.category ).toEqual( { ids: [ a ], total: 1 } );
		expect( results.tag ).toEqual( { ids: [ a ], total: 1 } );
		expect( results.author ).toEqual( { ids: [ d ], total: 1 } );
		expect( results.paged.total ).toBe( 3 );
		expect( results.paged.ids ).toHaveLength( 1 );
		expect( results.unknown ).toEqual( {
			ids: sorted( [ a, b, c, d ] ),
			total: 4,
		} );
		expect( results.ids ).toEqual( sorted( [ a, b, d ] ) );
	} );
} );
