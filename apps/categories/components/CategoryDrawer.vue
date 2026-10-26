<script setup lang="ts">
/**
 * The add and edit drawer.
 *
 * Opening it sends nothing. Saving is not destructive, so Save writes the
 * category straight away, with no confirmation. A field error from the
 * server (`data.params`) is shown on its field only; any other error above
 * the form.
 *
 * A new category's slug follows its name as the user types or pastes, using
 * core's rules, until the user edits the slug by hand. An existing
 * category's slug never follows a rename, because that would change its
 * URLs; "Generate from name" makes one on request instead.
 *
 * The icon is one source: only the picked source is kept, and the other icon
 * fields are cleared on save (see `../icon`).
 *
 * Ant Design's drawer does not keep focus inside itself, so while it is open
 * Tab and Shift+Tab wrap around its fields and buttons. It opens with focus
 * on the Name field rather than on its panel. Escape closes it, except on an
 * open select, where it closes only the select.
 *
 * @since 1.0.0
 */

import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import TermSelect from '../../components/TermSelect.vue';
import { FOCUSABLE, useEscapeClose } from '../../components/popover-focus';
import { errorMessage, RestError } from '../../components/rest';
import { __, sprintf } from '../../components/i18n';
import type { TermValue } from '../../components/types/terms';
import { iconInput, iconSourceOf } from '../icon';
import type { IconSource } from '../icon';
import { slugify } from '../slug';
import { selfAndDescendantIds, useCategoriesStore } from '../store';
import type { CategoryInput, CategoryItem } from '../types';
import IconField from './IconField.vue';
import MediaField from './MediaField.vue';

const props = defineProps< {
	/** Whether the drawer is open. */
	open: boolean;
	/** The category to edit, or null to add one. */
	category: CategoryItem | null;
	/** The parent a new category starts under. */
	parent: number;
} >();

const emit = defineEmits< {
	( event: 'close' ): void;
	( event: 'closed' ): void;
} >();

const store = useCategoriesStore();

const form = reactive( {
	name: '',
	slug: '',
	parent: null as number | null,
	description: '',
	iconSource: 'upload' as IconSource,
	icon: '',
	iconId: 0,
	iconUrl: '',
	iconInline: '',
	imageId: 0,
	imageUrl: '',
} );

/** The fields that show the server's error for them under the field. */
const FIELDS = [
	'name',
	'slug',
	'parent',
	'description',
	'icon',
	'icon_id',
	'icon_svg',
	'icon_data',
];

/** Whether the user edited a new category's slug by hand. */
const slugEdited = ref( false );

const saving = ref( false );
const formError = ref( '' );
const fieldErrors = ref< Record< string, string > >( {} );

const title = computed( () =>
	props.category
		? sprintf(
				/* translators: %s: category name. */
				__( 'Edit “%s”', 'licensio-knowledge-base' ),
				props.category.name
		  )
		: __( 'Add category', 'licensio-knowledge-base' )
);

const exclude = computed( () =>
	props.category ? selfAndDescendantIds( store.items, props.category.id ) : []
);

const nameMissing = computed( () => '' === form.name.trim() );

// Escape on an open select closes only the select, not the drawer.
useEscapeClose(
	() => props.open,
	'.lkb-category-drawer',
	() => emit( 'close' )
);

/**
 * Keep Tab and Shift+Tab inside the open drawer.
 *
 * Only acts while focus is in the drawer, so a dialog or popover, which Ant
 * Design renders elsewhere in the page, keeps its own tab order.
 *
 * @since 1.0.0
 *
 * @param {KeyboardEvent} event The key press.
 *
 * @return {void}
 */
function trapFocus( event: KeyboardEvent ): void {
	const panel = document.querySelector< HTMLElement >(
		'.lkb-category-drawer .ant-drawer-content-wrapper'
	);
	const active = panel?.ownerDocument.activeElement ?? null;

	if ( 'Tab' !== event.key || ! panel || ! panel.contains( active ) ) {
		return;
	}

	const items = Array.from(
		panel.querySelectorAll< HTMLElement >( FOCUSABLE )
	).filter( ( item ) => null !== item.offsetParent );

	if ( 0 === items.length ) {
		return;
	}

	const first = items[ 0 ];
	const last = items[ items.length - 1 ];

	if (
		event.shiftKey &&
		( active === first || ! items.includes( active as HTMLElement ) )
	) {
		event.preventDefault();
		last.focus();
	} else if ( ! event.shiftKey && active === last ) {
		event.preventDefault();
		first.focus();
	}
}

watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			document.addEventListener( 'keydown', trapFocus );
		} else {
			document.removeEventListener( 'keydown', trapFocus );
		}
	},
	{ immediate: true }
);

onBeforeUnmount( () => {
	document.removeEventListener( 'keydown', trapFocus );
} );

/**
 * Focus the Name field once the drawer has opened, or report it closed.
 *
 * A user who already tabbed to a control while the drawer slid in keeps
 * their place.
 *
 * @since 1.0.0
 *
 * @param {boolean} open Whether the drawer is now open.
 *
 * @return {void}
 */
