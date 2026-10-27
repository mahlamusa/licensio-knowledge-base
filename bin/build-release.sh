#!/usr/bin/env bash
#
# Release build.
#
# Produces the three shippable artifacts from a clean checkout:
#
#   licensio-knowledge-base.zip        free build, no pro/ anywhere in it
#   licensio-knowledge-base-pro.zip    the same tree plus pro/
#   licensio-knowledge-base-wporg.zip  the free build plus the sources dist/ is
#                                      compiled from, for WordPress.org
#
# WordPress.org requires the human-readable source of every compiled file next
# to the plugin, so the wporg artifact carries `apps/`, `blocks/`,
# the two build wrappers in `tools/`, the npm manifests and the build, type
# check and lint configs. Anyone working from SVN trunk can then run npm ci,
# npm run build, npm run typecheck and npm run lint:js.
# It is what goes up for plugin review and into SVN trunk. The SVN release tag
# matches the plain free build (see bin/wp-org-publish.sh).
#
# Both are staged from an allowlist rather than pruned from a copy of the
# repository. An exclude list silently ships whatever it has not heard of yet:
# add `tools/migrate-heroic/` and it lands in the artifact until someone notices.
# An allowlist fails the other way, which is the direction that cannot leak.
#
# The staged tree carries compiled output only. `apps/` and `blocks/` are built
# into `dist/` and neither the sources nor the build tooling is shipped.
#
# Nothing here publishes anywhere. `--dry-run` exists to build and report the
# artifact contents without writing them into releases/. A real build replaces
# any zip already sitting in the output directory under this build's names only
# once every gate has passed, so a failed build leaves the last good zips where
# they were.
#
# Usage:
#   bin/build-release.sh [--dry-run] [--skip-build] [--output DIR]
#
#   --dry-run     Build, verify and print both manifests, then discard. The
#                 repository is left untouched apart from dist/, which is build
#                 output and is not tracked.
#   --skip-build  Reuse the dist/ that is already there. For iterating on the
#                 packaging itself; never for a real release.
#   --output DIR  Where the zips land. Defaults to releases/.
#
# @since 1.0.0
# @since 1.0.0 Added the WordPress.org artifact carrying the sources of dist/.

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"

SLUG='licensio-knowledge-base'
TEXT_DOMAIN='licensio-knowledge-base'

DRY_RUN=0
SKIP_BUILD=0
OUTPUT_DIR="${ROOT}/releases"

# Everything the shipped plugin needs, and nothing else. Paths are relative to
# the repository root. `pro/` is added to the pro build only, and `languages/`
# is generated during the run.
SHIP_PATHS=(
    'licensio-knowledge-base.php'
    'uninstall.php'
    'readme.txt'
    'includes'
    'templates'
    'block-templates'
    'block-template-parts'
    'assets'
    'libraries'
    'dist'
)

# Anything on this list in a staged tree is a packaging bug. The allowlist above
# should already make every one of these impossible, which is exactly why the
# assertion is worth having: it is what notices when the allowlist grows an
# entry that drags one of them in.
#
# Checked at the top level only, because some of these names are legitimate
# deeper down: the compiled blocks live at dist/blocks/.
FORBIDDEN_ROOT_PATHS=(
    'apps'
    'blocks'
    'bin'
    'tools'
    'tests'
    'docs'
    '.github'
    'composer.json'
    'composer.lock'
    'package.json'
    'package-lock.json'
    'phpcs.xml'
    'phpstan.neon'
    'phpstan-bootstrap.php'
    'phpunit.xml'
    'playwright.config.ts'
    'tsconfig.json'
    'vite.config.mjs'
    'eslint.config.mjs'
    '.editorconfig'
    '.nvmrc'
    '.gitignore'
    '.env.example'
    'README.md'
    'SPEC.md'
)

