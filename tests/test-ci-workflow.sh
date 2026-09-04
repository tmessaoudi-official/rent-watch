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
# NO LONGER PURELY STRUCTURAL, as of 2026-08-19. The last block EXECUTES every runner invocation
# found in sabotage-check.sh, so this file now needs PHP, a fetched `tools/phpunit.phar` and a
# `--dev` autoloader to reach its final checks — and an unrelated red suite reddens this file too.
# Those checks SKIP (not fail) when the PHAR is absent, so a fresh clone still runs the rest. The
# reason for the change: the ledger's baseline gate reddened ITSELF for six days and no structural
# grep could have seen it, because every step was present and correctly spelled.
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
  # The path goes through argv, NOT interpolated into the Python source. Interpolating put the repo
  # path inside a Python string literal, so a checkout under a path containing a quote raised a
  # SyntaxError that this check reported as "the workflow is not valid YAML" — accusing the workflow
  # for a property of the directory it sits in. Found while proving the shell-side injection fix
  # below; same defect class, same remedy.
  check "the workflow is valid YAML" \
    python3 -c 'import yaml,sys; yaml.safe_load(open(sys.argv[1]))' "$wf"
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
check "runs the vehicle §1 tripwire test"    has "tests/test-vehicle-guard.sh"
check "runs the runner-fetch signature test" has "tests/test-fetch-phpunit.sh"
# The one line of `.env` loading PHPUnit cannot reach — it lives in `bin/scout` so the suite
# never loads a developer's real credentials. Uncovered by PHP, covered by that shell test,
# and worth nothing unless CI runs it.
check "runs the .env CLI test (bin/scout's loader is outside the PHP suite)" \
  has "tests/test-dotenv-cli.sh"
check "runs the config/doc drift scan"       has "drift-scan.sh"
# BOTH HALVES OF THE CAPTURE PATH, and the pair is the assertion. The scrubber decides what may be
# committed; `dump-eml.php` decides what may be written at all, and it writes RAW. Wiring one and
# not the other is the one-of-two-symmetric-surfaces shape, so both are pinned here rather than
# left to whoever remembers.
check "runs the fixture-scrubber test"       has "tests/test-scrub-eml.sh"
check "runs the raw-capture tool test"       has "tests/test-dump-eml.sh"
check "runs the deploy-verifier test"        has "tests/test-verify-deploy.sh"
# The twelfth shell gate, and the only one that was RUN by ci.yml without being pinned here — so a
# deletion from the workflow would have gone unnoticed by the very test whose subject is that the
# workflow still wires what this repo claims (C2 round 2). The seen-set is the one file this project
# calls unrecoverable; its backup tool's guard is not one to lose quietly.
check "runs the seen-set backup test"        has "tests/test-backup-state.sh"
check "runs the sabotage apply-sweep"       has "tests/test-sabotage-applies.sh"
# The gate that certified nothing for ~27 hours in 2026-08-22/23 — and would have gone on doing so
# indefinitely, since nothing in its own output says it has. Without this step it can go vacuous —
# every case reporting `ok` while proving nothing — and the only thing that would notice is a test
# CI does not run. That loop was self-sealing: this file pins ci.yml against what CLAUDE.md claims,
# and CLAUDE.md claimed nothing about that file, so the pin could never fire.
check "runs the ledger's scratch-baseline self-test" \
  has "tests/test-sabotage-baseline.sh"

# THE ALERT MUST CARRY ITS OWN EVIDENCE. Issue #3 (2026-08-24) named a run and nothing else, and
# that run's logs need admin rights to download — so the first question its reader asks, "what
# broke?", could not be answered by the person the alert was sent to. Recovering it meant re-running
# a ~5-hour ledger locally. Hard rule 2 says an alert computed and never sent is worse than none;
# this is the next step along that line — an alert delivered with no evidence still leaves its
# reader unable to act.
#
# Pinned by the MECHANISM as well as the heading, because a heading survives the body being gutted:
# the tee that captures the log, and the read that puts it in the issue.
check "the ledger's output is captured for the alert" has 'tee "$RUNNER_TEMP/ledger.log"'
check "and pipefail is set, so tee cannot mask a red ledger" has 'set -o pipefail'
check "the red-ledger issue names WHICH cases were not caught" has 'undetected or unapplied:'
check "and reads them from the captured log" has 'RUNNER_TEMP}/ledger.log'
check "runs the drift-scan self-test"        has "tests/test-drift-scan.sh"
check "runs the sabotage ledger"             has "tests/sabotage-check.sh"

