/**
 * Articles app entry point.
 *
 * Built by the plugin's Vite config, which turns every `apps/*\/index.ts` into
 * a manifest entry that `Licensio_KB_Assets::enqueue_app()` resolves. Points
 * the shared REST client at the namespace the page localised, then mounts
 * only when the Articles page's mount point is on the page.
 *
 * @since 1.0.0
 */

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import Antd from 'ant-design-vue';
import 'ant-design-vue/dist/reset.css';
import '../styles/admin.css';
import { configure } from '../components/rest';
import { config } from './api';
import App from './App.vue';

const mount = document.getElementById( 'licensio-kb-articles' );

if ( mount ) {
	const { root, nonce } = config();

	configure( { root, nonce } );

	mount.innerHTML = '';

	createApp( App ).use( createPinia() ).use( Antd ).mount( mount );
}
