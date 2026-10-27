#!/usr/bin/env bash
#
# Self-test for the release gates.
#
# A CI step that cannot fail is worse than no step at all: it turns an unchecked
# assumption into a false assurance. The release gates (the three in this
# directory, tools/check-app-strings.mjs and tools/check-ant-components.mjs)
# are all pattern matching over a tree, which is exactly the kind of thing that
# goes quietly green - a renamed directory, a pattern that stops compiling, a
# `find` that walks nothing.
#
# So every gate is exercised here against fixtures, in both directions:
#
#   * a violation must be rejected, and rejected for the stated reason - the
#     expected message is asserted, not just a non-zero exit;
#   * a clean fixture, and the near misses that look like violations but are
#     not, must be accepted. Without those a gate that failed unconditionally
#     would pass its own self-test.
#
# Usage: bin/check-gates-selftest.sh
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"

WORK_DIR="$( mktemp -d )"
trap 'rm -rf "$WORK_DIR"' EXIT

passed=0
failed=0

# Report a passing case.
#
# @since 1.0.0
ok() {
    printf '  ok    %s\n' "$1"
    passed=$(( passed + 1 ))
}

# Report a failing case.
#
# @since 1.0.0
bad() {
    printf '  FAIL  %s\n' "$1" >&2
    failed=$(( failed + 1 ))
}

# Assert that a command succeeds.
#
# $1   - Case name.
# $2.. - Command.
#
# @since 1.0.0
assert_green() {
    name="$1"
    shift
    output=''

    if output="$( "$@" 2>&1 )"; then
        ok "$name"
    else
        bad "$name - expected success, got a failure:"
        printf '%s\n' "$output" | sed 's/^/        /' >&2
    fi
}

# Assert that a command fails, and that its output explains why.
#
# $1   - Case name.
# $2   - Substring the output must contain.
# $3.. - Command.
#
# @since 1.0.0
assert_red() {
    name="$1"
    expected="$2"
    shift 2
    output=''

    if output="$( "$@" 2>&1 )"; then
        bad "$name - expected a failure, the gate passed."
        printf '%s\n' "$output" | sed 's/^/        /' >&2
        return
    fi

    # A substring test rather than a pipe into grep -q, which can lose the
    # writer to SIGPIPE under pipefail and report a match as a miss.
    if [[ "$output" == *"$expected"* ]]; then
        ok "$name"
    else
        bad "$name - failed, but not for the expected reason (wanted: ${expected})."
        printf '%s\n' "$output" | sed 's/^/        /' >&2
    fi
}

# Build a minimal but realistic staged plugin tree.
#
# $1 - Directory to create.
# $2 - Version to write into all four locations.
#
# @since 1.0.0
make_fixture() {
    fixture="$1"
    version="$2"

    mkdir -p "${fixture}/includes" "${fixture}/dist/apps/demo" "${fixture}/languages"

    cat > "${fixture}/licensio-knowledge-base.php" <<PHP
<?php
/**
 * Plugin Name: Licensio Knowledge Base
 * Version: ${version}
 * Text Domain: licensio-knowledge-base
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

define( 'LICENSIO_KB_VERSION', '${version}' );
PHP

    cat > "${fixture}/readme.txt" <<README
=== Licensio Knowledge Base ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: ${version}
README

    cat > "${fixture}/package.json" <<JSON
{
  "name": "licensio-knowledge-base",
  "version": "${version}"
}
JSON

    cat > "${fixture}/includes/class-licensio-kb-demo.php" <<'PHP'
<?php
/**
 * A demo class.
 */
class Licensio_KB_Demo {
    public function label() {
        return esc_html__( 'Demo', 'licensio-knowledge-base' );
    }
}
PHP

    # A minified bundle, carrying the short identifiers a minifier mints. Every
    # one of these matches the full pattern set - `WC(` and `WC_a` deliberately
    # so - and none of them matches the reduced set `dist/` is scanned with. If
    # the reduced set is ever dropped, the green case below turns red here.
    printf 'const wC=(e)=>e,wV=wC(1),WC_a=2,x=WC(3);export{x,wV,WC_a};\n' \
        > "${fixture}/dist/apps/demo/index.a1b2c3d4.js"
}

printf 'Release gate self-test\n'

# ---------------------------------------------------------------------------
# Boundary check
# ---------------------------------------------------------------------------

printf '\nbin/check-free-boundary.sh\n'

