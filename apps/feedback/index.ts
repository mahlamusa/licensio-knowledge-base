/**
 * Feedback inbox entry point.
 *
 * Built by the plugin's Vite config, which turns every `apps/*\/index.ts` into
 * a manifest entry that `Lisensio_KB_Assets::enqueue_app()` resolves. Mounts
 * only when the admin screen that owns the mount point is on the page, so the
 * bundle is inert if it is ever loaded elsewhere.
 *
 * @since 1.0.0
 */

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import Antd from 'ant-design-vue';
import 'ant-design-vue/dist/reset.css';
import '../styles/admin.css';
import App from './App.vue';

const mount = document.getElementById( 'lisensio-kb-feedback-app' );

if ( mount ) {
	mount.innerHTML = '';

	createApp( App ).use( createPinia() ).use( Antd ).mount( mount );
}
