/**
 * Every admin app fits the screen without a horizontal scroll bar.
 *
 * Visits each Vue app (and each Tools tab, and the second step of the import
 * wizard) at common desktop widths with the admin menu expanded and folded,
 * and checks that neither the page, nor the app's main region, nor a table
 * inside it scrolls sideways. On a wide monitor the screen stays a centred column with space
 * either side rather than running to the window edges. At phone width the
 * page itself must not scroll sideways; a wide table may scroll inside itself,
 * and a tag slug still reads as one word rather than breaking letter by letter.
 * Once the page scrolls, the app header sits right under a fixed admin bar,
 * and at the top of the screen on a phone, where the admin bar scrolls away.
 * On a small laptop (1024px) the Articles table leaves out Attachments,
 * Rating, Views and Reading time until the user picks columns, so Categories
 * and Tags have room, and an open Tags Quick edit row keeps its fields
 * readable.
 *
 * When wp-cli is available (`LICENSIO_KB_WP_CLI`), a long-titled article,
 * a long tag name and a category for it are added for the run and removed after it, and the admin
 * user's article columns are reset to the defaults, so the wrapping is
 * checked with long content and the default columns.
 *
 * The suite needs a running WordPress with this plugin active and an
 * administrator account, so it is skipped unless `LICENSIO_KB_E2E=1` and the
 * two credential variables are present.
 *
 * @since 1.0.0
 */

import { expect, test, type Page } from '@playwright/test';
import { contentManagerUrl } from './helpers/content-manager';
import { foldAdminMenu, measureFrame } from './helpers/layout';
import { logIn, password, user } from './helpers/session';
import { wpCli, wpEval } from './helpers/wp-cli';

const enabled = process.env.LICENSIO_KB_E2E === '1';

const SUBMENU = '/wp-admin/edit.php?post_type=licensio_article&page=';

/** The desktop widths every screen must fit. */
const DESKTOP_WIDTHS = [ 1024, 1280, 1440, 1920 ];

/** Makes this run's tag slug unique, kept short so it fits a narrow column. */
const suffix = Date.now().toString( 36 );

/** A tag whose slug has no hyphen, so it never needs to break. */
const SHORT_SLUG = `slugcheck${ suffix }`;

/** One screen to check, and how to reach it once its URL has loaded. */
interface Screen {
	name: string;
	url: string;
	open?: ( page: Page ) => Promise< void >;
}

/**
 * Open a Tools tab by the start of its name.
 *
 * @since 1.0.0
 *
 * @param {string} name The start of the tab's accessible name.
 *
 * @return {Function} Opens the tab, or skips when the tab is not shown.
 */
function toolsTab( name: string ): ( page: Page ) => Promise< void > {
	return async ( page ) => {
		await expect(
			page.getByRole( 'tab', { name: 'Search Index' } )
		).toBeVisible();

		const tab = page.getByRole( 'tab', {
			name: new RegExp( `^${ name }` ),
		} );

		test.skip(
			0 === ( await tab.count() ),
			`The ${ name } tab is hidden.`
		);
		await tab.click();
		await expect( tab ).toHaveAttribute( 'aria-selected', 'true' );
	};
}

const SCREENS: Screen[] = [
	{ name: 'Articles', url: contentManagerUrl( 'articles' ) },
	{ name: 'Categories', url: contentManagerUrl( 'categories' ) },
	{ name: 'Tags', url: contentManagerUrl( 'tags' ) },
	{ name: 'Feedback', url: `${ SUBMENU }licensio-kb-feedback` },
	{ name: 'Analytics', url: `${ SUBMENU }licensio-kb-analytics` },
	{
		name: 'Tools, Migration',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Migration' ),
	},
	{
		name: 'Tools, import wizard categories step',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: async ( page ) => {
			await toolsTab( 'Migration' )( page );

			const root = page.locator( '.licensio-kb-migration' );

			await root
				.locator( '.licensio-kb-migration__source input[type="radio"]' )
				.first()
				.check();

			const next = root.getByRole( 'button', {
				name: 'Next: categories',
			} );

			test.skip(
				0 === ( await next.count() ),
				'The first import source offers no categories step.'
			);
			await next.click();
			await expect( next ).toBeHidden();
		},
	},
	{
		name: 'Tools, Search Index',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Search Index' ),
	},
	{
		name: 'Tools, Data Maintenance',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Data' ),
	},
	{
		name: 'Tools, Duplicates',
		url: `${ SUBMENU }licensio-kb-tools`,
		open: toolsTab( 'Duplicates' ),
	},
	{ name: 'Settings', url: `${ SUBMENU }licensio-kb-settings` },
];

