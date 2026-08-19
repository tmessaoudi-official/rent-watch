#!/usr/bin/env bash
# test-ci-workflow.sh — the CI workflow's own self-test.
#
# Every other executable thing in this repo has one (test-tenure-guard.sh, test-fetch-phpunit.sh);
# the CI workflow is a claim surface too. This asserts
# the workflow exists, parses as YAML, and still wires the exact discipline CLAUDE.md says CI runs —
# so a step silently dropped from the workflow (the classic way "CI is green" stops meaning what it
# claims) fails a test rather than passing unnoticed.
#
# Grep-based structural checks, plus a YAML parse only when a parser is available (pyyaml locally;
# GitHub itself rejects invalid workflow YAML, so the parse is a convenience, not the guarantee).
#
# Run: bash tests/test-ci-workflow.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wf="$repo/.github/workflows/ci.yml"
sab="$repo/tests/sabotage-check.sh"

pass=0
fail=0

check() {
  local label="$1"; shift
  if "$@"; then
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
  fi
}

has() { grep -qF -- "$1" "$wf"; }

printf '\n== ci workflow self-test ==\n\n'

check "the workflow file exists" test -f "$wf"

# YAML parse — only if a parser is on hand. GitHub validates the syntax itself, so this is a local
# convenience that catches a broken edit before it is pushed.
if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' >/dev/null 2>&1; then
  check "the workflow is valid YAML" python3 -c "import yaml,sys; yaml.safe_load(open('$wf'))"
else
  printf '  \033[33mskip\033[0m the YAML parse (no python3+pyyaml here — GitHub validates syntax anyway)\n'
fi

# The toolchain this repo forces — a change to any of these is a change CI would silently stop honouring.
check "pins PHP 8.5"                         has "php-version: '8.5'"
check "generates the --dev autoloader"       has "composer dump-autoload --dev"
check "fetches the pinned runner"            has "tools/fetch-phpunit.sh"

# The discipline CLAUDE.md says CI enforces. Each is a step whose silent removal would make a green
# run mean less than it claims.
check "runs the PHPUnit suite"               has "tools/phpunit.phar"
check "runs the tenure §1 tripwire test"     has "tests/test-tenure-guard.sh"
check "runs the runner-fetch signature test" has "tests/test-fetch-phpunit.sh"
check "runs the config/doc drift scan"       has "drift-scan.sh"
check "runs the sabotage ledger"             has "tests/sabotage-check.sh"

# The sabotage ledger must NOT be on the per-push fast path — it re-runs the whole suite once per
# seeded break (~13 min). It belongs to schedule + dispatch only.
check "sabotage is gated to schedule/dispatch" \
  grep -q "github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'" "$wf"

# ── The sabotage ledger's baseline gate must be SATISFIABLE ──────────────────────────────────────
# sabotage-check.sh refuses to run unless the suite is green BEFORE any sabotage is applied. That
# gate is correct and load-bearing. But it invokes the runner with its own flag set, and a flag that
# reddens the suite ON ITS OWN turns the gate into an unconditional ABORT — the ledger then never
# reaches case 1, and the one check that proves the suite has teeth silently stops running.
#
# Not hypothetical: `--do-not-cache-result` disables the result cache, while phpunit.xml sets
# `executionOrder="defects"` (which needs it) and `failOnWarning="true"`. PHPUnit emitted a runner
# warning and exited 1 on a suite with zero failing tests. The nightly ledger failed 7/7 from the day
# it was added (2026-08-13) through 2026-08-19 without anyone noticing, because it is nightly-only.
#
# So this EXECUTES the gate's own command rather than banning a known-bad flag by name: a denylist of
# one would not have caught this flag before it was known, and will not catch the next one.
baseline_cmd="$(sed -n 's/.*&& \(php tools\/phpunit\.phar .*\) >\/dev\/null.*/\1/p' "$sab" | head -1)"

check "the ledger's baseline gate was found in sabotage-check.sh" test -n "$baseline_cmd"

if [[ -n "$baseline_cmd" ]]; then
  check "the ledger's baseline gate is satisfiable (its flags do not redden a green suite)" \
    bash -c "cd '$repo' && $baseline_cmd >/dev/null 2>&1"
fi

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
