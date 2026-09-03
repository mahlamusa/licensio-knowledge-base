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
import { fixDuplicates, scanDuplicates, translate } from '../api';
import type { DuplicateFixReport, DuplicateGroup, DuplicateTermGroup } from '../types';

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
		error.value = err instanceof Error ? err.message : translate( 'The scan failed.' );
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
		error.value = err instanceof Error ? err.message : translate( 'The fix failed.' );
	} finally {
		fixing.value = false;
	}
}

const resultLines = computed( () => {
	if ( ! result.value ) {
		return [];
	}

	return [
		translate( 'Article groups merged' ) + ': ' + result.value.groups_merged,
		translate( 'Duplicates moved to trash' ) + ': ' + result.value.posts_trashed,
		translate( 'Meta keys copied' ) + ': ' + result.value.meta_copied,
		translate( 'Votes moved' ) + ': ' + result.value.votes_moved,
		translate( 'Views moved' ) + ': ' + result.value.views_moved,
		translate( 'Migration sources cleaned' ) + ': ' + result.value.source_groups_cleaned,
		translate( 'Source posts moved to trash' ) + ': ' + result.value.sources_trashed,
		translate( 'Term groups merged' ) + ': ' + result.value.term_groups_merged,
		translate( 'Terms deleted' ) + ': ' + result.value.terms_deleted,
		translate( 'Term meta keys copied' ) + ': ' + result.value.term_meta_copied,
		translate( 'Article assignments moved' ) + ': ' + result.value.articles_reassigned,
	];
} );
</script>

