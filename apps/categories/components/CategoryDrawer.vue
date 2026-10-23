<script setup lang="ts">
/**
 * The add and edit drawer.
 *
 * Opening it sends nothing. Save is a `ConfirmButton`, so the category is
 * written only after the user confirms. A field error from the server
 * (`data.params`) is shown on its field; any other error above the form.
 *
 * @since 1.0.0
 */

import { computed, reactive, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import ConfirmButton from '../../components/ConfirmButton.vue';
import TermSelect from '../../components/TermSelect.vue';
import { RestError } from '../../components/rest';
import { __, sprintf } from '../../components/i18n';
import type { TermValue } from '../../components/types/terms';
import { errorMessage } from '../api';
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
	icon: '',
	iconId: 0,
	iconUrl: '',
	iconSvg: '',
	imageId: 0,
	imageUrl: '',
} );

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
	form.icon = item?.icon ?? '';
	form.iconId = item?.icon_id ?? 0;
	form.iconUrl = item?.icon_url ?? '';
	form.iconSvg = item?.icon_svg ?? '';
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
 * Write the category, after the user confirmed.
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
		description: form.description,
		icon: form.icon.trim(),
		icon_svg: form.iconSvg,
	};

	// An unchanged icon or image is not sent again: if its file was deleted
	// from the media library since, the server would refuse it and block
	// every other change to the category.
	if ( form.iconId !== props.category?.icon_id ) {
		input.icon_id = form.iconId;
	}

	if ( form.imageId !== props.category?.image_id ) {
		input.image_id = form.imageId;
	}

	// An empty slug is left out, so the server keeps or derives one.
	if ( '' !== form.slug.trim() ) {
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
		formError.value = errorMessage( error );
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
		destroy-on-close
		@close="emit( 'close' )"
		@after-open-change="( open: boolean ) => ! open && emit( 'closed' )"
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
				<a-input id="lkb-category-name" v-model:value="form.name" />
			</a-form-item>

			<a-form-item
				:label="__( 'Slug', 'licensio-knowledge-base' )"
				html-for="lkb-category-slug"
				:validate-status="statusOf( 'slug' )"
				:help="fieldErrors.slug"
				:extra="
					__(
						'Leave empty to make one from the name.',
						'licensio-knowledge-base'
					)
				"
			>
				<a-input id="lkb-category-slug" v-model:value="form.slug" />
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
					:aria-label="__( 'Parent category', 'licensio-knowledge-base' )"
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
				v-model:icon="form.icon"
				v-model:icon-id="form.iconId"
				v-model:icon-url="form.iconUrl"
				v-model:icon-svg="form.iconSvg"
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
				<ConfirmButton
					type="primary"
					:title="__( 'Save category?', 'licensio-knowledge-base' )"
					:ok-text="__( 'Save', 'licensio-knowledge-base' )"
					:disabled="nameMissing"
					:loading="saving"
					@confirm="save"
				>
					{{ __( 'Save', 'licensio-knowledge-base' ) }}
				</ConfirmButton>
			</div>
			<p v-if="nameMissing" class="lkb-drawer__hint">
				{{ __( 'Enter a name to save.', 'licensio-knowledge-base' ) }}
			</p>
		</template>
	</a-drawer>
</template>

<style scoped>
.lkb-drawer__error {
	margin-bottom: var(--lkb-gap);
}

.lkb-drawer__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.lkb-drawer__hint {
	margin: 6px 0 0;
	color: var(--lkb-ink-muted);
	font-size: 12px;
	text-align: right;
}
</style>

<style>
/*
 * The drawer is teleported to the body, outside the scoped styles. Start it
 * below the WordPress admin bar, which sits above it and would otherwise
 * cover its title and close button.
 */
.lkb-category-drawer .ant-drawer-content-wrapper {
	top: var(--wp-admin--admin-bar--height, 0px);
}
</style>
