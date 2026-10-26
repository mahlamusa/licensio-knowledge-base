/**
 * Categories screen entry point.
 *
 * Built by the plugin's Vite config like every `apps/*\/index.ts`. Points the
 * shared REST client at the root and nonce `Licensio_KB_Categories_Admin`
 * localised, then mounts onto `#licensio-kb-categories`. Bails quietly when
 * that element is absent, so the bundle is inert anywhere else.
 *
 * Ant Design Vue is registered component by component, as on the Settings
 * screen, rather than through `.use( Antd )`, which bundles the whole library.
 * Each plugin below also registers its own sub-components (`Form` brings
 * `a-form-item`, `Input` brings `a-input-search` and `a-textarea`, and so on).
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
	Drawer,
	Dropdown,
	Form,
	Input,
	Menu,
	Modal,
	Radio,
	Skeleton,
	Tag,
	Tree,
} from 'ant-design-vue';
import 'ant-design-vue/dist/reset.css';
import '../styles/admin.css';
import './categories.css';
import { configure } from '../components/rest';
import { settings } from './api';
import App from './App.vue';

const mount = document.getElementById( 'licensio-kb-categories' );

if ( mount ) {
	const { root, nonce } = settings();

	configure( { root, nonce } );

	mount.innerHTML = '';

	const app = createApp( App ).use( createPinia() );

	[
		Alert,
		Button,
		ConfigProvider,
		Drawer,
		Dropdown,
		Form,
		Input,
		Menu,
		Modal,
		Radio,
		Skeleton,
		Tag,
		Tree,
	].forEach( ( component ) => {
		app.use( component );
	} );

	app.mount( mount );
}
