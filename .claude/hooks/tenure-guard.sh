#!/usr/bin/env bash
# tenure-guard.sh — PostToolUse (Edit|Write)
#
# Enforces the one non-negotiable rule of this repo (CLAUDE.md §1):
#   `logement social` (PLAI, PLUS) must NEVER be surfaced as a match.
#
# This hook is a tripwire, not a gate. It cannot understand intent, so it never blocks
# a write. It runs PostToolUse and exits 2 when it fires, which feeds its stderr back
# to Claude in the same turn — so the change gets explained (or reverted) before it
# ships, rather than the warning scrolling past in a transcript nobody re-reads.
# It greps; it does not reason. A clean run proves nothing.
#
# Fires when a write to config/ or src/ looks like it is:
#   - adding PLAI / PLUS / social tenure to an *allowed* / *included* list
#   - lowering or removing the fail-closed confidence threshold (0.6)
#   - disabling the classifier, or making the excluded set user-overridable
#   - weakening a classifier test/fixture

set -uo pipefail

# Rule 13 observability (global CLAUDE.md, shipped by scripts/claude-bootstrap/install.sh): a hook
# that runs unattended logs state-changing actions and errors to var/claude/logs/ IN THE REPO. Sourced
# rather than reimplemented so there is one log format and one destination. Never fatal — a logging
# failure must not take down the hook that is logging, hence the `|| true` and the no-op fallback.
_HELPERS="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}/scripts/claude-bootstrap/hooks/log-helpers.sh"
# shellcheck disable=SC1090
[[ -f "$_HELPERS" ]] && source "$_HELPERS" 2>/dev/null || true
declare -F log_obs >/dev/null 2>&1 || log_obs() { :; }

payload="$(cat)"

py() { command -v python3 >/dev/null 2>&1 && python3 "$@"; }

read -r file_path new_text <<<"$(
  py -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("\t"); raise SystemExit
i = d.get("tool_input", {}) or {}
fp = i.get("file_path") or i.get("notebook_path") or ""
parts = [
    i.get("content") or "",
    i.get("new_string") or "",
    i.get("new_source") or "",
]
for e in (i.get("edits") or []):
    parts.append(e.get("new_string") or "")
blob = " ".join(parts).replace("\n", " ").replace("\t", " ")
print(f"{fp}\t{blob}")
' <<<"$payload" 2>/dev/null
)" || true

# Nothing parseable (non-Edit/Write shape, or no python3) — stay silent.
[[ -z "${file_path:-}" ]] && exit 0

# The guard's OWN test feeds it the exact payloads it exists to catch — an emptied excluded set, a
# lowered floor, PLAI on an allow-list. Scanning that file would make the tripwire fire on every
# edit to it, forever, which is the fastest way to teach a reader to ignore it. Excluded by exact
# path, which is narrow enough that it cannot become a hiding place: it is a shell script, the guard
# only ever reads src/, config/ and tests/, and drift-scan S6 audits the guard's patterns from the
# outside without consulting this file at all.
case "$file_path" in
  */tests/test-tenure-guard.sh) exit 0 ;;
esac

