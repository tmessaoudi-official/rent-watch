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

# Split a sed script into its individual commands, RESPECTING SED'S OWN SYNTAX.
#
# Splitting on `;` cannot work: this ledger's expressions routinely contain a semicolon INSIDE a
# pattern (`markNotified(...);$`), and a naive split produces fragments that are not commands. The
# first version of this checker refused to split in that case, which was safe and left exactly the
# rot it exists to find invisible.
#
# So the script is scanned the way sed reads it — `s`, a delimiter character, three unescaped
# delimiters, then flags — using PHP, which this project already requires to run at all. Anything
# the scanner does not fully understand yields NOTHING, and the caller then tests the script whole:
# a checker that guessed wrong would report false INERTs on valid cases, which is worse than the
# coverage it would buy.
split_sed_script() {
  php -r '
    $script = $argv[1];
    $out = [];
    $i = 0;
    $n = strlen($script);

    while ($i < $n) {
      while ($i < $n && ($script[$i] === " " || $script[$i] === ";")) { $i++; }
      if ($i >= $n) { break; }
      if ($script[$i] !== "s") { exit(0); }

      $start = $i;
      $i++;
      if ($i >= $n) { exit(0); }
      $delim = $script[$i];
      if (ctype_alnum($delim) || $delim === "\\") { exit(0); }
      $i++;

      for ($seen = 0; $seen < 2 && $i < $n; $i++) {
        if ($script[$i] === "\\") { $i++; continue; }
        if ($script[$i] === $delim) { $seen++; }
      }
      if ($seen < 2) { exit(0); }

      while ($i < $n && strpos("gGpi0123456789", $script[$i]) !== false) { $i++; }
      $out[] = substr($script, $start, $i - $start);
    }

    foreach ($out as $command) { echo $command, "\n"; }
  ' -- "$1"
}

# Redefined here rather than sourced: this checks the EXPRESSIONS, not the suite, so it must not
# copy a tree or run a test. Same argument order as the real one, so the two cannot drift apart
# without this failing loudly.
run_sabotage() {
  local label="$1" target="$2"
  shift 2

  if [[ ! -f "$repo/$target" ]]; then
    printf '  \033[31mMISSING\033[0m %s\n           -> %s\n' "$label" "$target"
    inert=$((inert + 1))
    return
  fi

  # EVERY EXPRESSION IS TESTED ON ITS OWN, and this used to apply them all to one copy and
  # compare once. That made a multi-expression case rot ONE EXPRESSION AT A TIME, invisibly:
  # a change to `markNotified()`'s signature voided the second half of the `--seed` case while
  # the first half still matched, so the file changed, the case reported `ok`, and the guarantee
  # it named — that seeding marks the survivor — was no longer being broken at all. Caught by
  # the author on 2026-08-24, in the checker written to catch exactly this.
  #
  # A single argument may itself be a compound sed script (`s%a%b%; s%c%d%`). Those are split
  # only when EVERY part looks like a sed command, so a `;` inside a pattern cannot corrupt a
  # case into false failures.
  local expression part parts
  for expression in "$@"; do
    parts=()
    mapfile -t parts < <(split_sed_script "$expression")
    (( ${#parts[@]} )) || parts=("$expression")

    for part in "${parts[@]}"; do
      total=$((total + 1))
      cp "$repo/$target" "$tmp/f"
      sed -i "$part" "$tmp/f" 2>/dev/null

      if cmp -s "$repo/$target" "$tmp/f"; then
        printf '  \033[31mINERT\033[0m   %s\n           -> %s :: %s\n' "$label" "$target" "${part# }"
        inert=$((inert + 1))
      fi
    done
  done
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
