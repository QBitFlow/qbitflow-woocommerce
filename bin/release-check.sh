#!/usr/bin/env bash
#
# The release gate: run it before every release (and before tagging). Every stage must pass; it
# stops at the first failure and exits non-zero, with a summary of what ran.
#
#   1. composer validate (and the dependencies installed)
#   2. PHP lint of every shipped file on the minimum PHP ("Requires PHP" of the plugin header), in Docker
#   3. unit suite
#   4. wp-env started if needed, then the integration suite
#   5. ./build.sh (the ZIP)
#   6. ZIP checks: one top-level folder, no dev files (composer.json ships, the lock doesn't), vendor-prefixed/ holds no file its installed
#      package no longer has, the shipped PHP linted on the minimum PHP, one version everywhere
#   7. Plugin Check on the built plugin (in wp-env's tests-cli; plugin-check activated around it)
#
# Usage: bin/release-check.sh      (from any directory; also `composer release-check`)
#
# Logs of each stage: printed, and kept in a temporary directory named at the end.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
SLUG="qbitflow-for-woocommerce"

for arg in "$@"; do
  case "$arg" in
    -h|--help) sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "release-check: unknown argument: $arg (see --help)" >&2; exit 2 ;;
  esac
done

LOGS="$(mktemp -d "${TMPDIR:-/tmp}/qbitflow-release-check.XXXXXX")"
STAGES=()
RESULTS=()
FAILED=0

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

# stage <name> <function>: runs the function, its output shown and logged; records the result
# line the function leaves in $LOGS/<n>.result (or "ok").
stage() {
  local name="$1" fn="$2" n="${#STAGES[@]}" started rc
  STAGES+=("$name")
  if [ "$FAILED" = 1 ]; then
    RESULTS+=("not run")
    return 0
  fi
  say "$((n + 1)). $name"
  started=$(date +%s)
  RESULT_FILE="$LOGS/$n.result"
  : > "$RESULT_FILE"
  "$fn" 2>&1 | tee "$LOGS/$n.log"
  rc=${PIPESTATUS[0]}
  local took=$(( $(date +%s) - started ))
  local detail
  detail="$(cat "$RESULT_FILE")"
  if [ "$rc" = 0 ]; then
    RESULTS+=("PASS (${took}s)${detail:+  $detail}")
  else
    RESULTS+=("FAIL (${took}s)${detail:+  $detail}  log: $LOGS/$n.log")
    FAILED=1
  fi
}

result() { printf '%s' "$*" > "$RESULT_FILE"; }

summary() {
  printf '\n\033[1m==> Release check summary\033[0m (%s)\n' "$SLUG $(plugin_version)"
  local i
  for i in "${!STAGES[@]}"; do
    printf '  %-48s %s\n' "$((i + 1)). ${STAGES[$i]}" "${RESULTS[$i]}"
  done
  if [ "$FAILED" = 1 ]; then
    printf '\nRELEASE CHECK FAILED. Logs: %s\n' "$LOGS"
  else
    printf '\nRelease check passed. Logs: %s\n' "$LOGS"
  fi
}

plugin_version() { sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]'; }
min_php() { sed -n 's/^ \* Requires PHP:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]'; }

# The last PHPUnit summary line ("OK (50 tests, 120 assertions)", "Tests: 311, Assertions: …").
phpunit_counts() { grep -E '^(OK \(|OK, but|Tests: [0-9]+)' "$1" | tail -n 1; }

wp_env() { npx --yes @wordpress/env "$@"; }
tests_cli() { wp_env run tests-cli --env-cwd="wp-content/plugins/$SLUG" "$@"; }

# --- Prerequisites, checked before anything long runs ---------------------------------------------

prereq_fail() {
  printf 'release-check: %s\n' "$*" >&2
  exit 1
}

say "Prerequisites"
for tool in composer php docker npx rsync zip unzip; do
  command -v "$tool" >/dev/null 2>&1 || prereq_fail "'$tool' is not installed."
done
docker info >/dev/null 2>&1 || prereq_fail "the Docker daemon is not answering: start Docker (wp-env and the PHP $(min_php) lint need it)."
[ -n "$(min_php)" ] || prereq_fail "no 'Requires PHP' in $SLUG.php."
echo "ok: $SLUG $(plugin_version), minimum PHP $(min_php)"

