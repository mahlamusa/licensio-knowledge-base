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
 * `colorBorder` is the boundary Ant draws round inputs, selects and default
 * buttons, so it is `--lkb-border` at 3.5:1 rather than a faint rule; cards
 * and tables draw with Ant's own lighter `colorBorderSecondary`. A tag is a
 * label, not a control, so it keeps the light wp-admin rule
 * (`--lkb-border-wp`).
 *
 * The tints Ant derives from the accent come out a muddy grey-green
 * (#afbab4), which painted the selected tree row and every info alert. They
 * are pinned to the brand tint `--lkb-brand-tint` instead, with a light green
 * edge for the info alert.
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
		colorBorder: '#7f8c85',
		colorPrimaryBg: '#edf9f4',
		colorPrimaryBgHover: '#dcf2e8',
		colorInfoBg: '#edf9f4',
		colorInfoBorder: '#a7dcc5',
		colorBgLayout: '#fafbfa',
		borderRadius: 8,
		fontSize: 13,
	},
	components: {
		Tag: {
			colorBorder: '#dcdcde',
		},
	},
};
