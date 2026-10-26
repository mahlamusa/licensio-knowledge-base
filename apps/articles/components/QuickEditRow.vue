<script setup lang="ts">
/**
 * The quick edit form, expanded in place under a row.
 *
 * Title, slug, status, publish date, visibility, categories, tags (new tags
 * can be typed in), author and comments. Save sends only the fields that
 * changed, without asking first, and is disabled while a bulk run is going.
 * A field error from the server shows under its field and the form keeps
 * what was typed. A row another user has locked answers
 * 409, which is shown and puts the lock on the row. Escape cancels, as
 * Cancel does, except while a save is under way or on an open select or
 * date picker, which closes only that.
 *
 * @since 1.0.0
 */

import {
	computed,
	onBeforeUnmount,
	onMounted,
	reactive,
	ref,
	useId,
} from 'vue';
import TermSelect from '../../components/TermSelect.vue';
import { useEscapeClose } from '../../components/popover-focus';
import { errorMessage, RestError } from '../../components/rest';
import { __, sprintf } from '../../components/i18n';
import { updateArticle } from '../api';
import { useArticlesStore } from '../store';
import AuthorSelect from './AuthorSelect.vue';
import type { TermValue } from '../../components/types/terms';
import type { ArticleItem, ArticleLock, QuickEditPayload } from '../types';

type Visibility = 'public' | 'private' | 'password';
type EditableStatus = 'publish' | 'future' | 'draft' | 'pending';

const props = defineProps< { item: ArticleItem } >();

const emit = defineEmits< {
	( event: 'close' ): void;
} >();

const store = useArticlesStore();

/**
 * The form values an article starts with.
 *
 * @since 1.0.0
 *
 * @param {ArticleItem} item The article.
 *
 * @return {Object} The values.
 */
function initial( item: ArticleItem ) {
	let visibility: Visibility = 'public';

	if ( 'private' === item.status ) {
		visibility = 'private';
	} else if ( item.has_password ) {
		visibility = 'password';
	}

	return {
		// The stored title, not the decoded one: decoding would turn a stored `&lt;` into markup on save.
		title: item.title_raw,
		slug: item.slug,
		status: ( 'private' === item.status
			? 'publish'
			: item.status ) as EditableStatus,
		date: item.date,
		visibility,
		password: '',
		categories: item.categories.map( ( term ) => term.id ) as TermValue[],
		tags: item.tags.map( ( term ) => term.id ) as TermValue[],
		author: item.author.id,
		comment_status: item.comment_status,
	};
}

const start = initial( props.item );
const form = reactive( { ...start } );
const errors = ref< Record< string, string > >( {} );
const error = ref( '' );
const saving = ref( false );
const savedSlug = ref( '' );
const formEl = ref< HTMLFormElement | null >( null );

// Names its radio groups: radios that share a name are one tab stop, with
// the arrow keys moving between them.
const uid = useId();

// Escape cancels; the table puts focus back on the row's Quick edit button.
useEscapeClose(
	() => store.quickEditId === props.item.id && ! saving.value,
	formEl,
	() => emit( 'close' )
);

// Move focus into the form as it opens, so keyboard users land on the title.
onMounted(
	() => formEl.value?.querySelector< HTMLInputElement >( 'input' )?.focus()
);

// When the form goes away while it has focus, as it does when a save moves
// the article out of this view, focus moves to the page title instead of
// falling back to the top of the document.
onBeforeUnmount( () => {
	// The form is still in the page; there is no other node to ask for its document.
	// eslint-disable-next-line @wordpress/no-global-active-element
	if ( ! formEl.value?.contains( document.activeElement ) ) {
		return;
	}

	const title = document.querySelector< HTMLElement >( '.lkb-page__title' );

	if ( title ) {
		title.tabIndex = -1;
		title.focus();
	}
} );

const statusOptions = computed( () => {
	const options = [
		{
			value: 'publish',
			label: __( 'Published', 'licensio-knowledge-base' ),
		},
		{ value: 'draft', label: __( 'Draft', 'licensio-knowledge-base' ) },
		{
			value: 'pending',
			label: __( 'Pending review', 'licensio-knowledge-base' ),
		},
	];

	if ( 'future' === start.status ) {
		options.unshift( {
			value: 'future',
			label: __( 'Scheduled', 'licensio-knowledge-base' ),
		} );
	}

	return options;
} );

