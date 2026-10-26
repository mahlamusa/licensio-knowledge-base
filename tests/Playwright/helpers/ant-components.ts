/**
 * Fail a spec when an app renders an Ant Design tag it never registered.
 *
 * The Articles, Categories and Tags apps register only the Ant Design Vue
 * components they use. A template tag left off that list does not throw: Vue
 * renders it as an unknown `<a-…>` element, and the "Failed to resolve
 * component" warning it would print is stripped from production builds, which
 * is what the suite runs against. So besides listening for that warning, the
 * page watches every element added to the document and reports any whose tag
 * starts with `a-`, which catches dialogs and drawers that only appear for a
 * moment as well as what is still on screen when the test ends.
 *
 * Specs import from here; this folder holds no specs of its own.
 *
 * @since 1.0.0
 */

import { expect, test } from '@playwright/test';

/** Prefix of the console line the page prints for an unresolved tag. */
const MARKER = 'licensio-kb-unresolved-component:';

/**
 * Register hooks that fail each test in the calling spec on an unresolved tag.
 *
 * Call it once at the top level of a spec file, so the hooks run around every
 * test in the file, before any `beforeEach` that navigates.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
export function failOnUnresolvedComponents(): void {
	let problems: string[] = [];

	test.beforeEach( async ( { page } ) => {
		problems = [];

		page.on( 'console', ( message ) => {
			const text = message.text();

			if (
				text.startsWith( MARKER ) ||
				text.includes( 'Failed to resolve component' )
			) {
				problems.push( text );
			}
		} );

		await page.addInitScript( ( marker: string ) => {
			const reported = new Set< string >();

			const check = ( element: Element ) => {
				const tag = element.tagName.toLowerCase();

				if ( tag.startsWith( 'a-' ) && ! reported.has( tag ) ) {
					reported.add( tag );
					// eslint-disable-next-line no-console
					console.error( `${ marker } <${ tag }>` );
				}
			};

			new MutationObserver( ( mutations ) => {
				for ( const mutation of mutations ) {
					mutation.addedNodes.forEach( ( node ) => {
						if ( node instanceof Element ) {
							check( node );
							node.querySelectorAll( '*' ).forEach( check );
						}
					} );
				}
			} ).observe( document, { childList: true, subtree: true } );
		}, MARKER );
	} );

	test.afterEach( () => {
		expect( problems, 'Ant Design tags the app did not register' ).toEqual(
			[]
		);
	} );
}
