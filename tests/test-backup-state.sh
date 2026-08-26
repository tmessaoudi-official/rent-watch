#!/usr/bin/env bash
#
# Sabotage test FOR `tools/backup-state.sh`.
#
# The seen-set is the one file this project documents as UNRECOVERABLE: delete it and the next run
# re-notifies the entire market, and the price history cannot be rebuilt because a listing only ever
# advertises its CURRENT rent. The README has said *"back up `state/rent-watch.sqlite3`"* since Q8
# and has never said HOW — so the instruction was advice, not a procedure.
#
# Every guarantee here is one a naive `cp` would break, and each is checked because the failure is
# silent: a torn copy of a live SQLite database opens without complaint and reports a plausible row
# count, and a backup nobody read back is a file, not a backup.

set -uo pipefail

_pass=0
_fail=0

ok() {
  printf '  \033[32mok\033[0m   %s\n' "$1"
  _pass=$((_pass + 1))
}
no() {
  printf '  \033[31mFAIL\033[0m %s\n' "$1"
  _fail=$((_fail + 1))
}

check() { # check <description> <expected-exit> <cmd...>
  local desc="$1" want="$2"
  shift 2
  local out
  out="$("$@" 2>&1)"
  local got=$?
  if [[ "$got" == "$want" ]]; then ok "$desc"; else no "$desc (exit $got, wanted $want)
        $out"; fi
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tool="$root/tools/backup-state.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

printf '\n== does the state backup actually back state up? ==\n\n'

if [[ ! -x "$tool" ]]; then
  no "tools/backup-state.sh exists and is executable"
  printf '\n  %d passed, %d failed\n\n' "$_pass" "$_fail"
  exit 1
fi

db="$tmp/rent-watch.sqlite3"
sqlite3 "$db" "CREATE TABLE listings (dedup_key TEXT PRIMARY KEY, rent_cc INT);
               INSERT INTO listings VALUES ('a', 1200), ('b', 950);
               PRAGMA journal_mode=WAL;" >/dev/null

# ── the happy path ───────────────────────────────────────────────────────────────────────────────

out="$("$tool" "$db" "$tmp/backups" 2>&1)"
rc=$?
if [[ $rc -eq 0 ]]; then ok "a backup of a healthy database succeeds"; else no "a backup of a healthy database succeeds (exit $rc)
        $out"; fi

made="$(find "$tmp/backups" -name '*.sqlite3' | wc -l)"
if [[ "$made" == 1 ]]; then ok "it writes exactly one backup file"; else no "it writes exactly one backup file (found $made)"; fi

# THE POINT OF THE WHOLE TOOL. A backup that cannot be opened and read back is a file, and a torn
# copy of a live SQLite database is exactly that: it opens, and it lies.
copy="$(find "$tmp/backups" -name '*.sqlite3' | head -1)"
rows="$(sqlite3 "$copy" "SELECT COUNT(*) FROM listings;" 2>&1)"
if [[ "$rows" == 2 ]]; then ok "the copy READS BACK with every row present"; else no "the copy reads back (got '$rows')"; fi

integrity="$(sqlite3 "$copy" "PRAGMA integrity_check;" 2>&1)"
if [[ "$integrity" == "ok" ]]; then ok "the copy passes SQLite's own integrity check"; else no "integrity check (got '$integrity')"; fi

# ── the refusals, which are the half a `cp` one-liner does not have ──────────────────────────────

check "a missing database is a LOUD refusal, never an empty backup" 1 "$tool" "$tmp/nope.sqlite3" "$tmp/backups"

# A directory that is not writable must fail rather than report success having written nothing —
# the cron-job failure mode, where nobody reads the output and the absence is discovered at restore.
mkdir -p "$tmp/ro" && chmod 500 "$tmp/ro"
if [[ "$(id -u)" == 0 ]]; then
  ok "an unwritable destination refuses (skipped: running as root, which cannot be denied)"
else
  check "an unwritable destination is a LOUD refusal" 1 "$tool" "$db" "$tmp/ro/sub"
fi
chmod 700 "$tmp/ro"

# ── retention ────────────────────────────────────────────────────────────────────────────────────
#
# Unbounded backups fill the VPS disk and take the seen-set down with them — the same reasoning that
# bounds the container's json-file logging in `compose.yaml`.

# EIGHT RUNS INSIDE ONE SECOND, deliberately: the first draft of this loop slept between runs and
# asserted `<= 7`, which was satisfied by **2** — every backup had collided on a second-granularity
# filename and overwritten the last, and the weak assertion hid it. Assert the exact number, and
# make the runs simultaneous enough that a collision cannot pass.
for _ in 1 2 3 4 5 6 7 8; do "$tool" "$db" "$tmp/backups" >/dev/null 2>&1; done
kept="$(find "$tmp/backups" -name '*.sqlite3' | wc -l)"
if [[ "$kept" == 7 ]]; then
  ok "retention keeps exactly 7 of 9, and same-second runs do not collide"
else
  no "retention keeps exactly 7 (kept $kept — a lower number means filenames collided)"
fi

# And it prunes the OLDEST, not an arbitrary one — a retention that keeps the oldest N is worse than
# none, because the copy you want after a bad migration is the most recent good one.
newest="$(find "$tmp/backups" -name '*.sqlite3' -printf '%T@ %p\n' | sort -rn | head -1 | cut -d' ' -f2-)"
if [[ -f "$newest" ]]; then ok "the most recent backup is among those kept"; else no "the most recent backup survived pruning"; fi

printf '\n  %d passed, %d failed\n\n' "$_pass" "$_fail"
[[ "$_fail" -eq 0 ]]
