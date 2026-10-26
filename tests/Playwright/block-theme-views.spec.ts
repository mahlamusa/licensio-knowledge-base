/**
 * The knowledge base views on a block theme.
 *
 * Proves the block templates serve the same views the classic templates do:
 * the category page has no stray line breaks in its cards and keeps each
 * article count on the line of its heading, and a single article carries the
 * breadcrumb trail, the search field, the On this page table of contents and
 * the sidebar.
 *
 * The spec seeds its own category, sub-category and article with wp-cli and
 * deletes them afterwards, so besides `LICENSIO_KB_E2E=1` it needs
 * `LICENSIO_KB_WP_CLI`, the command that runs wp-cli against the same site.
 * It also needs a block theme to be active (Twenty Twenty-Five, for one), and
 * skips itself under a classic theme.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';
import { wpCli, wpEval } from './helpers/wp-cli';

const enabled = process.env.LICENSIO_KB_E2E === '1';

/** Keeps the seeded names apart from anything else on the site. */
const RUN = `${ Date.now() }`;

/** An article body with enough headings for a table of contents. */
const BODY = [
	'<!-- wp:paragraph --><p>Opening paragraph.</p><!-- /wp:paragraph -->',
	'<!-- wp:heading --><h2 class="wp-block-heading">First section</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>First section body.</p><!-- /wp:paragraph -->',
	'<!-- wp:heading --><h2 class="wp-block-heading">Second section</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>Second section body.</p><!-- /wp:paragraph -->',
].join( '' );

test.describe( 'Knowledge base views on a block theme', () => {
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || '' === wpCli,
		'Set LICENSIO_KB_E2E=1 and LICENSIO_KB_WP_CLI to run.'
	);

	let seeded = { parent: 0, child: 0, article: 0, category: '', single: '' };

	test.beforeAll( () => {
		test.skip(
			'1' !== wpEval( 'echo wp_is_block_theme() ? 1 : 0;' ),
			'The active theme is not a block theme.'
		);

		seeded = JSON.parse(
			wpEval(
				`$parent = wp_insert_term( 'Block view parent ${ RUN }', 'licensio_category' );
				$child = wp_insert_term( 'Block view child ${ RUN }', 'licensio_category', array( 'parent' => $parent['term_id'] ) );
				$article = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => 'Block view article ${ RUN }', 'post_content' => '${ BODY }' ) );
				wp_set_object_terms( $article, array( (int) $parent['term_id'], (int) $child['term_id'] ), 'licensio_category' );
				echo wp_json_encode( array( 'parent' => (int) $parent['term_id'], 'child' => (int) $child['term_id'], 'article' => (int) $article, 'category' => get_term_link( (int) $parent['term_id'], 'licensio_category' ), 'single' => get_permalink( $article ) ) );`
			)
		);
	} );

	test.afterAll( () => {
		if ( seeded.article > 0 ) {
			wpEval(
				`wp_delete_post( ${ seeded.article }, true ); wp_delete_term( ${ seeded.child }, 'licensio_category' ); wp_delete_term( ${ seeded.parent }, 'licensio_category' );`
			);
		}
	} );

	test( 'the category cards carry no stray line breaks', async ( {
		page,
	} ) => {
		await page.goto( seeded.category );

		const view = page.locator( '.licensio-kb--category' );

		await expect( view ).toBeVisible();
		await expect(
			view.locator( '.licensio-kb-card' ).first()
		).toBeVisible();
		await expect( view.locator( 'br' ) ).toHaveCount( 0 );

		// The article count sits on the line of its heading, not under it.
		const card = view.locator( '.licensio-kb-card', {
			hasText: `Block view child ${ RUN }`,
		} );
		const heading = await card
			.locator( '.licensio-kb-card__title' )
			.boundingBox();
		const count = await card
			.locator( '.licensio-kb-card__count' )
			.boundingBox();

		expect( heading ).not.toBeNull();
		expect( count ).not.toBeNull();
		expect( count!.y ).toBeLessThan( heading!.y + heading!.height );
	} );

	test( 'a single article carries the breadcrumbs, search, contents and sidebar', async ( {
		page,
	} ) => {
		await page.goto( seeded.single );

		const crumbs = page.locator( '.licensio-kb__crumbs' );

		await expect( crumbs ).toBeVisible();
		await expect( crumbs ).toContainText( `Block view parent ${ RUN }` );
		await expect(
			page.locator( 'form.licensio-kb__search input[type="search"]' )
		).toBeVisible();
		await expect( page.locator( '.licensio-kb__sidebar' ) ).toBeVisible();

		const toc = page.locator( '.licensio-kb-widget--toc' );

		await expect( toc ).toBeVisible();
		await expect(
			toc.getByRole( 'link', { name: 'First section' } )
		).toBeVisible();
		await expect(
			toc.getByRole( 'link', { name: 'Second section' } )
		).toBeVisible();

		// One hero and one article, not the template's and the shortcode's both.
		await expect( page.locator( '.licensio-kb__hero' ) ).toHaveCount( 1 );
		await expect(
			page.locator( 'article.licensio-kb-article' )
		).toHaveCount( 1 );
	} );
} );
