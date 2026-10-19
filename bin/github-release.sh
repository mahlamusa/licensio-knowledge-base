#!/usr/bin/env bash
#
# GitHub release.
#
# Builds the release from the tagged commit and opens a draft GitHub release
# for it, with the free and pro zips attached and the readme.txt changelog
# entry as the notes. Publishing the draft is left to a person on GitHub.
#
# Nothing here commits, tags or pushes. Run it after the version bump commit
# is tagged `v<X.Y.Z>` and both are pushed (the release skill does that, and
# asks first).
#
# Preconditions, each checked:
#
#   * `gh` is installed and authenticated.
#   * The working tree is clean, and still clean after the build. The build
#     only rewrites languages/*.pot when the strings changed, which means the
#     committed template is stale and has to be committed first.
#   * HEAD is the commit tagged `v<X.Y.Z>`, and the tag exists on origin.
#   * readme.txt has a `= <X.Y.Z> =` changelog entry.
#   * No GitHub release exists for the tag yet.
#
# Usage: bin/github-release.sh [X.Y.Z]
#
#   Without a version, the Stable tag in readme.txt is used.
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"
SLUG='licensio-knowledge-base'
README_FILE="${ROOT}/readme.txt"

# Abort with a message.
#
# @since 1.0.0
abort() {
    printf 'GitHub release FAILED: %s\n' "$1" >&2
    exit 1
}

VERSION="${1:-$( grep -m1 '^Stable tag:' "$README_FILE" | awk '{print $NF}' | tr -d '\r' )}"
TAG="v${VERSION}"

printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' || abort "invalid version '${VERSION}'."

command -v gh > /dev/null 2>&1 || abort 'gh is required and is not on PATH.'
gh auth status > /dev/null 2>&1 || abort 'gh is not authenticated. Run: gh auth login'

cd "$ROOT"

[ -z "$( git status --porcelain )" ] || abort 'the working tree has uncommitted changes. Commit or stash them first.'

TAG_COMMIT="$( git rev-parse -q --verify "refs/tags/${TAG}^{commit}" || true )"
[ -n "$TAG_COMMIT" ] || abort "tag ${TAG} does not exist locally."
[ "$( git rev-parse HEAD )" = "$TAG_COMMIT" ] || abort "HEAD is not the commit tagged ${TAG}. Check it out first."
git ls-remote --exit-code --tags origin "refs/tags/${TAG}" > /dev/null 2>&1 || abort "tag ${TAG} is not on origin. Push it first."

if gh release view "$TAG" > /dev/null 2>&1; then
    abort "a GitHub release for ${TAG} already exists."
fi

# The changelog entry runs from its `= X.Y.Z =` heading to the next version
# heading or section heading.
NOTES="$(
    awk -v heading="= ${VERSION} =" '
        $0 == heading { found = 1; next }
        found && ( /^= / || /^== / ) { exit }
        found { print }
    ' "$README_FILE"
)"
[ -n "$( printf '%s' "$NOTES" | tr -d '[:space:]' )" ] || abort "readme.txt has no '= ${VERSION} =' changelog entry."

bash "${SCRIPT_DIR}/build-release.sh"

[ -z "$( git status --porcelain )" ] || abort 'the build changed tracked files (listed by git status). Commit the refreshed translation template and tag again.'

NOTES_FILE="$( mktemp )"
trap 'rm -f "$NOTES_FILE"' EXIT
printf '%s\n' "$NOTES" > "$NOTES_FILE"

gh release create "$TAG" \
    "${ROOT}/releases/${SLUG}.zip" \
    "${ROOT}/releases/${SLUG}-pro.zip" \
    --draft \
    --verify-tag \
    --title "$TAG" \
    --notes-file "$NOTES_FILE"

printf '\nDraft release %s created with %s.zip and %s-pro.zip.\n' "$TAG" "$SLUG" "$SLUG"
printf 'Review and publish it at: %s\n' "$( gh release view "$TAG" --json url --jq .url )"
