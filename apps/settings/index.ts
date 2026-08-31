/**
 * Settings screen entry point.
 *
 * Ant Design Vue is registered component by component rather than through
 * `.use( Antd )`. The blanket plugin pulls the whole library in — every table,
 * every date picker, every icon — because a default export that touches all of
 * them cannot be tree-shaken, and this is the third app in the plugin to bundle
 * it. Naming the twelve components this screen actually uses lets Rollup drop
 * the rest.
 *
 * Mounts onto the container `Lisensio_KB_Settings_Admin::render()` printed, and
 * bails quietly when it is absent so the bundle is inert if it is ever enqueued
 * on the wrong screen.
 *
 * @since 1.0.0
 */

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import {
	Alert,
	ConfigProvider,
	Button,
	Form,
	FormItem,
	Input,
	InputNumber,
	Modal,
	Select,
	SelectOption,
	Spin,
	Switch,
	TabPane,
	Tabs,
} from 'ant-design-vue';
import App from './App.vue';
import 'ant-design-vue/dist/reset.css';
import '../styles/admin.css';

const container = document.getElementById( 'lisensio-kb-settings' );

if ( container ) {
	container.innerHTML = '';

	const app = createApp( App ).use( createPinia() );

	[
		Alert,
		ConfigProvider,
		Button,
		Form,
		FormItem,
		Input,
		InputNumber,
		Modal,
		Select,
		SelectOption,
		Spin,
		Switch,
		TabPane,
		Tabs,
	].forEach( ( component ) => {
		app.use( component );
	} );

	app.mount( container );
}
