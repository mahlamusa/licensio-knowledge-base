/**
 * The knowledge base views on a block theme.
 *
 * Proves the block templates serve the same views the classic templates do:
 * the category page has no stray line breaks in its cards and keeps each
 * article count on the line of its heading, a single article carries the
 * breadcrumb trail, the search field, the On this page table of contents and
 * the sidebar, and the tag and search pages open with the same hero. Each page
 * has one main, one h1 and, on an article, one feedback form, and a view placed
 * in post content renders nothing rather than looping the page.
 *
 * The article sidebar and its table of contents depend on two settings, so the
 * spec pins them for its run and puts the stored settings back afterwards.
 *
 * The spec seeds its own category, sub-category, tag and article with wp-cli and
 * deletes them afterwards, so besides `LICENSIO_KB_E2E=1` it needs
 * `LICENSIO_KB_WP_CLI`, the command that runs wp-cli against the same site.
 * It also needs a block theme to be active (Twenty Twenty-Five, for one), and
 * skips itself under a classic theme.
 *
 * @since 1.0.0
 * @since 1.0.0 Pins the sidebar settings and covers a view placed in post content.
 */

import { expect, test, type Page } from '@playwright/test';
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

/**
 * A view placed in post content, as the shortcode and as the block.
 *
 * Neither may render there: a view body loops the main query, and inside the
 * loop that renders post content it used to restart that loop until PHP ran
 * out of memory.
 */
const VIEWS_IN_CONTENT = [
	'<!-- wp:shortcode -->[licensio_kb_view view="single"]<!-- /wp:shortcode -->',
	'<!-- wp:shortcode -->[licensio_kb_view view="tag"]<!-- /wp:shortcode -->',
	'<!-- wp:licensio-kb/view {"view":"single"} /-->',
].join( '' );

/**
 * How long a page may take before the spec calls it hung.
 *
 * The memory exhaustion took 8 to 30 seconds per request, so a short
 * navigation timeout fails a regression quickly instead of stalling the run.
 */
const PAGE_TIMEOUT = 15_000;

/**
 * Assert the page has one main landmark and one top-level heading.
 *
 * @since 1.0.0
 *
 * @param {Page} page The browser page.
 *
 * @return {Promise<void>} Resolves once both are counted.
 */
async function expectOneMainAndHeading( page: Page ): Promise< void > {
	await expect( page.locator( 'main' ) ).toHaveCount( 1 );
	await expect( page.locator( 'h1' ) ).toHaveCount( 1 );
}

