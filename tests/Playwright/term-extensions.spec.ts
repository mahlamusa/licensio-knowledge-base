/**
 * Extension columns and row actions in the Categories and Tags apps.
 *
 * Uses the example extension's term feature
 * (`tests/fixtures/extension-example/features/terms.php`): a column a script
 * renders from the counter the REST list fills, a column with no script that
 * shows the `text` fallback, a column that is not default visible and stays
 * hidden, a row action that bumps the counter and refreshes the row through
 * `ctx.refresh( [ id ] )`, a declared row action with no script that the
 * menu leaves out, and a row action whose `visible( row )` shows it only on
 * terms named `Marked…`. The refresh must ask for just that row (`include[]`) and
 * clean up the cell's last render before it renders again. The text column
 * sits after Slug, so an open Tags Quick edit row's shared cell spans it. A
 * double click on the Tags action runs it once, and an action that fails
 * shows the generic error toast.
 *
 * The extra columns also keep the page from scrolling sideways: at 1024px
 * the Tags table has no room to spare, so it scrolls inside its card, an
 * extension cell's long word breaks rather than widening the table, and the
 * extension row actions wrap below Edit and Quick edit rather than widening
 * the Name column; on a phone a category row puts its name on a line of its
 * own.
 *
 * The example declares the term items only while the option
 * `licensio_kb_example_terms` is on, so the extra columns never change the
 * term apps under other specs, such as the width checks that expect the
 * Tags table alone to fit at 1024px. This spec turns it on and off again, and runs
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
import { foldAdminMenu, measureFrame } from './helpers/layout';
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

	test.describe( 'the layout', () => {
		const word = `Unbrokenextensionname${ suffix }`.padEnd( 40, 'x' );
		let tagId = 0;
		let categoryId = 0;

		test.beforeAll( () => {
			tagId = makeTerm( 'licensio_tag', word );
			categoryId = makeTerm( 'licensio_category', word );
		} );

		test.afterAll( () => {
			dropTerm( 'licensio_tag', tagId );
			dropTerm( 'licensio_category', categoryId );
		} );

		test( 'extension columns never make the page scroll sideways', async ( {
			page,
		} ) => {
			await page.setViewportSize( { width: 1024, height: 900 } );
			await page.goto(
				`${ contentManagerUrl( 'tags' ) }&search=${ encodeURIComponent(
					word
				) }`
			);
			await foldAdminMenu( page, false );

			const cell = page
				.locator(
					`tr[data-row-key="${ tagId }"] td.lkb-tags__extension`
				)
				.filter( { hasText: word } );

			await expect( cell ).toContainText( word );
			expect(
				( await cell.boundingBox() )?.width ?? Infinity,
				'the extension cell breaks its long word'
			).toBeLessThan( 200 );

			let frame = await measureFrame( page );

			expect( frame.page + frame.container, 'Tags at 1024px' ).toBe( 0 );

			// Extension row actions wrap on lines of their own, so the Name
			// column is no wider than Edit and Quick edit side by side.
			const name = await page
				.locator( `tr[data-row-key="${ tagId }"]` )
				.evaluate( ( row ) => {
					const core = row.querySelector(
						'.lkb-rowactions:not(.lkb-rowactions--extensions)'
					) as HTMLElement;
					const buttons = core.querySelectorAll( '.ant-btn' );
					const td = core.closest( 'td' ) as HTMLElement;
					const style = getComputedStyle( td );

					return {
						actions:
							buttons[
								buttons.length - 1
							].getBoundingClientRect().right -
							buttons[ 0 ].getBoundingClientRect().left,
						content:
							td.clientWidth -
							parseFloat( style.paddingLeft ) -
							parseFloat( style.paddingRight ),
						extensions: row.querySelectorAll(
							'.lkb-rowactions--extensions .ant-btn'
						).length,
					};
				} );

			expect( name.extensions, 'extension row actions' ).toBe( 2 );
			expect(
				name.content,
				'Name column width beside its core row actions'
			).toBeLessThanOrEqual( Math.ceil( name.actions ) + 1 );

			await page.setViewportSize( { width: 390, height: 900 } );
			await page.goto(
				`${ contentManagerUrl( 'categories' ) }&search=${ encodeURIComponent(
					word
				) }`
			);

			const node = page.locator( '.lkb-tree__node', { hasText: word } );

			await expect(
				node.locator( '[data-lkb-column="example/term-text"]' )
			).toBeVisible();

			frame = await measureFrame( page );

			expect( frame.page + frame.container, 'Categories at 390px' ).toBe(
				0
			);
			expect(
				( await node.locator( '.lkb-tree__name' ).boundingBox() )
					?.width ?? 0,
				'the category name keeps its width on a phone'
			).toBeGreaterThan( 150 );
		} );
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
				.dblclick();
			await refreshed;

			await expect(
				row.locator( `[data-example-note="${ id }"]` )
			).toHaveText( 'Rendered 1' );
			expect( await cleanups( page ) ).toContain( id );

			// The text column, placed after Slug, sits between Name and
			// Description, so an open Quick edit row's Name cell spans it.
			await expect( table.locator( 'thead th' ) ).toHaveText( [
				'',
				/^Name/,
				'Slug',
				'Example text',
				'Description',
				/^Published/,
				'Example note',
			] );
			await expect( row.locator( 'td' ).nth( 3 ) ).toHaveText(
				`Text for ${ name }`
			);
			await row.hover();
			await row.locator( '[data-lkb-quick]' ).click();

			const open = table.locator( 'tr.ant-table-row' ).filter( {
				has: page.locator( 'textarea' ),
			} );

			await expect( open.locator( 'td' ) ).toHaveCount( 4 );
			await expect( open.locator( 'td' ).nth( 1 ) ).toHaveAttribute(
				'colspan',
				'4'
			);
			await open.getByRole( 'button', { name: 'Cancel' } ).click();
			await expect( row.locator( 'td' ) ).toHaveCount( 7 );

			// The double click ran the action once.
			await page.reload();
			await expect(
				row.locator( `[data-example-note="${ id }"]` )
			).toHaveText( 'Rendered 1' );

			await row.hover();
			await row
				.getByRole( 'button', { name: `Failing action “${ name }”` } )
				.click();
			await expect(
				page
					.locator( '.ant-message' )
					.getByText( 'Failing action could not be completed.' )
			).toBeVisible();
		} );
	} );

	test.describe( 'per row visibility in the Tags app', () => {
		const plainName = `Plain visibility ${ suffix }`;
		const markedName = `Marked visibility ${ suffix }`;
		let plainId = 0;
		let markedId = 0;

		test.beforeAll( () => {
			plainId = makeTerm( 'licensio_tag', plainName );
			markedId = makeTerm( 'licensio_tag', markedName );
		} );

		test.afterAll( () => {
			dropTerm( 'licensio_tag', plainId );
			dropTerm( 'licensio_tag', markedId );
		} );

		test( 'hides a row action on the rows its visible check refuses', async ( {
			page,
		} ) => {
			expect( plainId ).toBeGreaterThan( 0 );
			expect( markedId ).toBeGreaterThan( 0 );

			await page.goto(
				`${ contentManagerUrl( 'tags' ) }&search=${ encodeURIComponent(
					`visibility ${ suffix }`
				) }`
			);

			const table = page.locator( '#licensio-kb-tags .ant-table' );
			const plain = table.locator( `tr[data-row-key="${ plainId }"]` );
			const marked = table.locator( `tr[data-row-key="${ markedId }"]` );

			await expect( plain ).toBeVisible();
			await expect( marked ).toBeVisible();
			await expect(
				plain.getByRole( 'button', {
					name: `Bump note “${ plainName }”`,
				} )
			).toHaveCount( 1 );
			await expect(
				plain.getByRole( 'button', {
					name: `Marked action “${ plainName }”`,
				} )
			).toHaveCount( 0 );
			await expect(
				marked.getByRole( 'button', {
					name: `Marked action “${ markedName }”`,
				} )
			).toHaveCount( 1 );
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

			await node.locator( `[data-lkb-actions="${ id }"]` ).click();
			await menu
				.getByRole( 'menuitem', { name: 'Failing action' } )
				.click();
			await expect(
				page
					.locator( '.ant-message' )
					.getByText( 'Failing action could not be completed.' )
			).toBeVisible();
		} );
	} );
} );
