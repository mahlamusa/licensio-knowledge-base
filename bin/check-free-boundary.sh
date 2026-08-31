#!/usr/bin/env bash
#
# Free/pro boundary check.
#
# Asserts two things about a staged free plugin directory:
#
#   1. It contains no path under `pro/`.
#   2. It references no SLSWC or WooCommerce symbol.
#
# Rule 2 is about symbols, not prose. Two shipped files mention WooCommerce in a
# comment and must keep doing so: the plugin header states that the plugin needs
# neither SLSWC nor WooCommerce, and the vendored StellarWP DB notes that
# WooCommerce also sets SQL_BIG_SELECTS. PHP files are therefore run through
# `bin/php-code-only.php`, which blanks comments with PHP's own tokeniser, and
# the patterns are matched against the code that is left. Line numbers survive
# the stripping, so a hit still points at the real line.
#
# Scanning depth varies by file kind, on purpose:
#
#   * PHP, and every hand-written shipped file, gets the full pattern set,
#     including the bare `WC_`/`wc_` symbol prefixes.
#   * Files under `dist/` are minified bundles. Minifiers mint short identifiers
#     such as `wC(` and `wV`, so the full set produces thousands of false hits
#     there and would be switched off within a week. `dist/` is matched against
#     the long, distinctive tokens only.
#   * The hole that opens up - a WooCommerce call written in `apps/` or
#     `blocks/` and only ever shipped minified - is closed by passing those
#     source directories in as extra arguments. They are scanned with the full
#     pattern set even though they are not themselves shipped.
#
# There is no per-line exemption marker. A legitimate free-side mention has to
# be an argued change to this file, reviewed like any other.
#
# Usage: bin/check-free-boundary.sh <staged-free-dir> [uncompiled-source-dir ...]
#
# @since 1.0.0

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
STRIPPER="${SCRIPT_DIR}/php-code-only.php"

# Long tokens with no innocent reading. Matched case-insensitively everywhere,
# minified bundles included.
WORD_PATTERN='slswc|woocommerce'

# Code-shaped references. Case-sensitive, and only applied to files a human
# wrote.
SYMBOL_PATTERN='(^|[^[:alnum:]_$])(WC_[[:alnum:]]|wc_[[:alnum:]]|WC[[:space:]]*\(|WC::|wcSettings|wc/v[0-9])'

# The subset of SYMBOL_PATTERN that is still safe against minified output.
GENERATED_SYMBOL_PATTERN='(wcSettings|wc/v[0-9])'

failures=0

# Report a violation and remember that one happened.
#
# @since 1.0.0
fail() {
    printf '%s\n' "$1" >&2
    failures=$(( failures + 1 ))
}

# Scan a single file and report every matching line.
#
# $1 - File to read.
# $2 - Path to print in the report.
# $3 - "full" or "generated".
#
# @since 1.0.0
scan_file() {
    scan_target="$1"
    scan_label="$2"
    scan_mode="$3"
    scan_hits=''

    # -I skips binaries, so images and .mo files fall out here.
    if ! grep -Iq '' "$scan_target" 2>/dev/null; then
        return 0
    fi

    scan_hits="$( grep -IniE "$WORD_PATTERN" "$scan_target" || true )"

    if [ "$scan_mode" = 'generated' ]; then
        scan_hits="${scan_hits}
$( grep -InE "$GENERATED_SYMBOL_PATTERN" "$scan_target" || true )"
    else
        scan_hits="${scan_hits}
$( grep -InE "$SYMBOL_PATTERN" "$scan_target" || true )"
    fi

    scan_hits="$( printf '%s\n' "$scan_hits" | grep -v '^$' || true )"

    if [ -n "$scan_hits" ]; then
        while IFS= read -r line; do
            fail "  ${scan_label}:${line}"
        done <<< "$scan_hits"
    fi
}

# Scan every file in a tree.
#
# $1 - Directory to walk.
# $2 - Prefix stripped from reported paths.
# $3 - "artifact" (dist/ gets the reduced pattern set) or "source".
#
# @since 1.0.0
scan_tree() {
    tree_root="$1"
    tree_prefix="$2"
    tree_kind="$3"

    while IFS= read -r -d '' file; do
        relative="${file#"$tree_prefix"/}"
        mode='full'

        if [ "$tree_kind" = 'artifact' ]; then
            case "$relative" in
                dist/*) mode='generated' ;;
            esac
        fi

        case "$file" in
            *.php)
                stripped="$( mktemp )"
                if php "$STRIPPER" "$file" > "$stripped"; then
                    scan_file "$stripped" "$relative" "$mode"
                else
                    fail "  ${relative}: could not be tokenised for the boundary check."
                fi
                rm -f "$stripped"
                ;;
            *)
                scan_file "$file" "$relative" "$mode"
                ;;
        esac
    done < <( find "$tree_root" -type f -print0 | sort -z )
}

if [ "$#" -lt 1 ]; then
    printf 'Usage: bin/check-free-boundary.sh <staged-free-dir> [uncompiled-source-dir ...]\n' >&2
    exit 2
fi

STAGE="$1"
shift

if [ ! -d "$STAGE" ]; then
    printf 'Boundary check: %s is not a directory.\n' "$STAGE" >&2
    exit 2
fi

if [ ! -f "$STRIPPER" ]; then
    printf 'Boundary check: %s is missing.\n' "$STRIPPER" >&2
    exit 2
fi

if ! command -v php > /dev/null 2>&1; then
    printf 'Boundary check: php is required to strip comments before scanning.\n' >&2
    exit 2
fi

printf 'Boundary check: free artifact at %s\n' "$STAGE"

# Rule 1: nothing under pro/.
if [ -e "${STAGE}/pro" ]; then
    printf 'Free artifact contains pro/ paths:\n' >&2
    while IFS= read -r -d '' pro_path; do
        fail "  ${pro_path#"$STAGE"/}"
    done < <( find "${STAGE}/pro" -print0 | sort -z )
fi

# Rule 2: no SLSWC or WooCommerce symbol.
scan_tree "$STAGE" "$STAGE" 'artifact'

for source_dir in "$@"; do
    if [ -d "$source_dir" ]; then
        printf 'Boundary check: uncompiled sources at %s\n' "$source_dir"
        scan_tree "$source_dir" "$( dirname "$source_dir" )" 'source'
    fi
done

if [ "$failures" -gt 0 ]; then
    printf '\nBoundary check FAILED with %s violation(s).\n' "$failures" >&2
    printf 'The free build must contain no pro/ path and no SLSWC or WooCommerce symbol.\n' >&2
    exit 1
fi

printf 'Boundary check passed: no pro/ paths, no SLSWC or WooCommerce symbols.\n'