test.describe( 'Knowledge base views on a block theme', () => {
	test.describe.configure( { mode: 'serial' } );

	test.skip(
		! enabled || '' === wpCli,
		'Set LICENSIO_KB_E2E=1 and LICENSIO_KB_WP_CLI to run.'
	);

	let seeded = {
		parent: 0,
		child: 0,
		tag: 0,
		article: 0,
		category: '',
		single: '',
		tagLink: '',
		post: 0,
		postLink: '',
		settings: '',
	};

	test.beforeAll( () => {
		test.skip(
			'1' !== wpEval( 'echo wp_is_block_theme() ? 1 : 0;' ),
			'The active theme is not a block theme.'
		);

		seeded = JSON.parse(
			wpEval(
				`$settings = base64_encode( (string) wp_json_encode( get_option( 'licensio_settings', null ) ) );
				$pinned = (array) get_option( 'licensio_settings', array() );
				$pinned['article_sidebar_position'] = 'right';
				$pinned['show_table_of_contents'] = true;
				update_option( 'licensio_settings', $pinned );
				$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Block view post ${ RUN }', 'post_content' => '${ VIEWS_IN_CONTENT }' ) );
				$parent = wp_insert_term( 'Block view parent ${ RUN }', 'licensio_category' );
				$child = wp_insert_term( 'Block view child ${ RUN }', 'licensio_category', array( 'parent' => $parent['term_id'] ) );
				$article = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => 'Block view article ${ RUN }', 'post_content' => '${ BODY }${ VIEWS_IN_CONTENT }' ) );
				wp_set_object_terms( $article, array( (int) $parent['term_id'], (int) $child['term_id'] ), 'licensio_category' );
				$tag = wp_insert_term( 'Block view tag ${ RUN }', 'licensio_tag' );
				wp_set_object_terms( $article, array( (int) $tag['term_id'] ), 'licensio_tag' );
				echo wp_json_encode( array( 'parent' => (int) $parent['term_id'], 'child' => (int) $child['term_id'], 'tag' => (int) $tag['term_id'], 'article' => (int) $article, 'category' => get_term_link( (int) $parent['term_id'], 'licensio_category' ), 'single' => get_permalink( $article ), 'tagLink' => get_term_link( (int) $tag['term_id'], 'licensio_tag' ), 'post' => (int) $post, 'postLink' => get_permalink( $post ), 'settings' => $settings ) );`
			)
		);
	} );

	test.afterAll( () => {
		if ( seeded.article > 0 ) {
			wpEval(
				`wp_delete_post( ${ seeded.article }, true ); wp_delete_post( ${ seeded.post }, true ); wp_delete_term( ${ seeded.child }, 'licensio_category' ); wp_delete_term( ${ seeded.parent }, 'licensio_category' ); wp_delete_term( ${ seeded.tag }, 'licensio_tag' );
				$settings = json_decode( base64_decode( '${ seeded.settings }' ), true );
				if ( null === $settings ) { delete_option( 'licensio_settings' ); } else { update_option( 'licensio_settings', $settings ); }`
			);
		}
	} );

	test( 'the category cards carry no stray line breaks', async ( {
		page,
	} ) => {
		await page.goto( seeded.category );
		await expectOneMainAndHeading( page );

		const view = page.locator( '.licensio-kb--category' );

		await expect( view ).toBeVisible();
		await expect(
			view.locator( '.licensio-kb-card' ).first()
		).toBeVisible();
		await expect( view.locator( 'br' ) ).toHaveCount( 0 );

		/*
		 * The article count sits beside its heading in one flex row, not in a
		 * paragraph wpautop added under it. Checked in the markup rather than
		 * by position: a long seeded name wraps the row in a narrow card, which
		 * is the row doing its job.
		 */
		const card = view.locator( '.licensio-kb-card', {
			hasText: `Block view child ${ RUN }`,
		} );

		await expect(
			card.locator(
				'.licensio-kb-card__heading > .licensio-kb-card__title + .licensio-kb-card__count'
			)
		).toHaveCount( 1 );
		await expect(
			card.locator( '.licensio-kb-card__heading p' )
		).toHaveCount( 0 );
	} );

	test( 'a single article carries the breadcrumbs, search, contents and sidebar', async ( {
		page,
	} ) => {
		await page.goto( seeded.single, { timeout: PAGE_TIMEOUT } );
		await expectOneMainAndHeading( page );

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

		/*
		 * One hero, one article and one feedback form. The article's own
		 * content carries the view shortcode and block, and neither renders.
		 */
		await expect( page.locator( '.licensio-kb__hero' ) ).toHaveCount( 1 );
		await expect(
			page.locator( 'article.licensio-kb-article' )
		).toHaveCount( 1 );
		await expect(
			page.locator( 'section.licensio-kb-feedback' )
		).toHaveCount( 1 );
		await expect(
			page.locator( '.licensio-kb-article__body' )
		).not.toContainText( 'licensio_kb_view' );
	} );

	test( 'a view placed in a post renders nothing and does not hang', async ( {
		page,
	} ) => {
		const response = await page.goto( seeded.postLink, {
			timeout: PAGE_TIMEOUT,
		} );

		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( '.licensio-kb__hero' ) ).toHaveCount( 0 );
		await expect(
			page.locator( 'article.licensio-kb-article' )
		).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'licensio_kb_view'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Allowed memory size'
		);
	} );

	test( 'the tag and search pages open with the hero and list the article', async ( {
		page,
	} ) => {
		const views = [
			{ url: seeded.tagLink, view: '.licensio-kb--tag' },
			{
				url: `/?s=${ encodeURIComponent(
					`Block view article ${ RUN }`
				) }&post_type=licensio_article`,
				view: '.licensio-kb--search',
			},
		];

		for ( const { url, view } of views ) {
			await page.goto( url );
			await expectOneMainAndHeading( page );

			await expect( page.locator( '.licensio-kb__hero' ) ).toHaveCount(
				1
			);
			await expect(
				page.locator( '.licensio-kb__crumbs' )
			).toBeVisible();
			await expect(
				page.locator( 'form.licensio-kb__search input[type="search"]' )
			).toBeVisible();
			await expect(
				page.locator( view ).getByRole( 'link', {
					name: `Block view article ${ RUN }`,
				} )
			).toBeVisible();
			await expect( page.locator( `${ view } br` ) ).toHaveCount( 0 );
		}
	} );
} );
