#!/usr/bin/env bash
# Tests for .claude/hooks/precompact-handoff.sh.
#
# The hook's headline contract is that it NEVER blocks compaction — so "exit 0" is asserted on every
# path, including the ones where it fails to do its job. The rest verifies the two things that were
# actually wrong in the upstream hook this was adapted from: `jq -Rs` JSON-encoding its own output
# (newlines arriving as literal \n), and harness turns being reported as "recent user intent".
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SUT="${SCRIPT_DIR}/../precompact-handoff.sh"

PASS=0
FAIL=0
FAILURES=()

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  C_GREEN=$'\033[32m'
  C_RED=$'\033[31m'
  C_BOLD=$'\033[1m'
  C_RESET=$'\033[0m'
else
  C_GREEN='' C_RED='' C_BOLD='' C_RESET=''
fi

ok() {
  PASS=$((PASS + 1))
  printf '  %b✓%b  %s\n' "${C_GREEN}" "${C_RESET}" "$1"
}
ko() {
  FAIL=$((FAIL + 1))
  FAILURES+=("$1")
  printf '  %b✗%b  %s\n' "${C_RED}" "${C_RESET}" "$1"
}

# A throwaway git repo standing in for the project tree.
make_repo() {
  local root
  root="$(mktemp -d)"
  git -C "${root}" init -q 2>/dev/null
  git -C "${root}" config user.email t@example.com
  git -C "${root}" config user.name Tester
  printf 'seed\n' >"${root}/README.md"
  git -C "${root}" add README.md
  git -C "${root}" commit -qm 'seed commit' 2>/dev/null
  printf '%s\n' "${root}"
}

# A transcript in the JSONL shape Claude Code writes. Deliberately mixes real developer turns with
# every kind of harness turn that must NOT be reported as intent.
make_transcript() {
  local f="$1"
  {
    printf '%s\n' '{"type":"user","message":{"content":"first real request"}}'
    printf '%s\n' '{"type":"user","message":{"content":"<system-reminder>ignore me</system-reminder>"}}'
    printf '%s\n' '{"type":"user","message":{"content":"/lint"}}'
    printf '%s\n' '{"type":"user","message":{"content":"Continue from where you left off"}}'
    printf '%s\n' '{"type":"assistant","message":{"content":[{"type":"text","text":"working on it"}]}}'
    printf '%s\n' '{"type":"user","message":{"content":[{"type":"text","text":"second real request"}]}}'
    printf '%s\n' '{"type":"user","message":{"content":"second real request"}}'
    printf '%s\n' '{"type":"queue-operation","operation":"enqueue","content":"queued mid-turn message"}'
    printf '%s\n' '{"type":"queue-operation","operation":"remove","content":"queued mid-turn message"}'
    printf '%s\n' 'not json at all — must not crash the parser'
    printf '%s\n' '{"type":"assistant","message":{"content":[{"type":"text","text":"final assistant word"}]}}'
  } >"${f}"
}

run_hook() { # run_hook <payload-json> [env assignments...]
  printf '%s' "$1" | env "${@:2}" bash "${SUT}" 2>/dev/null
}

printf '\n%b── precompact-handoff.sh ──%b\n' "${C_BOLD}" "${C_RESET}"

# ── Case 0: static checks ───────────────────────────────────────────────────
bash -n "${SUT}" 2>/dev/null && ok "syntax: bash -n clean" || ko "syntax: bash -n failed"
grep -q 'exit 0' "${SUT}" && ok "contract: script contains explicit exit 0" || ko "contract: no exit 0 found"
grep -q 'set -uo pipefail' "${SUT}" && ok "contract: no -e (an aborting shell is the failure mode)" \
  || ko "contract: expected 'set -uo pipefail' without -e"

