<script setup lang="ts">
/**
 * The Duplicates tab.
 *
 * Finds content that exists twice and merges each group onto the member whose
 * slug carries no `-2`-style suffix:
 *
 * - Articles match on title, slug, date and opening content; the member with
 *   the most meta provides the fields, meta and terms, the duplicates are
 *   moved to the trash, and their analytics follow the survivor.
 * - Categories and tags match on name, slug and description; the duplicate's
 *   meta and article assignments move to the un-suffixed term, and the empty
 *   duplicate is deleted — a term has no trash.
 *
 * Groups that involve a post type the knowledge base does not own, or that
 * have no un-suffixed member, are listed for a human and never touched.
 *
 * @since 1.0.0
 */

import { computed, ref } from 'vue';
import { fixDuplicates, scanDuplicates } from '../api';
import type {
	DuplicateFixReport,
	DuplicateGroup,
	DuplicateTermGroup,
} from '../types';
import ConfirmButton from '../../components/ConfirmButton.vue';
import { __, _n, sprintf } from '../../components/i18n';

const loading = ref( false );
const error = ref( '' );
const postGroups = ref< DuplicateGroup[] >( [] );
const termGroups = ref< DuplicateTermGroup[] >( [] );
const scanned = ref( false );

const fixing = ref( false );
const result = ref< DuplicateFixReport | null >( null );

const mergeablePosts = computed( () =>
	postGroups.value.filter( ( group ) => 'merge' === group.kind )
);
const sourcePosts = computed( () =>
	postGroups.value.filter( ( group ) => 'source' === group.kind )
);
const flaggedPosts = computed( () =>
	postGroups.value.filter( ( group ) => 'flagged' === group.kind )
);
const fixableTerms = computed( () =>
	termGroups.value.filter( ( group ) => group.fixable )
);
const flaggedTerms = computed( () =>
	termGroups.value.filter( ( group ) => ! group.fixable )
);

const nothingFound = computed(
	() =>
		scanned.value &&
		postGroups.value.length === 0 &&
		termGroups.value.length === 0 &&
		! result.value
);

const fixableCount = computed(
	() =>
		mergeablePosts.value.length +
		sourcePosts.value.length +
		fixableTerms.value.length
);

/**
 * Scan for duplicate groups.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function scan(): Promise< void > {
	loading.value = true;
	error.value = '';
	result.value = null;

	try {
		const found = await scanDuplicates();
		postGroups.value = found.posts;
		termGroups.value = found.terms;
		scanned.value = true;
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'The scan failed.', 'licensio-knowledge-base' );
	} finally {
		loading.value = false;
	}
}

/**
 * Merge every fixable group.
 *
 * @since 1.0.0
 *
 * @return {Promise<void>}
 */
async function fix(): Promise< void > {
	fixing.value = true;
	error.value = '';

	try {
		result.value = await fixDuplicates();
		postGroups.value = [];
		termGroups.value = [];
	} catch ( err ) {
		error.value =
			err instanceof Error
				? err.message
				: __( 'The fix failed.', 'licensio-knowledge-base' );
	} finally {
		fixing.value = false;
	}
}

const resultLines = computed( () => {
	if ( ! result.value ) {
		return [];
	}

	const report = result.value;

	return [
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Article groups merged: %d', 'licensio-knowledge-base' ),
			report.groups_merged
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Duplicates moved to trash: %d', 'licensio-knowledge-base' ),
			report.posts_trashed
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Meta keys copied: %d', 'licensio-knowledge-base' ),
			report.meta_copied
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Votes moved: %d', 'licensio-knowledge-base' ),
			report.votes_moved
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Views moved: %d', 'licensio-knowledge-base' ),
			report.views_moved
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Migration sources cleaned: %d', 'licensio-knowledge-base' ),
			report.source_groups_cleaned
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Source posts moved to trash: %d', 'licensio-knowledge-base' ),
			report.sources_trashed
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Term groups merged: %d', 'licensio-knowledge-base' ),
			report.term_groups_merged
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Terms deleted: %d', 'licensio-knowledge-base' ),
			report.terms_deleted
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Term meta keys copied: %d', 'licensio-knowledge-base' ),
			report.term_meta_copied
		),
		sprintf(
			/* translators: %d: a count from the merge report. */
			__( 'Article assignments moved: %d', 'licensio-knowledge-base' ),
			report.articles_reassigned
		),
	];
} );
</script>

