<script setup lang="ts">
/**
 * One setting, rendered from its schema entry.
 *
 * The control is chosen by the server, not by the key, so a setting a third
 * party adds to the registry gets an editor here without this file knowing it
 * exists. The label names the control through its `for` and the
 * control's id. A number's allowed range shows once, under the label.
 *
 * @since 1.0.0
 */

import { computed } from 'vue';
import type { Field, SettingValue } from '../types';
import { __ } from '../../components/i18n';

const props = defineProps< {
	field: Field;
	value: SettingValue;
	error: string;
	changed: boolean;
} >();

const emit = defineEmits< {
	( event: 'update', key: string, value: SettingValue ): void;
} >();

/** The control's id, which the label's `for` points at. */
const controlId = computed( () => `lkb-${ props.field.key }` );

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
	<div
		class="lkb-field"
		:class="{ 'is-changed': changed, 'is-invalid': !! error }"
	>
		<div class="lkb-field__label">
			<label :for="controlId">{{ field.label }}</label>
			<span v-if="bounds" class="lkb-field__bounds">{{ bounds }}</span>
		</div>

		<div class="lkb-field__control">
			<div class="lkb-field__input">
				<a-switch
					v-if="field.control === 'toggle'"
					:id="controlId"
					:checked="value === true"
					@update:checked="update( $event )"
				/>

				<a-input-number
					v-else-if="field.control === 'number'"
					:id="controlId"
					:value="typeof value === 'number' ? value : null"
					:min="field.min ?? undefined"
					:max="field.max ?? undefined"
					:step="1"
					@update:value="
						update( $event === null ? null : Number( $event ) )
					"
				/>

				<a-select
					v-else-if="field.control === 'select'"
					:id="controlId"
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

				<span
					v-else-if="field.control === 'color'"
					class="licensio-kb-settings__color"
				>
					<input
						type="color"
						:value="String( value ?? '#000000' )"
						:aria-label="field.label"
						@input="
							update(
								( $event.target as HTMLInputElement ).value
							)
						"
					/>
					<a-input
						:id="controlId"
						:value="String( value ?? '' )"
						:placeholder="
							__( 'Theme colour', 'licensio-knowledge-base' )
						"
						allow-clear
						style="max-width: 11rem"
						@update:value="update( String( $event ?? '' ) )"
					/>
				</span>

				<a-input
					v-else
					:id="controlId"
					:value="String( value ?? '' )"
					@update:value="update( String( $event ) )"
				/>

				<span v-if="field.unit" class="licensio-kb-settings__unit">
					{{ field.unit }}
				</span>

				<span v-if="changed" class="licensio-kb-settings__changed"
					>•</span
				>
			</div>

			<p v-if="error" class="lkb-field__error">{{ error }}</p>
			<p v-else-if="field.description" class="lkb-field__help">
				{{ field.description }}
			</p>
		</div>
	</div>
</template>

<style>
/*
 * A settings row reads as a label and its control, not as a stacked form. The
 * dashed rule separates rows without the weight of a table border.
 */
.lkb-field {
	display: grid;
	grid-template-columns: 220px minmax( 0, 1fr );
	gap: 24px;
	align-items: start;
	padding: 18px 0;
	border-bottom: 1px dashed var( --lkb-line, #e5e7eb );
}

.lkb-field:last-child {
	border-bottom: 0;
}

.lkb-field__label {
	padding-top: 5px;
	font-size: 13px;
	line-height: 1.45;
}

.lkb-field__label label {
	font-weight: 500;
}

.lkb-field__bounds {
	display: block;
	margin-top: 2px;
	font-size: 12px;
	color: var( --lkb-ink-muted );
	font-variant-numeric: tabular-nums;
}

.lkb-field__input {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

/* Text and number controls fill the column; switches and selects size to content. */
.lkb-field__input > .ant-input,
.lkb-field__input > .ant-input-number {
	max-width: 480px;
	width: 100%;
}

.lkb-field__help,
.lkb-field__error {
	margin: 8px 0 0;
	font-size: 12px;
	line-height: 1.5;
}

.lkb-field__help {
	color: var( --lkb-ink-muted );
}

.lkb-field__error {
	color: var( --lkb-error );
}

/* An edited row is marked, so a change stays visible after scrolling past it. */
.lkb-field.is-changed .lkb-field__label label::after {
	content: '';
	display: inline-block;
	width: 6px;
	height: 6px;
	margin-left: 6px;
	border-radius: 50%;
	background: var( --lkb-accent );
	vertical-align: middle;
}

.lkb-field.is-invalid .lkb-field__label label {
	color: var( --lkb-error );
}

@media ( max-width: 782px ) {
	.lkb-field {
		grid-template-columns: minmax( 0, 1fr );
		gap: 8px;
	}

	.lkb-field__label {
		padding-top: 0;
	}
}
</style>
