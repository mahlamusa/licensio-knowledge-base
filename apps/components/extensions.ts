/**
 * Types and lookups of the content manager apps' extension API.
 *
 * Other plugins declare columns, filters, bulk actions and row actions in PHP
 * (`Licensio_KB_Extensions`); the page localises the ones the current user may
 * see into each app's config under `extensions` (`ExtensionsConfig`). Their
 * scripts register the behaviour on `window.licensioKb.extensions`, defined by
 * the classic script `assets/js/licensio-kb-extensions.js` that every app
 * depends on, and the apps look the handlers up here when they render.
 *
 * PHP declares the labels and who sees what; JS supplies the behaviour. A
 * column with no registered `render` shows its row data's `text`, and an
 * action with no registered `run` is hidden.
 *
 * Consumed by `ExtensionCell.vue`, `row-actions.ts` and the Articles,
 * Categories and Tags apps.
 *
 * @since 1.1.0
 */

/** The apps an extension can be added to. */
export type ExtensionScreen = 'articles' | 'categories' | 'tags';

/** A row of an app's list, as the extension handlers receive it. */
export interface ExtensionRow {
	id: number;
	/** The extension column data of the row, keyed by column key. */
	extensions?: Record< string, ExtensionCellData >;
}

/** One extension column's data for one row. */
export interface ExtensionCellData {
	/** What the cell shows when no script renders the column. */
	text?: string;
	[ key: string ]: unknown;
}

/** What an extension handler is given to talk back to the app. */
export interface ExtensionContext {
	/** Reload the given rows, or the whole page when no IDs are given. */
	refresh: ( ids?: number[] ) => Promise< void >;
	/** Clear the list's selection. */
	clearSelection: () => void;
	/** The app the handler runs in. */
	screen: ExtensionScreen;
	/** The `licensio-kb/v1` namespace URL and the `wp_rest` nonce. */
	rest: { root: string; nonce: string };
}

/**
 * Renders a column into the container the app gives it. May return a
 * cleanup function, which the app calls before the cell renders again or
 * unmounts.
 */
export interface ColumnHandler< Row extends ExtensionRow = ExtensionRow > {
	render: (
		el: HTMLElement,
		row: Row,
		ctx: ExtensionContext
	) => void | ( () => void );
}

/** Runs a bulk action on the selected row IDs. */
export interface BulkActionHandler {
	run: ( ids: number[], ctx: ExtensionContext ) => unknown;
}

/**
 * Runs a row action on one row. While its promise is pending, the same
 * action does not run again on that row. When it throws or returns a promise
 * that rejects, the app shows a generic error toast and logs the error to the
 * console with the action's key. Rows in the Articles Trash view show no
 * extension row actions.
 */
export interface RowActionHandler< Row extends ExtensionRow = ExtensionRow > {
	run: ( row: Row, ctx: ExtensionContext ) => unknown;
}

/** The handlers a script registers for one screen, keyed by declared key. */
export interface ExtensionDefinition<
	Row extends ExtensionRow = ExtensionRow,
> {
	columns?: Record< string, ColumnHandler< Row > >;
	bulkActions?: Record< string, BulkActionHandler >;
	rowActions?: Record< string, RowActionHandler< Row > >;
}

/** The handlers registered for one screen. */
export type ExtensionHandlers< Row extends ExtensionRow = ExtensionRow > =
	Required< ExtensionDefinition< Row > >;

/** `window.licensioKb.extensions`. */
export interface ExtensionRegistry {
	register: (
		screen: ExtensionScreen,
		definition: ExtensionDefinition
	) => boolean;
	get: ( screen: ExtensionScreen ) => ExtensionHandlers;
}

/** A column declared in PHP. */
export interface ExtensionColumn {
	key: string;
	label: string;
	/** The column picker group; empty for none. */
	group: string;
	/** Whether it shows before the user chooses columns. */
	defaultVisible: boolean;
	sortable: boolean;
	/** The column key it follows; empty for the end. */
	position: string;
}

/** A filter dropdown declared in PHP. */
export interface ExtensionFilter {
	key: string;
	label: string;
	/** The query argument the core screen uses for it; empty for none. */
	queryArg: string;
	options: Array< { value: string; label: string } >;
}

/** A bulk or row action declared in PHP. */
export interface ExtensionAction {
	key: string;
	label: string;
}

/** The `extensions` entry of an app's config: what the user may see. */
export interface ExtensionsConfig {
	columns: ExtensionColumn[];
	filters: ExtensionFilter[];
	bulkActions: ExtensionAction[];
	rowActions: ExtensionAction[];
}

declare global {
	interface Window {
		licensioKb?: { extensions?: ExtensionRegistry };
	}
}

/**
 * An `extensions` config with nothing declared, for the apps' fallbacks.
 *
 * @since 1.1.0
 *
 * @return {ExtensionsConfig} Empty lists.
 */
export function noExtensions(): ExtensionsConfig {
	return { columns: [], filters: [], bulkActions: [], rowActions: [] };
}

/**
 * The handlers registered for a screen.
 *
 * Read when an app renders rather than once at load. Extension scripts
 * register synchronously before the apps run (see
 * `assets/js/licensio-kb-extensions.js`).
 *
 * @since 1.1.0
 *
 * @param {ExtensionScreen} screen The app.
 *
 * @return {ExtensionHandlers} The handlers; empty when none, or when the
 *                             registry script did not load.
 */
export function extensionHandlers< Row extends ExtensionRow = ExtensionRow >(
	screen: ExtensionScreen
): ExtensionHandlers< Row > {
	// Each app knows the shape of its own rows; the registry stores handlers
	// for any row.
	return ( window.licensioKb?.extensions?.get( screen ) ?? {
		columns: {},
		bulkActions: {},
		rowActions: {},
	} ) as unknown as ExtensionHandlers< Row >;
}