function onAfterOpenChange( open: boolean ): void {
	if ( ! open ) {
		emit( 'closed' );
		return;
	}

	const name = document.getElementById( 'lkb-category-name' );
	const active = name?.ownerDocument.activeElement;

	if (
		active?.closest( '.lkb-category-drawer' ) &&
		active.matches( FOCUSABLE )
	) {
		return;
	}

	name?.focus();
}

/**
 * Fill the form from the category being edited, or blank it for a new one.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function reset(): void {
	const item = props.category;

	form.name = item?.name ?? '';
	form.slug = item?.slug ?? '';
	form.parent = ( item ? item.parent : props.parent ) || null;
	form.description = item?.description ?? '';
	form.iconSource = iconSourceOf( item );
	form.icon = item?.icon ?? '';
	form.iconId = item?.icon_id ?? 0;
	form.iconUrl = item?.icon_url ?? '';
	form.iconInline = item?.icon_svg || item?.icon_data || '';
	slugEdited.value = false;
	form.imageId = item?.image_id ?? 0;
	form.imageUrl = item?.image_url ?? '';
	formError.value = '';
	fieldErrors.value = {};
}

watch(
	() => props.open,
	( open ) => {
		if ( open ) {
			reset();
		}
	},
	{ immediate: true }
);

/**
 * Make a new category's slug from its name, until the slug is edited.
 *
 * @since 1.0.0
 *
 * @param {string} name The name as typed or pasted.
 *
 * @return {void}
 */
function onName( name: string ): void {
	form.name = name;

	if ( ! props.category && ! slugEdited.value ) {
		form.slug = slugify( name );
	}
}

/**
 * Keep a slug the user typed, and stop it following the name.
 *
 * @since 1.0.0
 *
 * @param {string} slug The slug as typed.
 *
 * @return {void}
 */
function onSlug( slug: string ): void {
	form.slug = slug;
	slugEdited.value = true;
}

/**
 * Make the slug from the name on request, for an existing category.
 *
 * @since 1.0.0
 *
 * @return {void}
 */
function generateSlug(): void {
	form.slug = slugify( form.name );
}

/**
 * Keep the picked parent.
 *
 * @since 1.0.0
 *
 * @param {TermValue|TermValue[]|null} value The picker's value.
 *
 * @return {void}
 */
function onParent( value: TermValue | TermValue[] | null ): void {
	form.parent = typeof value === 'number' ? value : null;
}

/**
 * The server's field errors, if the error carries any.
 *
 * @since 1.0.0
 *
 * @param {unknown} error The caught error.
 *
 * @return {Record<string, string>} Field name to message.
 */
function paramsOf( error: unknown ): Record< string, string > {
	if ( ! ( error instanceof RestError ) ) {
		return {};
	}

	const params = ( error.data as { params?: unknown } | null )?.params;

	if ( ! params || typeof params !== 'object' ) {
		return {};
	}

	return Object.fromEntries(
		Object.entries( params as Record< string, unknown > ).map(
			( [ key, value ] ) => [ key, String( value ) ]
		)
	);
}

/**
 * The validation state for a field.
 *
 * @since 1.0.0
 *
 * @param {string} field The REST field name.
 *
 * @return {'error'|undefined} The status.
 */
function statusOf( field: string ): 'error' | undefined {
	return fieldErrors.value[ field ] ? 'error' : undefined;
}

/**
 * Write the category.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once saved or failed.
 */