# The sabotage ledger must NOT be on the per-push fast path — it re-runs the whole suite once per
# seeded break. It belongs to schedule + dispatch only. (Runtime is ~20-30 min on a runner and
# ~2h10m on a debug PHP build — see the measured note at the top of ci.yml. The "~13 min" that used
# to stand here was never measured against anything.)
check "sabotage is gated to schedule/dispatch" \
  grep -q "github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'" "$wf"

# ── BOTH halves of the ledger's notice path ──────────────────────────────────────────────────────
# Neither half had a check until 2026-08-22 — on the one step in this workflow that has ALREADY
# failed into the void. The ledger ran red 7/7 from 2026-08-13 to 2026-08-19 and notified nobody,
# which is why the notice step exists at all; it could have been deleted the next day and every
# check in this file would still have passed.
#
# Structural greps are the right instrument and not a compromise: both steps need a real GitHub
# runner and an `issues: write` token, so nothing local can execute them, and their failure mode is
# silent DELETION rather than misbehaviour. The step name alone would be defeated by gutting the
# body, so each half is pinned by its name AND by the API call that does its work.
check "a RED ledger opens an issue (hard rule 2: the alert must reach a human)" \
  has "A red ledger must reach a human"
check "…and that path really calls issues.create" \
  has "github.rest.issues.create("

# THE NOTICE MUST ALSO FIRE WHEN THE LEDGER IS KILLED BY ITS OWN BUDGET. On 2026-08-29 the nightly
# ran for exactly 90 minutes — the `timeout-minutes` then in force — and GitHub recorded the job as
# `cancelled`, not `failure`. `if: failure()` is false on a cancellation, so the notice step was
# SKIPPED and the timeout reached nobody: hard rule 2's shape inside the alerting job itself. The
# ledger had grown 258 -> 527 cases in ten days (75 min on the 28th, 87 min on the 27th, 90+ on the
# 29th), so this was a budget outgrown, not a hang — and a budget outgrown silently is worse than a
# hang, because a hang at least looks wrong. Stated cost of `cancelled()`: a manual cancel opens a
# spurious issue, which is this repo's stated bias — one beat too many, never one suppressed.
red_if_line="$(grep -n -A1 'A red ledger must reach a human' "$wf" | grep -m1 'if:' | sed 's/^[0-9]*-//')"
check "the red-ledger notice fires on a CANCELLED job too (a timeout is a cancellation)" \
  bash -c 'grep -qE "cancelled\(\)" <<<"$1"' _ "$red_if_line"

# And the budget itself: measured 75 / 87 / 90+ minutes on three consecutive nightlies, so a floor
# of 180 is what stops a "tidy-up" back to the old value from silently disabling the nightly again.
# Read from the sabotage JOB, positionally — the fast job's 15-minute budget must not satisfy this.
ledger_budget="$(awk '/^  sabotage:/{f=1} f && /timeout-minutes:/{print $2; exit}' "$wf")"
check "the ledger job's budget is at least 180 minutes (it ran 90 and was cut off on 2026-08-29)" \
  test "${ledger_budget:-0}" -ge 180

# The other direction, and the reason it was added: an alert nobody retracts becomes furniture, so
# the next real red lands on a board that already reads RED. Issues #1 and #2 stood open for days
# after the regression they reported was fixed and pushed.
check "a GREEN ledger retracts the alert it raised" \
  has "A green ledger must retract the alert it raised"
check "…and that path really closes the issue" \
  has "state: 'closed'"

# The backlog sweep. Matching today's date would close only the issue this run would have opened,
# leaving every older one for a human to close by hand — which is the work the step exists to
# remove. The prefix match is the behaviour, so it is what gets pinned.
check "the retraction closes EVERY open ledger issue, not just today's" \
  has "startsWith('sabotage ledger RED')"

# GitHub returns pull requests from the issues endpoint. Without this filter a PR whose title began
# with the same words would be closed by a green nightly.
check "the retraction does not mistake a pull request for an issue" \
  has "!i.pull_request"

# The retraction must live in the SABOTAGE job. In the fast job it would fire on every green push
# and close a legitimately-open RED issue while the nightly ledger was still failing — the fast job
# never runs the ledger at all, so its green says nothing about detection. Positional, like the
# tally check further down: the defect is a step in the wrong place, and both placements parse.
sabotage_job_line="$(grep -n '^  sabotage:' "$wf" | head -1 | cut -d: -f1)"
retract_line="$(grep -n 'A green ledger must retract' "$wf" | head -1 | cut -d: -f1)"

# Bound first, exactly as the tally check below is. Without this the `${x:-0}` fallbacks make the
# comparison pass VACUOUSLY when a grep misses: rename the `sabotage:` job and `0` stands in for it,
# so any positive line number is "after the job". A positional check that cannot find its landmarks
# must fail, not assume them.
check "both landmarks for the placement check were found" \
  bash -c 'test -n "$1" && test -n "$2"' _ "$sabotage_job_line" "$retract_line"

