/**
 * Extension columns and row actions in the Categories and Tags apps.
 *
 * Uses the example extension's term feature
 * (`tests/fixtures/extension-example/features/terms.php`): a column a script
 * renders from the counter the REST list fills, a column with no script that
 * shows the `text` fallback, a column that is not default visible and stays
 * hidden, a row action that bumps the counter and refreshes the row through
 * `ctx.refresh( [ id ] )`, and a declared row action with no script that the
 * menu leaves out. The refresh must ask for just that row (`include[]`) and
 * clean up the cell's last render before it renders again.
 *
 * The example declares the term items only while the option
 * `licensio_kb_example_terms` is on, so the extra columns never change the
 * term apps under other specs. This spec turns it on and off again, and runs
 * alone (`@one-at-a-time`) so no other spec sees the columns meanwhile.
 *
 * The suite needs a running WordPress with this plugin and the example
 * extension active, an administrator account and wp-cli to make the terms,
 * so it is skipped unless `LICENSIO_KB_E2E=1`, the two credential variables
 * and `LICENSIO_KB_WP_CLI` are present.
 *
 * @since 1.1.0
 */

import { expect, test, type Page } from '@playwright/test';
import { contentManagerUrl } from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

test.skip(
	! enabled || ! user || ! password || ! wpCli,
	'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
);

const suffix = Date.now().toString( 36 );

/**
 * Make a term and answer its ID.
 *
 * @since 1.1.0
 *
 * @param {string} taxonomy The taxonomy.
 * @param {string} name     The term name.
 *
 * @return {number} The term ID.
 */
function makeTerm( taxonomy: string, name: string ): number {
	return Number(
		wpEval(
			`$t = wp_insert_term( ${ JSON.stringify(
				name
			) }, ${ JSON.stringify(
				taxonomy
			) } ); echo is_wp_error( $t ) ? 0 : $t['term_id'];`
		)
	);
}

/**
 * Delete a term.
 *
 * @since 1.1.0
 *
 * @param {string} taxonomy The taxonomy.
 * @param {number} id       The term ID.
 *
 * @return {void}
 */
function dropTerm( taxonomy: string, id: number ): void {
	if ( id > 0 ) {
		wpEval( `wp_delete_term( ${ id }, ${ JSON.stringify( taxonomy ) } );` );
	}
}

/**
 * Wait for the refresh of one row: a list request asking for it alone.
 *
 * @since 1.1.0
 *
 * @param {Page}   page The browser page.
 * @param {string} base The list route, `categories` or `tags`.
 * @param {number} id   The term ID.
 *
 * @return {Promise<unknown>} Resolves with the request.
 */
function refreshOf( page: Page, base: string, id: number ) {
	return page.waitForRequest( ( request ) => {
		const url = decodeURIComponent( request.url() );

		return (
			'GET' === request.method() &&
			url.includes( `licensio-kb/v1/${ base }` ) &&
			url.includes( `include[]=${ id }` )
		);
	} );
}

/**
 * The cleanups the example column ran, by row ID.
 *
 * @since 1.1.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<number[]>} The row IDs.
 */
function cleanups( page: Page ): Promise< number[] > {
	return page.evaluate(
		() =>
			(
				window as unknown as {
					licensioKbExampleTermCleanups?: number[];
				}
			 ).licensioKbExampleTermCleanups ?? []
	);
}

test.describe( 'term app extensions @one-at-a-time', () => {
	test.beforeAll( () => {
		wpEval( "update_option( 'licensio_kb_example_terms', 1 );" );
	} );

	test.afterAll( () => {
		wpEval( "delete_option( 'licensio_kb_example_terms' );" );
	} );

	test.describe( 'the Tags app', () => {
		const name = `Ext tag ${ suffix }`;
		let id = 0;

		test.beforeAll( () => {
			id = makeTerm( 'licensio_tag', name );
		} );

		test.afterAll( () => {
			dropTerm( 'licensio_tag', id );
		} );

		test( 'shows extension columns and runs an extension row action', async ( {
			page,
		} ) => {
			expect( id ).toBeGreaterThan( 0 );

			await page.goto(
				`${ contentManagerUrl( 'tags' ) }&search=${ encodeURIComponent(
					name
				) }`
			);

			const table = page.locator( '#licensio-kb-tags .ant-table' );
			const row = table.locator( `tr[data-row-key="${ id }"]` );

			await expect( row ).toBeVisible();
			await expect(
				table.locator( 'th', { hasText: 'Example note' } )
			).toBeVisible();
			await expect(
				table.locator( 'th', { hasText: 'Example text' } )
			).toBeVisible();
			await expect(
				table.locator( 'th', { hasText: 'Example hidden' } )
			).toHaveCount( 0 );
			await expect(
				row.locator( `[data-example-note="${ id }"]` )
			).toHaveText( 'Rendered 0' );
			await expect( row ).toContainText( `Text for ${ name }` );
			await expect( row ).not.toContainText( 'Never shown' );

			await row.hover();
			await expect(
				row.getByRole( 'button', { name: `Silent action “${ name }”` } )
			).toHaveCount( 0 );

			const refreshed = refreshOf( page, 'tags', id );

			await row
				.getByRole( 'button', { name: `Bump note “${ name }”` } )
				.click();
			await refreshed;

			await expect(
				row.locator( `[data-example-note="${ id }"]` )
			).toHaveText( 'Rendered 1' );
			expect( await cleanups( page ) ).toContain( id );
		} );
	} );

	test.describe( 'the Categories app', () => {
		const name = `Ext category ${ suffix }`;
		let id = 0;

		test.beforeAll( () => {
			id = makeTerm( 'licensio_category', name );
		} );

		test.afterAll( () => {
			dropTerm( 'licensio_category', id );
		} );

		test( 'shows extension cells and runs an extension row action', async ( {
			page,
		} ) => {
			expect( id ).toBeGreaterThan( 0 );

			await page.goto(
				`${ contentManagerUrl( 'categories' ) }&search=${ encodeURIComponent(
					name
				) }`
			);

			const node = page.locator( '.lkb-tree__node', { hasText: name } );

			await expect( node ).toBeVisible();
			await expect(
				node.locator( `[data-example-note="${ id }"]` )
			).toHaveText( 'Rendered 0' );
			await expect(
				node.locator( '[data-lkb-column="example/term-text"]' )
			).toHaveText( `Example text: Text for ${ name }` );
			await expect(
				node.locator( '[data-lkb-column="example/term-hidden"]' )
			).toHaveCount( 0 );

			await node.locator( `[data-lkb-actions="${ id }"]` ).click();

			const menu = page.locator(
				'.lkb-node-menu:not(.ant-dropdown-hidden)'
			);

			await expect(
				menu.getByRole( 'menuitem', { name: 'Bump note' } )
			).toBeVisible();
			await expect(
				menu.getByRole( 'menuitem', { name: 'Silent action' } )
			).toHaveCount( 0 );

			const refreshed = refreshOf( page, 'categories', id );

			await menu.getByRole( 'menuitem', { name: 'Bump note' } ).click();
			await refreshed;

			await expect(
				node.locator( `[data-example-note="${ id }"]` )
			).toHaveText( 'Rendered 1' );
			expect( await cleanups( page ) ).toContain( id );
		} );
	} );
} );