async function save(): Promise< void > {
	if ( nameMissing.value ) {
		return;
	}

	const input: CategoryInput = {
		name: form.name.trim(),
		parent: form.parent ?? 0,
		...iconInput(
			{
				source: form.iconSource,
				name: form.icon,
				id: form.iconId,
				inline: form.iconInline,
			},
			props.category
		),
	};

	// The description is shown and sent exactly as stored. It is sent only
	// when the user changed it, so a save that only renames a category or
	// changes its icon never passes the description through the server's
	// HTML filter again.
	if ( form.description !== ( props.category?.description ?? '' ) ) {
		input.description = form.description;
	}

	// An unchanged image is not sent again: if its file was deleted from the
	// media library since, the server would refuse it and block every other
	// change to the category. `iconInput()` does the same for the icon.
	if ( form.imageId !== props.category?.image_id ) {
		input.image_id = form.imageId;
	}

	// An empty slug is left out, so the server keeps or derives one. So is a
	// new category's slug that still follows the name: the server makes the
	// same one, and also makes it unique.
	if ( '' !== form.slug.trim() && ( props.category || slugEdited.value ) ) {
		input.slug = form.slug.trim();
	}

	saving.value = true;
	formError.value = '';
	fieldErrors.value = {};

	try {
		if ( props.category ) {
			await store.update( props.category.id, input );
		} else {
			await store.create( input );
		}

		message.success( __( 'Category saved.', 'licensio-knowledge-base' ) );
		emit( 'close' );
	} catch ( error ) {
		fieldErrors.value = paramsOf( error );

		// When every message is shown on its field, none is repeated above the form.
		const fields = Object.keys( fieldErrors.value );

		formError.value =
			fields.length > 0 &&
			fields.every( ( field ) => FIELDS.includes( field ) )
				? ''
				: errorMessage( error );
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<a-drawer
		:open="props.open"
		:title="title"
		width="min( 520px, 100vw )"
		root-class-name="lkb-category-drawer"
		:keyboard="false"
		destroy-on-close
		@close="emit( 'close' )"
		@after-open-change="onAfterOpenChange"
	>
		<a-alert
			v-if="formError"
			type="error"
			show-icon
			:message="formError"
			class="lkb-drawer__error"
		/>

		<a-form layout="vertical" @submit.prevent>
			<a-form-item
				:label="__( 'Name', 'licensio-knowledge-base' )"
				html-for="lkb-category-name"
				required
				:validate-status="statusOf( 'name' )"
				:help="fieldErrors.name"
			>
				<a-input
					id="lkb-category-name"
					:value="form.name"
					@update:value="onName"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Slug', 'licensio-knowledge-base' )"
				html-for="lkb-category-slug"
				:validate-status="statusOf( 'slug' )"
				:help="fieldErrors.slug"
				:extra="
					props.category
						? __(
								'The slug is part of the category’s web address, so it does not change when the category is renamed.',
								'licensio-knowledge-base'
						  )
						: __(
								'Made from the name until you edit it.',
								'licensio-knowledge-base'
						  )
				"
			>
				<div class="lkb-drawer__slug">
					<a-input
						id="lkb-category-slug"
						:value="form.slug"
						@update:value="onSlug"
					/>
					<a-button
						v-if="props.category"
						:disabled="nameMissing"
						@click="generateSlug"
					>
						{{
							__(
								'Generate from name',
								'licensio-knowledge-base'
							)
						}}
					</a-button>
				</div>
			</a-form-item>

			<a-form-item
				:label="__( 'Parent category', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'parent' )"
				:help="fieldErrors.parent"
				:extra="
					__(
						'Leave empty for a top-level category.',
						'licensio-knowledge-base'
					)
				"
			>
				<TermSelect
					:model-value="form.parent"
					taxonomy="licensio_category"
					:exclude="exclude"
					:placeholder="__( 'Top level', 'licensio-knowledge-base' )"
					:aria-label="
						__( 'Parent category', 'licensio-knowledge-base' )
					"
					@update:model-value="onParent"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Description', 'licensio-knowledge-base' )"
				html-for="lkb-category-description"
				:validate-status="statusOf( 'description' )"
				:help="fieldErrors.description"
			>
				<a-textarea
					id="lkb-category-description"
					v-model:value="form.description"
					:rows="3"
				/>
			</a-form-item>

			<IconField
				v-model:source="form.iconSource"
				v-model:icon="form.icon"
				v-model:icon-id="form.iconId"
				v-model:icon-url="form.iconUrl"
				v-model:inline="form.iconInline"
				:errors="fieldErrors"
			/>

			<a-form-item
				:label="__( 'Image', 'licensio-knowledge-base' )"
				:extra="
					__(
						'Shown on the category card and at the top of the category page.',
						'licensio-knowledge-base'
					)
				"
			>
				<MediaField
					:id="form.imageId"
					v-model:url="form.imageUrl"
					:label="__( 'Image', 'licensio-knowledge-base' )"
					:title="__( 'Select an image', 'licensio-knowledge-base' )"
					@update:id="( value: number ) => ( form.imageId = value )"
				/>
			</a-form-item>
		</a-form>

		<template #footer>
			<div class="lkb-drawer__footer">
				<a-button @click="emit( 'close' )">
					{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
				</a-button>
				<a-button
					type="primary"
					:disabled="nameMissing"
					:loading="saving"
					@click="save"
				>
					{{ __( 'Save', 'licensio-knowledge-base' ) }}
				</a-button>
			</div>
			<p v-if="nameMissing" class="lkb-drawer__hint">
				{{ __( 'Enter a name to save.', 'licensio-knowledge-base' ) }}
			</p>
		</template>
	</a-drawer>
</template>

<style scoped>
.lkb-drawer__error {
	margin-bottom: var( --lkb-gap );
}

.lkb-drawer__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.lkb-drawer__slug {
	display: flex;
	gap: 8px;
}

.lkb-drawer__hint {
	margin: 6px 0 0;
	color: var( --lkb-ink-muted );
	font-size: 12px;
	text-align: right;
}
</style>

<style>
/*
 * Fields 16px apart rather than Ant's 24px: with each field's help line the
 * default left gaps of about 45px, too loose for a tool, and pushed the
 * Image field below the fold.
 */
.lkb-category-drawer .ant-form-item {
	margin-bottom: var( --lkb-gap );
}

/*
 * The drawer is teleported to the body, outside the scoped styles. Start it
 * below the WordPress admin bar, which sits above it and would otherwise
 * cover its title and close button.
 */
.lkb-category-drawer .ant-drawer-content-wrapper {
	top: var( --wp-admin--admin-bar--height, 0px );
}
</style>
