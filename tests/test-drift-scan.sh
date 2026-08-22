#!/usr/bin/env bash
# test-drift-scan.sh — the drift gate's own self-test, scoped to S8 (`.env.example` sync).
#
# WHY THIS FILE EXISTS. `drift-scan.sh` runs in CI and fails the build on P0/P1, which makes it a
# gate; and its own preamble records the failure every gate here is prone to — an earlier version
# incremented counters inside a python heredoc, which is a subprocess, so it printed a P0 and still
# exited 0. "A gate reporting clean because it had been disabled" is not a hypothetical in this
# repo, it is a thing that happened, and no amount of reading the script catches it.
#
# So each S8 sub-check is exercised the only way that proves anything: BREAK the thing it watches,
# and require the scan to say so. A check that has never been seen red is a check nobody has tested.
#
# S8 is the scoped subject because it is new (2026-08-22) and because it caught two live defects on
# its first run — `IMAP_PORT` declared twice, and `RW_UID`/`RW_GID` read by compose.yaml while
# `.env.example` never listed them. The other sections predate it and have their own history.
#
# The repo is NEVER mutated. Every case runs against a scratch copy of the three files S8 reads,
# with `CLAUDE_PROJECT_DIR` pointed at it — the scan honours that variable, which is what makes an
# isolated run possible at all. `src/php` and `bin/scout` are symlinked read-only rather than
# copied: they are large, S8 only greps them, and a stale copy would silently test the wrong tree.
#
# Run: bash tests/test-drift-scan.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
scan="$repo/.claude/skills/rw-repair/drift-scan.sh"

work="$(mktemp -d)"
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed; refusing to continue\n' >&2; exit 1; }
trap 'rm -rf "$work"' EXIT

pass=0
fail=0
failed_labels=()

check() {
  local label="$1"; shift
  if "$@"; then
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
  fi
}

# A scratch project S8 can be pointed at. Only what S8 reads is materialised; every other section
# of the scan simply finds nothing there and stays quiet, which is why the assertions below match
# on S8's own message text rather than on the P0/P1 tally.
scratch_project() {
  local dir="$1"
  mkdir -p "$dir"
  cp "$repo/.env.example" "$dir/.env.example"
  cp "$repo/compose.yaml" "$dir/compose.yaml"
  ln -s "$repo/src" "$dir/src"
  mkdir -p "$dir/bin" && ln -s "$repo/bin/scout" "$dir/bin/scout"
  # Not read by S8 — present so that NO OTHER section crashes here. S1 opens it unguarded, and its
  # absence made the scratch project emit "checked NOTHING" on every run, which silently satisfied
  # the crash assertion below even with S8's entire body deleted. That check was then asserting
  # "some guard fires somewhere", which is not what it claims. Verified by re-running the gutting
  # sabotage: with this file present, deleting S8 makes the crash case fail as it should.
  mkdir -p "$dir/.claude" && cp "$repo/.claude/settings.json" "$dir/.claude/settings.json"
}

# S8's findings only. The scan's other sections are not under test here and would otherwise make
# every assertion depend on the whole repo staying clean.
#
# The output is CAPTURED rather than piped, and the capture tolerates a non-zero exit. That is not a
# swallowed error: `drift-scan` exits 1 **by design** whenever it finds a P0/P1, which is exactly
# what every case below is engineered to make it do. Written as a pipeline first, and all four
# mutation cases reported FAIL while the scan was working perfectly — `set -o pipefail` takes the
# leftmost non-zero status, so the scan's deliberate 1 outranked grep's successful 0. The clean case
# passed throughout, because there the scan exits 0, which is what made the harness look right.
scan_s8() {
  local out
  out="$(CLAUDE_PROJECT_DIR="$1" bash "$scan" --quiet 2>&1)" || true
  grep -E '^P[012] .*(env\.example|compose\.yaml)' <<<"$out"
}

fires_with() {
  local dir="$work/case-$1"; shift
  local pattern="$1"; shift
  local found
  scratch_project "$dir"
  "$@" "$dir"
  found="$(scan_s8 "$dir")" || true
  grep -qE -- "$pattern" <<<"$found"
}

# True when S8 says NOTHING matching $2 for the scratch project at $1 — the counterweight cases.
silent_about() {
  local found
  found="$(scan_s8 "$1")" || true
  ! grep -qE -- "$2" <<<"$found"
}

printf '\n== drift-scan self-test (S8: .env.example sync) ==\n\n'

# ── the gate must be QUIET on a correct tree ─────────────────────────────────────────────────────
# First, because every case below asserts a message appears; if the scan reported something on a
# clean tree, all of them would pass while proving nothing.
clean="$work/case-clean"
scratch_project "$clean"
check "S8 is silent on an unmodified tree (so the cases below mean something)" \
  test -z "$(scan_s8 "$clean")"

