<script setup lang="ts">
/**
 * The product header the three admin screens share.
 *
 * Carries the License Server mark and the Licensio wordmark, so a reader
 * moving between the two admins sees one product rather than two plugins that
 * happen to be installed together. The action buttons are the same four, in
 * the same order, for the same reason.
 *
 * It gains a shadow once the page scrolls: the bar is sticky, and without a
 * shadow the content slides underneath it with nothing to say it is a layer.
 *
 * @since 1.0.0
 */

import { onMounted, onUnmounted, ref } from 'vue';
import { Button as AButton, Tooltip as ATooltip } from 'ant-design-vue';
import {
	BookOutlined,
	BulbOutlined,
	QuestionCircleOutlined,
	SettingOutlined,
} from '@ant-design/icons-vue';

/**
 * The settings screen this header links to.
 *
 * Passed in rather than read off a global: each screen already receives its
 * own localised configuration, and a header that reached for a window property
 * would break silently on whichever screen forgot to set it.
 */
const props = withDefaults(
	defineProps< { settingsUrl?: string } >(),
	{ settingsUrl: '' }
);

const hasScrolled = ref( false );

let ticking = false;

/**
 * Track whether the page has left the top.
 *
 * Read inside rAF so a fast scroll cannot queue a layout read per event.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function handleScroll(): void {
	if ( ticking ) {
		return;
	}

	ticking = true;

	requestAnimationFrame( () => {
		hasScrolled.value = window.scrollY > 0;
		ticking = false;
	} );
}

onMounted( () => {
	window.addEventListener( 'scroll', handleScroll, { passive: true } );
} );

onUnmounted( () => {
	window.removeEventListener( 'scroll', handleScroll );
} );
</script>

<template>
	<header class="lkb-header" :class="{ 'is-scrolled': hasScrolled }">
		<a class="lkb-header__skip" href="#lkb-main">Skip to content</a>

		<div class="lkb-header__inner lkb-measure">
			<span class="lkb-header__brand">
				<svg
					class="lkb-header__mark"
					viewBox="0 0 92 80"
					xmlns="http://www.w3.org/2000/svg"
					aria-hidden="true"
					focusable="false"
				>
					<path
						d="M92.08,38.74a21.24,21.24,0,0,0-4.78.55,32.22,32.22,0,1,0-63.63-8.23,24.84,24.84,0,0,0,1.17,49.66H92.08a21,21,0,0,0,0-42ZM72.61,54h-9a8.06,8.06,0,0,0,1.07-4,8.16,8.16,0,0,0-16.32,0,8.06,8.06,0,0,0,1.07,4h-9V33.26H72.61Zm-19,1-.6-.47a5.74,5.74,0,0,1-2.25-4.58,5.79,5.79,0,0,1,11.58,0,5.74,5.74,0,0,1-2.25,4.58l-.6.47L61.82,65.3H51.26ZM40.45,78.34V56.41H50.84L48.28,67.62h16.5L62.22,56.41H72.61V78.34Zm51.63,0H75V30.88H38.08V78.34H24.84a22.47,22.47,0,1,1,0-44.93H26V32.22a29.85,29.85,0,1,1,58.5,8.41l-.65,2.21,2.18-.75a18.61,18.61,0,1,1,6.06,36.21Z"
						transform="translate(-0.06 -0.02)"
					/>
				</svg>

				<span class="lkb-header__wordmark">Licensio</span>
			</span>

			<div class="lkb-header__actions">
				<ATooltip title="Request a feature" placement="bottom">
					<AButton
						type="text"
						class="lkb-header__btn"
						href="https://licenseserver.io/feature-requests"
						target="_blank"
						rel="noreferrer noopener"
						aria-label="Request a feature"
					>
						<template #icon><BulbOutlined /></template>
					</AButton>
				</ATooltip>

				<ATooltip title="Documentation" placement="bottom">
					<AButton
						type="text"
						class="lkb-header__btn"
						href="https://licenseserver.io/documentation"
						target="_blank"
						rel="noreferrer noopener"
						aria-label="Documentation"
					>
						<template #icon><BookOutlined /></template>
					</AButton>
				</ATooltip>

				<ATooltip title="Support" placement="bottom">
					<AButton
						type="text"
						class="lkb-header__btn"
						href="https://licenseserver.io/support"
						target="_blank"
						rel="noreferrer noopener"
						aria-label="Support"
					>
						<template #icon><QuestionCircleOutlined /></template>
					</AButton>
				</ATooltip>

				<ATooltip v-if="props.settingsUrl" title="Knowledge base settings" placement="bottom">
					<AButton
						type="text"
						class="lkb-header__btn"
						:href="props.settingsUrl"
						aria-label="Knowledge base settings"
					>
						<template #icon><SettingOutlined /></template>
					</AButton>
				</ATooltip>
			</div>
		</div>
	</header>
</template>

<style scoped>
.lkb-header {
	position: sticky;
	top: 32px;
	z-index: 100;
	background: var(--lkb-surface);
	border-bottom: 1px solid transparent;
	transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.lkb-header.is-scrolled {
	border-bottom-color: var(--lkb-border-wp);
	box-shadow: var(--lkb-shadow-md);
}

.lkb-header__inner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 56px;
}

.lkb-header__brand {
	display: flex;
	align-items: center;
	gap: 10px;
}

.lkb-header__mark {
	width: auto;
	height: 28px;
	fill: var(--lkb-brand);
}

/*
 * The wordmark is set in the brand face the theme uses, so the admin and the
 * storefront say the product name the same way. It is the one place a webfont
 * would be worth loading and still is not worth it: the stack degrades to the
 * admin's own sans, which is what the rest of the screen is set in anyway.
 */
.lkb-header__wordmark {
	color: var(--lkb-ink-wp);
	font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont,
		'Segoe UI', Roboto, sans-serif;
	font-size: 21px;
	font-weight: 700;
	letter-spacing: -0.02em;
	line-height: 1;
}

.lkb-header__actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.lkb-header__btn {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--lkb-ink-wp-muted);
}

.lkb-header__btn:hover {
	color: var(--lkb-accent);
}

/*
 * Off-screen until focused. A keyboard reader landing on the page should not
 * have to tab the whole header to reach the screen.
 */
.lkb-header__skip {
	position: absolute;
	top: 0;
	left: -9999px;
	z-index: 999;
	padding: 8px 16px;
	border-radius: 0 0 4px 0;
	background: var(--lkb-ink-wp);
	color: #fff;
	font-size: 14px;
	text-decoration: none;
}

.lkb-header__skip:focus {
	left: 0;
}

@media ( max-width: 782px ) {
	.lkb-header {
		top: 46px;
	}

	.lkb-header__inner {
		height: 48px;
	}

	.lkb-header__mark {
		height: 22px;
	}

	.lkb-header__wordmark {
		font-size: 17px;
	}
}
</style>
