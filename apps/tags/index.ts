/**
 * Tags app entry point.
 *
 * Mounts onto the container `Licensio_KB_Tags_Admin::render()` printed, after
 * pointing the shared REST client at the root and nonce the page localised.
 * Bails quietly when the container is absent, so the bundle is inert if it is
 * ever enqueued on the wrong screen.
 *
 * Ant Design Vue is registered component by component, as on the Settings
 * screen, rather than through `.use( Antd )`, which bundles the whole library.
 * Each plugin below also registers its own sub-components (`Input` brings
 * `a-input-search` and `a-textarea`).
 * The shared components in `apps/components` import what they render
 * themselves, and `message` and `notification` are imported where they are
 * called, so neither needs registering here. A tag used in a template but
 * missing from this list renders as an unknown element.
 *
 * @since 1.0.0
 */

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import {
	Alert,
	Button,
	ConfigProvider,
	Input,
	Modal,
	Switch,
	Table,
} from 'ant-design-vue';
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

	const app = createApp( App ).use( createPinia() );

	[ Alert, Button, ConfigProvider, Input, Modal, Switch, Table ].forEach(
		( component ) => {
			app.use( component );
		}
	);

	app.mount( mount );
}