# --- Stages --------------------------------------------------------------------------------------

composer_stage() {
  composer validate --strict --no-interaction || return 1
  composer install --no-interaction --no-progress || return 1
  result "composer.json valid, dependencies installed"
}

# The files the ZIP ships, as build.sh copies them (.distignore applied).
stage_shipped() {
  local dest="$1"
  rm -rf "$dest" && mkdir -p "$dest"
  rsync -a --exclude='build/' --exclude-from="$ROOT/.distignore" "$ROOT/" "$dest/"
}

# Lints every PHP file under a directory with the minimum PHP (the official php:<min>-cli image;
# one file per `php -l`, which checks only its first file before PHP 8.3). Sets LINTED.
lint_dir() {
  local dir="$1"
  LINTED="$(find "$dir" -name '*.php' | wc -l | tr -d ' ')"
  [ "$LINTED" -gt 0 ] || { echo "no PHP file under $dir" >&2; return 1; }
  docker run --rm -v "$dir:/src:ro" "php:$(min_php)-cli" sh -c \
    'php -v | head -n 1; find /src -name "*.php" -print0 | xargs -0 -n 1 -P 4 php -n -l > /tmp/lint.out 2>&1; rc=$?; grep -v "^No syntax errors detected" /tmp/lint.out; exit $rc'
}

lint_stage() {
  [ -f vendor-prefixed/autoload.php ] || { echo "vendor-prefixed/ missing" >&2; return 1; }
  stage_shipped "$LOGS/shipped"
  lint_dir "$LOGS/shipped" || return 1
  result "$LINTED files, PHP $(min_php)"
}

unit_stage() {
  vendor/bin/phpunit -c phpunit.xml.dist | tee "$LOGS/unit.out"
  local rc=${PIPESTATUS[0]}
  result "$(phpunit_counts "$LOGS/unit.out")"
  return "$rc"
}

integration_stage() {
  if ! tests_cli true >/dev/null 2>&1; then
    echo "wp-env is not running: starting it"
    wp_env start || return 1
  fi
  tests_cli vendor/bin/phpunit -c phpunit-integration.xml.dist | tee "$LOGS/integration.out"
  local rc=${PIPESTATUS[0]}
  result "$(phpunit_counts "$LOGS/integration.out")"
  return "$rc"
}

build_stage() {
  ./build.sh || return 1
  [ -f "build/$SLUG.zip" ] || { echo "build/$SLUG.zip was not made" >&2; return 1; }
  result "build/$SLUG.zip ($(du -h "build/$SLUG.zip" | cut -f1 | tr -d ' '))"
}

