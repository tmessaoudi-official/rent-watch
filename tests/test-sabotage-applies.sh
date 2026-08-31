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
whole=0
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

      $start = $i;

      // AN OPTIONAL LEADING ADDRESS. Three cases in this ledger are address-prefixed
      // (`/private function withDetail/,$ s%…%%`), and without this they parsed to nothing and fell
      // back to whole-script comparison — including the ONE genuinely compound case, whose second
      // command was therefore never checked on its own. That is the exact blindness this scanner
      // was written to remove, surviving in the three scripts it could not read.
      for ($piece = 0; $piece < 2; $piece++) {
        if ($i < $n && $script[$i] === "/") {
          for ($i++; $i < $n; $i++) {
            if ($script[$i] === "\\") { $i++; continue; }
            if ($script[$i] === "/") { $i++; break; }
          }
        } elseif ($i < $n && ($script[$i] === "$" || $script[$i] === "+" || ctype_digit($script[$i]))) {
          $i++;
          while ($i < $n && ctype_digit($script[$i])) { $i++; }
        } else {
          break;
        }

        if ($i < $n && $script[$i] === ",") { $i++; continue; }
        break;
      }

      while ($i < $n && $script[$i] === " ") { $i++; }

      if ($i >= $n || $script[$i] !== "s") { exit(0); }

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

    if (( ${#parts[@]} == 0 )); then
      # THE FALLBACK IS ANNOUNCED, because it restores exactly the blindness this checker was
      # rebuilt to remove: a script tested whole passes as soon as ANY of its commands still
      # matches, so the others may rot unseen. Harmless today — every compound script in the ledger
      # is a pure `s`-chain and is split — but an address (`/foo/d`), a line number (`1s%…%`) or an
      # `I`/`w` flag would silently revert that case to one-comparison checking while the summary
      # still read `ok, all N expressions still apply`. A coverage claim that cannot say where it
      # stopped is the shape of defect this whole file exists to catch.
      if [[ "$expression" == *';'* ]]; then
        printf '  \033[33mWHOLE\033[0m   %s\n           -> not a pure s-chain; tested as ONE script, so a rotted command inside it is invisible\n' "$label"
        whole=$((whole + 1))
      fi

      parts=("$expression")
    fi

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

if (( whole > 0 )); then
  printf '\n  \033[33m%d compound script(s) were tested whole\033[0m — see the WHOLE lines above.\n' "$whole"
fi

if (( inert > 0 )); then
  printf '\n  \033[31m%d of %d expression(s) match NOTHING\033[0m — they report coverage they do not have.\n' "$inert" "$total"
  printf '  Retarget each one at a guarantee that still exists. Do not delete it.\n\n'
  exit 1
fi

# ── AND THAT EVERY REPLACEMENT NAMES A METHOD THAT STILL EXISTS ──────────────────────────────────
# Round-6 panel, 2026-08-31, found by three lenses independently. A sabotage that CALLS a deleted
# method produces `Error: Call to undefined method` — which PHPUnit reports as an ERROR, which
# satisfies `run_sabotage`'s detection rule (`ERRORS!` + `Errors: [1-9]` + a real test count) just as
# a caught regression does. The case then reports `ok` for ever while covering nothing at all.
#
# `takeLastRefusal()` was renamed out of existence and its case went on reporting `ok` for a day.
# The check above cannot see it: the left-hand PATTERN still matched perfectly. This is the same rot
# class, on the other side of the `s%…%…%`.
dead=0
while read -r method; do
  [[ -z "$method" ]] && continue
  if ! grep -rqE "function ${method}\(" "$repo/src/php/"; then
    printf '  \033[31mDEAD\033[0m    a replacement calls $this->%s(), which no longer exists in src/\n' "$method"
    dead=$((dead + 1))
  fi
done < <(grep -oE '\$this->[a-zA-Z_][a-zA-Z0-9_]*\(' "$ledger" | sed 's/^\$this->//; s/($//' | tr -d '(' | sort -u)

if (( dead > 0 )); then
  printf '\n  \033[31m%d replacement(s) call a method that no longer exists\033[0m — they redden on an\n' "$dead"
  printf '  undefined-method ERROR, which the ledger accepts as a detection, so they certify NOTHING.\n'
  printf '  Retarget each one at the live API. Do not delete it.\n\n'
  exit 1
fi

printf '  \033[32mok\033[0m   all %d expressions still apply, and every replacement names a live method\n\n' "$total"
