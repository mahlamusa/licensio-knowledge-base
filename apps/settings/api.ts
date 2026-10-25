/**
 * REST client for the settings screen.
 *
 * Every request carries the `wp_rest` nonce the screen was rendered with, which
 * is what makes the `manage_licensio_kb` check on the server apply to the
 * logged-in administrator rather than to an anonymous caller.
 *
 * A rejected save is not an exception here. The server answers a bad payload
 * with a 400 whose body names every field that failed, and the screen needs
 * that body, so `SaveRejected` carries it through instead of flattening it into
 * a message string.
 *
 * @since 1.0.0
 */

import type {
	FieldErrors,
	RestError,
	SettingsConfig,
	SettingsMap,
	SettingsResponse,
} from './types';
import { __ } from '../components/i18n';

declare global {
	interface Window {
		licensioKbSettings?: SettingsConfig;
	}
}

const FALLBACK_CONFIG: SettingsConfig = {
	restUrl: '',
	nonce: '',
};

/**
 * The bootstrap data PHP localised onto the page.
 *
 * @since 1.0.0
 *
 * @return {SettingsConfig} The configuration, or safe defaults when the page did not supply one.
 */
export function config(): SettingsConfig {
	return { ...FALLBACK_CONFIG, ...( window.licensioKbSettings ?? {} ) };
}

/**
 * A save the server refused, with the per-field messages it gave.
 *
 * @since 1.0.0
 */
export class SaveRejected extends Error {
	public readonly fields: FieldErrors;

	/**
	 * Build the rejection.
	 *
	 * @since 1.0.0
	 *
	 * @param {string}      message The overall message.
	 * @param {FieldErrors} fields  The per-field messages, keyed by setting name.
	 */
	public constructor( message: string, fields: FieldErrors ) {
		super( message );

		this.name = 'SaveRejected';
		this.fields = fields;
	}
}

/**
 * Send one request to the settings endpoint.
 *
 * @since 1.0.0
 *
 * @param {string}      method The HTTP method.
 * @param {SettingsMap} body   The settings to write, for a POST.
 *
 * @return {Promise<SettingsResponse>} The decoded response body.
 */
async function request(
	method: 'GET' | 'POST' | 'DELETE',
	body?: SettingsMap
): Promise< SettingsResponse > {
	const { restUrl, nonce } = config();

	const response = await fetch( restUrl, {
		method,
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': nonce,
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
		body: body ? JSON.stringify( { settings: body } ) : undefined,
	} );

	const payload = await response.json().catch( () => null );

	if ( ! response.ok ) {
		const error = ( payload ?? {} ) as RestError;

		throw new SaveRejected(
			error.message ||
				__( 'The settings request failed.', 'licensio-knowledge-base' ),
			error.data?.params ?? {}
		);
	}

	return payload as SettingsResponse;
}

/**
 * Read the current settings, the registry defaults and the screen schema.
 *
 * @since 1.0.0
 *
 * @return {Promise<SettingsResponse>} The settings payload.
 */
export function fetchSettings(): Promise< SettingsResponse > {
	return request( 'GET' );
}

/**
 * Write a settings payload.
 *
 * @since 1.0.0
 *
 * @param {SettingsMap} values The settings to write.
 *
 * @return {Promise<SettingsResponse>} The stored settings.
 */
export function saveSettings(
	values: SettingsMap
): Promise< SettingsResponse > {
	return request( 'POST', values );
}

/**
 * Reset every setting to its registered default.
 *
 * @since 1.0.0
 *
 * @return {Promise<SettingsResponse>} The restored settings.
 */
export function resetSettings(): Promise< SettingsResponse > {
	return request( 'DELETE' );
}
