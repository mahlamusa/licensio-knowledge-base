<script setup lang="ts">
/**
 * An async search select for knowledge base categories or tags.
 *
 * Data source: `GET licensio-kb/v1/terms` (`Licensio_KB_Terms_REST_Controller`)
 * through `rest.ts`, so the app must call `configure()` before this mounts.
 * That route is the plugin's own read-only term search, built for this picker
 * so no app depends on the categories or tags endpoints, and so the apps never
 * use core's `wp/v2` term routes. It searches as the user types, and looks up
 * the labels of already-selected IDs with `include`.
 *
 * - `v-model` is a term ID (or null), or with `multiple` an array of them.
 * - `allow-create` adds a "Create …" option for a name that does not exist.
 *   Picking it adds `{ name }` to the value instead of an ID; nothing is
 *   created here. The write route that receives the value creates the term.
 * - `exclude` hides term IDs, e.g. a category and its descendants when
 *   choosing its parent.
 * - Subcategories are labelled with their ancestors: "Parent › Child".
 *
 * Usage:
 *
 *     <TermSelect v-model="tags" taxonomy="licensio_tag" multiple allow-create />
 *     <TermSelect v-model="parent" taxonomy="licensio_category" :exclude="[ id, ...descendants ]" />
 *
 * Consumed by the articles, categories and tags apps.
 *
 * @since 1.0.0
 */

import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Select as ASelect } from 'ant-design-vue';
import { get } from './rest';
import { __, sprintf } from './i18n';
import type {
	NewTerm,
	TermItem,
	TermTaxonomy,
	TermValue,
} from './types/terms';

const props = withDefaults(
	defineProps< {
		/** Which taxonomy to pick from. */
		taxonomy: TermTaxonomy;
		/** The picked term, or with `multiple`, the picked terms. */
		modelValue?: TermValue | TermValue[] | null;
		/** Allow several terms. */
		multiple?: boolean;
		/** Offer to create a term from the search text. */
		allowCreate?: boolean;
		/** Term IDs that cannot be picked. */
		exclude?: number[];
		/** Placeholder text. */
		placeholder?: string;
		/** Disable the control. */
		disabled?: boolean;
	} >(),
	{
		modelValue: null,
		multiple: false,
		allowCreate: false,
		exclude: () => [],
		placeholder: '',
		disabled: false,
	}
);

const emit = defineEmits< {
	( event: 'update:modelValue', value: TermValue | TermValue[] | null ): void;
} >();

const NEW_PREFIX = 'new:';
const SEARCH_DELAY = 250;

const results = ref< TermItem[] >( [] );
const labels = ref< Record< number, string > >( {} );
const searchText = ref( '' );
const loading = ref( false );
const failed = ref( false );

let searchTimer: ReturnType< typeof setTimeout > | undefined;
let latestSearch = 0;

/**
 * The label a term is shown with: its ancestors, then its name.
 *
 * @since 1.0.0
 *
 * @param {TermItem} term The term.
 *
 * @return {string} The label.
 */
function labelFor( term: TermItem ): string {
	return [ ...term.path, term.name ].join( ' › ' );
}

/**
 * Remember the labels of terms the server returned.
 *
 * @since 1.0.0
 *
 * @param {TermItem[]} terms The terms.
 *
 * @return {void}
 */
function remember( terms: TermItem[] ): void {
	const next = { ...labels.value };

	terms.forEach( ( term ) => {
		next[ term.id ] = labelFor( term );
	} );

	labels.value = next;
}

/**
 * The select's key for a value.
 *
 * @since 1.0.0
 *
 * @param {TermValue} value An ID or a new term.
 *
 * @return {string} The key.
 */
function keyOf( value: TermValue ): string {
	return typeof value === 'number'
		? String( value )
		: NEW_PREFIX + value.name;
}

/**
 * The value a select key stands for.
 *
 * @since 1.0.0
 *
 * @param {string} key The key.
 *
 * @return {TermValue} An ID or a new term.
 */
function valueOf( key: string ): TermValue {
	return key.startsWith( NEW_PREFIX )
		? ( { name: key.slice( NEW_PREFIX.length ) } as NewTerm )
		: Number( key );
}

const selected = computed< TermValue[] >( () => {
	if ( props.modelValue === null || props.modelValue === undefined ) {
		return [];
	}

	return Array.isArray( props.modelValue )
		? props.modelValue
		: [ props.modelValue ];
} );

const selectValue = computed( () => {
	const keys = selected.value.map( keyOf );

	return props.multiple ? keys : keys[ 0 ];
} );

