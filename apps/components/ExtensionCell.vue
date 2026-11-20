<script setup lang="ts">
/**
 * One cell of an extension column.
 *
 * When a script registered a `render` for the column, the cell gives it a
 * plain container element and leaves its content to that script: Vue never
 * renders inside it. The `render` runs once the cell is mounted and again
 * whenever the row is replaced (a refresh) or the column changes, and the
 * cleanup it returned runs before each new render and when the cell
 * unmounts, after which the container is emptied.
 *
 * Without a registered `render` the cell shows the row's
 * `extensions[ key ].text`, which PHP filled.
 *
 * Usage:
 *
 *     <ExtensionCell screen="articles" column-key="acme/score" :row="row" :ctx="ctx" />
 *
 * Consumed by the Articles, Categories and Tags apps' extension columns.
 *
 * @since 1.1.0
 */

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { extensionHandlers } from './extensions';
import type {
	ExtensionContext,
	ExtensionRow,
	ExtensionScreen,
} from './extensions';

const props = defineProps< {
	/** The app the cell is in. */
	screen: ExtensionScreen;
	/** The declared column key, e.g. `acme/score`. */
	columnKey: string;
	/** The row the cell belongs to. */
	row: ExtensionRow;
	/** What the column's `render` is given to talk back to the app. */
	ctx: ExtensionContext;
} >();

const container = ref< HTMLElement | null >( null );

/**
 * The column's registered handler, if any.
 *
 * @since 1.1.0
 *
 * @return {Object|undefined} The handler.
 */
function handler() {
	return extensionHandlers( props.screen ).columns[ props.columnKey ];
}

// Read when the cell is set up: the app mounts after every extension script ran.
const rendered = 'function' === typeof handler()?.render;

const text = computed(
	() => props.row.extensions?.[ props.columnKey ]?.text ?? ''
);

let cleanup: ( () => void ) | undefined;

/**
 * Run the last render's cleanup and empty the container.
 *
 * @since 1.1.0
 *
 * @return {void}
 */
function teardown(): void {
	const done = cleanup;

	cleanup = undefined;

	if ( done ) {
		done();
	}

	container.value?.replaceChildren();
}

/**
 * Render the column into the container, after cleaning up the last render.
 *
 * @since 1.1.0
 *
 * @return {void}
 */
function paint(): void {
	teardown();

	const column = handler();

	if ( 'function' !== typeof column?.render || ! container.value ) {
		return;
	}

	const result = column.render( container.value, props.row, props.ctx );

	cleanup = 'function' === typeof result ? result : undefined;
}

onMounted( paint );

watch( () => [ props.row, props.columnKey ], paint, { flush: 'post' } );

onBeforeUnmount( teardown );
</script>

<template>
	<div
		v-if="rendered"
		ref="container"
		class="licensio-kb-extension-cell"
	></div>
	<span v-else class="licensio-kb-extension-cell">{{ text }}</span>
</template>