check "the retraction sits in the sabotage job, not the fast push job" \
  test "${retract_line:-0}" -gt "${sabotage_job_line:-0}"

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
# So this EXECUTES the invocations rather than banning a known-bad flag by name: a denylist of one
# would not have caught this flag before it was known, and will not catch the next one.
#
# EVERY invocation, not just the gate's. The first cut of this check extracted one command with
# `head -1`, which bound it to whichever matching line came FIRST in the file rather than to the
# gate — two reviewers independently defeated it by adding a redirect to the per-case run above,
# which silently stole the match while the real gate rotted. It also left the per-case invocation
# (sabotage-check.sh:81) unpinned, and a suite-reddening flag THERE would hit all 258 cases at once.
# Enumerating every call site removes both problems and the ordering dependency with them.
#
# The `[0-9]` strip is not cosmetic: the pattern stops at the first `>` or `)`, so `… 2>&1` leaves a
# dangling file-descriptor number on the extracted command. Verified against both current call sites.
mapfile -t runner_cmds < <(
  sed -n 's/.*\(php tools\/phpunit\.phar[^)>]*\).*/\1/p' "$sab" \
    | sed 's/[[:space:]][0-9]\{1,\}[[:space:]]*$//; s/[[:space:]]*$//'
)

# At least two: the per-case run and the baseline gate. A lower count means the extraction broke,
# which must FAIL rather than silently pin nothing — the failure mode this whole check exists for.
check "every runner invocation in sabotage-check.sh was found (>=2)" \
  test "${#runner_cmds[@]}" -ge 2

# Executing the suite needs the gitignored PHAR. SKIP rather than FAIL when it is absent: on a fresh
# clone the run would otherwise report "the flags redden a green suite", accusing the one thing that
# is not at fault. That is the mirror image of the error sabotage-check.sh:84-94 warns about at
# length, and it cost a real debugging detour before it was caught here.
if [[ ! -f "$repo/tools/phpunit.phar" ]]; then
  printf '  \033[33mskip\033[0m the runner-invocation checks (no tools/phpunit.phar — run tools/fetch-phpunit.sh)\n'
else
  for cmd in "${runner_cmds[@]}"; do
    # `eval` inside a subshell, NOT `bash -c "cd '$repo' && …"`. The interpolating form executed
    # arbitrary commands from the REPO PATH: a reviewer ran it from a directory named
    # `rw';touch INJECTED;'y` and the payload fired. Here the path never enters a parsed string.
    check "runner invocation does not redden a green suite: ${cmd#php tools/phpunit.phar }" \
      bash -c 'cd "$1" && eval "$2" >/dev/null 2>&1' _ "$repo" "$cmd"
  done
fi

# ── The ledger must COUNT every case it runs, and its exit status must reflect them ──────────────
# sabotage-check.sh prints a tally and then ends on the expression that becomes its exit status.
# Both are POSITIONAL, and both were defeated by appending cases to the end of the file: eight cases
# added on 2026-08-20 landed below the tally, so the ledger ran 303 cases and reported 295 — and
# because there is no `set -e`, those trailing calls ran on past the exit expression and left their
# own 0 as the script's status. A FAIL anywhere then exited 0, so the nightly job could not go red
# and the issue it opens could not be opened. Measured 2026-08-20: an unmatched pattern appended to
# the end printed `FAIL` and the script still exited 0.
#
# Structural on purpose. The defect is a matter of WHERE a line sits; executing the script from a
# green tree reports success either way, which is exactly how it survived a two-hour ledger run.
tally_line="$(grep -n 'sabotage(s) detected, %d undetected' "$sab" | head -1 | cut -d: -f1)"
last_case_line="$(grep -n '^run_sabotage ' "$sab" | tail -1 | cut -d: -f1)"

check "the ledger's tally line was found (the check below is bound to it)" \
  test -n "$tally_line"

check "every sabotage case is defined ABOVE the tally, so every one of them is counted" \
  test "${last_case_line:-0}" -lt "${tally_line:-0}"

# The exit status must be the last thing the script does. `[[ $fail -eq 0 ]]` as a trailing
# expression is correct only while nothing follows it; an explicit `exit` makes anything appended
# below it dead code — which the check above then reports, instead of the ledger absorbing it.
check "the ledger ends on an explicit exit, not a positional test expression" \
  test "$(grep -vE '^[[:space:]]*(#|$)' "$sab" | tail -1)" = 'exit 0'

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