BOUNDARY_CLEAN="${WORK_DIR}/boundary-clean"
make_fixture "$BOUNDARY_CLEAN" '1.0.0'
assert_green 'a clean free tree passes' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_CLEAN"

assert_green 'minified identifiers such as wC( and WC do not trip the dist scan' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_CLEAN"

BOUNDARY_COMMENT="${WORK_DIR}/boundary-comment"
make_fixture "$BOUNDARY_COMMENT" '1.0.0'
cat > "${BOUNDARY_COMMENT}/includes/class-licensio-kb-standalone.php" <<'PHP'
<?php
/**
 * The plugin is standalone: it needs neither SLSWC nor WooCommerce.
 *
 * Not even a WC_Order or a call to wc_get_product() - in prose.
 */
class Licensio_KB_Standalone {
    public function ok() {
        // WooCommerce is not required here either.
        return true;
    }
}
PHP
assert_green 'SLSWC and WooCommerce named in PHP comments are allowed' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_COMMENT"

BOUNDARY_PRO="${WORK_DIR}/boundary-pro"
make_fixture "$BOUNDARY_PRO" '1.0.0'
mkdir -p "${BOUNDARY_PRO}/pro"
printf '<?php\nclass Licensio_KB_Pro_Gate {}\n' > "${BOUNDARY_PRO}/pro/class-licensio-kb-pro-gate.php"
assert_red 'a file under pro/ fails the free build' \
    'pro/class-licensio-kb-pro-gate.php' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_PRO"

BOUNDARY_WC="${WORK_DIR}/boundary-wc"
make_fixture "$BOUNDARY_WC" '1.0.0'
cat > "${BOUNDARY_WC}/includes/class-licensio-kb-gate.php" <<'PHP'
<?php
class Licensio_KB_Gate {
    public function order() {
        return new WC_Order( 1 );
    }
}
PHP
assert_red 'a WooCommerce class outside pro/ fails the free build' \
    'includes/class-licensio-kb-gate.php:4' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_WC"

BOUNDARY_SLSWC="${WORK_DIR}/boundary-slswc"
make_fixture "$BOUNDARY_SLSWC" '1.0.0'
cat > "${BOUNDARY_SLSWC}/includes/class-licensio-kb-licence.php" <<'PHP'
<?php
class Licensio_KB_Licence {
    public function check() {
        return slswc_client_is_active();
    }
}
PHP
assert_red 'an SLSWC symbol outside pro/ fails the free build' \
    'includes/class-licensio-kb-licence.php:4' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_SLSWC"

BOUNDARY_DIST="${WORK_DIR}/boundary-dist"
make_fixture "$BOUNDARY_DIST" '1.0.0'
printf 'const a=fetch("/wp-json/wc/v3/products");\n' \
    >> "${BOUNDARY_DIST}/dist/apps/demo/index.a1b2c3d4.js"
assert_red 'a WooCommerce REST route in a compiled bundle fails the free build' \
    'dist/apps/demo/index.a1b2c3d4.js' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_DIST"

BOUNDARY_SOURCE="${WORK_DIR}/boundary-source"
make_fixture "$BOUNDARY_SOURCE" '1.0.0'
mkdir -p "${WORK_DIR}/uncompiled/apps/demo"
printf "const product = wc_get_product( 1 );\n" \
    > "${WORK_DIR}/uncompiled/apps/demo/index.ts"
assert_red 'a WooCommerce call in an uncompiled source directory fails the free build' \
    'apps/demo/index.ts:1' \
    bash "${SCRIPT_DIR}/check-free-boundary.sh" "$BOUNDARY_SOURCE" "${WORK_DIR}/uncompiled/apps"

# ---------------------------------------------------------------------------
# Version check
# ---------------------------------------------------------------------------

printf '\nbin/check-versions.sh\n'

VERSION_OK="${WORK_DIR}/version-ok"
make_fixture "$VERSION_OK" '1.0.0'
assert_green 'four matching versions pass' \
    bash "${SCRIPT_DIR}/check-versions.sh" "$VERSION_OK"