# ── Case 1: full happy path ─────────────────────────────────────────────────
ROOT="$(make_repo)"
TRANSCRIPT="${ROOT}/transcript.jsonl"
make_transcript "${TRANSCRIPT}"
printf 'uncommitted\n' >"${ROOT}/dirty.txt"
mkdir -p "${ROOT}/tools/errors" "${ROOT}/tools/successes"
: >"${ROOT}/tools/errors/03node24"
: >"${ROOT}/tools/successes/00base"
PAYLOAD="$(jq -nc --arg t "${TRANSCRIPT}" --arg c "${ROOT}" '{transcript_path:$t,cwd:$c,session_id:"sess-abc"}')"
run_hook "${PAYLOAD}"
rc=$?
[[ $rc -eq 0 ]] && ok "happy path: exit 0" || ko "happy path: exit $rc (want 0)"

HANDOFF_DIR="${ROOT}/var/claude/handoff"
LATEST="${HANDOFF_DIR}/latest.md"
[[ -f "${LATEST}" ]] && ok "happy path: latest.md written" || ko "happy path: latest.md missing"
n_archive=$(find "${HANDOFF_DIR}" -maxdepth 1 -name 'handoff-*.md' -type f 2>/dev/null | wc -l | tr -d ' ')
[[ "${n_archive}" == "1" ]] && ok "happy path: one timestamped archive written" \
  || ko "happy path: expected 1 archive, found ${n_archive}"

if [[ -f "${LATEST}" ]]; then
  BODY="$(cat "${LATEST}")"

  # Git state
  grep -q 'sess-abc' <<<"${BODY}" && ok "git: session id recorded" || ko "git: session id missing"
  grep -q 'seed commit' <<<"${BODY}" && ok "git: recent commits included" || ko "git: commits missing"
  grep -q 'dirty.txt' <<<"${BODY}" && ok "git: uncommitted path listed (the work at risk)" \
    || ko "git: uncommitted path missing"

  # Stack health — the /stack-specific block
  grep -q '03node24' <<<"${BODY}" && ok "stack: error token listed" || ko "stack: error token missing"
  grep -qE '\*\*1 error\(s\)\*\*' <<<"${BODY}" && ok "stack: error count reported" \
    || ko "stack: error count missing"

  # Intent extraction
  grep -q 'first real request' <<<"${BODY}" && ok "intent: real user turn captured" \
    || ko "intent: real user turn missing"
  grep -q 'queued mid-turn message' <<<"${BODY}" && ok "intent: queue-operation enqueue captured" \
    || ko "intent: queued message missing"
  [[ "$(grep -c 'queued mid-turn message' <<<"${BODY}")" == "1" ]] \
    && ok "intent: queue 'remove' twin not double-counted" || ko "intent: queued message duplicated"
  [[ "$(grep -c 'second real request' <<<"${BODY}")" == "1" ]] \
    && ok "intent: consecutive duplicate turns de-duplicated" || ko "intent: duplicate not collapsed"

  # Noise filtering — the upstream bug
  grep -q 'ignore me' <<<"${BODY}" && ko "noise: <system-reminder> leaked into intent" \
    || ok "noise: <system-reminder> filtered out"
  grep -qE '^[0-9]+\. /lint$' <<<"${BODY}" && ko "noise: bare slash-command leaked into intent" \
    || ok "noise: bare slash-command filtered out"
  grep -q 'Continue from where you left off' <<<"${BODY}" \
    && ko "noise: resume prompt leaked into intent" || ok "noise: resume prompt filtered out"

  # jq -Rrs, not -Rs: newlines must be real, not literal backslash-n
  grep -q '\\n' <<<"${BODY}" && ko "encoding: literal \\n present (jq -Rs bug)" \
    || ok "encoding: no literal \\n (jq -Rrs used)"

  # Assistant tail
  grep -q 'final assistant word' <<<"${BODY}" && ok "assistant: last message captured" \
    || ko "assistant: last message missing"
  grep -q 'working on it' <<<"${BODY}" && ko "assistant: only the LAST message should appear" \
    || ok "assistant: earlier messages not included"

  # Resume pointers + LLM opt-out
  grep -q 'master' <<<"${BODY}" && ok "resume: branch policy stated" || ko "resume: branch policy missing"
  grep -q 'LLM narrative' <<<"${BODY}" && ko "llm: narrative present without GS_HANDOFF_LLM=1" \
    || ok "llm: no narrative by default (deterministic)"

  # latest.md must be a faithful copy of the archive
  ARCHIVE="$(find "${HANDOFF_DIR}" -maxdepth 1 -name 'handoff-*.md' -type f | head -1)"
  diff -q "${ARCHIVE}" "${LATEST}" >/dev/null 2>&1 && ok "latest.md is byte-identical to the archive" \
    || ko "latest.md diverges from the archive"
