#!/usr/bin/env bash
#
# Build the distributable plugin ZIP.
#
# Single packaging path: both `mise run dev:zip` and CI call this script, so a
# release artifact cannot differ from what a developer builds locally.
# Assets must already be built (mise's dev:zip depends on assets:build).

set -euo pipefail

PLUGIN_SLUG="folderfolio"
DIST_DIR="dist"
STAGE_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}.zip"

# Always start from a clean distribution folder: no artifact from a previous
# build may survive into this one.
rm -rf "${DIST_DIR}"
mkdir -p "${STAGE_DIR}"

# Runtime allowlist. Nothing is copied that is not named here.
cp "${PLUGIN_SLUG}.php" "${STAGE_DIR}/"
cp -R includes "${STAGE_DIR}/"

if [ -d assets/build ]; then
  mkdir -p "${STAGE_DIR}/assets"
  cp -R assets/build "${STAGE_DIR}/assets/"
  # Source maps are a development aid; they double the payload.
  find "${STAGE_DIR}/assets" -name '*.map' -delete
fi

if [ -d languages ]; then
  cp -R languages "${STAGE_DIR}/"
fi

for f in LICENSE README.md readme.txt; do
  if [ -f "$f" ]; then
    cp "$f" "${STAGE_DIR}/"
  fi
done

find "${STAGE_DIR}" -name '.DS_Store' -delete

# The activation fatal this project already hit once was a ZIP without the
# autoloader. Fail the build rather than ship that again.
if [ ! -f "${STAGE_DIR}/includes/Autoloader.php" ]; then
  echo "FATAL: includes/Autoloader.php missing from the staged plugin." >&2
  exit 1
fi

if [ ! -f "${STAGE_DIR}/includes/Plugin.php" ]; then
  echo "FATAL: includes/Plugin.php missing from the staged plugin." >&2
  exit 1
fi

(
  cd "${DIST_DIR}"
  zip -qr "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
)

rm -rf "${STAGE_DIR}"

# Development files must never reach a user-facing archive.
FORBIDDEN='(^|/)(node_modules|vendor|tests|\.git|\.github|assets/src|src/|phpstan|phpunit|playwright|vite\.config|tsconfig|package(-lock)?\.json|composer\.(json|lock)|Makefile|\.mise|var/|coverage)'

if unzip -l "${ZIP_FILE}" | awk '{print $4}' | grep -Eq "${FORBIDDEN}"; then
  echo "FATAL: development files found in ${ZIP_FILE}:" >&2
  unzip -l "${ZIP_FILE}" | awk '{print $4}' | grep -E "${FORBIDDEN}" >&2
  exit 1
fi

echo "Created ${ZIP_FILE}"
unzip -l "${ZIP_FILE}"