/**
 * Whether two ID lists hold the same IDs.
 *
 * @since 1.0.0
 *
 * @param {TermValue[]} a One list.
 * @param {TermValue[]} b The other.
 *
 * @return {boolean} True when equal as sets of IDs, with nothing new typed.
 */
function sameTerms( a: TermValue[], b: TermValue[] ): boolean {
	if ( a.some( ( value ) => typeof value !== 'number' ) ) {
		return false;
	}

	const left = [ ...a ].map( Number ).sort();
	const right = [ ...b ].map( Number ).sort();

	return left.join( ',' ) === right.join( ',' );
}

/**
 * The fields that differ from the article as loaded.
 *
 * @since 1.0.0
 *
 * @return {QuickEditPayload} The changes.
 */
function changes(): QuickEditPayload {
	const payload: QuickEditPayload = {};

	if ( form.title !== start.title ) {
		payload.title = form.title;
	}

	if ( form.slug !== start.slug ) {
		payload.slug = form.slug;
	}

	if ( form.date !== start.date && form.date ) {
		payload.date = form.date;
	}

	const nextStatus = 'private' === form.visibility ? 'private' : form.status;
	const startStatus =
		'private' === start.visibility ? 'private' : start.status;

	if ( nextStatus !== startStatus ) {
		payload.status = nextStatus;
	}

	if ( 'password' === form.visibility ) {
		if ( '' !== form.password ) {
			payload.password = form.password;
		}
	} else if ( props.item.has_password ) {
		payload.password = '';
	}

	if ( ! sameTerms( form.categories, start.categories ) ) {
		payload.categories = form.categories.filter(
			( value ): value is number => typeof value === 'number'
		);
	}

	if ( ! sameTerms( form.tags, start.tags ) ) {
		payload.tags = form.tags;
	}

	if ( form.author !== start.author && form.author > 0 ) {
		payload.author = form.author;
	}

	if ( form.comment_status !== start.comment_status ) {
		payload.comment_status = form.comment_status;
	}

	return payload;
}

const passwordMissing = computed(
	() =>
		'password' === form.visibility &&
		! props.item.has_password &&
		'' === form.password.trim()
);

/**
 * Send the changes.
 *
 * Once saved, the list and its status counts are loaded again, and the form
 * closes when the article has left the page.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>} Resolves once saved or refused.
 */
async function save(): Promise< void > {
	const payload = changes();

	errors.value = {};
	error.value = '';
	savedSlug.value = '';

	if ( 0 === Object.keys( payload ).length ) {
		emit( 'close' );
		return;
	}

	saving.value = true;

	try {
		const updated = await updateArticle( props.item.id, payload );

		store.replaceItem( updated );
		savedSlug.value = updated.slug;
		Object.assign( start, initial( updated ) );
		Object.assign( form, initial( updated ) );

		// The change may move the article out of this view, as publishing a
		// draft in the Drafts view does, and changes the status counts.
		await store.load();

		if (
			store.quickEditId === props.item.id &&
			! store.items.some( ( row ) => row.id === props.item.id )
		) {
			store.quickEditId = 0;
		}
	} catch ( caught ) {
		if ( caught instanceof RestError ) {
			const data = ( caught.data ?? {} ) as {
				params?: Record< string, string >;
				lock?: ArticleLock;
			};

			if ( 409 === caught.status && data.lock ) {
				store.replaceItem( { ...props.item, lock: data.lock } );
			}

			if ( data.params && 'object' === typeof data.params ) {
				errors.value = data.params;
			}
		}

		error.value = errorMessage( caught );
	} finally {
		saving.value = false;
	}
}

/**
 * The validation status of a field, for `a-form-item`.
 *
 * @since 1.0.0
 *
 * @param {string} field The field.
 *
 * @return {string|undefined} `error` when the server refused it.
 */
function statusOf( field: string ): 'error' | undefined {
	return errors.value[ field ] ? 'error' : undefined;
}
</script>

