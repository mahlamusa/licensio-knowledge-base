/**
 * Tools screen entry point.
 *
 * Mounts the Vue app onto the container `Lisensio_KB_Tools_Admin::render()`
 * printed. Bails quietly when the container is absent, so the bundle is inert
 * if it is ever enqueued on the wrong screen.
 *
 * @since 1.0.0
 */

import { createApp } from 'vue';
import Antd from 'ant-design-vue';
import App from './App.vue';
import 'ant-design-vue/dist/reset.css';
import '../styles/admin.css';

const container = document.getElementById( 'lisensio-kb-tools' );

if ( container ) {
	container.innerHTML = '';

	createApp( App ).use( Antd ).mount( container );
}
