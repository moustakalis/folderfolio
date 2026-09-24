#!/usr/bin/env bash
#
# Build the distributable plugin ZIP.
#
# Single packaging path: both `mise run dev:zip` and CI call this script, so a
# release artifact cannot differ from what a developer builds locally. The
# allowlist itself lives in bin/stage-plugin.sh, which the end-to-end suite
# also uses — so the files the tests run against and the files that ship are
# the same files by construction.
#
# Assets must already be built (mise's dev:zip depends on assets:build).

set -euo pipefail

PLUGIN_SLUG="folderfolio"
DIST_DIR="dist"
STAGE_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}.zip"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Always start from a clean distribution folder: no artifact from a previous
# build may survive into this one.
rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

bash "${HERE}/stage-plugin.sh" "${STAGE_DIR}"

(
  cd "${DIST_DIR}"
  zip -qr "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
)

rm -rf "${STAGE_DIR}"

# Development files must never reach a user-facing archive.
FORBIDDEN='(^|/)(node_modules|vendor|tests|\.git|\.github|assets/src|src/|phpstan|phpunit|playwright|vite\.config|tsconfig|package(-lock)?\.json|yarn\.lock|\.yarnrc|\.yarn/|composer\.(json|lock)|Makefile|\.mise|var/|coverage)'

# `zipinfo -1` prints one name per line, whole. This used to read the fourth
# column of `unzip -l`, which is the name only until the name has a space in
# it — then the check saw the first word and missed the rest (review item #29).
if zipinfo -1 "${ZIP_FILE}" | grep -Eq "${FORBIDDEN}"; then
  echo "FATAL: development files found in ${ZIP_FILE}:" >&2
  zipinfo -1 "${ZIP_FILE}" | grep -E "${FORBIDDEN}" >&2
  exit 1
fi

echo "Created ${ZIP_FILE}"
unzip -l "${ZIP_FILE}"