# The sources the wporg artifact adds to the free build, so dist/ can be rebuilt,
# type checked and linted from what WordPress.org hosts. Only the two build
# wrappers come from tools/: the rest of that directory is migration tooling
# that never ships.
SOURCE_PATHS=(
    'apps'
    'blocks'
    'tools/build-apps.mjs'
    'tools/build-blocks.mjs'
    'package.json'
    'package-lock.json'
    'vite.config.mjs'
    'tsconfig.json'
    'eslint.config.mjs'
)

# The FORBIDDEN_ROOT_PATHS entries the wporg artifact may contain, because
# SOURCE_PATHS puts them there. Everything else on that list stays forbidden.
WPORG_ALLOWED_ROOT_PATHS=(
    'apps'
    'blocks'
    'tools'
    'package.json'
    'package-lock.json'
    'vite.config.mjs'
    'tsconfig.json'
    'eslint.config.mjs'
)

# These must not appear at any depth.
FORBIDDEN_ANYWHERE=(
    'node_modules'
    'vendor'
    '.git'
    '*.map'
)

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
    printf 'Release build FAILED: %s\n' "$1" >&2
    exit 1
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run)
            DRY_RUN=1
            ;;
        --skip-build)
            SKIP_BUILD=1
            ;;
        --output)
            [ "$#" -ge 2 ] || abort '--output needs a directory.'
            OUTPUT_DIR="$2"
            shift
            ;;
        -h|--help)
            sed -n '2,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            abort "unknown option: $1"
            ;;
    esac
    shift
done

for tool in php zip unzip; do
    command -v "$tool" > /dev/null 2>&1 || abort "$tool is required and is not on PATH."
done

# Resolve the WP-CLI binary used to generate the .pot file.
#
# @since 1.0.0
resolve_wp_cli() {
    if [ -n "${WP_CLI_BIN:-}" ]; then
        printf '%s\n' "$WP_CLI_BIN"
        return 0
    fi

    if command -v wp > /dev/null 2>&1; then
        command -v wp
        return 0
    fi

    phar="${ROOT}/.tmp/wp-cli.phar"

    if [ ! -f "$phar" ]; then
        mkdir -p "${ROOT}/.tmp"
        printf 'WP-CLI is not on PATH; downloading wp-cli.phar into .tmp/ ...\n' >&2

        if command -v curl > /dev/null 2>&1; then
            curl -fsSL -o "$phar" 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar'
        elif command -v wget > /dev/null 2>&1; then
            wget -q -O "$phar" 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar'
        else
            return 1
        fi
    fi

    printf 'php %s\n' "$phar"
}

printf 'Licensio Knowledge Base release build\n'
printf 'Repository: %s\n' "$ROOT"
if [ "$DRY_RUN" -eq 1 ]; then
    printf 'Mode: dry run - artifacts are reported and then discarded.\n'
else
    printf 'Mode: release - artifacts are written to %s\n' "$OUTPUT_DIR"
fi

step 'Version consistency'
bash "${SCRIPT_DIR}/check-versions.sh" "$ROOT"

VERSION="$(
    grep -m1 -E "define\([[:space:]]*'LICENSIO_KB_VERSION'" "${ROOT}/licensio-knowledge-base.php" \
        | sed -E "s/.*'LICENSIO_KB_VERSION'[[:space:]]*,[[:space:]]*'([^']*)'.*/\1/"
)"

step 'Text domains'
bash "${SCRIPT_DIR}/check-i18n.sh"

step 'Production build'
if [ "$SKIP_BUILD" -eq 1 ]; then
    printf 'Skipping the JS build at your request; reusing dist/.\n'
else
    command -v npm > /dev/null 2>&1 || abort 'npm is required and is not on PATH.'
    # Vite is configured not to empty dist/, because wp-scripts writes there
    # too. Across two builds that leaves last release's hashed bundles behind,
    # so the directory is cleared here instead.
    rm -rf "${ROOT}/dist"
    ( cd "$ROOT" && NODE_ENV=production npm run build )
fi