for location in header constant readme package; do
    fixture="${WORK_DIR}/version-${location}"
    make_fixture "$fixture" '1.0.0'

    case "$location" in
        header)
            sed -i.bak 's/^ \* Version: 1.0.0$/ * Version: 1.1.0/' "${fixture}/licensio-knowledge-base.php"
            expected='plugin header Version (1.1.0)'
            ;;
        constant)
            sed -i.bak "s/'LICENSIO_KB_VERSION', '1.0.0'/'LICENSIO_KB_VERSION', '1.1.0'/" "${fixture}/licensio-knowledge-base.php"
            expected='LICENSIO_KB_VERSION (1.1.0)'
            ;;
        readme)
            sed -i.bak 's/^Stable tag: 1.0.0$/Stable tag: 1.1.0/' "${fixture}/readme.txt"
            expected='readme.txt Stable tag (1.1.0)'
            ;;
        package)
            sed -i.bak 's/"version": "1.0.0"/"version": "1.1.0"/' "${fixture}/package.json"
            expected='package.json version (1.1.0)'
            ;;
    esac

    rm -f "${fixture}"/*.bak
    assert_red "a version bumped only in the ${location} fails" "$expected" \
        bash "${SCRIPT_DIR}/check-versions.sh" "$fixture"
done

VERSION_FLOOR="${WORK_DIR}/version-floor"
make_fixture "$VERSION_FLOOR" '1.0.0'
sed -i.bak 's/^Requires PHP: 7.4$/Requires PHP: 8.0/' "${VERSION_FLOOR}/readme.txt"
rm -f "${VERSION_FLOOR}"/*.bak
assert_red 'a readme support floor that drifts from the header fails' \
    'Requires PHP' \
    bash "${SCRIPT_DIR}/check-versions.sh" "$VERSION_FLOOR"

# ---------------------------------------------------------------------------
# Text domain check
# ---------------------------------------------------------------------------

printf '\nbin/check-i18n.sh\n'

if [ ! -x "${ROOT}/vendor/bin/phpcs" ]; then
    printf '  skip  PHPCS is not installed; run composer install to include these cases.\n' >&2
    failed=$(( failed + 1 ))
else
    I18N_DIR="${WORK_DIR}/i18n"
    mkdir -p "$I18N_DIR"

    cat > "${I18N_DIR}/good.php" <<'PHP'
<?php
$label = esc_html__( 'Demo', 'licensio-knowledge-base' );
PHP

    cat > "${I18N_DIR}/missing.php" <<'PHP'
<?php
$label = esc_html__( 'Demo' );
PHP

    cat > "${I18N_DIR}/wrong.php" <<'PHP'
<?php
$label = esc_html__( 'Demo', 'some-other-plugin' );
PHP

    assert_green 'a correctly domained PHP string passes' \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/good.php"

    assert_red 'a PHP string with no text domain fails' \
        'MissingArgDomain' \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/missing.php"

    assert_red 'a PHP string with the wrong text domain fails' \
        'TextDomainMismatch' \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/wrong.php"

    printf 'export default [ { rules: {} } ];\n' > "${WORK_DIR}/unpinned-eslint.config.mjs"
    assert_red 'an eslint config that no longer pins the JS text domain fails' \
        'no longer pins @wordpress/i18n-text-domain' \
        env "LICENSIO_KB_ESLINT_CONFIG=${WORK_DIR}/unpinned-eslint.config.mjs" \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/good.php"

    assert_red 'a missing eslint config fails' \
        'is missing' \
        env "LICENSIO_KB_ESLINT_CONFIG=${WORK_DIR}/no-such-eslint.config.mjs" \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/good.php"

    # Copies of the real config, so every grep-able part of the pin is still
    # in the file while the config ESLint resolves no longer carries it. The
    # node_modules link lets the copy resolve its imports.
    ESLINT_DIR="${WORK_DIR}/eslint"
    mkdir -p "$ESLINT_DIR"
    ln -s "${ROOT}/node_modules" "${ESLINT_DIR}/node_modules"

    sed "/'@wordpress\/i18n-text-domain': \[/,/^\t\t\t\],\$/ s|^|// |" \
        "${ROOT}/eslint.config.mjs" > "${ESLINT_DIR}/commented.config.mjs"
    sed "s|allowedTextDomain: \[ 'licensio-knowledge-base' \]|allowedTextDomain: []|" \
        "${ROOT}/eslint.config.mjs" > "${ESLINT_DIR}/empty.config.mjs"

    assert_green 'the real eslint config passes when named explicitly' \
        env "LICENSIO_KB_ESLINT_CONFIG=${ROOT}/eslint.config.mjs" \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/good.php"

    assert_red 'an eslint config with the text domain rule commented out fails' \
        'no longer pins @wordpress/i18n-text-domain' \
        env "LICENSIO_KB_ESLINT_CONFIG=${ESLINT_DIR}/commented.config.mjs" \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/good.php"

    assert_red 'an eslint config with an empty allowedTextDomain list fails' \
        'no longer pins @wordpress/i18n-text-domain' \
        env "LICENSIO_KB_ESLINT_CONFIG=${ESLINT_DIR}/empty.config.mjs" \
        bash "${SCRIPT_DIR}/check-i18n.sh" "${I18N_DIR}/good.php"
fi

# ---------------------------------------------------------------------------
# Vue app string check
# ---------------------------------------------------------------------------

printf '\ntools/check-app-strings.mjs\n'

APP_STRINGS_DIR="${WORK_DIR}/app-strings"
mkdir -p "${APP_STRINGS_DIR}/apps/demo"

cat > "${APP_STRINGS_DIR}/apps/demo/App.vue" <<'VUE'
<script setup lang="ts">
/**
 * Usage: __( 'Only an example in a docblock', 'licensio-knowledge-base' )
 */
