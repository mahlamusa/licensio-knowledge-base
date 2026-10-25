#!/usr/bin/env bash
#
# A disposable WordPress for the Playwright suite.
#
# Boots WordPress in Docker with @wordpress/env, installs the plugin from a
# release zip rather than from the working tree, so the suite tests what ships,
# switches to plain permalinks and seeds the content the specs read
# (tests/Playwright/seed.php). CI and a local run use the same script.
#
# Usage (from anywhere; paths are taken from where it is called):
#   bin/e2e-env.sh start <plugin-zip>   Boot, install and seed; print the env vars.
#   bin/e2e-env.sh stop                 Remove the containers, volumes and images.
#
# `start` prints the variables the specs need as KEY=value lines. Append them to
# $GITHUB_ENV in CI, or export them locally:
#
#   bin/e2e-env.sh start releases/licensio-knowledge-base-wporg.zip > .tmp/e2e.env
#   while IFS= read -r line; do export "$line"; done < .tmp/e2e.env
#   npx playwright test
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( dirname "$SCRIPT_DIR" )"

# Pinned rather than a devDependency: @wordpress/scripts 30 declares a peer
# range on the 10.x line, and 10.x cannot read a config file from anywhere but
# the working directory.
WP_ENV_VERSION='11.17.0'
CONFIG='tests/Playwright/wp-env.json'

# A relative zip path is taken from where the script was called.
ZIP="${2:-}"
if [ -n "$ZIP" ]; then
    ZIP="$( realpath "$ZIP" )"
fi

# The plugin path in the config is resolved from the working directory.
cd "$ROOT"

wp_env() {
    npm exec --yes --package="@wordpress/env@${WP_ENV_VERSION}" -- wp-env --config="$CONFIG" "$@"
}

# The wp-cli container wp-env leaves running. `wp-env run` prints its progress
# on stdout, which would end up in every value a spec reads back.
cli_container() {
    local ids

    ids="$( docker ps -q --filter 'label=com.docker.compose.service=cli' --filter 'name=^wp-env-playwright-' )"

    if [ "$( printf '%s\n' "$ids" | grep -c . )" != '1' ]; then
        echo "Expected one wp-env cli container, found: ${ids:-none}." >&2
        exit 1
    fi

    printf '%s' "$ids"
}

case "${1:-}" in
    start)
        zip="${ZIP:?Pass the plugin zip to install.}"

        rm -rf .tmp/e2e
        mkdir -p .tmp/e2e
        unzip -q "$zip" -d .tmp/e2e

        if [ ! -f .tmp/e2e/licensio-knowledge-base/licensio-knowledge-base.php ]; then
            echo "${zip} does not hold licensio-knowledge-base/licensio-knowledge-base.php." >&2
            exit 1
        fi

        wp_env start >&2

        cli="$( cli_container )"

        docker exec -i "$cli" wp eval-file - < tests/Playwright/seed.php >&2

        cat <<VARS
LICENSIO_KB_E2E=1
LICENSIO_KB_BASE_URL=http://localhost:8888
LICENSIO_KB_ADMIN_USER=admin
LICENSIO_KB_ADMIN_PASSWORD=password
LICENSIO_KB_E2E_PATH=/?pagename=kb-search-e2e
LICENSIO_KB_E2E_TERM=licence
LICENSIO_KB_WP_CLI=docker exec ${cli} wp
VARS
        ;;
    stop)
        wp_env destroy --force >&2
        rm -rf .tmp/e2e
        ;;
    *)
        echo 'Usage: bin/e2e-env.sh start <plugin-zip> | stop' >&2
        exit 2
        ;;
esac
