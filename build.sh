#!/usr/bin/env bash
#
# Build a clean, WordPress.org-ready plugin ZIP.
#
# Copies the plugin into a staging directory, dropping every path listed in
# .distignore (dev tooling, directory assets, VCS cruft), then zips it so the
# archive contains a single top-level `qbitflow-for-woocommerce/` folder.
#
# Usage: ./build.sh
set -euo pipefail

SLUG="qbitflow-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${ROOT}/build"
STAGE_DIR="${BUILD_DIR}/${SLUG}"
ZIP_PATH="${BUILD_DIR}/${SLUG}.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE_DIR}"

# rsync honours .distignore as the single source of truth for exclusions.
rsync -a --exclude='build/' --exclude-from="${ROOT}/.distignore" \
  "${ROOT}/" "${STAGE_DIR}/"

( cd "${BUILD_DIR}" && zip -rq "${ZIP_PATH}" "${SLUG}" )

echo "Built ${ZIP_PATH}"