test.describe( 'App width', () => {
	test.skip(
		! enabled || ! user || ! password,
		'Set LICENSIO_KB_E2E=1 and the admin credentials to run.'
	);

	/** What the seeding made, removed again after the run. */
	let seeded = '';

	test.beforeAll( () => {
		if ( ! wpCli ) {
			return;
		}

		seeded = wpEval( `
			$admin = get_user_by( 'login', '${ user }' );
			$prefs = $admin ? get_user_meta( $admin->ID, 'licensio_kb_article_list_prefs', true ) : '';
			if ( $admin ) {
				delete_user_meta( $admin->ID, 'licensio_kb_article_list_prefs' );
			}
			$long  = term_exists( 'A very long tag name to check that tags wrap', 'licensio_tag' ) ?: wp_insert_term( 'A very long tag name to check that tags wrap', 'licensio_tag' );
			$short = wp_insert_term( '${ SHORT_SLUG }', 'licensio_tag' );
			$post  = wp_insert_post( array( 'post_type' => 'licensio_article', 'post_status' => 'publish', 'post_title' => 'How to configure automatic license key renewal and handle expired subscriptions across multisite networks', 'post_author' => $admin ? $admin->ID : 0 ) );
			wp_set_object_terms( $post, array( (int) $long['term_id'] ), 'licensio_tag' );
			$category = wp_insert_term( 'Width check category ${ suffix }', 'licensio_category' );
			wp_set_object_terms( $post, array( (int) $category['term_id'] ), 'licensio_category' );
			echo wp_json_encode( array( 'admin' => $admin ? $admin->ID : 0, 'prefs' => $prefs, 'terms' => array( (int) $long['term_id'], (int) $short['term_id'] ), 'category' => (int) $category['term_id'], 'post' => $post ) );
		` );
	} );

	test.afterAll( () => {
		if ( ! seeded ) {
			return;
		}

		wpEval( `
			$seeded = json_decode( '${ seeded }', true );
			wp_delete_post( $seeded['post'], true );
			foreach ( $seeded['terms'] as $term ) {
				wp_delete_term( $term, 'licensio_tag' );
			}
			wp_delete_term( $seeded['category'], 'licensio_category' );
			if ( $seeded['admin'] && $seeded['prefs'] ) {
				update_user_meta( $seeded['admin'], 'licensio_kb_article_list_prefs', $seeded['prefs'] );
			}
		` );
	} );

	test.beforeEach( async ( { page } ) => {
		await logIn( page );
	} );

	test( 'a short tag slug stays on one line on a phone', async ( {
		page,
	} ) => {
		test.skip( ! seeded, 'Set LICENSIO_KB_WP_CLI to run.' );

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( contentManagerUrl( 'tags' ) );
		await page.getByLabel( 'Search tags' ).fill( SHORT_SLUG );
		await page.getByLabel( 'Search tags' ).press( 'Enter' );

		const slug = page
			.locator( '.ant-table-row', { hasText: SHORT_SLUG } )
			.locator( '.lkb-tags__slug' );

		await expect( slug ).toHaveText( SHORT_SLUG );
		expect(
			await slug.evaluate( ( el ) => el.getClientRects().length ),
			'lines the slug takes'
		).toBe( 1 );
	} );

	test( 'the app header sticks under the admin bar only while the bar is fixed', async ( {
		page,
	} ) => {
		// Core fixes the admin bar down to 601px and lets it scroll away below.
		for ( const [ width, top ] of [
			[ 390, 0 ],
			[ 700, 46 ],
		] ) {
			await page.setViewportSize( { width, height: 400 } );
			await page.goto( contentManagerUrl( 'articles' ) );
			await expect( page.locator( '#lkb-main' ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );
			await page.evaluate( () => window.scrollTo( 0, 300 ) );

			expect(
				await page.evaluate( () => window.scrollY ),
				`page scrolled at ${ width }px`
			).toBeGreaterThan( 100 );
			await expect
				.poll(
					() =>
						page
							.locator( '.lkb-header' )
							.evaluate( ( el ) =>
								Math.round( el.getBoundingClientRect().top )
							),
					{ message: `header top at ${ width }px` }
				)
				.toBe( top );
		}
	} );

	test( 'Articles shows fewer default columns on a small laptop', async ( {
		page,
	} ) => {
		test.skip( ! seeded, 'Set LICENSIO_KB_WP_CLI to run.' );

		await page.setViewportSize( { width: 1024, height: 900 } );
		await page.goto( contentManagerUrl( 'articles' ) );

		const app = page.locator( '#lkb-main' );
		// A sortable header's name ends with its sort icons.
		const header = ( name: string ) =>
			app.getByRole( 'columnheader', {
				name: new RegExp( `^${ name }( caret-up caret-down)?$` ),
			} );

		await expect( header( 'Categories' ) ).toBeVisible();

		// Until the user picks columns, the less used defaults are left out.
		for ( const name of [
			'Attachments',
			'Rating',
			'Views',
			'Reading time',
		] ) {
			await expect( header( name ) ).toHaveCount( 0 );
		}

		for ( const name of [ 'Categories', 'Tags' ] ) {
			expect(
				( await header( name ).boundingBox() )?.width ?? 0,
				`${ name } column width`
			).toBeGreaterThanOrEqual( 130 );
		}

		// The picker ticks what is shown, and a column ticked there shows.
		await app
			.getByRole( 'button', { name: 'Columns and rows per page' } )
			.click();

		const picker = page.locator( '.ant-popover' ).filter( {
			has: page.getByRole( 'checkbox', { name: 'Reading time' } ),
		} );

		await expect(
			picker.getByRole( 'checkbox', { name: 'Rating' } )
		).not.toBeChecked();
		await expect(
			picker.getByRole( 'checkbox', { name: 'Categories' } )
		).toBeChecked();

		const saved = page.waitForResponse(
			( response ) =>
				response.url().includes( 'preferences' ) &&
				'PUT' === response.request().method()
		);

		await picker.getByRole( 'checkbox', { name: 'Reading time' } ).check();
		await expect( header( 'Reading time' ) ).toBeVisible();
		await expect( header( 'Rating' ) ).toHaveCount( 0 );
		await saved;

		// The choice is kept on reload, and the table still fits.
		await page.reload();
		await expect( header( 'Reading time' ) ).toBeVisible();
		await expect( header( 'Rating' ) ).toHaveCount( 0 );
		await expect
			.poll( async () => {
				const frame = await measureFrame( page );

				return frame.page + frame.container + frame.inner;
			} )
			.toBe( 0 );

		// A pick on a small laptop drops nothing from a wider screen.
		await page.setViewportSize( { width: 1440, height: 900 } );

		for ( const name of [
			'Attachments',
			'Rating',
			'Views',
			'Reading time',
		] ) {
			await expect( header( name ) ).toBeVisible();
		}

		// Columns saved before the medium-width defaults are the user's choice.
		const admin = JSON.parse( seeded ).admin;

		wpEval(
			`update_user_meta( ${ admin }, 'licensio_kb_article_list_prefs', array( 'columns' => array( 'author', 'categories', 'tags', 'views', 'date' ), 'per_page' => 20 ) );`
		);
		await page.setViewportSize( { width: 1024, height: 900 } );
		await page.reload();
		await expect( header( 'Views' ) ).toBeVisible();

		// Back to the defaults for the other tests.
		wpEval(
			`delete_user_meta( ${ admin }, 'licensio_kb_article_list_prefs' );`
		);
	} );

	test( 'Tags Quick edit fields stay readable on a small laptop', async ( {
		page,
	} ) => {
		test.skip( ! seeded, 'Set LICENSIO_KB_WP_CLI to run.' );

		await page.setViewportSize( { width: 1024, height: 900 } );
		await page.goto( contentManagerUrl( 'tags' ) );
		await page.getByLabel( 'Search tags' ).fill( SHORT_SLUG );
		await page.getByLabel( 'Search tags' ).press( 'Enter' );
		await page
			.locator( '.ant-table-row', { hasText: SHORT_SLUG } )
			.locator( '[data-lkb-quick]' )
			.click();

		const open = page.locator( '.ant-table-row' ).filter( {
			has: page.locator( 'textarea' ),
		} );

		for ( const name of [ 'Name', 'Slug', 'Description' ] ) {
			expect(
				( await open.getByLabel( name, { exact: true } ).boundingBox() )
					?.width ?? 0,
				`${ name } field width`
			).toBeGreaterThanOrEqual( 180 );
		}

		await expect
			.poll( async () => {
				const frame = await measureFrame( page );

				return frame.page + frame.container + frame.inner;
			} )
			.toBe( 0 );
	} );

	for ( const screen of SCREENS ) {
		test( `${ screen.name } fits every width without a horizontal scroll bar`, async ( {
			page,
		} ) => {
			await page.setViewportSize( { width: 1440, height: 900 } );
			await page.goto( screen.url );
			await expect( page.locator( '#lkb-main' ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );
			await screen.open?.( page );

			for ( const width of DESKTOP_WIDTHS ) {
				for ( const folded of [ false, true ] ) {
					const state = `${ width }px, menu ${
						folded ? 'folded' : 'expanded'
					}`;

					await page.setViewportSize( { width, height: 900 } );
					await foldAdminMenu( page, folded );

					// Charts redraw a frame after a resize, so the width settles.
					await expect
						.poll(
							async () => {
								const {
									page: doc,
									container,
									inner,
								} = await measureFrame( page );

								return doc + container + inner;
							},
							{ message: `overflow at ${ state }` }
						)
						.toBe( 0 );

					const frame = await measureFrame( page );

					expect(
						frame.left,
						`left gutter at ${ state }`
					).toBeGreaterThanOrEqual( 0 );

					// A wide monitor keeps the screen a centred column.
					if ( 1920 === width ) {
						expect(
							frame.left,
							`left margin at ${ state }`
						).toBeGreaterThanOrEqual( 40 );
						expect(
							frame.right,
							`right margin at ${ state }`
						).toBeGreaterThanOrEqual( 40 );
					}
				}
			}

			await foldAdminMenu( page, false );
			await page.setViewportSize( { width: 390, height: 844 } );

			await expect
				.poll( async () => ( await measureFrame( page ) ).page, {
					message: 'page overflow at 390px',
				} )
				.toBe( 0 );
		} );
	}
} );