<template>
	<div class="lisensio-kb-duplicates">
		<p class="lisensio-kb-duplicates__lede">
			{{
				translate(
					'Duplicate articles share a title, a slug once any -2 style suffix is stripped, a date and the opening lines of their content; duplicate categories and tags share a name, a slug and a description. Merging keeps the member without a suffix: for articles the duplicate with the most meta provides the content and the rest go to the trash, for terms the meta and article assignments move across and the empty duplicate is deleted. Anything the knowledge base does not own is listed for you to resolve by hand.'
				)
			}}
		</p>

		<div class="lisensio-kb-duplicates__actions">
			<a-button type="primary" :loading="loading" :disabled="fixing" @click="scan">
				{{ translate( 'Scan for duplicates' ) }}
			</a-button>

			<a-popconfirm
				v-if="scanned && fixableCount > 0"
				:title="
					translate( 'Merge' ) +
					' ' + fixableCount + ' ' +
					translate( 'group(s)? Duplicated articles and migration source posts are moved to the trash; duplicated terms are deleted.' )
				"
				:ok-text="translate( 'Merge' )"
				:cancel-text="translate( 'Cancel' )"
				@confirm="fix"
			>
				<a-button type="primary" danger :loading="fixing" :disabled="loading">
					{{ translate( 'Merge duplicates' ) }} ({{ fixableCount }})
				</a-button>
			</a-popconfirm>
		</div>

		<a-alert v-if="error" type="error" show-icon :message="error" class="lisensio-kb-duplicates__error" />

		<a-alert
			v-if="nothingFound"
			type="success"
			show-icon
			:message="translate( 'No duplicate articles, categories or tags detected.' )"
			class="lisensio-kb-duplicates__error"
		/>

		<template v-if="result">
			<a-card :title="translate( 'Merge report' )" class="lisensio-kb-duplicates__card">
				<ul class="lisensio-kb-duplicates__report">
					<li v-for="line in resultLines" :key="line">{{ line }}</li>
				</ul>

				<p v-if="result.notes.length" class="lisensio-kb-duplicates__notes">
					{{ translate( 'What was done' ) }}:
				</p>
				<ul class="lisensio-kb-duplicates__report">
					<li v-for="note in result.notes" :key="note">{{ note }}</li>
				</ul>
			</a-card>
		</template>

		<a-card
			v-if="mergeablePosts.length > 0"
			:title="translate( 'Mergeable article groups' )"
			class="lisensio-kb-duplicates__card"
		>
			<div
				v-for="group in mergeablePosts"
				:key="group.baseSlug + group.date"
				class="lisensio-kb-duplicates__group"
			>
				<strong>{{ group.title }}</strong>
				<span class="lisensio-kb-duplicates__group-meta">
					{{ group.baseSlug }} · {{ group.date }}
				</span>

				<ul class="lisensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						{{ member.slug }}
						<a-tag v-if="member.id === group.canonical" color="green">
							{{ translate( 'keeps the slug' ) }}
						</a-tag>
						<a-tag v-if="member.id === group.winner" color="blue">
							{{ translate( 'provides the content' ) }}
						</a-tag>
						<span class="lisensio-kb-duplicates__meta-count">
							{{ member.metaCount }} {{ translate( 'meta' ) }}
						</span>
					</li>
				</ul>
			</div>
		</a-card>

		<a-card
			v-if="sourcePosts.length > 0"
			:title="translate( 'Migration sources to clean up' )"
			class="lisensio-kb-duplicates__card"
		>
			<p class="lisensio-kb-duplicates__flagged-note">
				{{
					translate(
						'Each pair is an article and the migration source post it replaced. Merging moves the source post to the trash; the article is left exactly as the site serves it.'
					)
				}}
			</p>

			<div
				v-for="group in sourcePosts"
				:key="group.baseSlug + group.date"
				class="lisensio-kb-duplicates__group"
			>
				<strong>{{ group.title }}</strong>
				<span class="lisensio-kb-duplicates__group-meta">
					{{ group.baseSlug }} · {{ group.date }}
				</span>

				<ul class="lisensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						<a-tag :color="member.type === 'lisensio_article' ? 'green' : 'blue'">
							{{ member.type }}
						</a-tag>
						{{ member.slug }}
						<a-tag v-if="member.id === group.canonical" color="green">
							{{ translate( 'stays' ) }}
						</a-tag>
						<a-tag v-else color="orange">
							{{ translate( 'moves to trash' ) }}
						</a-tag>
					</li>
				</ul>
			</div>
		</a-card>

		<a-card
			v-if="fixableTerms.length > 0"
			:title="translate( 'Mergeable term groups' )"
			class="lisensio-kb-duplicates__card"
		>
			<div
				v-for="group in fixableTerms"
				:key="group.taxonomy + group.baseSlug"
				class="lisensio-kb-duplicates__group"
			>
				<strong>{{ group.name }}</strong>
				<span class="lisensio-kb-duplicates__group-meta">
					{{ group.taxonomy }} · {{ group.baseSlug }}
				</span>

				<ul class="lisensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						{{ member.slug }}
						<a-tag v-if="member.id === group.canonical" color="green">
							{{ translate( 'keeps the slug' ) }}
						</a-tag>
						<a-tag v-if="member.id === group.winner" color="blue">
							{{ translate( 'provides the meta' ) }}
						</a-tag>
						<span class="lisensio-kb-duplicates__meta-count">
							{{ member.metaCount }} {{ translate( 'meta' ) }} ·
							{{ member.count }} {{ translate( 'articles' ) }}
						</span>
					</li>
				</ul>
			</div>
		</a-card>

		<a-card
			v-if="flaggedPosts.length > 0 || flaggedTerms.length > 0"
			:title="translate( 'Flagged for manual review' )"
			class="lisensio-kb-duplicates__card"
		>
			<p class="lisensio-kb-duplicates__flagged-note">
				{{
					translate(
						'These groups involve a post type the knowledge base does not own, or have no un-suffixed member to merge into. Nothing was changed.'
					)
				}}
			</p>

			<div
				v-for="group in flaggedPosts"
				:key="group.baseSlug + group.date"
				class="lisensio-kb-duplicates__group"
			>
				<strong>{{ group.title }}</strong>
				<span class="lisensio-kb-duplicates__group-meta">
					{{ group.baseSlug }} · {{ group.date }}
				</span>

				<ul class="lisensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }}
						<a-tag :color="member.type === 'lisensio_article' ? 'green' : 'orange'">
							{{ member.type }}
						</a-tag>
						{{ member.slug }} ({{ member.status }})
					</li>
				</ul>
			</div>

			<div
				v-for="group in flaggedTerms"
				:key="group.taxonomy + group.baseSlug"
				class="lisensio-kb-duplicates__group"
			>
				<strong>{{ group.name }}</strong>
				<span class="lisensio-kb-duplicates__group-meta">
					{{ group.taxonomy }} · {{ group.baseSlug }}
				</span>

				<ul class="lisensio-kb-duplicates__members">
					<li v-for="member in group.members" :key="member.id">
						#{{ member.id }} {{ member.slug }}
					</li>
				</ul>
			</div>
		</a-card>
	</div>
</template>

<style scoped>
.lisensio-kb-duplicates__lede {
	margin: 0 0 16px;
}

.lisensio-kb-duplicates__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.lisensio-kb-duplicates__error {
	margin-bottom: 16px;
}

.lisensio-kb-duplicates__card {
	margin-bottom: 16px;
}

.lisensio-kb-duplicates__group {
	padding: 12px 0;
	border-bottom: 1px solid var( --lkb-border-wp, #f0f0f0 );
}

.lisensio-kb-duplicates__group:last-child {
	border-bottom: none;
}

.lisensio-kb-duplicates__group-meta {
	margin-left: 8px;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.lisensio-kb-duplicates__members {
	margin: 8px 0 0;
	padding-left: 20px;
}

.lisensio-kb-duplicates__meta-count {
	margin-left: 8px;
	color: var( --lkb-ink-wp-muted, #646970 );
	font-size: 12px;
}

.lisensio-kb-duplicates__report {
	margin: 0;
	padding-left: 20px;
}

.lisensio-kb-duplicates__notes {
	margin: 16px 0 4px;
}

.lisensio-kb-duplicates__flagged-note {
	margin: 0 0 8px;
	color: var( --lkb-ink-wp-muted, #646970 );
}
</style>
