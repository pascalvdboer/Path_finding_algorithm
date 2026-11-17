#!/usr/bin/env bash
# Run the Patch Studio e2e scenarios against the local WordPress test site.
#
# Usage: tests/e2e/run-e2e.sh [filter…]      e.g. run-e2e.sh 03 05   (runs scenarios whose file name contains 03 or 05)
# Env:   BPS_E2E_NO_START=1   do not (re)start/configure the site (it must already run)
#        BPS_E2E_HEADED=1     show the browser
#        BPS_E2E_ARTIFACTS    folder for screenshots + JSON reports (default <wp-dir>/e2e-artifacts)
# Exit:  0 = all passed (skips allowed), 1 = at least one scenario failed, 2 = environment problem.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

say() { printf '\033[1;35m[e2e]\033[0m %s\n' "$*"; }

# --- Playwright + axe-core ---------------------------------------------------
if ! node -e "require('playwright')" >/dev/null 2>&1 \
	&& ! node -e "require('/opt/node-tools/node_modules/playwright')" >/dev/null 2>&1 \
	&& ! node -e "require('/opt/node22/lib/node_modules/playwright')" >/dev/null 2>&1; then
	say "Playwright not found (npm i -g playwright && npx playwright install chromium)"; exit 2
fi
if [[ ! -f node_modules/axe-core/axe.min.js ]]; then
	say "installing axe-core in tests/e2e (a11y checks)"
	npm install --no-audit --no-fund --silent >/dev/null 2>&1 || say "axe-core install failed: a11y checks will be skipped"
fi

# --- Site ---------------------------------------------------------------------
if [[ "${BPS_E2E_NO_START:-0}" != 1 ]]; then
	bash "$SCRIPT_DIR/start-wp.sh" start 2> >(grep -v -E 'Notice:|^$' >&2) || { say "start-wp.sh failed"; exit 2; }
fi
bash "$SCRIPT_DIR/start-wp.sh" status >/dev/null || { say "WordPress is not running"; exit 2; }

WP_DIR="$(bash "$SCRIPT_DIR/start-wp.sh" wp eval 'echo dirname( ABSPATH );' 2>/dev/null | tail -1)"
DEBUG_LOG="$WP_DIR/site/wp-content/debug.log"
ARTIFACTS="${BPS_E2E_ARTIFACTS:-$WP_DIR/e2e-artifacts}"
mkdir -p "$ARTIFACTS"
rm -f "$ARTIFACTS/state.json" "$ARTIFACTS/php-notices.txt"

# Fresh rate-limit window (all scenarios come from 127.0.0.1) and an empty object cache.
bash "$SCRIPT_DIR/start-wp.sh" wp transient delete --all >/dev/null 2>&1 || true
LOG_START=$( [[ -f "$DEBUG_LOG" ]] && wc -c <"$DEBUG_LOG" || echo 0 )

# --- Scenarios ------------------------------------------------------------------
mapfile -t ALL < <(ls -1 "$SCRIPT_DIR"/*.e2e.cjs | sort)
SELECTED=()
for f in "${ALL[@]}"; do
	if [[ $# -eq 0 ]]; then SELECTED+=("$f"); continue; fi
	for pat in "$@"; do [[ "$(basename "$f")" == *"$pat"* ]] && { SELECTED+=("$f"); break; }; done
done
[[ ${#SELECTED[@]} -gt 0 ]] || { say "no scenarios match: $*"; exit 2; }

declare -a SUMMARY=()
FAILED=0
for f in "${SELECTED[@]}"; do
	name="$(basename "$f" .e2e.cjs)"
	start=$(date +%s)
	BPS_E2E_ARTIFACTS="$ARTIFACTS" node "$f"
	code=$?
	dur=$(( $(date +%s) - start ))
	case $code in
		0)  SUMMARY+=("PASS  $name (${dur}s)") ;;
		77) SUMMARY+=("SKIP  $name (${dur}s)") ;;
		*)  SUMMARY+=("FAIL  $name (${dur}s, exit $code)"); FAILED=1 ;;
	esac
done

# --- PHP notices from the plugin during the run -----------------------------------
if [[ -f "$DEBUG_LOG" ]]; then
	NEW="$(tail -c +"$((LOG_START + 1))" "$DEBUG_LOG" | grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)|Uncaught' | grep -E 'borduur-patch-studio|BorduurPatchStudio' || true)"
	if [[ -n "$NEW" ]]; then
		SUMMARY+=("FAIL  php-debug-log (plugin notices during the run, see below)"); FAILED=1
		printf '%s\n' "$NEW" | sort | uniq -c | sort -rn | head -20 >"$ARTIFACTS/php-notices.txt"
	else
		SUMMARY+=("PASS  php-debug-log (no plugin notices)")
	fi
fi

echo
say "summary  (artifacts: $ARTIFACTS)"
printf '  %s\n' "${SUMMARY[@]}"
[[ -s "$ARTIFACTS/php-notices.txt" ]] && { echo; cat "$ARTIFACTS/php-notices.txt"; }
exit $FAILED