<template>
	<div class="licensio-kb-duplicates">
		<p class="licensio-kb-duplicates__lede">
			{{
				__(
					'Duplicate articles share a title, a slug once any -2 style suffix is stripped, a date and the opening lines of their content; duplicate categories and tags share a name, a slug and a description. Merging keeps the member without a suffix: for articles the duplicate with the most meta provides the content and the rest go to the trash, for terms the meta and article assignments move across and the empty duplicate is deleted. Anything the knowledge base does not own is listed for you to resolve by hand.',
					'licensio-knowledge-base'
				)
			}}
		</p>

		<div class="licensio-kb-duplicates__actions">
			<a-button
				type="primary"
				:loading="loading"
				:disabled="fixing"
				@click="scan"
			>
				{{ __( 'Scan for duplicates', 'licensio-knowledge-base' ) }}
			</a-button>

			<ConfirmButton
				v-if="scanned && fixableCount > 0"
				type="primary"
				danger
				:loading="fixing"
				:disabled="loading"
				:title="
					sprintf(
						/* translators: %d: how many duplicate groups will be merged. */
						_n(
							'Merge %d group? Duplicated articles and migration source posts are moved to the trash; duplicated terms are deleted.',
							'Merge %d groups? Duplicated articles and migration source posts are moved to the trash; duplicated terms are deleted.',
							fixableCount,
							'licensio-knowledge-base'
						),
						fixableCount
					)
				"
				:ok-text="__( 'Merge', 'licensio-knowledge-base' )"
				@confirm="fix"
			>
				{{
					sprintf(
						/* translators: %d: how many duplicate groups can be merged. */
						__(
							'Merge duplicates (%d)',
							'licensio-knowledge-base'
						),
						fixableCount
					)
				}}
			</ConfirmButton>
		</div>

		<a-alert
			v-if="error"
			type="error"
			show-icon
			:message="error"
			class="licensio-kb-duplicates__error"
		/>

		<a-alert
			v-if="nothingFound"
			type="success"
			show-icon
			:message="
				__(
					'No duplicate articles, categories or tags detected.',
					'licensio-knowledge-base'
				)
			"
			class="licensio-kb-duplicates__error"
		/>

		<template v-if="result">
			<a-card
				:title="__( 'Merge report', 'licensio-knowledge-base' )"
				class="licensio-kb-duplicates__card"
			>
				<ul class="licensio-kb-duplicates__report">
					<li v-for="line in resultLines" :key="line">{{ line }}</li>
				</ul>

				<p
					v-if="result.notes.length"
					class="licensio-kb-duplicates__notes"
				>
					{{ __( 'What was done:', 'licensio-knowledge-base' ) }}
				</p>
				<ul class="licensio-kb-duplicates__report">
					<li v-for="note in result.notes" :key="note">{{ note }}</li>
				</ul>
			</a-card>
		</template>

		<a-card
			v-if="mergeablePosts.length > 0"
			:title="__( 'Mergeable article groups', 'licensio-knowledge-base' )"
			class="licensio-kb-duplicates__card"
		>
			<div
				v-for="group in mergeablePosts"
				:key="group.baseSlug + group.date"
				class="licensio-kb-duplicates__group"
			>
				<strong>{{ group.title }}</strong>
				<span class="licensio-kb-duplicates__group-meta">
					{{ group.baseSlug }} · {{ group.date }}
				</span>

				<ul class="licensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						{{ member.slug }}
						<a-tag
							v-if="member.id === group.canonical"
							color="green"
						>
							{{
								__(
									'keeps the slug',
									'licensio-knowledge-base'
								)
							}}
						</a-tag>
						<a-tag v-if="member.id === group.winner" color="blue">
							{{
								__(
									'provides the content',
									'licensio-knowledge-base'
								)
							}}
						</a-tag>
						<span class="licensio-kb-duplicates__meta-count">
							{{
								sprintf(
									/* translators: %d: how many meta keys the duplicate has. */
									_n(
										'%d meta key',
										'%d meta keys',
										member.metaCount,
										'licensio-knowledge-base'
									),
									member.metaCount
								)
							}}
						</span>
					</li>
				</ul>
			</div>
		</a-card>

		<a-card
			v-if="sourcePosts.length > 0"
			:title="
				__( 'Migration sources to clean up', 'licensio-knowledge-base' )
			"
			class="licensio-kb-duplicates__card"
		>
			<p class="licensio-kb-duplicates__flagged-note">
				{{
					__(
						'Each pair is an article and the migration source post it replaced. Merging moves the source post to the trash; the article is left exactly as the site serves it.',
						'licensio-knowledge-base'
					)
				}}
			</p>

			<div
				v-for="group in sourcePosts"
				:key="group.baseSlug + group.date"
				class="licensio-kb-duplicates__group"
			>
				<strong>{{ group.title }}</strong>
				<span class="licensio-kb-duplicates__group-meta">
					{{ group.baseSlug }} · {{ group.date }}
				</span>

				<ul class="licensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						<a-tag
							:color="
								member.type === 'licensio_article'
									? 'green'
									: 'blue'
							"
						>
							{{ member.type }}
						</a-tag>
						{{ member.slug }}
						<a-tag
							v-if="member.id === group.canonical"
							color="green"
						>
							{{ __( 'stays', 'licensio-knowledge-base' ) }}
						</a-tag>
						<a-tag v-else color="orange">
							{{
								__(
									'moves to trash',
									'licensio-knowledge-base'
								)
							}}
						</a-tag>
					</li>
				</ul>
			</div>
		</a-card>

		<a-card
			v-if="fixableTerms.length > 0"
			:title="__( 'Mergeable term groups', 'licensio-knowledge-base' )"
			class="licensio-kb-duplicates__card"
		>
			<div
				v-for="group in fixableTerms"
				:key="group.taxonomy + group.baseSlug"
				class="licensio-kb-duplicates__group"
			>
				<strong>{{ group.name }}</strong>
				<span class="licensio-kb-duplicates__group-meta">
					{{ group.taxonomy }} · {{ group.baseSlug }}
				</span>

				<ul class="licensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						{{ member.slug }}
						<a-tag
							v-if="member.id === group.canonical"
							color="green"
						>
							{{
								__(
									'keeps the slug',
									'licensio-knowledge-base'
								)
							}}
						</a-tag>
						<a-tag v-if="member.id === group.winner" color="blue">
							{{
								__(
									'provides the meta',
									'licensio-knowledge-base'
								)
							}}
						</a-tag>
						<span class="licensio-kb-duplicates__meta-count">
							{{
								sprintf(
									/* translators: %d: how many meta keys the duplicate has. */
									_n(
										'%d meta key',
										'%d meta keys',
										member.metaCount,
										'licensio-knowledge-base'
									),
									member.metaCount
								)
							}}
							·
							{{
								sprintf(
									/* translators: %d: how many articles have the duplicate term. */
									_n(
										'%d article',
										'%d articles',
										member.count,
										'licensio-knowledge-base'
									),
									member.count
								)
							}}
						</span>
					</li>
				</ul>
			</div>
		</a-card>

		<a-card
			v-if="flaggedPosts.length > 0 || flaggedTerms.length > 0"
			:title="
				__( 'Flagged for manual review', 'licensio-knowledge-base' )
			"
			class="licensio-kb-duplicates__card"
		>
			<p class="licensio-kb-duplicates__flagged-note">
				{{
					__(
						'These groups involve a post type the knowledge base does not own, or have no un-suffixed member to merge into. Nothing was changed.',
						'licensio-knowledge-base'
					)
				}}
			</p>

			<div
				v-for="group in flaggedPosts"
				:key="group.baseSlug + group.date"
				class="licensio-kb-duplicates__group"
			>
				<strong>{{ group.title }}</strong>
				<span class="licensio-kb-duplicates__group-meta">
					{{ group.baseSlug }} · {{ group.date }}
				</span>

				<ul class="licensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						<a-tag
							:color="
								member.type === 'licensio_article'
									? 'green'
									: 'orange'
							"
						>
							{{ member.type }}
						</a-tag>
						{{ member.slug }} ({{ member.status }})
					</li>
				</ul>
			</div>

			<div
				v-for="group in flaggedTerms"
				:key="group.taxonomy + group.baseSlug"
				class="licensio-kb-duplicates__group"
			>
				<strong>{{ group.name }}</strong>
				<span class="licensio-kb-duplicates__group-meta">
					{{ group.taxonomy }} · {{ group.baseSlug }}
				</span>

				<ul class="licensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }} {{ member.slug }}
					</li>
				</ul>
			</div>
		</a-card>
	</div>
</template>

<style scoped>
.licensio-kb-duplicates__lede {
	margin: 0 0 16px;
}

.licensio-kb-duplicates__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.licensio-kb-duplicates__error {
	margin-bottom: 16px;
}

.licensio-kb-duplicates__card {
	margin-bottom: 16px;
}

.licensio-kb-duplicates__group {
	padding: 12px 0;
	border-bottom: 1px solid var( --lkb-border-wp, #f0f0f0 );
}

.licensio-kb-duplicates__group:last-child {
	border-bottom: none;
}

.licensio-kb-duplicates__group-meta {
	margin-left: 8px;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-duplicates__members {
	margin: 8px 0 0;
	padding-left: 20px;
}

.licensio-kb-duplicates__meta-count {
	margin-left: 8px;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.licensio-kb-duplicates__report {
	margin: 0;
	padding-left: 20px;
}

.licensio-kb-duplicates__notes {
	margin: 16px 0 4px;
}

.licensio-kb-duplicates__flagged-note {
	margin: 0 0 8px;
	color: var( --lkb-ink-wp-muted, #646970 );
}
</style>
