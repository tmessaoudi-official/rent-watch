#!/usr/bin/env bash
#
# Does the sabotage ledger still judge its cases in a tree that is green to begin with?
#
# THIS EXISTS BECAUSE THE LEDGER WAS VACUOUS FOR A MONTH AND NOTHING SAID SO. From 2026-08-22 the
# scratch copy every sabotage is judged in omitted `.env.example`, which
# `DotEnvTest::testTheShippedTemplateParsesCleanly` reads through a repo-root constant. That single
# test failed in every scratch run, the ledger's detection assertion is `Failures: [1-9]`, and one
# failure satisfies it — so all ~375 cases reported `ok` whether or not the suite noticed anything,
# `fail` could never increment, the script always exited 0, and the nightly stayed green. Per
# CLAUDE.md a green nightly CLOSES every open ledger issue, so the broken gate was also retracting
# real alarms. A review panel found it on 2026-08-24 by rebuilding the scratch copy by hand; three
# in-range cases turned out to be genuinely undetected behind that unconditional `ok`.
#
# The ledger's own baseline could not catch it: that check runs in `$repo`, which is green, and says
# nothing about the throwaway tree. So the ledger gained a SECOND baseline over an unsabotaged
# scratch copy, and this file is the sabotage test for that guard — a gate nobody has seen go red is
# a gate nobody has tested.
#
# Two checks, and the first is the general one:
#
#   1. The two `cp -a` lists — the one in `run_sabotage()` and the one the scratch baseline uses —
#      must name exactly the same sources. If they can drift, the baseline proves the wrong tree is
#      green, which is the whole defect wearing a different file name.
#   2. Dropping `.env.example` from the baseline list must ABORT with a non-zero exit.
#
# SC2016 is deliberate throughout, and the directive sits ABOVE `set` because a file-scope one must
# precede the first COMMAND to apply to the whole file. (It also may not be introduced by a comment
# whose own first word is the tool's name — that parses as a second, malformed directive.) This
# script greps for the LITERAL characters `$repo` and `$work` as they appear in sabotage-check.sh's
# source; expanding them would search for this script's own paths, the opposite of the check.
# shellcheck disable=SC2016
set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ledger="$repo/tests/sabotage-check.sh"

pass=0
fail=0

ok() {
  printf '  \033[32mok\033[0m   %s\n' "$1"
  pass=$((pass + 1))
}
bad() {
  printf '  \033[31mFAIL\033[0m %s\n' "$1"
  fail=$((fail + 1))
}

printf '\n== is the sabotage ledger judged in a green scratch tree? ==\n\n'

if [[ ! -f "$ledger" ]]; then
  printf 'FAIL: %s not found\n' "$ledger" >&2
  exit 1
fi

# ── 1. the two copy lists must agree ──────────────────────────────────────────────────────────────
#
# Compared as SETS of `$repo/...` sources rather than as literal lines: the two differ legitimately
# in their destination (`$work/repo/` vs `$work/baseline/`), and pinning the whole line would fail
# on a rename that changed nothing that matters.
mapfile -t copy_lines < <(grep -n 'cp -a "\$repo/src"' "$ledger" | cut -d: -f1)

if ((${#copy_lines[@]} != 2)); then
  bad "expected exactly 2 scratch-copy sites in the ledger, found ${#copy_lines[@]} — if a third was added, this check must learn about it"
else
  sources_at() {
    sed -n "${1}p" "$ledger" | grep -oE '\$repo/[A-Za-z0-9._/-]+' | sort -u
  }
  if diff -q <(sources_at "${copy_lines[0]}") <(sources_at "${copy_lines[1]}") >/dev/null; then
    ok "the case copy list and the baseline copy list name the same sources"
  else
    bad "the two scratch-copy lists have DRIFTED — the baseline proves a different tree green than the one cases are judged in:"
    diff <(sources_at "${copy_lines[0]}") <(sources_at "${copy_lines[1]}") | sed 's/^/        /'
  fi
fi

# `.env.example` by name, because it is the one that actually broke and a set-equality check alone
# would stay green if BOTH lists lost it together.
if grep -q 'cp -a "\$repo/src".*\$repo/\.env\.example' "$ledger"; then
  ok ".env.example is in the scratch copy list (its absence made every case report ok)"
else
  bad ".env.example is NOT copied into the scratch tree — DotEnvTest fails there for no reason a sabotage caused, and every case reports ok unconditionally"
fi

# ── 2. the guard must actually fire ───────────────────────────────────────────────────────────────
#
# The broken copy is written into `tests/` deliberately: the ledger derives `$repo` from
# `BASH_SOURCE`, so a copy run from anywhere else computes the wrong repo root and aborts on the
# FIRST baseline — which looks like the guard firing and is not. Removed on exit, whatever happens.
broken="$repo/tests/.test-sabotage-baseline-probe.sh"
cleanup() { rm -f "$broken"; }
trap cleanup EXIT

sed 's| "$repo/.env.example" "$work/baseline/"| "$work/baseline/"|' "$ledger" >"$broken"

if cmp -s "$ledger" "$broken"; then
  bad "the probe changed nothing — the baseline copy line no longer matches the expected shape, so this check proves nothing"
else
  probe_out="$(SABOTAGE_FILTER='__no_such_case__' bash "$broken" 2>&1)"
  probe_rc=$?

  if [[ $probe_rc -ne 0 ]] && grep -q 'UNSABOTAGED scratch copy' <<<"$probe_out"; then
    ok "dropping .env.example from the baseline list ABORTS the ledger, non-zero"
  else
    bad "dropping .env.example did NOT abort the ledger (exit $probe_rc) — the guard is inert:"
    printf '        %s\n' "$(tail -4 <<<"$probe_out")"
  fi

  # And the diagnosis has to be the right one. Aborting on the FIRST baseline would also exit
  # non-zero while saying nothing about the copy list, and would leave the next reader fixing the
  # suite instead of the harness.
  if grep -q 'red BEFORE any sabotage' <<<"$probe_out"; then
    bad "the probe aborted on the REPO baseline, not the scratch one — this check did not exercise the guard it claims to"
  else
    ok "and it aborts on the SCRATCH baseline, naming the copy list rather than the suite"
  fi
fi

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"

if ((fail > 0)); then
  exit 1
fi
