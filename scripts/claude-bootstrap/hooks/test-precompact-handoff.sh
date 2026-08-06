#!/usr/bin/env bash
# Test suite for precompact-handoff.sh.
# The hook is a PreCompact hook: it must ALWAYS exit 0 (a non-zero exit would block compaction),
# and it must produce a deterministic handoff without any network/LLM call.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOK="$HERE/precompact-handoff.sh"
PASS=0
FAIL=0

ok()   { printf '  ok   — %s\n' "$1"; PASS=$((PASS + 1)); }
bad()  { printf '  FAIL — %s\n' "$1"; FAIL=$((FAIL + 1)); }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1 (want '$3', got '$2')"; fi; }

TMP=$(mktemp -d /tmp/test-precompact-XXXXXX)
trap 'rm -rf "$TMP"' EXIT

# ── A synthetic transcript in the real JSONL shape Claude Code writes ─────────────────
TRANSCRIPT="$TMP/transcript.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"start the claude bundle work"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"Importing the seven ruled skills."}]}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"now wire the precompact hook"}]}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"Writing the test first."}]}}'
  # Harness noise that is NOT the developer speaking — must never be reported as "user intent".
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"<local-command-caveat>Caveat: generated while running local commands.</local-command-caveat>"}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"<command-name>/compact</command-name> <command-message>compact</command-message>"}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"<local-command-stdout>Compacted </local-command-stdout>"}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"This session is being continued from a previous conversation that ran out of context. The summary below covers the earlier portion."}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"Continue from where you left off."}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"<system-reminder>a reminder, not the user</system-reminder>"}}'
  # Mid-turn messages (sent while a turn is running) are recorded as queue-operation entries, NOT as
  # type:"user" — and they are the most recent, highest-signal developer input. The enqueue/remove
  # pair must not produce a duplicate.
  printf '%s\n' '{"type":"queue-operation","operation":"enqueue","content":"mid turn steering message"}'
  printf '%s\n' '{"type":"queue-operation","operation":"remove","content":"mid turn steering message"}'
  # A bare slash-command echo is harness noise; a message that merely STARTS with a path is not.
  printf '%s\n' '{"type":"queue-operation","operation":"enqueue","content":"/compact"}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"/home/user/rent-watch is the repo root"}}'
} > "$TRANSCRIPT"

run_hook() { # $1 = stdin JSON ; sets RC and uses $TMP/out as the handoff dir
  printf '%s' "$1" | RENTWATCH_HANDOFF_DIR="$TMP/out" OBS_LOG="$TMP/obs.log" bash "$HOOK" >"$TMP/stdout" 2>"$TMP/stderr"
  RC=$?
}

echo "== precompact-handoff.sh =="
if [[ ! -f "$HOOK" ]]; then
  bad "hook exists at $HOOK"
  printf '\n%s passed, %s failed\n' "$PASS" "$FAIL"
  exit 1
fi
ok "hook exists"

check "bash -n parses" "$(bash -n "$HOOK" 2>&1 && echo clean)" "clean"

# ── 1. Happy path: a real transcript produces a handoff, exit 0 ───────────────────────
run_hook "{\"transcript_path\":\"$TRANSCRIPT\",\"cwd\":\"$PWD\",\"session_id\":\"testsess\"}"
check "exit 0 on the happy path" "$RC" "0"
HANDOFF="$TMP/out/latest.md"
if [[ -f "$HANDOFF" ]]; then ok "wrote $HANDOFF"; else bad "wrote $HANDOFF"; fi

