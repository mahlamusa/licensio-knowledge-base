#!/usr/bin/env bash
#
# WordPress.org publish.
#
# Stages a release in the plugin's WordPress.org SVN working copy and stops
# before `svn ci`, so the wordpress.org password prompt stays with a person.
#
#   trunk/            the wporg build: the free plugin plus the sources dist/
#                     is compiled from (apps/, blocks/, the build wrappers and
#                     the npm manifests), so the hosted code can be traced and
#                     rebuilt from SVN.
#   tags/<version>/   a copy of trunk synced down to exactly the free build,
#                     the same files as releases/licensio-knowledge-base.zip.
#   assets/           .wordpress-org/ (screenshots, and banners and icons when
#                     they exist).
#
# Syncing the tag against the free build, rather than deleting a list of
# source paths from it, keeps the two artifacts defined in one place:
# bin/build-release.sh.
#
# Before the plugin is approved there is no SVN repository. The script detects
# that, prints the zip to upload for review, and stops.
#
# Usage: bin/wp-org-publish.sh [X.Y.Z]
#
#   Without a version, the Stable tag in readme.txt is used.
#   WP_ORG_USERNAME overrides the committer name printed in the final command.
#   SVN_URL and SVN_DIR override the repository and working copy (for testing
#   against a local file:// repository).
#
# Requires: bash, git, svn, rsync, unzip, and everything bin/build-release.sh
# needs (php, zip, npm, WP-CLI).
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"
SLUG='licensio-knowledge-base'
ASSETS_DIR="${ROOT}/.wordpress-org"
SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/${SLUG}/}"
SVN_DIR="${SVN_DIR:-/tmp/${SLUG}-svn}"
WP_ORG_USERNAME="${WP_ORG_USERNAME:-mahlamusa}"

WORK_DIR=''

# Remove the scratch directory on the way out, however we leave.
#
# @since 1.0.0
cleanup() {
    if [ -n "$WORK_DIR" ] && [ -d "$WORK_DIR" ]; then
        rm -rf "$WORK_DIR"
    fi
}
trap cleanup EXIT

# Print a step heading.
#
# @since 1.0.0
step() {
    printf '\n== %s ==\n' "$1"
}

# Abort with a message.
#
# @since 1.0.0
abort() {
    printf 'wp.org publish FAILED: %s\n' "$1" >&2
    exit 1
}

# Mirror a directory into an SVN-managed one and schedule the adds and removals.
#
# rsync -c compares content, not timestamps, so only files that really changed
# show as modified. --delete leaves removed files missing (!), which become
# `svn rm`.
#
# $1 - Source directory.
# $2 - Destination directory inside the SVN working copy.
#
# @since 1.0.0
svn_mirror() {
    source_dir="$1"
    target_dir="$2"

    mkdir -p "$target_dir"
    rsync -rc --delete --exclude='.svn' "${source_dir}/" "${target_dir}/"
    svn add --force --parents "$target_dir" > /dev/null

    # A removed directory is listed along with everything that was in it.
    # Only the top-most missing path still has a parent on disk; removing it
    # takes the rest with it, so the paths beneath it are skipped.
    while IFS= read -r missing; do
        if [ -n "$missing" ] && [ -d "$( dirname "$missing" )" ]; then
            svn rm --force "$missing" > /dev/null
        fi
    done < <( svn status "$target_dir" | { grep '^!' || true; } | cut -c9- )
}

for tool in git svn rsync unzip; do
    command -v "$tool" > /dev/null 2>&1 || abort "$tool is required and is not on PATH."
done

VERSION="${1:-$( grep -m1 '^Stable tag:' "${ROOT}/readme.txt" | awk '{print $NF}' | tr -d '\r' )}"
printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' || abort "invalid version '${VERSION}'."

printf 'Slug:     %s\n' "$SLUG"
printf 'Version:  %s\n' "$VERSION"
printf 'SVN URL:  %s\n' "$SVN_URL"
printf 'SVN WC:   %s\n' "$SVN_DIR"

step 'Preconditions'
cd "$ROOT"

[ -d "$ASSETS_DIR" ] || abort '.wordpress-org/ is missing. Add the listing screenshots, banners and icons first.'
[ -z "$( git status --porcelain )" ] || abort 'the working tree has uncommitted changes. Commit or stash them first.'
grep -qE "^Stable tag:[[:space:]]*${VERSION//./\\.}[[:space:]]*$" readme.txt || abort "readme.txt Stable tag is not ${VERSION}. Run bin/bump-version.sh ${VERSION}."
grep -qE "^= ${VERSION//./\\.} =" readme.txt || abort "readme.txt has no '= ${VERSION} =' changelog entry."

