#!/usr/bin/env bash
# Test suite for install.sh — the SessionStart bootstrap.
#
# The contract being pinned (developer ruling, 2026-08-06): **the repo is always the
# truth.** install.sh copies the three framework docs from the repo into ~/.claude
# UNCONDITIONALLY, every session, regardless of timestamps. The previous `cp -u`
# behaviour was timestamp-dependent and therefore nondeterministic: after a fresh
# clone every repo file is newer than the target, so it clobbered; after a hand-edit
# of the target it silently did nothing. Neither was chosen.
#
# The one thing unconditional copying must NOT do is destroy a pre-existing global
# framework with no way back — so a file that was there before this hook ever ran is
# snapshotted once to <name>.pre-bootstrap.bak, and that snapshot is never touched again.
#
# Run: bash scripts/claude-bootstrap/test-install.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$HERE/install.sh"
PASS=0; FAIL=0

ok()   { printf '  ok   — %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  FAIL — %s\n' "$1"; FAIL=$((FAIL+1)); }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1 (want '$3', got '$2')"; fi; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Run install.sh with HOME and the project dir redirected into the sandbox.
run_install() {
  HOME="$TMP/home" CLAUDE_PROJECT_DIR="$TMP/proj" bash "$SCRIPT" >"$TMP/out" 2>"$TMP/err"
  printf '%s' $?
}

reset_sandbox() {
  rm -rf "$TMP/home" "$TMP/proj"
  mkdir -p "$TMP/home" "$TMP/proj"
}

echo "install.sh — repo-is-truth contract"

# ── 1. Cold install: nothing at ~/.claude yet ────────────────────────────────────
reset_sandbox
rc="$(run_install)"
check "exit 0 on a cold install" "$rc" "0"
for f in CLAUDE.md THINKING.md BLAST-RADIUS.md; do
  [[ -f "$TMP/home/.claude/$f" ]] && ok "installed $f" || bad "did not install $f"
done
if diff -q "$HERE/CLAUDE-global.md" "$TMP/home/.claude/CLAUDE.md" >/dev/null 2>&1; then
  ok "CLAUDE.md content matches the repo's CLAUDE-global.md"
else
  bad "CLAUDE.md content does not match the repo copy"
fi
[[ -d "$TMP/proj/var/claude" ]] && ok "pre-creates var/claude/ in the project dir" \
                                || bad "did not create var/claude/"

# ── 2. THE REGRESSION THIS SUITE EXISTS FOR ─────────────────────────────────────
# A NEWER file at the target must still be overwritten by the repo copy. Under the old
# `cp -u` this silently did nothing, so a stale global framework survived forever and
# the repo was NOT the truth.
reset_sandbox
mkdir -p "$TMP/home/.claude"
printf 'STALE CONTENT THAT MUST BE REPLACED\n' > "$TMP/home/.claude/CLAUDE.md"
touch -d '2099-01-01' "$TMP/home/.claude/CLAUDE.md"     # far newer than the repo copy
run_install >/dev/null
if grep -q 'STALE CONTENT' "$TMP/home/.claude/CLAUDE.md"; then
  bad "repo copy did NOT overwrite a newer target — the repo is not the truth"
else
  ok "repo copy overwrites a NEWER target (repo is the truth)"
fi

# Converse: the replacement is the real repo content, not an empty/truncated file.
if diff -q "$HERE/CLAUDE-global.md" "$TMP/home/.claude/CLAUDE.md" >/dev/null 2>&1; then
  ok "the overwritten file is byte-identical to the repo copy"
else
  bad "overwrote the target but the result differs from the repo copy"
fi

# ── 3. A pre-existing foreign framework is snapshotted exactly once ─────────────
reset_sandbox
mkdir -p "$TMP/home/.claude"
printf 'THE DEVELOPER OWN HAND-MAINTAINED FRAMEWORK\n' > "$TMP/home/.claude/CLAUDE.md"
run_install >/dev/null
BAK="$TMP/home/.claude/CLAUDE.md.pre-bootstrap.bak"
if [[ -f "$BAK" ]] && grep -q 'HAND-MAINTAINED' "$BAK"; then
  ok "snapshots a pre-existing foreign CLAUDE.md to .pre-bootstrap.bak"
