#!/usr/bin/env bash
#
# Build a clean, WordPress.org-ready plugin ZIP.
#
# Installs the dependencies (Strauss then copies the runtime ones, namespace-prefixed, into
# vendor-prefixed/), copies the plugin into a staging directory without every path listed in
# .distignore (dev tooling, tests, the unprefixed vendor/), then zips it so the archive contains
# a single top-level `qbitflow-for-woocommerce/` folder.
#
# Usage: ./build.sh
set -euo pipefail

SLUG="qbitflow-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${ROOT}/build"
STAGE_DIR="${BUILD_DIR}/${SLUG}"
ZIP_PATH="${BUILD_DIR}/${SLUG}.zip"

# Strauss copies into vendor-prefixed/ but never removes a file: start from an empty one, so a
# file the bundled packages no longer have is not shipped.
rm -rf "${ROOT}/vendor-prefixed"
( cd "${ROOT}" && composer install --no-interaction --no-progress --quiet )

if [ ! -f "${ROOT}/vendor-prefixed/autoload.php" ]; then
  echo "vendor-prefixed/autoload.php is missing: Strauss did not run" >&2
  exit 1
fi

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE_DIR}"

# rsync honours .distignore as the single source of truth for exclusions.
rsync -a --exclude='build/' --exclude-from="${ROOT}/.distignore" \
  "${ROOT}/" "${STAGE_DIR}/"

# Every shipped PHP file must parse on the minimum PHP the plugin supports.
find "${STAGE_DIR}" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

( cd "${BUILD_DIR}" && zip -rq "${ZIP_PATH}" "${SLUG}" )

echo "Built ${ZIP_PATH}"
