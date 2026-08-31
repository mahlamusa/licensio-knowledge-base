<script setup lang="ts">
/**
 * One setting, rendered from its schema entry.
 *
 * The control is chosen by the server, not by the key, so a setting a third
 * party adds to the registry gets an editor here without this file knowing it
 * exists.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import type { Field, SettingValue } from '../types';

const props = defineProps< {
	field: Field;
	value: SettingValue;
	error: string;
	changed: boolean;
} >();

const emit = defineEmits< {
	( event: 'update', key: string, value: SettingValue ): void;
} >();

const bounds = computed( () => {
	const { min, max } = props.field;

	if ( min === null && max === null ) {
		return '';
	}

	if ( min !== null && max !== null ) {
		return `${ min } – ${ max }`;
	}

	return min !== null ? `≥ ${ min }` : `≤ ${ max }`;
} );

/**
 * Push an edited value up to the screen.
 *
 * @since 1.0.0
 *
 * @param {SettingValue} value The new value.
 *
 * @return {void}
 */
function update( value: SettingValue ): void {
	emit( 'update', props.field.key, value );
}
</script>

<template>
	<a-form-item
		:label="field.label"
		:validate-status="error ? 'error' : ''"
		:help="error || field.description"
		class="lisensio-kb-settings__field"
	>
		<a-switch
			v-if="field.control === 'toggle'"
			:checked="value === true"
			@update:checked="update( $event )"
		/>

		<a-input-number
			v-else-if="field.control === 'number'"
			:value="typeof value === 'number' ? value : null"
			:min="field.min ?? undefined"
			:max="field.max ?? undefined"
			:step="1"
			@update:value="update( $event === null ? null : Number( $event ) )"
		/>

		<a-select
			v-else-if="field.control === 'select'"
			:value="String( value ?? '' )"
			style="min-width: 14rem"
			@update:value="update( String( $event ) )"
		>
			<a-select-option
				v-for="choice in field.choices"
				:key="choice.value"
				:value="choice.value"
			>
				{{ choice.label }}
			</a-select-option>
		</a-select>

		<span v-else-if="field.control === 'color'" class="lisensio-kb-settings__color">
			<input
				type="color"
				:value="String( value ?? '#000000' )"
				:aria-label="field.label"
				@input="update( ( $event.target as HTMLInputElement ).value )"
			/>
			<a-input
				:value="String( value ?? '' )"
				style="max-width: 9rem"
				@update:value="update( String( $event ) )"
			/>
		</span>

		<a-input
			v-else
			:value="String( value ?? '' )"
			@update:value="update( String( $event ) )"
		/>

		<span v-if="field.unit" class="lisensio-kb-settings__unit">
			{{ field.unit }}
		</span>

		<span v-if="bounds" class="lisensio-kb-settings__bounds">{{ bounds }}</span>

		<span v-if="changed" class="lisensio-kb-settings__changed">•</span>
	</a-form-item>
</template>

<style scoped>
.lisensio-kb-settings__color {
	align-items: center;
	display: inline-flex;
	gap: 8px;
}

.lisensio-kb-settings__color input[type='color'] {
	block-size: 32px;
	border: 1px solid #d9d9d9;
	border-radius: 6px;
	cursor: pointer;
	inline-size: 48px;
	padding: 2px;
}

.lisensio-kb-settings__unit,
.lisensio-kb-settings__bounds {
	color: #646970;
	margin-inline-start: 8px;
}

.lisensio-kb-settings__changed {
	color: #d97706;
	font-size: 20px;
	line-height: 1;
	margin-inline-start: 8px;
}
</style>
