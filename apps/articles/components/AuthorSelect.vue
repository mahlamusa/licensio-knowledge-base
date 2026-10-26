<script setup lang="ts">
/**
 * A search select for the users who can edit articles.
 *
 * Data source: `GET authors`. Searches as the user types and looks up the
 * name of an already-chosen user with `include`. `v-model` is a user ID, or
 * 0 for none. The caller gives it an accessible name with `aria-label`.
 *
 * @since 1.0.0
 */

import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { __ } from '../../components/i18n';
import { fetchAuthors } from '../api';
import type { AuthorItem } from '../types';

const props = withDefaults(
	defineProps< {
		modelValue?: number;
		placeholder?: string;
		disabled?: boolean;
		/** Name of the chosen user, when the caller already knows it. */
		knownName?: string;
	} >(),
	{ modelValue: 0, placeholder: '', disabled: false, knownName: '' }
);

const emit = defineEmits< {
	( event: 'update:modelValue', value: number ): void;
} >();

const results = ref< AuthorItem[] >( [] );
const names = ref< Record< number, string > >( {} );
const loading = ref( false );

let timer: ReturnType< typeof setTimeout > | undefined;
let latest = 0;

const options = computed( () => {
	const list = results.value.map( ( author ) => ( {
		value: author.id,
		label: author.name,
	} ) );

	if (
		props.modelValue > 0 &&
		! list.some( ( option ) => option.value === props.modelValue )
	) {
		list.unshift( {
			value: props.modelValue,
			label:
				names.value[ props.modelValue ] ||
				props.knownName ||
				String( props.modelValue ),
		} );
	}

	return list;
} );

/**
 * Fetch the users matching a search.
 *
 * @since 1.0.0
 *
 * @param {string}   text    The search text.
 * @param {number[]} include IDs to include.
 *
 * @return {Promise<void>} Resolves once stored.
 */
async function search( text: string, include: number[] = [] ): Promise< void > {
	const ticket = ++latest;

	loading.value = true;

	try {
		const page = await fetchAuthors( text, include );

		page.items.forEach( ( author ) => {
			names.value[ author.id ] = author.name;
		} );

		if ( ticket === latest && 0 === include.length ) {
			results.value = page.items;
		}
	} catch {
		if ( ticket === latest ) {
			results.value = [];
		}
	} finally {
		if ( ticket === latest ) {
			loading.value = false;
		}
	}
}

/**
 * Search after the user stops typing.
 *
 * @since 1.0.0
 *
 * @param {string} text The search text.
 *
 * @return {void}
 */
function onSearch( text: string ): void {
	clearTimeout( timer );
	timer = setTimeout( () => search( text ), 250 );
}

/**
 * Load the list the first time the dropdown opens.
 *
 * @since 1.0.0
 *
 * @param {boolean} open Whether the dropdown is open.
 *
 * @return {void}
 */
function onDropdown( open: boolean ): void {
	if ( open && 0 === results.value.length ) {
		search( '' );
	}
}

/**
 * Pass a change up.
 *
 * @since 1.0.0
 *
 * @param {unknown} value The new value.
 *
 * @return {void}
 */
function onChange( value: unknown ): void {
	emit( 'update:modelValue', Number( value ) || 0 );
}

watch(
	() => props.modelValue,
	( id ) => {
		if ( id > 0 && ! names.value[ id ] && ! props.knownName ) {
			search( '', [ id ] );
		}
	},
	{ immediate: true }
);

onBeforeUnmount( () => clearTimeout( timer ) );
</script>

<template>
	<a-select
		:value="props.modelValue > 0 ? props.modelValue : undefined"
		:options="options"
		:placeholder="
			props.placeholder || __( 'Any author', 'licensio-knowledge-base' )
		"
		:disabled="props.disabled"
		:loading="loading"
		:filter-option="false"
		:not-found-content="__( 'No matches', 'licensio-knowledge-base' )"
		show-search
		allow-clear
		@search="onSearch"
		@change="onChange"
		@dropdown-visible-change="onDropdown"
	/>
</template>