else
  bad "did not snapshot the pre-existing CLAUDE.md"
fi

# Second run must NOT re-snapshot: by now the target is our own repo copy, and
# overwriting the backup with it would destroy the only surviving original.
run_install >/dev/null
if grep -q 'HAND-MAINTAINED' "$BAK"; then
  ok "the snapshot survives a second run (not overwritten by our own copy)"
else
  bad "second run clobbered the snapshot — the original is lost"
fi

# THE CASE THAT ACTUALLY EXERCISES THE `! -e "$backup"` GUARD, and the one that matters
# most in practice: the MULTI-REPO scenario. All five sibling repos ship this same hook,
# so opening twes-in installs ITS CLAUDE-global.md over ours. On the next rent-watch
# session the target differs from our source again — and without the guard we would
# snapshot twes-in's copy on top of the developer's irreplaceable original.
# Found by sabotage-verification: removing the guard passed the assertion above, because
# `cmp -s` alone short-circuits when the target happens to equal our source.
printf 'A SIBLING REPO CLAUDE-global.md (e.g. twes-in)\n' > "$TMP/home/.claude/CLAUDE.md"
run_install >/dev/null
if grep -q 'HAND-MAINTAINED' "$BAK"; then
  ok "snapshot survives a sibling repo overwriting the target in between"
else
  bad "a sibling repo's copy replaced the original snapshot — the original is lost"
fi

# ── 4. No snapshot when there was nothing to lose ───────────────────────────────
reset_sandbox
run_install >/dev/null
if [[ -f "$TMP/home/.claude/CLAUDE.md.pre-bootstrap.bak" ]]; then
  bad "created a pointless .bak on a cold install"
else
  ok "no .bak on a cold install (nothing was at risk)"
fi

# ── 5. Idempotent: repeated runs are stable and quiet ──────────────────────────
reset_sandbox
run_install >/dev/null
sum1="$(cat "$TMP/home/.claude/CLAUDE.md" | cksum)"
rc="$(run_install)"
sum2="$(cat "$TMP/home/.claude/CLAUDE.md" | cksum)"
check "exit 0 on a repeat run" "$rc" "0"
check "content stable across runs" "$sum1" "$sum2"

# ── 6. ONE-DIRECTIONAL: never copies anything OUT of ~/.claude into the repo ────
# ~/.claude.json holds the oauth account, userID and machineID, and this repo is public.
reset_sandbox
printf '{"oauthAccount":"MUST-NEVER-BE-COPIED"}\n' > "$TMP/home/.claude.json"
mkdir -p "$TMP/home/.claude"
printf 'SECRET-SESSION-STATE\n' > "$TMP/home/.claude/.credentials.json"
run_install >/dev/null
if grep -rq 'MUST-NEVER-BE-COPIED\|SECRET-SESSION-STATE' "$TMP/proj" 2>/dev/null; then
  bad "copied data OUT of ~/.claude into the project tree"
else
  ok "nothing from ~/.claude was copied into the project tree"
fi
# Grep EXECUTABLE lines only. The header deliberately describes the copy-out block that
# must never return, so scanning the whole file matches its own warning — that false
# positive was the first thing this assertion caught, in itself.
if sed 's/#.*//' "$SCRIPT" | grep -qE 'cp .*(-R|-r).*\.claude|claude-bundle|force-with-lease'; then
  bad "install.sh contains a copy-out / bundle-publish pattern in executable code"
else
  ok "no copy-out or bundle-publish pattern in executable code"
fi

# ── 7. Not fatal when the project dir is unwritable ────────────────────────────
reset_sandbox
chmod 500 "$TMP/proj"
rc="$(run_install)"
chmod 700 "$TMP/proj"
check "exit 0 when var/claude cannot be created" "$rc" "0"

echo
echo "$PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