step 'Release build'
bash "${SCRIPT_DIR}/build-release.sh"

# build-release.sh writes no tracked file (the translation template goes into
# the zips only), so a dirty tree here means something unexpected changed.
[ -z "$( git status --porcelain )" ] || abort 'the build changed tracked files (listed by git status). Find out why first.'

WPORG_ZIP="${ROOT}/releases/${SLUG}-wporg.zip"
FREE_ZIP="${ROOT}/releases/${SLUG}.zip"

step 'SVN repository'
if ! svn ls "$SVN_URL" > /dev/null 2>&1; then
    printf 'There is no SVN repository at %s yet: the plugin has not been approved.\n\n' "$SVN_URL"
    printf 'Upload this zip for review:\n  %s (%s)\n\n' "$WPORG_ZIP" "$( du -h "$WPORG_ZIP" | cut -f1 )"
    printf 'At: https://wordpress.org/plugins/developers/add/ (signed in as %s)\n' "$WP_ORG_USERNAME"
    printf 'Re-run this script after approval to stage the first SVN commit.\n'
    exit 2
fi

if svn ls "${SVN_URL}tags/${VERSION}/" > /dev/null 2>&1; then
    abort "tags/${VERSION} already exists. WordPress.org tags are immutable: bump the version and re-run."
fi

step 'SVN working copy'
if [ -d "${SVN_DIR}/.svn" ]; then
    printf 'Reusing %s\n' "$SVN_DIR"
    svn cleanup "$SVN_DIR"
    svn revert -R "$SVN_DIR" > /dev/null
    # Files left behind by an earlier, abandoned run are unversioned (?) after
    # the revert. They would be swept into this commit, so they go.
    svn status "$SVN_DIR" | { grep '^?' || true; } | cut -c9- | while IFS= read -r stray; do
        [ -n "$stray" ] && rm -rf "$stray"
    done
else
    svn checkout --depth immediates "$SVN_URL" "$SVN_DIR"
fi

svn update --set-depth infinity "${SVN_DIR}/assets" "${SVN_DIR}/trunk"
svn update --set-depth immediates "${SVN_DIR}/tags"

WORK_DIR="$( mktemp -d )"
unzip -q "$WPORG_ZIP" -d "${WORK_DIR}/wporg"
unzip -q "$FREE_ZIP" -d "${WORK_DIR}/free"

step 'trunk/ (build and sources)'
svn_mirror "${WORK_DIR}/wporg/${SLUG}" "${SVN_DIR}/trunk"

step 'assets/'
svn_mirror "$ASSETS_DIR" "${SVN_DIR}/assets"

step "tags/${VERSION}/ (free build)"
svn cp "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}" > /dev/null
svn_mirror "${WORK_DIR}/free/${SLUG}" "${SVN_DIR}/tags/${VERSION}"

step 'Verification'
[ -f "${SVN_DIR}/trunk/package.json" ] || abort 'trunk/ is missing its sources.'
[ ! -e "${SVN_DIR}/tags/${VERSION}/package.json" ] || abort "tags/${VERSION}/ still carries the sources."
grep -qE "^Stable tag:[[:space:]]*${VERSION//./\\.}" "${SVN_DIR}/tags/${VERSION}/readme.txt" || abort "tags/${VERSION}/readme.txt has the wrong Stable tag."

UNEXPECTED="$( svn status "$SVN_DIR" | { grep -E '^[?!]' || true; } )"
[ -z "$UNEXPECTED" ] || abort "the working copy has unscheduled changes:
${UNEXPECTED}"

step 'Pending SVN changes'
svn status "$SVN_DIR" | cut -c1 | sort | uniq -c | sed 's/^/  /'
printf '\n'
for area in trunk "tags/${VERSION}" assets; do
    printf '  %-16s %s change(s)\n' "$area" "$( svn status "${SVN_DIR}/${area}" | wc -l | tr -d ' ' )"
done

printf '\nNext, review with: svn status %s\nThen commit:\n\n' "$SVN_DIR"
printf '  cd "%s" && svn ci -m "Release %s" --username %s\n\n' "$SVN_DIR" "$VERSION" "$WP_ORG_USERNAME"
printf 'SVN prompts for the wordpress.org password. The listing refreshes within about 15 minutes.\n'
