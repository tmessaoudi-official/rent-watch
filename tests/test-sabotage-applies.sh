#!/usr/bin/env bash
#
# Does every sabotage expression actually CHANGE the file it targets?
#
# A sed that matches nothing is not a passing case — it is a case that reports coverage it does not
# have, which is strictly worse than one that fails: a failure asks to be looked at, and this asks
# for nothing. The ledger scores such a case `unapplied`, in the same breath as `undetected`, and a
# 4.8-hour run is the only thing that surfaces it.
#
# It has happened twice in one day, both times for the same reason — a rename or an edit landing on
# the exact line a sabotage targets verbatim:
#
#   * `the detail gate stops narrowing`      -> `$detailGate` became `$detailPriority`
#   * `the field map's regex capture is ignored` -> the `return` line it matched was restructured
#
# This runs in about a second and needs no PHP, so there is no excuse for learning it from a nightly.
# Run it after ANY change to a file the ledger targets.
set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ledger="$repo/tests/sabotage-check.sh"

if [[ ! -f "$ledger" ]]; then
  printf 'FAIL: %s not found\n' "$ledger" >&2
  exit 1
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

total=0
inert=0

# Redefined here rather than sourced: this checks the EXPRESSIONS, not the suite, so it must not
# copy a tree or run a test. Same argument order as the real one, so the two cannot drift apart
# without this failing loudly.
run_sabotage() {
  local label="$1" target="$2"
  shift 2
  total=$((total + 1))

  if [[ ! -f "$repo/$target" ]]; then
    printf '  \033[31mMISSING\033[0m %s\n           -> %s\n' "$label" "$target"
    inert=$((inert + 1))
    return
  fi

  cp "$repo/$target" "$tmp/f"
  cp "$tmp/f" "$tmp/orig"

  local expression
  for expression in "$@"; do
    sed -i "$expression" "$tmp/f" 2>/dev/null
  done

  if cmp -s "$tmp/orig" "$tmp/f"; then
    printf '  \033[31mINERT\033[0m   %s\n           -> %s\n' "$label" "$target"
    inert=$((inert + 1))
  fi
}

# Only the invocations, and whole ones: a run_sabotage call spans as many continued lines as it
# needs, so the block ends at the first line NOT ending in a backslash.
awk '/^run_sabotage / { inblock = 1 }
     inblock { print; if ($0 !~ /\\$/) inblock = 0 }' "$ledger" > "$tmp/calls.sh"

printf '\n== do the sabotage expressions still apply? ==\n\n'

# shellcheck source=/dev/null
source "$tmp/calls.sh"

if (( total == 0 )); then
  printf '  \033[31mFAIL\033[0m no expressions found — the ledger format changed\n\n'
  exit 1
fi

if (( inert > 0 )); then
  printf '\n  \033[31m%d of %d expression(s) match NOTHING\033[0m — they report coverage they do not have.\n' "$inert" "$total"
  printf '  Retarget each one at a guarantee that still exists. Do not delete it.\n\n'
  exit 1
fi

printf '  \033[32mok\033[0m   all %d expressions still apply\n\n' "$total"
