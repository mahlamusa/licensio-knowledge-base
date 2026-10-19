/**
 * Turning appearance values into the CSS custom properties the frontend uses.
 *
 * The preview is only worth having if it is the real thing, so it sets exactly
 * the custom properties `Licensio_KB_Templates::custom_properties()` publishes
 * on `.licensio-kb`, under exactly those names. For the two enumerated settings
 * the name/value pairs come from the server in the field's `preview.values`, so
 * they cannot drift from the PHP.
 *
 * The brand colour is the one exception: it is continuous, so its derived
 * `-rgb` and `-contrast` properties have to be computed here. `hexToRgb` and
 * `contrastColor` mirror `Licensio_KB_Templates::hex_to_rgb()` and
 * `::contrast_color()` — same fallback, same luminance coefficients, same
 * threshold. A unit test pins the PHP side of that pair.
 *
 * @since 1.0.0
 */

import type { Field, SettingsMap } from './types';

const FALLBACK_RGB: [ number, number, number ] = [ 37, 99, 235 ];

/**
 * Split a hex colour into its red, green and blue components.
 *
 * @since 1.0.0
 *
 * @param {string} hex A `#rgb` or `#rrggbb` colour.
 *
 * @return {number[]} The colour components, 0-255.
 */
export function hexToRgb( hex: string ): [ number, number, number ] {
	let value = String( hex ).replace( /^#/, '' );

	if ( value.length === 3 ) {
		value = value
			.split( '' )
			.map( ( character ) => character + character )
			.join( '' );
	}

	if ( ! /^[0-9a-f]{6}$/i.test( value ) ) {
		return FALLBACK_RGB;
	}

	return [
		parseInt( value.slice( 0, 2 ), 16 ),
		parseInt( value.slice( 2, 4 ), 16 ),
		parseInt( value.slice( 4, 6 ), 16 ),
	];
}

/**
 * A readable foreground colour for text sitting on the brand colour.
 *
 * @since 1.0.0
 *
 * @param {number[]} rgb The brand colour components.
 *
 * @return {string} `#000000` on a light brand colour, `#ffffff` on a dark one.
 */
export function contrastColor( rgb: [ number, number, number ] ): string {
	const luminance =
		( 0.299 * rgb[ 0 ] + 0.587 * rgb[ 1 ] + 0.114 * rgb[ 2 ] ) / 255;

	return luminance > 0.6 ? '#000000' : '#ffffff';
}

/**
 * The custom properties a set of draft values would publish.
 *
 * @since 1.0.0
 *
 * @param {Field[]}     fields The schema fields.
 * @param {SettingsMap} draft  The current draft values.
 *
 * @return {Object<string,string>} Custom property name to value.
 */
export function previewProperties(
	fields: Field[],
	draft: SettingsMap
): Record< string, string > {
	const properties: Record< string, string > = {};

	fields.forEach( ( field ) => {
		const preview = field.preview;

		if ( ! preview ) {
			return;
		}

		const value = draft[ field.key ];

		if ( preview.kind === 'color' ) {
			const hex = String( value ?? '' );
			const rgb = hexToRgb( hex );

			properties[ preview.properties.value ] = hex;
			properties[ preview.properties.rgb ] = rgb.join( ',' );
			properties[ preview.properties.contrast ] = contrastColor( rgb );

			return;
		}

		const mapped = preview.values[ String( value ) ];

		if ( mapped ) {
			Object.assign( properties, mapped );
		}
	} );

	return properties;
}