# ── (a) duplicate keys: env_file takes the LAST occurrence ───────────────────────────────────────
# The live defect. Editing a key where it belongs silently does nothing when a second copy sits
# below it, and the resulting value is plausible, so nothing errors and nobody looks.
check "a duplicated key is reported (env_file takes the LAST occurrence)" \
  fires_with dup 'declares TZ 2 times' \
  bash -c 'printf "TZ=UTC\n" >> "$1/.env.example"' _

# ── (b) a getenv() the template does not declare ─────────────────────────────────────────────────
check "a setting read by getenv() and missing from the template is reported" \
  fires_with getenv 'HEARTBEAT_HOURS is read by getenv' \
  bash -c 'sed -i "/^HEARTBEAT_HOURS=/d" "$1/.env.example"' _

# ── (c) a ${VAR} compose substitutes that the template does not declare ──────────────────────────
# Its own direction because a compose substitution has NO getenv() to find it by: Compose resolves
# it before the container exists. This is the half that hid RW_UID/RW_GID.
check "a compose substitution missing from the template is reported" \
  fires_with compose 'compose.yaml substitutes \$\{RW_UID\}' \
  bash -c 'sed -i "/^RW_UID=/d" "$1/.env.example"' _

# ── (d) a template key nothing reads ─────────────────────────────────────────────────────────────
check "a template key no code reads is reported" \
  fires_with orphan 'declares FOO_LEFTOVER and no code reads it' \
  bash -c 'printf "FOO_LEFTOVER=x\n" >> "$1/.env.example"' _

# ── the allowlists must still SUPPRESS, or the gate cries wolf ───────────────────────────────────
# The other half of (d), and the reason the allowlist is named DECLARED_UNREAD rather than
# AHEAD_OF_FEATURE: "unsupported on purpose" and "not built yet" are different facts. The commented
# TELEGRAM_* keys exist precisely BECAUSE Q9 does not support that channel — filling them in must
# fail loudly rather than silently do nothing. A gate that reports them every run gets ignored
# within a week, which is the argument drift-scan's own preamble makes about itself.
check "a key named in DECLARED_UNREAD is NOT reported (the gate must not cry wolf)" \
  silent_about "$clean" 'TELEGRAM'

# RENT_WATCH_OFFLINE is read by src/ and deliberately absent from the template: it is the test seam
# that makes CurlHttpClient refuse third-party hosts, so listing it as a setting would invite an
# operator to set it and silently disable every source while health stayed plausible.
check "RENT_WATCH_OFFLINE is NOT demanded of the template (it is a test seam)" \
  silent_about "$clean" 'RENT_WATCH_OFFLINE is read by getenv'

# ── the section must actually RUN ────────────────────────────────────────────────────────────────
# The failure this whole file exists for. A python section that throws writes nothing to the
# findings file, and under `set -uo pipefail` with no `-e` the tally then counts zero — clean, and
# disabled. drift-scan guards it by checking `$?` after the heredoc; this proves the guard fires,
# by making the section crash on unreadable input.
crash="$work/case-crash"
scratch_project "$crash"
# UNREADABLE `.env.example`, not a broken `src` symlink. The symlink was the first attempt and it
# was a false pass: it crashes several sections, so the check went green even with S8's whole body
# deleted — it was asserting that SOME section's guard fires, which is not what it claims. Nothing
# else in the scan reads `.env.example` (verified: the only other occurrence is S8's own heading),
# so this input can crash S8 alone. Skipped under root, where a mode of 000 is not a barrier.
chmod 000 "$crash/.env.example"
if (( EUID == 0 )); then
  printf '  \033[33mskip\033[0m a crashing S8 reports P0 (running as root: mode 000 does not deny)\n'
else
  check "a crashing S8 reports P0 rather than reading as a clean section" \
    bash -c 'out="$(CLAUDE_PROJECT_DIR="'"$crash"'" bash "'"$scan"'" --quiet 2>&1)" || true; grep -q "checked NOTHING" <<<"$out"'
fi
chmod 644 "$crash/.env.example"

# ── the scan announces the section at all ────────────────────────────────────────────────────────
check "S8 announces itself in a non-quiet run (so a silent removal is visible)" \
  bash -c 'CLAUDE_PROJECT_DIR="'"$clean"'" bash "'"$scan"'" 2>&1 | grep -q "S8 .env.example"'

printf '\n  %d passed, %d failed\n' "$pass" "$fail"

if (( fail )); then
  printf '\n  failed:\n'
  for label in "${failed_labels[@]}"; do printf '    - %s\n' "$label"; done
fi

printf '\n'
[[ $fail -eq 0 ]]
exit $?