<template>
	<form ref="formEl" class="lkb-quickedit" @submit.prevent>
		<a-alert
			v-if="error"
			type="error"
			show-icon
			:message="error"
			class="lkb-quickedit__alert"
		/>

		<a-alert
			v-if="savedSlug"
			type="success"
			show-icon
			:message="
				/* translators: %s: the new slug. */ sprintf(
					__(
						'Saved. The slug is now “%s”.',
						'licensio-knowledge-base'
					),
					savedSlug
				)
			"
			class="lkb-quickedit__alert"
		/>

		<div class="lkb-quickedit__grid">
			<a-form-item
				:label="__( 'Title', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'title' )"
				:help="errors.title"
			>
				<a-input
					v-model:value="form.title"
					:aria-label="__( 'Title', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Slug', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'slug' )"
				:help="errors.slug"
			>
				<a-input
					v-model:value="form.slug"
					:aria-label="__( 'Slug', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Status', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'status' )"
				:help="errors.status"
			>
				<a-select
					v-model:value="form.status"
					:options="statusOptions"
					:disabled="'private' === form.visibility"
					:aria-label="__( 'Status', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Publish date', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'date' )"
				:help="errors.date"
			>
				<a-date-picker
					v-model:value="form.date"
					show-time
					value-format="YYYY-MM-DDTHH:mm:ss"
					format="YYYY-MM-DD HH:mm"
					:allow-clear="false"
					:aria-label="
						__( 'Publish date', 'licensio-knowledge-base' )
					"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Visibility', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'password' )"
				:help="errors.password"
			>
				<a-radio-group
					v-model:value="form.visibility"
					:name="`${ uid }-visibility`"
				>
					<a-radio value="public">
						{{ __( 'Public', 'licensio-knowledge-base' ) }}
					</a-radio>
					<a-radio value="private">
						{{ __( 'Private', 'licensio-knowledge-base' ) }}
					</a-radio>
					<a-radio value="password">
						{{
							__(
								'Password protected',
								'licensio-knowledge-base'
							)
						}}
					</a-radio>
				</a-radio-group>
				<a-input-password
					v-if="'password' === form.visibility"
					v-model:value="form.password"
					class="lkb-quickedit__password"
					:placeholder="
						props.item.has_password
							? __(
									'Leave empty to keep the current password',
									'licensio-knowledge-base'
							  )
							: __( 'Password', 'licensio-knowledge-base' )
					"
					:aria-label="__( 'Password', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Author', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'author' )"
				:help="errors.author"
			>
				<AuthorSelect
					v-model="form.author"
					:known-name="
						form.author === props.item.author.id
							? props.item.author.name
							: ''
					"
					:aria-label="__( 'Author', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Categories', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'categories' )"
				:help="errors.categories"
			>
				<TermSelect
					v-model="form.categories"
					taxonomy="licensio_category"
					multiple
					:aria-label="__( 'Categories', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Tags', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'tags' )"
				:help="errors.tags"
			>
				<TermSelect
					v-model="form.tags"
					taxonomy="licensio_tag"
					multiple
					allow-create
					:aria-label="__( 'Tags', 'licensio-knowledge-base' )"
				/>
			</a-form-item>

			<a-form-item
				:label="__( 'Comments', 'licensio-knowledge-base' )"
				:validate-status="statusOf( 'comment_status' )"
				:help="errors.comment_status"
			>
				<a-radio-group
					v-model:value="form.comment_status"
					:name="`${ uid }-comments`"
				>
					<a-radio value="open">
						{{ __( 'Open', 'licensio-knowledge-base' ) }}
					</a-radio>
					<a-radio value="closed">
						{{ __( 'Closed', 'licensio-knowledge-base' ) }}
					</a-radio>
				</a-radio-group>
			</a-form-item>
		</div>

		<div class="lkb-quickedit__actions">
			<a-button :disabled="saving" @click="emit( 'close' )">
				{{ __( 'Cancel', 'licensio-knowledge-base' ) }}
			</a-button>
			<a-button
				type="primary"
				:loading="saving"
				:disabled="
					passwordMissing || !! props.item.lock || store.bulk.running
				"
				@click="save"
			>
				{{ __( 'Save', 'licensio-knowledge-base' ) }}
			</a-button>
		</div>
	</form>
</template>

<style scoped>
.lkb-quickedit {
	padding: 8px 0;
}

.lkb-quickedit__alert {
	margin-bottom: 12px;
}

.lkb-quickedit__grid {
	display: grid;
	grid-template-columns: repeat( auto-fill, minmax( 240px, 1fr ) );
	gap: 0 var( --lkb-gap );
}

.lkb-quickedit__grid :deep( .ant-form-item ) {
	display: flex;
	flex-direction: column;
	margin-bottom: 12px;
}

.lkb-quickedit__grid :deep( .ant-form-item-label ) {
	text-align: left;
}

.lkb-quickedit__grid :deep( .ant-picker ) {
	width: 100%;
}

.lkb-quickedit__password {
	margin-top: 8px;
}

.lkb-quickedit__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
