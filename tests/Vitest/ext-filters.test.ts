/**
 * Unit tests of the Articles app's extension filters.
 *
 * The helpers in `apps/articles/ext-filters.ts` and how `readQuery()` and
 * `writeQuery()` keep the chosen options in the address bar: only declared
 * filters set to a declared option are read, a cleared filter leaves the URL,
 * and the list requests carry them as `ext_filters[{key}]`.
 *
 * @since 1.1.0
 */

import { describe, expect, it } from 'vitest';
import {
	extFilterArgs,
	extFilterParam,
	readExtFilters,
	withExtFilter,
	writeExtFilters,
} from '../../apps/articles/ext-filters';
import { readQuery, writeQuery } from '../../apps/articles/url';
import type { ExtensionFilter } from '../../apps/components/extensions';

const declared: ExtensionFilter[] = [
	{
		key: 'seo/status',
		label: 'SEO',
		queryArg: 'seo_filter',
		options: [
			{ value: 'missing_meta', label: 'Missing meta' },
			{ value: 'noindex', label: 'Noindex' },
		],
	},
	{
		key: 'example/flag',
		label: 'Flag',
		queryArg: '',
		options: [ { value: 'yes', label: 'Yes' } ],
	},
];

const BASE =
	'https://example.test/wp-admin/admin.php?page=licensio-kb-articles';

describe( 'extension filter helpers', () => {
	it( 'names the argument of a filter', () => {
		expect( extFilterParam( 'seo/status' ) ).toBe(
			'ext_filters[seo/status]'
		);
	} );

	it( 'reads only declared filters set to a declared option', () => {
		const params = new URLSearchParams();

		params.set( 'ext_filters[seo/status]', 'noindex' );
		params.set( 'ext_filters[example/flag]', 'no' );
		params.set( 'ext_filters[other/filter]', 'yes' );

		expect( readExtFilters( params, declared ) ).toEqual( {
			'seo/status': 'noindex',
		} );
		expect( readExtFilters( params, [] ) ).toEqual( {} );
	} );

	it( 'leaves empty values out of the request arguments', () => {
		expect(
			extFilterArgs( { 'seo/status': 'noindex', 'example/flag': '' } )
		).toEqual( { 'ext_filters[seo/status]': 'noindex' } );
	} );

	it( 'replaces every earlier filter argument and keeps the rest', () => {
		const params = new URLSearchParams(
			'page=licensio-kb-articles&ext_filters[seo/status]=noindex&ext_filters[gone/filter]=x'
		);

		writeExtFilters( params, { 'example/flag': 'yes' } );

		expect( [ ...params.entries() ] ).toEqual( [
			[ 'page', 'licensio-kb-articles' ],
			[ 'ext_filters[example/flag]', 'yes' ],
		] );
	} );

	it( 'sets and clears one filter without touching the others', () => {
		const values = { 'seo/status': 'noindex' };
		const set = withExtFilter( values, 'example/flag', 'yes' );

		expect( set ).toEqual( {
			'seo/status': 'noindex',
			'example/flag': 'yes',
		} );
		expect( withExtFilter( set, 'seo/status', '' ) ).toEqual( {
			'example/flag': 'yes',
		} );
		expect( values ).toEqual( { 'seo/status': 'noindex' } );
	} );
} );

describe( 'the Articles URL state', () => {
	it( 'round-trips the extension filters with the core filters', () => {
		const query = {
			...readQuery( '', 20, [], declared ),
			search: 'licence',
			status: 'draft' as const,
			category: 4,
			ext_filters: { 'seo/status': 'missing_meta' },
		};
		const href = writeQuery( BASE, query );

		expect(
			new URL( href ).searchParams.get( 'ext_filters[seo/status]' )
		).toBe( 'missing_meta' );
		expect( readQuery( new URL( href ).search, 20, [], declared ) ).toEqual(
			query
		);
	} );

	it( 'reads the redirect form and drops a cleared filter from the URL', () => {
		const redirected =
			'?page=licensio-kb-articles&status=draft&ext_filters[seo/status]=missing_meta';
		const query = readQuery( redirected, 20, [], declared );

		expect( query.ext_filters ).toEqual( { 'seo/status': 'missing_meta' } );
		expect( query.status ).toBe( 'draft' );

		const cleared = writeQuery(
			BASE + redirected.replace( '?page=licensio-kb-articles', '' ),
			{
				...query,
				ext_filters: {},
			}
		);

		expect( cleared ).not.toContain( 'ext_filters' );
		expect(
			readQuery( new URL( cleared ).search, 20, [], declared ).ext_filters
		).toEqual( {} );
	} );

	it( 'ignores a filter the page did not declare', () => {
		expect(
			readQuery( '?ext_filters[seo/status]=missing_meta', 20, [], [] )
				.ext_filters
		).toEqual( {} );
	} );
} );
