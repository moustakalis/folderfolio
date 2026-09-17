#!/usr/bin/env bash
#
# Stage the plugin's runtime files into a directory.
#
# One allowlist, three callers: bin/build-zip.sh zips what this produces, the
# end-to-end suite mounts it into WordPress Playground, and `mise run dev:zip`
# reaches both through them. A second copy of the list would be a second thing
# to keep in step, and the one that drifted would be the one nobody ran.
#
# Mounting the staged plugin rather than the checkout is not only faster — the
# checkout is ~700MB of node_modules — it also means the suite exercises the
# same file set the ZIP ships. A packaging mistake shows up as a failing test
# rather than as a broken download.
#
# Usage: bin/stage-plugin.sh <target-dir>
#
# Assets must already be built. Everything under the target is replaced.

set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: $0 <target-dir>" >&2
  exit 1
fi

STAGE_DIR="$1"

rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"

# Runtime allowlist. Nothing is copied that is not named here.
cp folderfolio.php "${STAGE_DIR}/"
cp uninstall.php "${STAGE_DIR}/"
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
# autoloader. Fail here rather than ship that again — and, since the e2e suite
# stages through this script too, fail in a test run rather than in a release.
for required in includes/Autoloader.php includes/api.php includes/Plugin.php uninstall.php; do
  if [ ! -f "${STAGE_DIR}/${required}" ]; then
    echo "FATAL: ${required} missing from the staged plugin." >&2
    exit 1
  fi
done