if [[ -f "$HANDOFF" ]]; then
  for needle in "Git state" "Recent user intent" "now wire the precompact hook"; do
    if grep -qF "$needle" "$HANDOFF"; then ok "handoff contains '$needle'"; else bad "handoff contains '$needle'"; fi
  done
  # Determinism: the deterministic path must not depend on an LLM being reachable.
  if grep -qiF "auto-generated" "$HANDOFF"; then ok "handoff is marked auto-generated"; else bad "handoff is marked auto-generated"; fi
  # A timestamped archive copy must exist beside latest.md, so successive compactions do not clobber.
  if [[ $(find "$TMP/out" -name 'handoff-*.md' | wc -l) -ge 1 ]]; then
    ok "keeps a timestamped archive copy"
  else
    bad "keeps a timestamped archive copy"
  fi

  # Real newlines, not literal backslash-n — the note is read by a human (and by the next context).
  if grep -qF '\n' "$HANDOFF"; then
    bad "handoff contains literal \\n escapes instead of real newlines"
  else
    ok "no literal \\n escapes — real newlines"
  fi

  # Harness noise must be filtered out of "user intent"; only the developer's own words belong.
  for noise in "local-command-caveat" "command-name" "local-command-stdout" \
               "This session is being continued" "Continue from where you left off" "system-reminder"; do
    if grep -qF "$noise" "$HANDOFF"; then
      bad "harness noise leaked into the handoff: '$noise'"
    else
      ok "filtered harness noise: '$noise'"
    fi
  done

  # …while the developer's real messages survive that filter.
  if grep -qF "start the claude bundle work" "$HANDOFF"; then
    ok "real user messages survive the noise filter"
  else
    bad "real user messages survive the noise filter"
  fi

  # Mid-turn (queued) messages must be captured — they are the newest developer input.
  if grep -qF "mid turn steering message" "$HANDOFF"; then
    ok "captures mid-turn (queue-operation) messages"
  else
    bad "captures mid-turn (queue-operation) messages"
  fi
  if grep -qxF "5. /compact" "$HANDOFF" || grep -qF ". /compact" "$HANDOFF"; then
    bad "bare slash-command echo leaked into the handoff"
  else
    ok "filtered bare slash-command echoes"
  fi
  if grep -qF "/home/user/rent-watch is the repo root" "$HANDOFF"; then
    ok "a message starting with a path is NOT mistaken for a slash command"
  else
    bad "a message starting with a path is NOT mistaken for a slash command"
  fi
  # …exactly once: the enqueue/remove pair must not double up.
  if [[ $(grep -cF "mid turn steering message" "$HANDOFF") -eq 1 ]]; then
    ok "mid-turn message appears exactly once (enqueue/remove not doubled)"
  else
    bad "mid-turn message appears $(grep -cF "mid turn steering message" "$HANDOFF") times, want 1"
  fi
fi

# ── 2. The hook must never call out to the network/LLM unless explicitly opted in ─────
if grep -qE '^[^#]*claude[[:space:]]+-p' "$HOOK"; then
  if grep -q 'RENTWATCH_HANDOFF_LLM' "$HOOK"; then
    ok "any LLM call is gated behind RENTWATCH_HANDOFF_LLM"
  else
    bad "an ungated 'claude -p' call would burn the developer's quota at every compaction"
  fi
else
  ok "no LLM call in the default path"
fi

# ── 3. Degenerate inputs must still exit 0 (never block compaction) ──────────────────
run_hook '{"transcript_path":"/nonexistent/nope.jsonl","cwd":"'"$PWD"'","session_id":"x"}'
check "exit 0 when the transcript is missing" "$RC" "0"

run_hook ''
check "exit 0 on empty stdin" "$RC" "0"

run_hook 'not json at all'
check "exit 0 on malformed stdin" "$RC" "0"

# ── 4. It must never stage/commit, and must not write outside the handoff dir ────────
# The section heading used to claim both and assert only the first; the write-location claim was also
# FALSE (the optional LLM path mktemps under /tmp). Both are now asserted, and the /tmp use is
# acknowledged as the one allowed exception rather than silently contradicting the heading.
if grep -qE 'git (add|commit|push)' "$HOOK"; then
  bad "hook must never stage, commit or push"
else
  ok "hook never stages, commits or pushes"
fi

# Writes are confined to $HANDOFF_DIR, with one disclosed exception: the opt-in LLM narrative
# mktemps a prompt file under /tmp and removes it. Assert there is no OTHER outside write.
outside=$(grep -nE '^[^#]*>[[:space:]]*"?/(tmp|etc|usr|var/log)' "$HOOK" | grep -v mktemp || true)
if [[ -n "$outside" ]]; then
  bad "hook writes outside the handoff dir: $outside"
else
  ok "no writes outside the handoff dir (bar the disclosed mktemp prompt)"
fi