else
  ko "happy path: no latest.md — 20 dependent assertions skipped"
fi
rm -rf "${ROOT}"

# ── Case 2: missing transcript → git-only handoff, still exit 0 ─────────────
ROOT="$(make_repo)"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{transcript_path:"/nonexistent/path.jsonl",cwd:$c,session_id:"s2"}')"
run_hook "${PAYLOAD}"
rc=$?
[[ $rc -eq 0 ]] && ok "missing transcript: exit 0" || ko "missing transcript: exit $rc"
grep -q 'No user messages recovered' "${ROOT}/var/claude/handoff/latest.md" 2>/dev/null \
  && ok "missing transcript: says so explicitly, still writes git state" \
  || ko "missing transcript: expected the 'no user messages' note"
rm -rf "${ROOT}"

# ── Case 3: empty stdin → exit 0, falls back to CLAUDE_PROJECT_DIR ──────────
ROOT="$(make_repo)"
printf '' | env CLAUDE_PROJECT_DIR="${ROOT}" bash "${SUT}" >/dev/null 2>&1
rc=$?
[[ $rc -eq 0 ]] && ok "empty stdin: exit 0" || ko "empty stdin: exit $rc"
[[ -f "${ROOT}/var/claude/handoff/latest.md" ]] \
  && ok "empty stdin: CLAUDE_PROJECT_DIR fallback used for cwd" \
  || ko "empty stdin: no handoff written to the fallback cwd"
rm -rf "${ROOT}"

# ── Case 4: malformed JSON payload → exit 0 ────────────────────────────────
ROOT="$(make_repo)"
printf '%s' 'this is not json' | env CLAUDE_PROJECT_DIR="${ROOT}" bash "${SUT}" >/dev/null 2>&1
rc=$?
[[ $rc -eq 0 ]] && ok "malformed payload: exit 0" || ko "malformed payload: exit $rc"
rm -rf "${ROOT}"

# ── Case 5: GS_HANDOFF_DIR override ────────────────────────────────────────
ROOT="$(make_repo)"
ALT="$(mktemp -d)/custom-handoff"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{cwd:$c,session_id:"s5"}')"
run_hook "${PAYLOAD}" "GS_HANDOFF_DIR=${ALT}"
rc=$?
[[ $rc -eq 0 ]] && ok "GS_HANDOFF_DIR: exit 0" || ko "GS_HANDOFF_DIR: exit $rc"
[[ -f "${ALT}/latest.md" ]] && ok "GS_HANDOFF_DIR: honoured" || ko "GS_HANDOFF_DIR: ignored"
[[ ! -d "${ROOT}/var/claude/handoff" ]] && ok "GS_HANDOFF_DIR: default dir not also created" \
  || ko "GS_HANDOFF_DIR: default dir created anyway"
rm -rf "${ROOT}" "${ALT}"

# ── Case 6: non-git cwd → exit 0 and say so ────────────────────────────────
ROOT="$(mktemp -d)"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{cwd:$c,session_id:"s6"}')"
run_hook "${PAYLOAD}"
rc=$?
[[ $rc -eq 0 ]] && ok "non-git cwd: exit 0" || ko "non-git cwd: exit $rc"
grep -q 'Not a git work tree' "${ROOT}/var/claude/handoff/latest.md" 2>/dev/null \
  && ok "non-git cwd: reported, not crashed" || ko "non-git cwd: expected the not-a-work-tree note"
rm -rf "${ROOT}"

