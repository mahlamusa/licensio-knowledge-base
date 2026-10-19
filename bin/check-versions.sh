#!/usr/bin/env bash
#
# Version consistency check.
#
# The release version lives in four places that nothing keeps in step:
#
#   * the `Version:` plugin header in licensio-knowledge-base.php
#   * the `LICENSIO_KB_VERSION` constant in the same file
#   * `Stable tag:` in readme.txt
#   * `version` in package.json
#
# A bump that lands in three of them ships a plugin whose header disagrees with
# its own constant, or a wordpress.org listing pointing at a tag that was never
# built. This fails the build instead.
#
# The support floors are checked the same way: readme.txt's `Requires at least`
# and `Requires PHP` must match the plugin header, because wordpress.org reads
# the readme and WordPress reads the header.
#
# Usage: bin/check-versions.sh [repo-root]
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="${1:-$( dirname "$SCRIPT_DIR" )}"

PLUGIN_FILE="${ROOT}/licensio-knowledge-base.php"
README_FILE="${ROOT}/readme.txt"
PACKAGE_FILE="${ROOT}/package.json"

failures=0

# Report a mismatch and remember that one happened.
#
# @since 1.0.0
fail() {
    printf '%s\n' "$1" >&2
    failures=$(( failures + 1 ))
}

# Read a `Key: value` line out of a plugin header or a readme.
#
# Case-sensitive on purpose. Both WordPress and wordpress.org spell these
# headers exactly one way, and `sed`'s case-insensitive flag is a GNU extension
# that would make this script behave differently on a contributor's Mac.
#
# $1 - File to read.
# $2 - Header name.
#
# @since 1.0.0
header_value() {
    grep -m1 -E "^[[:space:]]*\*?[[:space:]]*${2}:" "$1" 2>/dev/null \
        | sed -E "s/^[[:space:]]*\*?[[:space:]]*${2}:[[:space:]]*//" \
        | tr -d '\r' \
        | sed -E 's/[[:space:]]+$//'
}

for required in "$PLUGIN_FILE" "$README_FILE" "$PACKAGE_FILE"; do
    if [ ! -f "$required" ]; then
        printf 'Version check: %s is missing.\n' "$required" >&2
        exit 2
    fi
done

if ! command -v php > /dev/null 2>&1; then
    printf 'Version check: php is required to read package.json.\n' >&2
    exit 2
fi

HEADER_VERSION="$( header_value "$PLUGIN_FILE" 'Version' )"

CONSTANT_VERSION="$(
    grep -m1 -E "define\([[:space:]]*'LICENSIO_KB_VERSION'" "$PLUGIN_FILE" \
        | sed -E "s/.*'LICENSIO_KB_VERSION'[[:space:]]*,[[:space:]]*'([^']*)'.*/\1/"
)"

README_VERSION="$( header_value "$README_FILE" 'Stable tag' )"

# Read with a JSON parser rather than a regex: `version` is not the only key
# that can carry that name once dependencies are listed.
PACKAGE_VERSION="$(
    # shellcheck disable=SC2016 # The single quotes hold PHP, not shell.
    php -r '$data = json_decode( file_get_contents( $argv[1] ), true ); echo isset( $data["version"] ) ? $data["version"] : "";' "$PACKAGE_FILE"
)"

HEADER_WP="$( header_value "$PLUGIN_FILE" 'Requires at least' )"
README_WP="$( header_value "$README_FILE" 'Requires at least' )"
HEADER_PHP="$( header_value "$PLUGIN_FILE" 'Requires PHP' )"
README_PHP="$( header_value "$README_FILE" 'Requires PHP' )"

printf 'Version check\n'
printf '  plugin header Version      : %s\n' "${HEADER_VERSION:-<missing>}"
printf '  LICENSIO_KB_VERSION        : %s\n' "${CONSTANT_VERSION:-<missing>}"
printf '  readme.txt Stable tag      : %s\n' "${README_VERSION:-<missing>}"
printf '  package.json version       : %s\n' "${PACKAGE_VERSION:-<missing>}"
printf '  Requires at least (header) : %s\n' "${HEADER_WP:-<missing>}"
printf '  Requires at least (readme) : %s\n' "${README_WP:-<missing>}"
printf '  Requires PHP (header)      : %s\n' "${HEADER_PHP:-<missing>}"
printf '  Requires PHP (readme)      : %s\n' "${README_PHP:-<missing>}"

for pair in \
    "plugin header Version:${HEADER_VERSION}" \
    "LICENSIO_KB_VERSION:${CONSTANT_VERSION}" \
    "readme.txt Stable tag:${README_VERSION}" \
    "package.json version:${PACKAGE_VERSION}"
do
    if [ -z "${pair#*:}" ]; then
        fail "Version check: ${pair%%:*} could not be read."
    fi
done

if [ "$failures" -eq 0 ]; then
    if [ "$HEADER_VERSION" != "$CONSTANT_VERSION" ]; then
        fail "Version mismatch: plugin header Version (${HEADER_VERSION}) != LICENSIO_KB_VERSION (${CONSTANT_VERSION})."
    fi

    if [ "$HEADER_VERSION" != "$README_VERSION" ]; then
        fail "Version mismatch: plugin header Version (${HEADER_VERSION}) != readme.txt Stable tag (${README_VERSION})."
    fi

    if [ "$HEADER_VERSION" != "$PACKAGE_VERSION" ]; then
        fail "Version mismatch: plugin header Version (${HEADER_VERSION}) != package.json version (${PACKAGE_VERSION})."
    fi
fi

if [ "$HEADER_WP" != "$README_WP" ]; then
    fail "Support floor mismatch: plugin header Requires at least (${HEADER_WP:-<missing>}) != readme.txt (${README_WP:-<missing>})."
fi

if [ "$HEADER_PHP" != "$README_PHP" ]; then
    fail "Support floor mismatch: plugin header Requires PHP (${HEADER_PHP:-<missing>}) != readme.txt (${README_PHP:-<missing>})."
fi

if [ "$failures" -gt 0 ]; then
    printf '\nVersion check FAILED with %s problem(s).\n' "$failures" >&2
    printf 'Bump the version in all four places, and keep the readme support floors on the header.\n' >&2
    exit 1
fi

printf 'Version check passed: %s is consistent across all four locations.\n' "$HEADER_VERSION"
