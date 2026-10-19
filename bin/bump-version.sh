#!/usr/bin/env bash
#
# Version bump.
#
# Writes one release version into the four places bin/check-versions.sh holds
# in step:
#
#   * the `Version:` plugin header in lisensio-knowledge-base.php
#   * the `LISENSIO_KB_VERSION` constant in the same file
#   * `Stable tag:` in readme.txt
#   * `version` in package.json (and the matching entries in package-lock.json)
#
# `LISENSIO_KB_DB_VERSION` is left alone: it tracks the database schema, not
# the release, and only moves when a migration does.
#
# Nothing is committed. Add the changelog entry for the new version to
# readme.txt, then commit the bump with it.
#
# Usage: bin/bump-version.sh <X.Y.Z>
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"

PLUGIN_FILE="${ROOT}/lisensio-knowledge-base.php"
README_FILE="${ROOT}/readme.txt"

VERSION="${1:-}"

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    printf 'Usage: bin/bump-version.sh <X.Y.Z>\n' >&2
    exit 1
fi

command -v npm > /dev/null 2>&1 || { printf 'npm is required and is not on PATH.\n' >&2; exit 1; }

sed -i -E "s/^( \* Version:[[:space:]]*).*/\1${VERSION}/" "$PLUGIN_FILE"
sed -i -E "s/(define\([[:space:]]*'LISENSIO_KB_VERSION'[[:space:]]*,[[:space:]]*')[^']*(')/\1${VERSION}\2/" "$PLUGIN_FILE"
sed -i -E "s/^(Stable tag:[[:space:]]*).*/\1${VERSION}/" "$README_FILE"

# npm rewrites package.json and both version fields in package-lock.json, and
# refuses a no-op bump unless told it is allowed.
( cd "$ROOT" && npm version "$VERSION" --no-git-tag-version --allow-same-version > /dev/null )

bash "${SCRIPT_DIR}/check-versions.sh" "$ROOT"

if ! grep -qE "^= ${VERSION//./\\.} =" "$README_FILE"; then
    printf 'Note: readme.txt has no "= %s =" changelog entry yet. Add one before releasing.\n' "$VERSION"
fi

printf 'Bumped to %s. Review with: git diff\n' "$VERSION"
