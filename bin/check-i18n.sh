#!/usr/bin/env bash
#
# Text domain check.
#
# Fails when a PHP translation call is missing its text domain, or carries a
# domain other than `licensio-knowledge-base`. Both cases are what PHPCS's
# `WordPress.WP.I18n` sniff reports once `phpcs.xml` declares the domain, which
# it does, so this runs that sniff on its own rather than reimplementing it. Run
# alone it gives a failure message about text domains instead of burying one in
# a full lint run, and it is fast enough to gate a release on.
#
# The JavaScript half is not duplicated here. `@wordpress/eslint-plugin` already
# ships `@wordpress/i18n-text-domain`, and `npm run lint:js` runs it. That rule
# is a no-op until `allowedTextDomain` is set - with no domains configured it
# treats a missing domain as the WordPress default and says nothing - so the
# repo's `eslint.config.mjs` pins it to this plugin's domain. That pin is the JS
# gate, and this script asserts the pin is still in place, because losing it
# turns the JS gate green forever without any visible failure. It asks ESLint
# for the config it resolves for a .vue, a .ts and a .js file rather than
# reading the config's text, so a commented-out rule or an empty domain list
# fails here too.
#
# Usage: bin/check-i18n.sh [path ...]
#
# With no arguments the files listed in phpcs.xml are checked. Paths are
# accepted so the gate's own self-test can point it at a fixture.
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"

TEXT_DOMAIN='licensio-knowledge-base'
PHPCS="${ROOT}/vendor/bin/phpcs"

# Overridable so the gate's own self-test can point the pin check at a fixture.
# Nothing else should set it.
ESLINT_CONFIG="${LICENSIO_KB_ESLINT_CONFIG:-${ROOT}/eslint.config.mjs}"

if [ ! -x "$PHPCS" ]; then
    printf 'i18n check: %s is missing. Run composer install first.\n' "$PHPCS" >&2
    exit 2
fi

if [ ! -d "${ROOT}/node_modules/eslint" ]; then
    printf 'i18n check: ESLint is missing from %s/node_modules. Run npm ci first.\n' "$ROOT" >&2
    exit 2
fi

status=0

printf 'i18n check: PHP text domains (%s)\n' "$TEXT_DOMAIN"

if ! "$PHPCS" --standard="${ROOT}/phpcs.xml" --sniffs=WordPress.WP.I18n --no-colors "$@"; then
    printf '\ni18n check FAILED: a PHP string is missing the %s text domain, or uses another one.\n' "$TEXT_DOMAIN" >&2
    status=1
fi

printf 'i18n check: JavaScript text domain rule is pinned\n'

if [ ! -f "$ESLINT_CONFIG" ]; then
    printf 'i18n check FAILED: %s is missing, so npm run lint:js no longer enforces the JS text domain.\n' "$ESLINT_CONFIG" >&2
    status=1
elif ! ( cd "$ROOT" && ESLINT_CONFIG="$ESLINT_CONFIG" TEXT_DOMAIN="$TEXT_DOMAIN" node --input-type=module - ) <<'JS'
// The rule as ESLint resolves it for each kind of linted file, so only the
// effective config counts, not what the config file happens to contain.
import { ESLint } from 'eslint';

const { ESLINT_CONFIG: configFile, TEXT_DOMAIN: domain } = process.env;
const eslint = new ESLint( { cwd: process.cwd(), overrideConfigFile: configFile } );
let pinned = true;

for ( const file of [ 'apps/i18n-probe.vue', 'apps/i18n-probe.ts', 'assets/js/i18n-probe.js' ] ) {
    const config = await eslint.calculateConfigForFile( file );
    const [ severity, options ] = config?.rules?.[ '@wordpress/i18n-text-domain' ] ?? [];
    const domains = [ options?.allowedTextDomain ?? [] ].flat();

    if ( 2 !== severity || 1 !== domains.length || domain !== domains[ 0 ] ) {
        process.stderr.write( `  ${ file }: ${ JSON.stringify( [ severity, options ] ) }\n` );
        pinned = false;
    }
}

process.exit( pinned ? 0 : 1 );
JS
then
    printf 'i18n check FAILED: %s no longer pins @wordpress/i18n-text-domain to %s.\n' "$ESLINT_CONFIG" "$TEXT_DOMAIN" >&2
    printf 'The rule must be an error with allowedTextDomain set to exactly that domain;\n' >&2
    printf 'without it the rule accepts a missing domain silently.\n' >&2
    status=1
fi

if [ "$status" -ne 0 ]; then
    exit "$status"
fi

printf 'i18n check passed.\n'
