<script setup lang="ts">
/**
 * A line chart of one or two daily series.
 *
 * Chart.js is created once and updated in place, because destroying and
 * rebuilding a chart on every range change leaks canvases in long admin
 * sessions.
 *
 * @since 1.0.0
 */

import { Chart, registerables } from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { TrendPoint } from '../types';

Chart.register( ...registerables );

const props = defineProps< {
	series: Array< { label: string; color: string; points: TrendPoint[] } >;
} >();

const canvas = ref< HTMLCanvasElement | null >( null );
let chart: Chart | null = null;

/**
 * Every date any series covers, in ascending order.
 *
 * @since 1.0.0
 *
 * @return {string[]} The x-axis labels.
 */
function labels(): string[] {
	const dates = new Set< string >();

	props.series.forEach( ( set ) =>
		set.points.forEach( ( point ) => dates.add( point.date ) )
	);

	return Array.from( dates ).sort();
}

/**
 * The chart datasets, zero-filled across every label.
 *
 * @since 1.0.0
 *
 * @param {string[]} axis The x-axis labels.
 *
 * @return {Array<Object>} The Chart.js datasets.
 */
function datasets( axis: string[] ) {
	return props.series.map( ( set ) => {
		const byDate = new Map(
			set.points.map( ( point ) => [ point.date, point.total ] )
		);

		return {
			label: set.label,
			borderColor: set.color,
			backgroundColor: set.color,
			tension: 0.3,
			data: axis.map( ( date ) => byDate.get( date ) ?? 0 ),
		};
	} );
}

/**
 * Draw or redraw the chart from the current props.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function render(): void {
	if ( ! canvas.value ) {
		return;
	}

	const axis = labels();

	if ( chart ) {
		chart.data.labels = axis;
		chart.data.datasets = datasets( axis );
		chart.update();

		return;
	}

	chart = new Chart( canvas.value, {
		type: 'line',
		data: { labels: axis, datasets: datasets( axis ) },
		options: {
			responsive: true,
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
		},
	} );
}

onMounted( render );
watch( () => props.series, render, { deep: true } );

onBeforeUnmount( () => {
	if ( chart ) {
		chart.destroy();
		chart = null;
	}
} );
</script>

<template>
	<div class="licensio-kb-chart">
		<canvas ref="canvas"></canvas>
	</div>
</template>

<style scoped>
.licensio-kb-chart {
	position: relative;
	height: 280px;
}
</style>
