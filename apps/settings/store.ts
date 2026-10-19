/**
 * Pinia store backing the settings screen.
 *
 * Holds two copies of every setting: `saved`, which is what the server last
 * confirmed, and `draft`, which is what the administrator has typed. Everything
 * the screen needs — whether there is unsaved work, which fields changed, what
 * to send — is derived by comparing the two, so no separate "dirty" flag can
 * fall out of step with the form.
 *
 * Only the changed keys are sent. The endpoint validates every key it is given
 * and refuses the whole payload if any one of them fails, so sending the
 * untouched fields as well would let an invalid value somebody else stored
 * block a save that has nothing to do with it.
 *
 * @since 1.0.0
 */

import { defineStore } from 'pinia';
import {
	SaveRejected,
	fetchSettings,
	resetSettings,
	saveSettings,
} from './api';
import type {
	CategoryBlock,
	Field,
	FieldErrors,
	Group,
	SettingValue,
	SettingsMap,
	SettingsResponse,
} from './types';

interface SettingsState {
	loading: boolean;
	saving: boolean;
	loaded: boolean;
	error: string;
	notice: string;
	fieldErrors: FieldErrors;
	schema: Group[];
	categories: Record< string, string >;
	saved: SettingsMap;
	draft: SettingsMap;
	defaults: SettingsMap;
}

export const useSettingsStore = defineStore( 'licensioKbSettings', {
	state: (): SettingsState => ( {
		loading: false,
		saving: false,
		loaded: false,
		error: '',
		notice: '',
		fieldErrors: {},
		schema: [],
		categories: {},
		saved: {},
		draft: {},
		defaults: {},
	} ),

	getters: {
		/**
		 * The sidebar: each declared category with its groups, in order.
		 *
		 * A group whose category the server does not declare would otherwise
		 * vanish from the navigation while its settings stayed stored, so any
		 * such group is collected under a trailing block rather than dropped.
		 * @param state
		 */
		categoryBlocks( state ): CategoryBlock[] {
			const declared = Object.keys( state.categories );
			const blocks: CategoryBlock[] = declared.map( ( id ) => ( {
				id,
				label: state.categories[ id ],
				groups: state.schema.filter(
					( group ) => group.category === id
				),
			} ) );

			const orphans = state.schema.filter(
				( group ) => ! declared.includes( group.category )
			);

			if ( orphans.length > 0 ) {
				blocks.push( { id: 'other', label: 'Other', groups: orphans } );
			}

			return blocks.filter( ( block ) => block.groups.length > 0 );
		},

		/**
		 * Every field in the schema, flattened out of its group.
		 *
		 * @since 1.0.0
		 *
		 * @param {SettingsState} state The store state.
		 *
		 * @return {Field[]} The fields.
		 */
		fields( state ): Field[] {
			return state.schema.flatMap( ( group ) => group.fields );
		},

		/**
		 * The keys whose draft value differs from the saved one.
		 *
		 * @since 1.0.0
		 *
		 * @param {SettingsState} state The store state.
		 *
		 * @return {string[]} The changed keys.
		 */
		changedKeys( state ): string[] {
			return Object.keys( state.draft ).filter(
				( key ) => state.draft[ key ] !== state.saved[ key ]
			);
		},

		/**
		 * Whether there is unsaved work on the screen.
		 *
		 * @since 1.0.0
		 *
		 * @return {boolean} True when at least one field has been edited.
		 */
		dirty(): boolean {
			return ( this.changedKeys as string[] ).length > 0;
		},

		/**
		 * The payload a save would send.
		 *
		 * @since 1.0.0
		 *
		 * @param {SettingsState} state The store state.
		 *
		 * @return {SettingsMap} The changed settings.
		 */
		changes( state ): SettingsMap {
			const payload: SettingsMap = {};

			( this.changedKeys as string[] ).forEach( ( key ) => {
				payload[ key ] = state.draft[ key ];
			} );

			return payload;
		},
	},

	actions: {
		/**
		 * Adopt a server payload as the new saved state.
		 *
		 * @since 1.0.0
		 *
		 * @param {SettingsResponse} payload The response body.
		 *
		 * @return {void}
		 */
		adopt( payload: SettingsResponse ): void {
			this.schema = payload.schema ?? [];
			this.categories = payload.categories ?? {};
			this.saved = { ...payload.settings };
			this.draft = { ...payload.settings };
			this.defaults = { ...payload.defaults };
			this.fieldErrors = {};
			this.loaded = true;
		},

		/**
		 * Edit one field.
		 *
		 * @since 1.0.0
		 *
		 * @param {string}       key   The setting key.
		 * @param {SettingValue} value The new value.
		 *
		 * @return {void}
		 */
		setValue( key: string, value: SettingValue ): void {
			this.draft[ key ] = value;

			delete this.fieldErrors[ key ];

			this.notice = '';
		},

		/**
		 * Throw away every edit and go back to the saved values.
		 *
		 * @since 1.0.0
		 *
		 * @return {void}
		 */
		revert(): void {
			this.draft = { ...this.saved };
			this.fieldErrors = {};
			this.error = '';
			this.notice = '';
		},

		/**
		 * Load the settings, the defaults and the schema.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<void>} Resolves once the screen can render.
		 */
		async load(): Promise< void > {
			this.loading = true;
			this.error = '';

			try {
				this.adopt( await fetchSettings() );
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );
			} finally {
				this.loading = false;
			}
		},

		/**
		 * Save the edited fields.
		 *
		 * A rejected save leaves the draft exactly as it was, so the
		 * administrator can correct the offending field rather than retype
		 * everything.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<boolean>} True when the settings were stored.
		 */
		async save(): Promise< boolean > {
			if ( ! this.dirty ) {
				return true;
			}

			this.saving = true;
			this.error = '';
			this.notice = '';
			this.fieldErrors = {};

			try {
				this.adopt( await saveSettings( this.changes ) );
				this.notice = 'saved';

				return true;
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );

				if ( error instanceof SaveRejected ) {
					this.fieldErrors = error.fields;
				}

				return false;
			} finally {
				this.saving = false;
			}
		},

		/**
		 * Reset every setting to its registered default.
		 *
		 * @since 1.0.0
		 *
		 * @return {Promise<boolean>} True when the reset was applied.
		 */
		async reset(): Promise< boolean > {
			this.saving = true;
			this.error = '';
			this.notice = '';

			try {
				this.adopt( await resetSettings() );
				this.notice = 'reset';

				return true;
			} catch ( error ) {
				this.error =
					error instanceof Error ? error.message : String( error );

				return false;
			} finally {
				this.saving = false;
			}
		},
	},
} );
