/**
 * Shapes returned by the `lisensio-kb/v1/settings` endpoint.
 *
 * The field list is not declared here as a union of known keys. The server
 * builds it from the settings registry, which is extensible, so the app treats
 * a field as data and renders whatever it is handed.
 *
 * @since 1.0.0
 */

export type SettingValue = string | number | boolean | null;

export type SettingsMap = Record< string, SettingValue >;

export type Control = 'text' | 'number' | 'color' | 'select' | 'toggle';

export interface Choice {
	value: string;
	label: string;
}

export interface ColorPreview {
	kind: 'color';
	properties: {
		value: string;
		rgb: string;
		contrast: string;
	};
}

export interface MapPreview {
	kind: 'map';
	values: Record< string, Record< string, string > >;
}

export type Preview = ColorPreview | MapPreview;

export interface Field {
	key: string;
	group: string;
	type: 'string' | 'int' | 'bool' | 'float' | 'array';
	control: Control;
	label: string;
	description: string;
	default: SettingValue;
	min: number | null;
	max: number | null;
	unit: string;
	choices: Choice[];
	preview: Preview | null;
}

export interface Group {
	id: string;
	label: string;
	description: string;
	category: string;
	icon: string;
	count: number;
	fields: Field[];
}

/**
 * A sidebar heading with the groups filed under it.
 *
 * Built in the app rather than sent by the server: the server declares the
 * category order and each group's category, and the shape the navigation wants
 * is the join of the two.
 */
export interface CategoryBlock {
	id: string;
	label: string;
	groups: Group[];
}

export interface SettingsResponse {
	settings: SettingsMap;
	defaults: SettingsMap;
	categories: Record< string, string >;
	schema: Group[];
}

export interface SettingsConfig {
	restUrl: string;
	nonce: string;
	settingsUrl?: string;
	archiveUrl?: string;
}

/**
 * The per-field messages a rejected save comes back with.
 *
 * @since 1.0.0
 */
export type FieldErrors = Record< string, string >;

export interface RestError {
	code: string;
	message: string;
	data?: {
		status?: number;
		params?: FieldErrors;
	};
}
