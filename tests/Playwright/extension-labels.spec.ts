/**
 * Extension labels with characters HTML gives a meaning to show as declared.
 *
 * Uses the example extension's labels feature
 * (`tests/fixtures/extension-example/features/labels.php`): labels holding a
 * less-than sign, an ampersand, a percent sequence and an encoded closing
 * script tag. Every app must show them exactly as written, as text, with no
 * HTML entity left in sight: the Articles column header, Columns picker
 * group, filter label and options, bulk action and row action; the Tags
 * column header and row action; the Categories column label and node menu.
 *
 * The example declares these items only while the option
 * `licensio_kb_example_labels` is on, so the extra columns never change the
 * apps under other specs. This spec turns it on and off again, and runs alone
 * (`@one-at-a-time`) so no other spec sees the items meanwhile.
 *
 * The suite needs a running WordPress with this plugin and the example
 * extension active, an administrator account and wp-cli to seed content, so
 * it is skipped unless `LICENSIO_KB_E2E=1`, the two credential variables and
 * `LICENSIO_KB_WP_CLI` are present.
 *
 * @since 1.1.0
 */

import { expect, test, type Locator } from '@playwright/test';
import {
	contentManagerUrl,
	openContentManager,
} from './helpers/content-manager';
import { failOnUnresolvedComponents } from './helpers/ant-components';
import { openDashboard, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

failOnUnresolvedComponents();

const enabled = process.env.LICENSIO_KB_E2E === '1';

/** Entities that would mean a label was shown encoded. */
const ENTITY = /&(lt|gt|amp|quot|#0?39);/;

interface Seed {
	word: string;
	article: number;
	category: number;
	tag: number;
}

/**
 * Expect an element's text to hold no HTML entity.
 *
 * @since 1.1.0
 *
 * @param {Locator} element The element.
 *
 * @return {Promise<void>} Resolves once checked.
 */
async function expectNoEntities( element: Locator ): Promise< void > {
	expect( await element.textContent() ).not.toMatch( ENTITY );
}

test.describe( 'extension labels @one-at-a-time', () => {
	test.skip(
		! enabled || ! user || ! password || ! wpCli,
		'Set LICENSIO_KB_E2E=1, the admin credentials and LICENSIO_KB_WP_CLI to run.'
	);

	test.describe.configure( { mode: 'serial' } );

	let seed: Seed;

	test.beforeAll( () => {
		const word = `Labels${ Date.now().toString( 36 ) }`;

		seed = JSON.parse(
			wpEval( `
				update_option( 'licensio_kb_example_labels', 1 );
				$word     = ${ JSON.stringify( word ) };
				$category = wp_insert_term( $word . ' category', 'licensio_category' );
				$tag      = wp_insert_term( $word . ' tag', 'licensio_tag' );
				echo wp_json_encode( array(
					'word'     => $word,
					'article'  => wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => $word . ' article' ) ),
					'category' => (int) $category['term_id'],
					'tag'      => (int) $tag['term_id'],
				) );
			` )
		) as Seed;
	} );

	test.afterAll( () => {
		wpEval( "delete_option( 'licensio_kb_example_labels' );" );

		if ( ! seed ) {
			return;
		}

		wpEval( `
			wp_delete_post( ${ seed.article }, true );
			wp_delete_term( ${ seed.category }, 'licensio_category' );
			wp_delete_term( ${ seed.tag }, 'licensio_tag' );
		` );
	} );

	test.beforeEach( async ( { page } ) => {
		await openDashboard( page );
	} );

	test( 'the Articles app shows every label as declared', async ( {
		page,
	} ) => {
		await page.goto(
			`${ contentManagerUrl( 'articles' ) }&search=${ encodeURIComponent(
				seed.word
			) }`
		);

		const app = page.locator( '#licensio-kb-articles' );
		const row = app.locator( `tr[data-row-key="${ seed.article }"]` );

		await expect( row ).toBeVisible();

		// The column header and the row action.
		await expect(
			app.locator( '.ant-table-thead th', { hasText: 'Words < 300' } )
		).toHaveText( 'Words < 300' );
		await row.hover();
		await expect(
			row.locator( '.lkb-rowactions' ).getByText( 'CTR %ab < 5 & more', {
				exact: true,
			} )
		).toBeVisible();

		// The Columns picker group and checkbox.
		await app.getByRole( 'button', { name: 'Columns' } ).click();

		const picker = page.locator( '.ant-popover:visible' );

		await expect(
			picker.getByText( 'Score < 50 & up', { exact: true } )
		).toBeVisible();
		await expect(
			picker.getByRole( 'checkbox', { name: 'Words < 300' } )
		).toBeChecked();
		await expectNoEntities( picker );
		await page.keyboard.press( 'Escape' );

		// The filter label and its options.
		await app.getByRole( 'button', { name: 'Filters' } ).click();

		const field = page
			.locator( '.ant-popover .lkb-filters__field' )
			.filter( { hasText: 'CTR %ab' } );

		await expect( field ).toBeVisible();
		await field.locator( '.ant-select' ).click();

		const options = page.locator(
			'.ant-select-dropdown:visible .ant-select-item-option'
		);

		await expect( options ).toHaveText( [
			'Words <300 & less',
			'</script> end',
		] );
		await page.keyboard.press( 'Escape' );
		await page.keyboard.press( 'Escape' );

		// The bulk action.
		await row.locator( '.ant-checkbox-input' ).check();
		await app.locator( '.lkb-bulk__choice' ).click();

		const dropdown = page
			.locator( '.ant-select-dropdown:not(.ant-select-dropdown-hidden)' )
			.filter( { hasNotText: 'Words <300 & less' } );

		// The list is virtual: scroll to its end until the last choice renders.
		await expect( async () => {
			await dropdown
				.locator( '.rc-virtual-list-holder' )
				.evaluate( ( holder ) => {
					holder.scrollTop = holder.scrollHeight;
				} );
			await expect(
				dropdown.getByText( 'Score < 50 & up', { exact: true } )
			).toBeVisible( { timeout: 1_000 } );
		} ).toPass();
		await expectNoEntities( dropdown );

		await expectNoEntities( app );
	} );

	test( 'the Tags app shows the column and row action labels as declared', async ( {
		page,
	} ) => {
		await page.goto(
			`${ contentManagerUrl( 'tags' ) }&search=${ encodeURIComponent(
				seed.word
			) }`
		);

		const app = page.locator( '#licensio-kb-tags' );
		const row = app.locator( `tr[data-row-key="${ seed.tag }"]` );

		await expect( row ).toBeVisible();
		await expect(
			app.locator( '.ant-table-thead th', {
				hasText: 'Words < 300 & up',
			} )
		).toHaveText( 'Words < 300 & up' );
		await row.hover();
		await expect(
			row.getByRole( 'button', {
				name: `CTR %ab < 5 & more “${ seed.word } tag”`,
			} )
		).toHaveText( 'CTR %ab < 5 & more' );
		await expectNoEntities( app );
	} );

	test( 'the Categories app shows the column and menu labels as declared', async ( {
		page,
	} ) => {
		const app = await openContentManager( page, 'categories' );
		const node = app.locator( '.lkb-tree__node', {
			hasText: `${ seed.word } category`,
		} );
		const column = node.locator( '[data-lkb-column="example/label-term"]' );

		await expect( column ).toHaveAttribute( 'title', 'Words < 300 & up' );
		await expect( column.locator( '.screen-reader-text' ) ).toHaveText(
			'Words < 300 & up:'
		);

		await node.locator( `[data-lkb-actions="${ seed.category }"]` ).click();

		const menu = page.locator( '.lkb-node-menu:visible' );

		await expect(
			menu.getByRole( 'menuitem', { name: 'CTR %ab < 5 & more' } )
		).toBeVisible();
		await expectNoEntities( menu );
		await expectNoEntities( app );
	} );
} );
