/**
 * Frontend namespace bootstrap.
 *
 * Creates the single global the plugin's frontend scripts hang off, so the
 * type-ahead (#4), the feedback widget (#8) and the analytics beacon (#9)
 * share one namespace instead of each declaring its own global.
 *
 * This is deliberately a CLASSIC script, not a Vite entry and not an ES module.
 * The frontend scripts are dependency-free classic scripts by design, and a
 * module script is always deferred: it executes AFTER every classic script on
 * the page. So a classic `search.js` that declares `licensio-kb` as a WordPress
 * dependency would still run first and find `window.licensioKB` undefined — an
 * execution-order rule of the browser that no amount of declared dependency
 * ordering can fix. It is therefore served straight from `assets/js/` and is
 * never built.
 *
 * Register a dependency on the `licensio-kb` handle to use it.
 *
 * This file deliberately contains no behaviour.
 *
 * @since 1.0.0
 */

( function () {
	'use strict';

	window.licensioKB = window.licensioKB || {};
} )();