zip_stage() {
  local zip="build/$SLUG.zip" problems=() list tops
  list="$(unzip -Z1 "$zip")"

  tops="$(printf '%s\n' "$list" | cut -d/ -f1 | sort -u)"
  [ "$tops" = "$SLUG" ] || problems+=("top-level entries other than $SLUG/: $(printf '%s' "$tops" | tr '\n' ' ')")

  # Dev files: anything .distignore lists, at the plugin's top level, and these anywhere.
  local name
  while IFS= read -r name; do
    case "$name" in ''|'#'*) continue ;; esac
    name="${name%/}"
    if printf '%s\n' "$list" | grep -qxE "$SLUG/$(printf '%s' "$name" | sed 's/[.[\*^$]/\\&/g')(/.*)?"; then
      problems+=("ships $name (listed in .distignore)")
    fi
  done < .distignore
  local bad
  bad="$(printf '%s\n' "$list" | grep -E "^$SLUG/(tests|vendor|bin|build|node_modules)/|^$SLUG/(composer\.lock|phpunit[^/]*|\.[^/]+)$|(^|/)(node_modules|\.git)(/|$)|(^|/)\.DS_Store$" || true)"
  [ -z "$bad" ] || problems+=("dev files: $(printf '%s' "$bad" | head -n 10 | tr '\n' ' ')")

  # vendor-prefixed/<vendor>/<package>/ holds only files the installed package (vendor/) has: a
  # file an older SDK had and the installed one dropped must not ship.
  local f rel
  while IFS= read -r f; do
    rel="${f#"$SLUG/vendor-prefixed/"}"
    case "$rel" in composer/*|autoload.php|*/) continue ;; esac
    [ -e "vendor/$rel" ] || problems+=("vendor-prefixed/$rel is not in the installed package (vendor/$rel)")
  done < <(printf '%s\n' "$list" | grep "^$SLUG/vendor-prefixed/")
  local sdk_files
  sdk_files="$(printf '%s\n' "$list" | grep -c "^$SLUG/vendor-prefixed/qbitflow/qbitflow-php/src/.*\.php$")"
  [ "$sdk_files" -gt 0 ] || problems+=("no QBitFlow SDK in vendor-prefixed/")

  # The shipped PHP parses on the minimum PHP (build.sh lints with the host's PHP).
  rm -rf "$LOGS/zip" && mkdir -p "$LOGS/zip" && unzip -q "$zip" -d "$LOGS/zip"
  lint_dir "$LOGS/zip/$SLUG" || problems+=("a shipped PHP file does not parse on PHP $(min_php)")

  # One version everywhere.
  local version stable constant
  version="$(plugin_version)"
  stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '[:space:]')"
  constant="$(sed -n "s/^define('QBITFLOW_WC_VERSION', '\([^']*\)');/\1/p" "$SLUG.php")"
  [ "$stable" = "$version" ] || problems+=("readme.txt Stable tag $stable, plugin header $version")
  [ "$constant" = "$version" ] || problems+=("QBITFLOW_WC_VERSION $constant, plugin header $version")
  grep -q "define('QBITFLOW_WC_VERSION', '$version')" uninstall.php || problems+=("uninstall.php's QBITFLOW_WC_VERSION is not $version")
  grep -q "^= $version =" readme.txt || problems+=("no '= $version =' in readme.txt's changelog")
  grep -q "^## \[$version\]" CHANGELOG.md || problems+=("no '## [$version]' in CHANGELOG.md")

  if [ "${#problems[@]}" -gt 0 ]; then
    printf 'ZIP check: %s\n' "${problems[@]}" >&2
    result "${#problems[@]} problem(s)"
    return 1
  fi
  echo "$(printf '%s\n' "$list" | grep -vc '/$') files in one $SLUG/ folder; no dev files; $sdk_files SDK files, all in the installed SDK; PHP $(min_php) lint ok; version $version everywhere"
  result "$(printf '%s\n' "$list" | grep -vc '/$') files, $sdk_files SDK files, version $version"
}

plugin_check_stage() {
  local out rc
  if ! tests_cli wp plugin is-installed plugin-check >/dev/null 2>&1; then
    tests_cli wp plugin install plugin-check || return 1
  fi
  tests_cli sh -c "rm -rf /tmp/pc && mkdir -p /tmp/pc && cp -r build/$SLUG /tmp/pc/" || return 1
  tests_cli wp plugin activate plugin-check || return 1
  tests_cli wp plugin check "/tmp/pc/$SLUG" > "$LOGS/plugin-check.out" 2>&1
  rc=$?
  tests_cli wp plugin deactivate plugin-check || rc=1
  tests_cli sh -c 'rm -rf /tmp/pc' >/dev/null 2>&1
  cat "$LOGS/plugin-check.out"
  if [ "$rc" != 0 ] || ! grep -q 'Success: Checks complete. No errors found.' "$LOGS/plugin-check.out" || grep -qE '(^|[[:space:]])(ERROR|WARNING)([[:space:]]|$)' "$LOGS/plugin-check.out"; then
    result "errors or warnings"
    return 1
  fi
  result "no errors, no warnings"
}

stage "composer validate + dependencies" composer_stage
stage "PHP $(min_php) lint of the shipped files" lint_stage
stage "unit suite" unit_stage
stage "wp-env + integration suite" integration_stage
stage "build (./build.sh)" build_stage
stage "ZIP contents" zip_stage
stage "Plugin Check (built plugin)" plugin_check_stage

summary
[ "$FAILED" = 0 ]