const title = __( 'Tag saved', 'licensio-knowledge-base' );
const count = /* translators: %d: number of tags. */ _n( '%d tag', '%d tags', 2, 'licensio-knowledge-base' );
const verb = _x( 'Post', 'verb', 'licensio-knowledge-base' );
const items = _nx( '%d item', '%d items', 2, 'cart', 'licensio-knowledge-base' );
/* translators: a stray note that describes no call. */
const unrelated = 1;
const again = __( 'Tag saved', 'licensio-knowledge-base' );
const named = /* Translators: %s: tag name. */ __( 'Tag %s', 'licensio-knowledge-base' );
</script>
<template>
	<!-- __( 'Only an example in markup', 'licensio-knowledge-base' ) -->
	<p>{{ __( 'It\'s here', 'licensio-knowledge-base' ) }}</p>
</template>
VUE

# Every app string, referenced from the built bundle. "Post" also has a PHP
# entry without a context, which must not stand in for the app's "verb" one.
cat > "${APP_STRINGS_DIR}/complete.pot" <<'POT'
msgid ""
msgstr ""
"Project-Id-Version: Licensio Knowledge Base\n"

#: dist/apps/demo/index.a1b2c3d4.js:1
msgid "Tag saved"
msgstr ""

#. translators: %d: number of tags.
#: dist/apps/demo/index.a1b2c3d4.js:1
msgid "%d tag"
msgid_plural "%d tags"
msgstr[0] ""
msgstr[1] ""

#: includes/class-licensio-kb-demo.php:9
msgid "Post"
msgstr ""

#: dist/apps/demo/index.a1b2c3d4.js:1
msgctxt "verb"
msgid "Post"
msgstr ""

#: dist/apps/demo/index.a1b2c3d4.js:1
msgctxt "cart"
msgid "%d item"
msgid_plural "%d items"
msgstr[0] ""
msgstr[1] ""

#: includes/class-licensio-kb-demo.php:12
#: dist/apps/demo/index.a1b2c3d4.js:1
msgid "It's here"
msgstr ""

#. Translators: %s: tag name.
#: dist/apps/demo/index.a1b2c3d4.js:1
msgid "Tag %s"
msgstr ""
POT

grep -v '%d tags' "${APP_STRINGS_DIR}/complete.pot" > "${APP_STRINGS_DIR}/no-plural.pot"
sed 's|^#: dist/apps/demo/index.a1b2c3d4.js:1$|#: includes/class-licensio-kb-demo.php:3|' \
    "${APP_STRINGS_DIR}/complete.pot" > "${APP_STRINGS_DIR}/php-only.pot"
grep -v '^msgctxt "verb"$' "${APP_STRINGS_DIR}/complete.pot" > "${APP_STRINGS_DIR}/no-context.pot"
sed 's|^msgctxt "cart"$|msgctxt "basket"|' \
    "${APP_STRINGS_DIR}/complete.pot" > "${APP_STRINGS_DIR}/wrong-context.pot"
grep -vi '^#\. translators:' "${APP_STRINGS_DIR}/complete.pot" > "${APP_STRINGS_DIR}/no-comment.pot"
# A later entry for the plural text alone, without the comment, which is the
# entry the plural form of the _n call is looked up in.
cat "${APP_STRINGS_DIR}/complete.pot" - > "${APP_STRINGS_DIR}/plural-no-comment.pot" <<'POT'

#: dist/apps/demo/index.a1b2c3d4.js:1
msgid "%d tags"
msgstr ""
POT

