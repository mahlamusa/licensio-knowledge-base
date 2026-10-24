/**
 * Categories screen entry point.
 *
 * Built by the plugin's Vite config like every `apps/*\/index.ts`. Points the
 * shared REST client at the root and nonce `Licensio_KB_Categories_Admin`
 * localised, then mounts onto `#licensio-kb-categories`. Bails quietly when
 * that element is absent, so the bundle is inert anywhere else.
 *
 * @since 1.0.0
 */

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import Antd from 'ant-design-vue';
import 'ant-design-vue/dist/reset.css';
import '../styles/admin.css';
import { configure } from '../components/rest';
import { settings } from './api';
import App from './App.vue';

const mount = document.getElementById( 'licensio-kb-categories' );

if ( mount ) {
	const { root, nonce } = settings();

	configure( { root, nonce } );

	mount.innerHTML = '';

	createApp( App ).use( createPinia() ).use( Antd ).mount( mount );
}