[ -d "${ROOT}/dist" ] || abort 'dist/ does not exist. Run without --skip-build.'

WORK_DIR="$( mktemp -d )"
FREE_STAGE="${WORK_DIR}/free/${SLUG}"
PRO_STAGE="${WORK_DIR}/pro/${SLUG}"
WPORG_STAGE="${WORK_DIR}/wporg/${SLUG}"

step 'Translation template'
WP_CLI="$( resolve_wp_cli || true )"
[ -n "$WP_CLI" ] || abort 'WP-CLI is needed to generate the .pot file. Install it, or set WP_CLI_BIN.'

POT_FILE="${WORK_DIR}/${SLUG}.pot"

# Run make-pot over the plugin into the template.
#
# $@ - Extra make-pot arguments, such as the paths to read.
make_pot() {
    # shellcheck disable=SC2086
    $WP_CLI i18n make-pot "$ROOT" "$POT_FILE" \
        --domain="$TEXT_DOMAIN" \
        --slug="$SLUG" \
        --headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/licensio-knowledge-base"}' \
        --allow-root \
        "$@"
}

# Directories that never ship and can hold copies of the plugin: end-to-end
# and sandbox sites unpack release zips under .tmp/ and .sandbox/, so without
# these a stale copy of dist/ would add its strings to the template.
NOT_SHIPPED='node_modules,vendor,tests,releases,specs,coverage,playwright-report,test-results,.tmp,.sandbox,.claude,.playwright-cli'

make_pot --exclude="${NOT_SHIPPED},libraries,dist,tools,bin,docs,.github"

# A second pass merges in the built JavaScript in dist/. make-pot cannot read
# the Vue apps' .vue files, and the JSON translation files are keyed by the
# built file paths, so the app strings have to be referenced from there. It
# is a separate pass because make-pot excludes match a name at any depth: the
# tools exclusion above would also drop dist/apps/tools/. The include matches
# a dist/ directory at any depth too, so the directories that never ship are
# excluded again. vite.config.mjs keeps the built calls recognisable, and the
# app string check below fails the build if one goes missing.
make_pot --include='dist' --exclude="$NOT_SHIPPED" --merge

printf 'Extracted %s strings.\n' "$( grep -c '^msgid ' "$POT_FILE" )"

command -v node > /dev/null 2>&1 || abort 'node is required to check the Vue app strings.'
node "${ROOT}/tools/check-app-strings.mjs" "$POT_FILE" \
    || abort 'Vue app strings are missing from the .pot (listed above).'
node "${ROOT}/tools/check-ant-components.mjs" \
    || abort 'A Vue app uses Ant Design tags its entry does not register (listed above).'

# Copy the allowlisted paths into a staging directory.
#
# $1 - Destination directory.
#
# @since 1.0.0
stage_plugin() {
    stage="$1"
    mkdir -p "$stage"

    for path in "${SHIP_PATHS[@]}"; do
        if [ ! -e "${ROOT}/${path}" ]; then
            abort "shipped path ${path} is missing from the repository."
        fi

        if [ -d "${ROOT}/${path}" ]; then
            mkdir -p "${stage}/${path}"
            # -a keeps dotfiles, which matters: dist/.vite/manifest.json is one.
            cp -a "${ROOT}/${path}/." "${stage}/${path}/"
        else
            cp -a "${ROOT}/${path}" "${stage}/${path}"
        fi
    done

    mkdir -p "${stage}/languages"
    cp -a "$POT_FILE" "${stage}/languages/${SLUG}.pot"

    # Placeholders that only exist to keep an empty directory in git.
    find "$stage" -name '.gitkeep' -type f -delete
}