# ── Case 7: no tools/ markers → the clean-stack wording ────────────────────
ROOT="$(make_repo)"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{cwd:$c,session_id:"s7"}')"
run_hook "${PAYLOAD}"
grep -q 'none present' "${ROOT}/var/claude/handoff/latest.md" 2>/dev/null \
  && ok "no markers: 'none present' rather than a bogus zero-count" \
  || ko "no markers: expected the 'none present' wording"
rm -rf "${ROOT}"

# ── Case 7b: a MANUAL latest.md is preserved, on BOTH write paths ──────────
# `/handoff` marks a hand-written latest.md with `<!-- manual -->`. Without the guard the next
# compaction clobbers it, so following the documented ritual loses the note exactly when it matters.
ROOT="$(make_repo)"
mkdir -p "${ROOT}/var/claude/handoff"
printf '# My deliberate handoff\n\nDo not lose this.\n\n<!-- manual -->\n' \
  >"${ROOT}/var/claude/handoff/latest.md"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{cwd:$c,session_id:"manual"}')"
run_hook "${PAYLOAD}" "OBS_LOG=${ROOT}/obs.log"
rc=$?
[[ $rc -eq 0 ]] && ok "manual marker: exit 0" || ko "manual marker: exit $rc"
grep -q 'Do not lose this' "${ROOT}/var/claude/handoff/latest.md" 2>/dev/null \
  && ok "manual marker: latest.md preserved, not clobbered" \
  || ko "manual marker: latest.md was overwritten despite the marker"
n_arch=$(find "${ROOT}/var/claude/handoff" -maxdepth 1 -name 'handoff-*.md' -type f | wc -l | tr -d ' ')
[[ "${n_arch}" == "1" ]] && ok "manual marker: the auto handoff still lands in its own archive" \
  || ko "manual marker: expected 1 archive, found ${n_arch}"
grep -q 'manual — kept' "${ROOT}/obs.log" 2>/dev/null \
  && ok "manual marker: logged the decision to keep it" || ko "manual marker: no log line explaining the skip"
rm -rf "${ROOT}"

# The converse: WITHOUT the marker, latest.md must be refreshed (the default must stay the default).
ROOT="$(make_repo)"
mkdir -p "${ROOT}/var/claude/handoff"
printf '# stale auto handoff\n\nsupersede me\n' >"${ROOT}/var/claude/handoff/latest.md"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{cwd:$c,session_id:"auto"}')"
run_hook "${PAYLOAD}"
grep -q 'supersede me' "${ROOT}/var/claude/handoff/latest.md" 2>/dev/null \
  && ko "no marker: latest.md was NOT refreshed — the guard is too broad" \
  || ok "no marker: latest.md refreshed as normal (guard is marker-scoped)"
rm -rf "${ROOT}"

# ── Case 8: unwritable handoff dir → STILL exit 0 (the whole contract) ─────
# A parent that is a regular file makes mkdir -p fail even for root, unlike chmod 000.
BLOCKER="$(mktemp)"
ROOT="$(make_repo)"
PAYLOAD="$(jq -nc --arg c "${ROOT}" '{cwd:$c,session_id:"s8"}')"
run_hook "${PAYLOAD}" "GS_HANDOFF_DIR=${BLOCKER}/cannot/exist" "OBS_LOG=${ROOT}/obs.log"
rc=$?
[[ $rc -eq 0 ]] && ok "unwritable dir: exit 0 — compaction never blocked" || ko "unwritable dir: exit $rc"
grep -q 'mkdir failed' "${ROOT}/obs.log" 2>/dev/null \
  && ok "unwritable dir: logged a reason via log_obs" || ko "unwritable dir: no reason logged"
rm -rf "${ROOT}" "${BLOCKER}"

printf '\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n'
if [[ "${FAIL}" -eq 0 ]]; then
  printf '  %bALL PASSED%b   ✓ %d / %d\n' "${C_GREEN}" "${C_RESET}" "${PASS}" "$((PASS + FAIL))"
else
  printf '  %bFAILED%b        ✗ %d / %d\n' "${C_RED}" "${C_RESET}" "${FAIL}" "$((PASS + FAIL))"
  for f in "${FAILURES[@]}"; do printf '    - %s\n' "$f"; done
  exit 1
fi