assert_green 'a template with every app string passes, comments are not calls, stray notes are ignored' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/complete.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'a template missing an app plural form fails' \
    'apps/demo/App.vue:6  %d tags' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/no-plural.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'a string referenced only from PHP does not count for the app' \
    'apps/demo/App.vue:5  Tag saved' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/php-only.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'an _x string without its context fails' \
    'apps/demo/App.vue:7  Post (context: verb)' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/no-context.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'an _nx string under another context fails' \
    'apps/demo/App.vue:8  %d item (context: cart)' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/wrong-context.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'a translator comment missing from the template entry fails' \
    'apps/demo/App.vue:6  %d tag (comment lost: translators: %d: number of tags.)' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/no-comment.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'a translator comment written with a capital T is checked too' \
    'apps/demo/App.vue:12  Tag %s (comment lost: Translators: %s: tag name.)' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/no-comment.pot" "${APP_STRINGS_DIR}/apps"

assert_red 'a translator comment missing from the plural form fails' \
    'apps/demo/App.vue:6  %d tags (comment lost: translators: %d: number of tags.)' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/plural-no-comment.pot" "${APP_STRINGS_DIR}/apps"

mkdir -p "${APP_STRINGS_DIR}/line-comment/demo"
cat > "${APP_STRINGS_DIR}/line-comment/demo/index.ts" <<'TS'
// translators: %d: number of tags.
const count = _n( '%d tag', '%d tags', 2, 'licensio-knowledge-base' );
TS

assert_red 'a line comment translator note fails, the build would drop it' \
    'line-comment/demo/index.ts:1  // translators: (a line comment does not survive the build' \
    node "${ROOT}/tools/check-app-strings.mjs" "${APP_STRINGS_DIR}/complete.pot" "${APP_STRINGS_DIR}/line-comment"

printf '\ntools/check-ant-components.mjs\n'

ANT_DIR="${WORK_DIR}/ant-components"
mkdir -p "${ANT_DIR}/apps/demo" "${ANT_DIR}/apps/legacy" "${ANT_DIR}/apps/plain"

cat > "${ANT_DIR}/apps/demo/index.ts" <<'TS'
import { Button, Form, Tooltip } from 'ant-design-vue';
const app = createApp( App );
[ Button, Form ].forEach( ( component ) => {
	app.use( component );
} );
TS

cat > "${ANT_DIR}/apps/plain/index.js" <<'JS'
import { Button as AntButton } from 'ant-design-vue';
createApp( App ).use( AntButton ).mount( '#app' );
JS

cat > "${ANT_DIR}/apps/plain/App.vue" <<'VUE'
<template><a-button /></template>
VUE

cat > "${ANT_DIR}/apps/demo/App.vue" <<'VUE'
<template>
	<a-form><a-form-item><a-button /></a-form-item></a-form>
</template>
VUE

cat > "${ANT_DIR}/apps/legacy/index.ts" <<'TS'
import Antd from 'ant-design-vue';
TS

cat > "${ANT_DIR}/apps/legacy/App.vue" <<'VUE'
<template><a-tooltip /></template>
VUE

assert_green 'registered tags and plugin sub-components pass, default Antd apps are skipped' \
    node "${ROOT}/tools/check-ant-components.mjs" "${ANT_DIR}/apps"

printf '<template><a-button /><a-tooltip /></template>\n' > "${ANT_DIR}/apps/plain/App.vue"

assert_red 'an index.js entry is read, and its unregistered tag fails' \
    'plain/App.vue  <a-tooltip>' \
    node "${ROOT}/tools/check-ant-components.mjs" "${ANT_DIR}/apps"

rm -r "${ANT_DIR}/apps/plain"

printf '<template><a-tooltip /></template>\n' > "${ANT_DIR}/apps/demo/Extra.vue"

assert_red 'a component imported but never passed to app.use does not count' \
    'demo/Extra.vue  <a-tooltip>' \
    node "${ROOT}/tools/check-ant-components.mjs" "${ANT_DIR}/apps"

rm -r "${ANT_DIR}/apps/demo"

assert_red 'no app with named imports fails rather than passing vacuously' \
    'imports named Ant Design components' \
    node "${ROOT}/tools/check-ant-components.mjs" "${ANT_DIR}/apps"

printf '\n%s passed, %s failed.\n' "$passed" "$failed"

if [ "$failed" -gt 0 ]; then
    printf 'Release gate self-test FAILED. A gate is not behaving as documented.\n' >&2
    exit 1
fi

printf 'Release gate self-test passed.\n'
