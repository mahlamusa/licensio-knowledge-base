/**
 * Ant Design theme for the knowledge base admin.
 *
 * Ant Design components take their colour from this object, not from CSS
 * custom properties, so the tokens in `tokens.css` and the values here have to
 * be kept in step by hand. Every value below has a counterpart there.
 *
 * `colorPrimary` is `--lkb-accent` (#247a5c), not the brand `#40b58a`. Ant
 * paints buttons, switches, links, active tabs and focus rings with
 * `colorPrimary`, and `#40b58a` fails WCAG in every one of those roles: 2.56:1
 * against white where a UI component needs 3:1, and white label text on a
 * `#40b58a` button is 2.56:1 against a 4.5:1 requirement. The accent measures
 * 5.23:1 and passes both.
 *
 * @since 1.0.0
 */

import type { ThemeConfig } from 'ant-design-vue/es/config-provider/context';

export const lkbTheme: ThemeConfig = {
	token: {
		colorPrimary: '#247a5c',
		colorSuccess: '#3f8600',
		colorError: '#cf1322',
		colorWarning: '#d48806',
		colorInfo: '#247a5c',
		colorText: '#11221b',
		colorTextSecondary: '#5b6b64',
		colorBorder: '#e4e9e6',
		colorBgLayout: '#fafbfa',
		borderRadius: 8,
		fontSize: 13,
	},
};