# Fail if a staged tree contains anything that must never ship.
#
# $1    - Staged directory.
# $2... - Optional. Top-level forbidden paths this stage may contain.
#
# @since 1.0.0
# @since 1.0.0 Accepts the top-level paths a stage is allowed to contain.
assert_no_forbidden_paths() {
    stage="$1"
    shift
    found=0

    for path in "${FORBIDDEN_ROOT_PATHS[@]}"; do
        for allowed in "$@"; do
            if [ "$path" = "$allowed" ]; then
                continue 2
            fi
        done

        if [ -e "${stage}/${path}" ]; then
            printf '  %s\n' "$path" >&2
            found=$(( found + 1 ))
        fi
    done

    for path in "${FORBIDDEN_ANYWHERE[@]}"; do
        while IFS= read -r -d '' hit; do
            printf '  %s\n' "${hit#"$stage"/}" >&2
            found=$(( found + 1 ))
        done < <( find "$stage" -name "$path" -print0 )
    done

    if [ "$found" -gt 0 ]; then
        abort "the staged tree contains ${found} path(s) that must never ship (listed above)."
    fi
}

# Print what an artifact contains.
#
# $1 - Staged directory.
# $2 - Label.
#
# @since 1.0.0
report_stage() {
    stage="$1"
    label="$2"
    count="$( find "$stage" -type f | wc -l | tr -d ' ' )"
    bytes="$( du -sk "$stage" | cut -f1 )"

    printf '\n%s: %s files, %s KiB unpacked\n' "$label" "$count" "$bytes"
    printf 'Top-level contents:\n'
    ( cd "$stage" && find . -mindepth 1 -maxdepth 1 | sed 's|^\./|  |' | sort )
    printf 'Files by directory:\n'
    (
        cd "$stage" && find . -type f | while IFS= read -r file; do
            directory="$( dirname "$file" )"

            if [ "$directory" = '.' ]; then
                printf '(root)\n'
            else
                printf '%s\n' "${directory#./}"
            fi
        done | sort | uniq -c | sed 's/^/  /'
    )
}

step 'Staging the free build'
stage_plugin "$FREE_STAGE"
assert_no_forbidden_paths "$FREE_STAGE"
[ ! -e "${FREE_STAGE}/pro" ] || abort 'the free stage somehow contains pro/.'
report_stage "$FREE_STAGE" 'Free build'

step 'Staging the pro build'
stage_plugin "$PRO_STAGE"
mkdir -p "${PRO_STAGE}/pro"
cp -a "${ROOT}/pro/." "${PRO_STAGE}/pro/"
find "${PRO_STAGE}/pro" -name '.gitkeep' -type f -delete
assert_no_forbidden_paths "$PRO_STAGE"
report_stage "$PRO_STAGE" 'Pro build'

step 'Staging the wporg build'
stage_plugin "$WPORG_STAGE"
for path in "${SOURCE_PATHS[@]}"; do
    [ -e "${ROOT}/${path}" ] || abort "source path ${path} is missing from the repository."
    mkdir -p "$( dirname "${WPORG_STAGE}/${path}" )"
    cp -a "${ROOT}/${path}" "${WPORG_STAGE}/${path}"
done
find "$WPORG_STAGE" -name '.gitkeep' -type f -delete
assert_no_forbidden_paths "$WPORG_STAGE" "${WPORG_ALLOWED_ROOT_PATHS[@]}"
[ ! -e "${WPORG_STAGE}/pro" ] || abort 'the wporg stage somehow contains pro/.'
[ ! -e "${WPORG_STAGE}/tools/migrate-heroic" ] || abort 'the wporg stage contains tools/migrate-heroic/.'
report_stage "$WPORG_STAGE" 'WordPress.org build'

step 'Free/pro boundary check'
bash "${SCRIPT_DIR}/check-free-boundary.sh" "$FREE_STAGE" "${ROOT}/apps" "${ROOT}/blocks"
bash "${SCRIPT_DIR}/check-free-boundary.sh" "$WPORG_STAGE"

step 'Packaging'
ZIP_DIR="${WORK_DIR}/zips"
mkdir -p "$ZIP_DIR"

