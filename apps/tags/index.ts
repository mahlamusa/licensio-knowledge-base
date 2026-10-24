/**
 * Tags app entry point.
 *
 * Mounts onto the container `Licensio_KB_Tags_Admin::render()` printed, after
 * pointing the shared REST client at the root and nonce the page localised.
 * Bails quietly when the container is absent, so the bundle is inert if it is
 * ever enqueued on the wrong screen.
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

const mount = document.getElementById( 'licensio-kb-tags' );

if ( mount ) {
	const config = settings();

	configure( { root: config.root, nonce: config.nonce } );

	mount.innerHTML = '';

	createApp( App ).use( createPinia() ).use( Antd ).mount( mount );
}
