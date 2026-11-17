#!/usr/bin/env bash
# Patch Studio — run every check: build (+ size budget) → node unit tests → PHP pricing vectors → php -l → e2e.
#
# Usage: tests/run-all.sh [--no-e2e] [--fail-fast] [e2e-filter…]
#   --no-e2e      skip the browser scenarios (also: BPS_SKIP_E2E=1)
#   --fail-fast   stop at the first failing step
#   e2e-filter    passed to tests/e2e/run-e2e.sh (e.g. "03 05")
# Exit code = exit code of the first failing step (0 = everything green). Steps after a failure still run
# (unless --fail-fast) so one run shows the whole picture.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

RUN_E2E=1; FAIL_FAST=0; E2E_ARGS=()
for a in "$@"; do
	case "$a" in
		--no-e2e) RUN_E2E=0 ;;
		--fail-fast) FAIL_FAST=1 ;;
		*) E2E_ARGS+=("$a") ;;
	esac
done
[[ "${BPS_SKIP_E2E:-0}" == 1 ]] && RUN_E2E=0

FIRST_FAIL=0
declare -a REPORT=()
step() { # step <name> <command…>
	local name="$1"; shift
	printf '\n\033[1;36m━━ %s ━━\033[0m\n' "$name"
	local start=$SECONDS
	"$@"
	local code=$?
	local dur=$(( SECONDS - start ))
	if [[ $code -eq 0 ]]; then REPORT+=("PASS  $name (${dur}s)")
	else
		REPORT+=("FAIL  $name (${dur}s, exit $code)")
		[[ $FIRST_FAIL -eq 0 ]] && FIRST_FAIL=$code
		if [[ $FAIL_FAST -eq 1 ]]; then finish; fi
	fi
	return 0
}
finish() {
	printf '\n\033[1;36m━━ summary ━━\033[0m\n'
	printf '  %s\n' "${REPORT[@]}"
	exit "$FIRST_FAIL"
}

ensure_deps() {
	if [[ ! -d node_modules/esbuild ]]; then npm install --no-audit --no-fund; else return 0; fi
}

php_lint() {
	local bad=0 n=0 f out
	while IFS= read -r -d '' f; do
		n=$((n + 1))
		out="$(php -l "$f" 2>&1)" || { echo "$out"; bad=$((bad + 1)); }
	done < <(find plugin tests -name '*.php' -not -path '*/node_modules/*' -not -path '*/vendor/*' -print0)
	echo "php -l: $n files, $bad with errors"
	[[ $bad -eq 0 ]]
}

shell_lint() {
	local f bad=0
	for f in tests/run-all.sh tests/e2e/*.sh; do bash -n "$f" || bad=1; done
	for f in tests/e2e/*.cjs tests/e2e/lib/*.cjs tests/e2e/fixtures/*.cjs; do node --check "$f" || bad=1; done
	[[ $bad -eq 0 ]] && echo "bash -n / node --check: ok"
	[[ $bad -eq 0 ]]
}

step "npm dependencies"            ensure_deps
step "build + size budget"         npm run --silent build
step "standalone demo (dist/)"     node scripts/build-demo.mjs
step "unit tests (node --test)"    npm test --silent
step "pricing vectors (PHP)"       npm run --silent test:php
step "php -l"                      php_lint
step "test scripts syntax"         shell_lint
if [[ $RUN_E2E -eq 1 ]]; then
	step "e2e (WordPress + WooCommerce)" bash tests/e2e/run-e2e.sh "${E2E_ARGS[@]}"
else
	REPORT+=("SKIP  e2e (--no-e2e)")
fi
finish