case "$file_path" in
  */config/*|*/src/*|*/tests/*|*config/*|*src/*|*tests/*) ;;
  *) exit 0 ;;
esac

blob="$(printf '%s' "${new_text:-}" | tr '[:upper:]' '[:lower:]')"
[[ -z "$blob" ]] && exit 0

hits=()

# 1. Social tenure appearing near an inclusion keyword.
# `pls` joined the alternation on 2026-08-06: the Q4 answer ruled PLS out of scope, so it is an
# excluded term like the rest, and drift-scan S6 now asserts this pattern covers it.
if grep -Eq '(allow|allowed|include|included|accept|accepted|in_scope|whitelist|enabled)[^.]{0,80}(plai|plus|pls|logement social|conventionn|anru|anah)' <<<"$blob" \
|| grep -Eq '(plai|plus|pls|logement social|conventionn|anru|anah)[^.]{0,80}(allow|allowed|include|included|accept|accepted|in_scope|whitelist|: *true)' <<<"$blob"; then
  hits+=("social tenure (PLAI/PLUS/PLS/conventionné/ANRU/ANAH) appears next to an inclusion keyword")
fi

# 2. Social tenure being removed from the excluded set.
#
# The empty-list alternatives are anchored to the SHAPES THAT ACTUALLY EMPTY SOMETHING, because a
# bare `[]` anywhere within 80 characters is not one of them in PHP: `$flat[] = …` is an append —
# the exact opposite of clearing — and `!== []` is a comparison. Both tripped the guard while the
# first PHP in this repo was being written.
#
# Anchoring to `= []` alone was NOT enough, and the first attempt at this comment wrongly claimed it
# lost no detection. A 2026-08-06 review showed `public static function excluded(): array { return
# []; }` — precisely how you would empty an excluded-set accessor in the language this repo now
# uses — going silent. `return []` and `=> []` are therefore listed explicitly, and the assignment
# form excludes a preceding `!`, `=`, `<` or `>` so comparisons stay quiet. Every shape here, and
# every shape that must NOT fire, has a case in tests/test-tenure-guard.sh.
if grep -Eq '(exclude|excluded|denied|blocked|forbidden|never)[^.]{0,80}(remove|delete|drop|pop|clear|[^!=<>] *= *\[\]|=> *\[\]|return *\[\]|= *none|: *\[\])' <<<"$blob"; then
  hits+=("the excluded-tenure set looks like it is being emptied or shrunk")
fi

# 3. Fail-closed confidence threshold being weakened.
if grep -Eq 'confidence[^.]{0,40}(0\.[0-5][0-9]?|< *0\.[0-5])' <<<"$blob"; then
  hits+=("classifier confidence threshold looks lowered below the 0.6 fail-closed floor")
fi

# 4. UNKNOWN being treated as notifiable.
if grep -Eq 'unknown[^.]{0,60}(notify|match|emit|send|alert)' <<<"$blob"; then
  hits+=("UNKNOWN tenure looks like it is being routed to notification instead of the \"à vérifier\" digest")
fi

# 5. Classifier turned off / bypassed.
if grep -Eq '(skip|bypass|disable|no)[_ -]?(tenure|classif)' <<<"$blob"; then
  hits+=("the tenure classifier looks like it is being skipped or disabled")
fi

# 6. Making the hard exclusions configurable.
if grep -Eq '(config|option|setting|toggle|flag|param)[^.]{0,60}(plai|plus|pls|logement social|allow_social|include_social)' <<<"$blob"; then
  hits+=("social tenure looks like it is becoming a config toggle — CLAUDE.md forbids this")
fi

# 7. Weakening classifier tests.
case "$file_path" in
  *tests*tenure*|*tenure*test*|*tests/fixtures*)
    if grep -Eq '(skip|xfail|expectedfailure|pytest\.mark\.skip|todo|# *disabled)' <<<"$blob"; then
      hits+=("a tenure test looks like it is being skipped or marked xfail — fix the classifier, not the test")
    fi
    ;;
esac

[[ ${#hits[@]} -eq 0 ]] && exit 0

# WARN, not INFO: this is the repo's one non-negotiable rule, and a firing here is the single most
# important line the log can carry — it must be greppable after the session is gone.
log_obs WARN tenure-guard "FIRED on $file_path — ${#hits[@]} signal(s): ${hits[*]}" || true
{
  echo "⛔ tenure-guard: this write may relax the non-negotiable social-housing rule."
  echo "   file: $file_path"
  for h in "${hits[@]}"; do
    echo "   • $h"
  done
  echo
  echo "   CLAUDE.md §1: PLAI/PLUS/conventionné/ANRU/ANAH must NEVER be surfaced as a match,"
  echo "   and that is not a config toggle. Confidence < 0.6 on a mixed-tenure source means"
  echo "   UNKNOWN → \"à vérifier\" digest, never a notification."
  echo
  echo "   If this change is intentional, say so explicitly to the user and explain why"
  echo "   before continuing. If it is not, revert it."
} >&2

# Exit 2 feeds the message above back to Claude rather than only to the transcript.
exit 2