const options = computed( () => {
	const seen = new Set< string >();
	const list: Array< { value: string; label: string } > = [];

	const add = ( value: string, label: string ): void => {
		if ( ! seen.has( value ) ) {
			seen.add( value );
			list.push( { value, label } );
		}
	};

	// Selected values first, so the select can always show their labels.
	selected.value.forEach( ( value ) => {
		add(
			keyOf( value ),
			typeof value === 'number'
				? labels.value[ value ] ?? String( value )
				: value.name
		);
	} );

	results.value
		.filter( ( term ) => ! props.exclude.includes( term.id ) )
		.forEach( ( term ) => add( String( term.id ), labelFor( term ) ) );

	const typed = searchText.value.trim();
	const exists = results.value.some(
		( term ) => term.name.toLowerCase() === typed.toLowerCase()
	);

	if ( props.allowCreate && '' !== typed && ! exists ) {
		add(
			NEW_PREFIX + typed,
			/* translators: %s: the name of the term that will be created. */
			sprintf( __( 'Create “%s”', 'licensio-knowledge-base' ), typed )
		);
	}

	return list;
} );

/**
 * Fetch the terms matching the search text.
 *
 * Only the latest search's answer is kept, so a slow early answer cannot
 * replace a later one.
 *
 * @since 1.0.0
 *
 * @param {string} text The search text.
 *
 * @return {Promise<void>} Resolves once the results are stored.
 */
async function runSearch( text: string ): Promise< void > {
	const ticket = ++latestSearch;

	loading.value = true;
	failed.value = false;

	try {
		const terms = await get< TermItem[] >( 'terms', {
			taxonomy: props.taxonomy,
			search: text,
			exclude: props.exclude,
			per_page: 20,
		} );

		if ( ticket === latestSearch ) {
			results.value = terms;
			remember( terms );
		}
	} catch {
		if ( ticket === latestSearch ) {
			results.value = [];
			failed.value = true;
		}
	} finally {
		if ( ticket === latestSearch ) {
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
	searchText.value = text;

	if ( searchTimer ) {
		clearTimeout( searchTimer );
	}

	searchTimer = setTimeout( () => runSearch( text ), SEARCH_DELAY );
}

/**
 * Load the first page of terms when the dropdown opens.
 *
 * @since 1.0.0
 *
 * @param {boolean} open Whether the dropdown is open.
 *
 * @return {void}
 */
function onDropdown( open: boolean ): void {
	if ( open && results.value.length === 0 && ! loading.value ) {
		runSearch( searchText.value );
	}
}

/**
 * Pass a change up as term values.
 *
 * @since 1.0.0
 *
 * @param {unknown} value The select's new value: a key, keys, or nothing.
 *
 * @return {void}
 */
function onChange( value: unknown ): void {
	searchText.value = '';

	if ( props.multiple ) {
		emit(
			'update:modelValue',
			( Array.isArray( value ) ? value : [] ).map( ( key ) =>
				valueOf( String( key ) )
			)
		);
		return;
	}

	emit(
		'update:modelValue',
		typeof value === 'string' && '' !== value ? valueOf( value ) : null
	);
}

/**
 * Look up the labels of selected IDs the picker has not seen yet.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once the labels are stored.
 */
async function loadMissingLabels(): Promise< void > {
	const missing = selected.value.filter(
		( value ): value is number =>
			typeof value === 'number' && ! ( value in labels.value )
	);

	if ( missing.length === 0 ) {
		return;
	}

	try {
		remember(
			await get< TermItem[] >( 'terms', {
				taxonomy: props.taxonomy,
				include: missing,
				per_page: missing.length,
			} )
		);
	} catch {
		// The IDs stay on show as numbers; the search still works.
	}
}

watch( selected, loadMissingLabels, { immediate: true } );

watch(
	() => props.taxonomy,
	() => {
		results.value = [];
		labels.value = {};
		loadMissingLabels();
	}
);

onBeforeUnmount( () => {
	if ( searchTimer ) {
		clearTimeout( searchTimer );
	}
} );
</script>

<template>
	<a-select
		:value="selectValue"
		:mode="props.multiple ? 'multiple' : undefined"
		:options="options"
		:placeholder="
			props.placeholder || __( 'Search…', 'licensio-knowledge-base' )
		"
		:disabled="props.disabled"
		:loading="loading"
		:filter-option="false"
		:not-found-content="
			failed
				? __( 'The terms could not be loaded.', 'licensio-knowledge-base' )
				: __( 'No matches', 'licensio-knowledge-base' )
		"
		show-search
		allow-clear
		@search="onSearch"
		@change="onChange"
		@dropdown-visible-change="onDropdown"
	/>
</template>