# ── 5. A hand-written latest.md marked `<!-- manual -->` must survive a compaction ───
# /handoff documents this marker as claiming latest.md. Until 2026-07-29 nothing implemented it, so
# following the documented ritual lost the note at the next auto-compaction.
rm -rf "$TMP/out"; mkdir -p "$TMP/out"
printf 'MY HAND-WRITTEN STATE\n<!-- manual -->\n' > "$TMP/out/latest.md"
run_hook "$(printf '{"transcript_path":"%s","cwd":"%s","session_id":"manual-test"}' "$TRANSCRIPT" "$TMP")"
check "exit 0 with a manual latest.md present" "$RC" "0"
if grep -q 'MY HAND-WRITTEN STATE' "$TMP/out/latest.md"; then
  ok "manual latest.md is preserved, not overwritten"
else
  bad "manual latest.md was CLOBBERED — the documented <!-- manual --> marker is inert"
fi
if ls "$TMP/out"/handoff-*.md >/dev/null 2>&1; then
  ok "auto handoff still written to its own archive file"
else
  bad "no archive copy written — the auto handoff was lost instead"
fi

# And the converse: without the marker, latest.md IS refreshed.
rm -rf "$TMP/out"; mkdir -p "$TMP/out"
printf 'stale auto content\n' > "$TMP/out/latest.md"
run_hook "$(printf '{"transcript_path":"%s","cwd":"%s","session_id":"auto-test"}' "$TRANSCRIPT" "$TMP")"
if grep -q 'stale auto content' "$TMP/out/latest.md"; then
  bad "unmarked latest.md was NOT refreshed — the guard is too broad"
else
  ok "unmarked latest.md is refreshed as before"
fi

# ── 6. The guard must hold on the OPT-IN LLM PATH TOO ────────────────────────────────
# This is the assertion whose absence let a real bug ship: the first guard protected only the default
# write, so with RENTWATCH_HANDOFF_LLM=1 a marked latest.md was still clobbered — and the log said "kept"
# two lines before destroying it. Grepping the source for the env var (as the old LLM assertion did)
# cannot catch that; the path has to be EXECUTED. A stub `claude` on PATH makes that cheap.
STUB="$TMP/stub"; mkdir -p "$STUB"
cat > "$STUB/claude" <<'STUBEOF'
#!/usr/bin/env bash
printf '{"result":"STUB NARRATIVE"}\n'
STUBEOF
chmod +x "$STUB/claude"

rm -rf "$TMP/out"; mkdir -p "$TMP/out"
printf 'MY HAND-WRITTEN STATE\n<!-- manual -->\n' > "$TMP/out/latest.md"
PATH="$STUB:$PATH" RENTWATCH_HANDOFF_LLM=1   run_hook "$(printf '{"transcript_path":"%s","cwd":"%s","session_id":"llm-manual"}' "$TRANSCRIPT" "$TMP")"
check "exit 0 on the LLM path with a manual latest.md" "$RC" "0"
if grep -q 'MY HAND-WRITTEN STATE' "$TMP/out/latest.md"; then
  ok "manual latest.md survives the LLM path too"
else
  bad "manual latest.md CLOBBERED by the LLM path — guard not honoured on both writes"
fi
if grep -q 'STUB NARRATIVE' "$TMP"/out/handoff-*.md 2>/dev/null; then
  ok "LLM narrative still appended to the archive"
else
  bad "LLM narrative missing from the archive — the narrative was lost, not just withheld"
fi

# Converse on the LLM path: unmarked latest.md must receive the narrative.
rm -rf "$TMP/out"; mkdir -p "$TMP/out"
PATH="$STUB:$PATH" RENTWATCH_HANDOFF_LLM=1   run_hook "$(printf '{"transcript_path":"%s","cwd":"%s","session_id":"llm-auto"}' "$TRANSCRIPT" "$TMP")"
if grep -q 'STUB NARRATIVE' "$TMP/out/latest.md" 2>/dev/null; then
  ok "unmarked latest.md receives the LLM narrative"
else
  bad "unmarked latest.md did not receive the narrative — guard too broad on the LLM path"
fi

printf '\n%s passed, %s failed\n' "$PASS" "$FAIL"
[[ $FAIL -eq 0 ]]
