/**
 * Articles app entry point.
 *
 * Built by the plugin's Vite config, which turns every `apps/*\/index.ts` into
 * a manifest entry that `Licensio_KB_Assets::enqueue_app()` resolves. Points
 * the shared REST client at the namespace the page localised, then mounts
 * only when the Articles page's mount point is on the page.
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
	Badge,
	Button,
	Checkbox,
	ConfigProvider,
	DatePicker,
	Form,
	Input,
	Modal,
	Popover,
	Progress,
	Radio,
	Select,
	Skeleton,
	Table,
	Tag,
	Tooltip,
} from 'ant-design-vue';
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

	const app = createApp( App ).use( createPinia() );

	[
		Alert,
		Badge,
		Button,
		Checkbox,
		ConfigProvider,
		DatePicker,
		Form,
		Input,
		Modal,
		Popover,
		Progress,
		Radio,
		Select,
		Skeleton,
		Table,
		Tag,
		Tooltip,
	].forEach( ( component ) => {
		app.use( component );
	} );

	app.mount( mount );
}