( cd "${WORK_DIR}/free" && zip -qr "${ZIP_DIR}/${SLUG}.zip" "$SLUG" )
( cd "${WORK_DIR}/pro" && zip -qr "${ZIP_DIR}/${SLUG}-pro.zip" "$SLUG" )
( cd "${WORK_DIR}/wporg" && zip -qr "${ZIP_DIR}/${SLUG}-wporg.zip" "$SLUG" )

for zip_file in "${ZIP_DIR}/${SLUG}.zip" "${ZIP_DIR}/${SLUG}-pro.zip" "${ZIP_DIR}/${SLUG}-wporg.zip"; do
    printf '  %s (%s bytes)\n' "$( basename "$zip_file" )" "$( wc -c < "$zip_file" | tr -d ' ' )"
done

# The boundary check ran against the staged directory. Read the zips back so the
# assertions also cover whatever packaging did to them.
#
# The listings are written to files and grep reads those. Piping a listing into
# `grep -q` under `set -o pipefail` fails the pipeline whenever grep matches and
# exits before the writer is done (the writer dies of SIGPIPE), which inverts
# these assertions at random on a large listing.
FREE_LISTING="${WORK_DIR}/free.list"
PRO_LISTING="${WORK_DIR}/pro.list"
WPORG_LISTING="${WORK_DIR}/wporg.list"
unzip -Z1 "${ZIP_DIR}/${SLUG}.zip" > "$FREE_LISTING"
unzip -Z1 "${ZIP_DIR}/${SLUG}-pro.zip" > "$PRO_LISTING"
unzip -Z1 "${ZIP_DIR}/${SLUG}-wporg.zip" > "$WPORG_LISTING"

if grep -q "^${SLUG}/pro/" "$FREE_LISTING"; then
    abort 'the packaged free zip contains pro/ paths.'
fi

if grep -q "^${SLUG}/pro/" "$WPORG_LISTING"; then
    abort 'the packaged wporg zip contains pro/ paths.'
fi

if ! grep -q "^${SLUG}/licensio-knowledge-base\.php$" "$WPORG_LISTING"; then
    abort 'the packaged wporg zip does not contain the plugin bootstrap.'
fi

if ! grep -q "^${SLUG}/package\.json$" "$WPORG_LISTING"; then
    abort 'the packaged wporg zip does not contain its sources.'
fi

if ! grep -q "^${SLUG}/licensio-knowledge-base\.php$" "$PRO_LISTING"; then
    abort 'the packaged pro zip does not contain the plugin bootstrap.'
fi

if ! grep -q "^${SLUG}/licensio-knowledge-base\.php$" "$FREE_LISTING"; then
    abort 'the packaged free zip does not contain the plugin bootstrap.'
fi

step 'Result'
if [ "$DRY_RUN" -eq 1 ]; then
    printf 'Dry run complete for version %s. Nothing was written to %s.\n' "$VERSION" "$OUTPUT_DIR"
    exit 0
fi

# A zip left over from an earlier build of the same version is a stale
# artifact wearing the current name. It is replaced only here, after every
# gate has passed: a build that fails leaves the previous zips in place rather
# than none at all.
mkdir -p "$OUTPUT_DIR"
for zip_name in "${SLUG}.zip" "${SLUG}-pro.zip" "${SLUG}-wporg.zip"; do
    if [ -e "${OUTPUT_DIR}/${zip_name}" ]; then
        rm -f "${OUTPUT_DIR}/${zip_name}"
        printf '  replaced %s\n' "${OUTPUT_DIR}/${zip_name}"
    fi

    cp -a "${ZIP_DIR}/${zip_name}" "${OUTPUT_DIR}/${zip_name}"
done

printf 'Version %s built.\n' "$VERSION"
printf '  %s/%s.zip\n' "$OUTPUT_DIR" "$SLUG"
printf '  %s/%s-pro.zip\n' "$OUTPUT_DIR" "$SLUG"
printf '  %s/%s-wporg.zip\n' "$OUTPUT_DIR" "$SLUG"
