#!/usr/bin/env bash
# PreCompact hook — write a handoff note BEFORE the context is compacted.
#
# Why this exists: context compaction loses working state. Only committed
# repo state survives, but a compaction mid-slice is exactly the moment when the useful state is NOT
# yet committable. This writes it to a gitignored file in the repo so the post-compaction context can
# read it back.
#
# Adapted from the developer's bundle hook (`claude-setup-global-20260722`): write to a scratch
# handoff file, never auto-commit. Three deliberate differences:
#   1. DETERMINISTIC BY DEFAULT — no LLM call. The upstream hook shelled out to `claude -p` (Haiku)
#      on every compaction; here that spends the same weekly quota the developer is rationing, and it
#      fails whenever the API is unreachable. Everything below is derived from `git` + the transcript
#      with `jq`. Opt into an LLM narrative with RENTWATCH_HANDOFF_LLM=1.
#   2. WRITES INTO THE REPO (`var/claude/handoff/`, gitignored) — not `~/.claude/projects/<slug>/`,
#      which is wiped when the container is reclaimed.
#   3. NO statusline/banner writes — the statusline and its `~/.claude/run/` sentinels do not exist
#      in this container (rejected in `docs/plans/claude-bundle-integration.plan.md`).
#
# CONTRACT: a PreCompact hook must never block compaction, so this script ALWAYS exits 0. That is the
# hook contract, not error suppression — every failure path that could LOSE THE HANDOFF logs a reason
# via log_obs. Three paths deliberately do not log, because there is nothing actionable to say and no
# data at risk: unreadable stdin (falls through to a git-only handoff, which is logged), and the two
# `rm -f`/cleanup lines.
# Note the deliberate absence of `set -e`: an aborting shell here would be the failure mode.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/log-helpers.sh" 2>/dev/null || log_obs() { :; }

INPUT=$(cat 2>/dev/null || true)
TRANSCRIPT=$(printf '%s' "$INPUT" | jq -r '.transcript_path // empty' 2>/dev/null || true)
CWD=$(printf '%s'        "$INPUT" | jq -r '.cwd            // empty' 2>/dev/null || true)
SESSION=$(printf '%s'    "$INPUT" | jq -r '.session_id     // empty' 2>/dev/null || true)

[[ -z "$CWD" ]] && CWD="${CLAUDE_PROJECT_DIR:-$PWD}"
HANDOFF_DIR="${RENTWATCH_HANDOFF_DIR:-$CWD/var/claude/handoff}"

if ! mkdir -p "$HANDOFF_DIR" 2>/dev/null; then
  log_obs ERROR precompact-handoff "mkdir failed for $HANDOFF_DIR — handoff lost"
  exit 0
fi

STAMP=$(date +%Y-%m-%d-%H%M%S)
ARCHIVE="$HANDOFF_DIR/handoff-$STAMP.md"
LATEST="$HANDOFF_DIR/latest.md"

# ── Git state (the durable half — always available, never depends on the transcript) ──────────
git_block() {
  if ! git -C "$CWD" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    printf '_Not a git work tree (%s)._\n' "$CWD"
    return
  fi
  local branch head upstream ahead dirty untracked
  branch=$(git -C "$CWD" rev-parse --abbrev-ref HEAD 2>/dev/null)
  head=$(git -C "$CWD" log --oneline -1 2>/dev/null)
  upstream=$(git -C "$CWD" rev-parse --abbrev-ref '@{upstream}' 2>/dev/null || echo "none")
  ahead=$(git -C "$CWD" rev-list --left-right --count "HEAD...@{upstream}" 2>/dev/null | tr '\t' '/' || echo "n/a")
  dirty=$(git -C "$CWD" status --porcelain 2>/dev/null | wc -l | tr -d ' ')
  untracked=$(git -C "$CWD" ls-files --others --exclude-standard 2>/dev/null | wc -l | tr -d ' ')
  printf -- '- branch: `%s` → upstream `%s` (ahead/behind: %s)\n' "$branch" "$upstream" "$ahead"
  printf -- '- HEAD: %s\n' "$head"
  printf -- '- uncommitted: %s file(s) · untracked: %s file(s)\n' "$dirty" "$untracked"
  if [[ "$dirty" != "0" ]]; then
    printf -- '\n**Uncommitted paths — this is the work at risk:**\n\n```\n'
    git -C "$CWD" status --porcelain 2>/dev/null | head -40
    printf '```\n'
  fi
  printf -- '\n**Last 5 commits:**\n\n```\n'
  git -C "$CWD" log --oneline -5 2>/dev/null
  printf '```\n'
}

# ── Recent intent, straight from the transcript (no LLM) ──────────────────────────────────────
# The user's own words are the highest-signal thing a post-compaction context can read, and they
# need no summarisation to be useful — so they are extracted verbatim, not paraphrased.
#
# Two things this has to get right, both found by running it against a real 26k-line transcript:
#   • `jq -Rs` JSON-ENCODES its output, so newlines came out as literal `\n`. Hence `-Rrs` (raw out).
#   • Not every `type == "user"` entry is the developer speaking. Slash-command echoes, the
#     local-command caveat/stdout wrappers, `<system-reminder>` blocks, the compaction summary and
#     the "Continue from where you left off" resume prompt all arrive as user turns. Reporting those
#     as "recent user intent" actively misleads the next context, so they are filtered out.
NOISE='^<(local-command-caveat|command-name|command-message|command-args|local-command-stdout|system-reminder|user-prompt-submit-hook)|^This session is being continued|^Continue from where you left off|^<local-command|^Caveat: The messages below were generated|^/[a-z][a-z0-9-]*$'

transcript_last_assistant() {
  jq -Rrs '
    split("\n") | map(select(length > 0) | try fromjson catch null) | map(select(. != null))
    | map(select(.type? == "assistant"))
    | map(
        [ .message?.content?
          | if type == "string" then .
            elif type == "array" then (.[] | select(.type? == "text") | .text)
            else "" end
        ] | join(" ") | gsub("\\s+"; " ")
      )
    | map(select(length > 0)) | last // "" | .[0:800]
  ' < "$1" 2>/dev/null
}

USERS=""
LAST_ASSISTANT=""
if [[ -n "$TRANSCRIPT" && -f "$TRANSCRIPT" ]]; then
  USERS=$(jq -Rrs --argjson n 8 --arg noise "$NOISE" '
    split("\n") | map(select(length > 0) | try fromjson catch null) | map(select(. != null))
    # Developer input arrives two ways: ordinary "user" turns, and — for a message typed WHILE a turn
    # is running — a "queue-operation" entry. Only the "enqueue" half; "remove" repeats the same text.
    | map(select((.type? == "user")
                 or (.type? == "queue-operation" and .operation? == "enqueue")))
    | map(
        if .type? == "queue-operation" then (.content // "")
        else
          [ .message?.content?
            | if type == "string" then .
              elif type == "array" then (.[] | select(.type? == "text") | .text)
              else "" end
          ] | join(" ")
        end | gsub("\\s+"; " ")
      )
    | map(select(length > 0))
    | map(select(test($noise) | not))          # drop harness turns — not the developer speaking
    | reduce .[] as $x ([]; if (length > 0 and .[-1] == $x) then . else . + [$x] end)  # de-dup repeats
    | map(.[0:400])
    | .[-$n:] | to_entries | map("\(.key + 1). \(.value)") | join("\n")
  ' < "$TRANSCRIPT" 2>/dev/null)
  LAST_ASSISTANT=$(transcript_last_assistant "$TRANSCRIPT")
else
  log_obs WARN precompact-handoff "transcript missing or unreadable — git-only handoff"
fi

# ── The project's own continuity pointers ─────────────────────────────────────────────────────
cursor_block() {
  # rent-watch keeps plans in-repo as docs/plans/<topic>.plan.md, each carrying its own
  # `## Decisions Log`. List the most recently touched ones — they are the cursor.
  local found=0 f
  while IFS= read -r f; do
    [[ -n "$f" ]] || continue
    found=1
    printf -- '- Plan: `%s`\n' "${f#"$CWD"/}"
  done < <(ls -t "$CWD"/docs/plans/*.plan.md 2>/dev/null | head -3)
  if (( found )); then
    printf -- '- Read the `## Decisions Log` of the plan above FIRST after compaction.\n'
  else
    printf -- '- (no `docs/plans/*.plan.md` in this tree)\n'
  fi
}

{
  printf '# Pre-compaction handoff — %s\n\n' "$STAMP"
  printf 'Session `%s` · cwd `%s`\n\n' "${SESSION:-unknown}" "$CWD"
  printf '> Written automatically by `scripts/claude-bootstrap/hooks/precompact-handoff.sh` just\n'
  printf '> before context compaction. Deterministic — derived from git and the transcript, no LLM\n'
  printf '> call. Gitignored: this file is never committed.\n\n'
  printf '## Git state\n\n'
  git_block
  printf '\n## Recent user intent (verbatim, most recent last)\n\n'
  if [[ -n "$USERS" ]]; then printf '%s\n' "$USERS"; else printf '_No user messages recovered from the transcript._\n'; fi
  printf '\n## Last thing Claude said\n\n'
  if [[ -n "$LAST_ASSISTANT" ]]; then printf '%s\n' "$LAST_ASSISTANT"; else printf '_None recovered._\n'; fi
  printf '\n## Where to resume\n\n'
  cursor_block
  printf '\n<!-- auto-generated by precompact-handoff (deterministic) -->\n'
} > "$ARCHIVE" 2>/dev/null || {
  log_obs ERROR precompact-handoff "write failed for $ARCHIVE"
  exit 0
}

# A handoff a human wrote by hand outranks an auto-generated one. `/handoff` documents appending
# `<!-- manual -->` to claim latest.md; that promise was inert until 2026-07-29 — this hook overwrote
# it unconditionally, so following the documented ritual silently lost the note at the next
# compaction. The archive copy is always written, so nothing is lost either way.
# Decided ONCE, here, and honoured by every path that writes $LATEST. The first version of this guard
# protected only the default path below and left the opt-in LLM path (further down) overwriting
# unconditionally — so with RENTWATCH_HANDOFF_LLM=1 the marker was still ignored, and the log claimed
# "kept" two lines before clobbering the file. A single variable is what makes that class of bug
# impossible rather than merely unlikely.
LATEST_IS_MANUAL=0
if [[ -f "$LATEST" ]] && grep -q '<!-- manual -->' "$LATEST" 2>/dev/null; then
  LATEST_IS_MANUAL=1
fi

if (( LATEST_IS_MANUAL )); then
  log_obs INFO precompact-handoff "latest.md is manual — kept; auto handoff is at $ARCHIVE"
else
  cp -f "$ARCHIVE" "$LATEST" 2>/dev/null || log_obs WARN precompact-handoff "could not refresh $LATEST"
fi

# ── Optional LLM narrative — OFF by default, see header note 1 ─────────────────────────────────
if [[ "${RENTWATCH_HANDOFF_LLM:-0}" == "1" && -n "$TRANSCRIPT" && -f "$TRANSCRIPT" ]]; then
  if command -v claude >/dev/null 2>&1; then
    PROMPT=$(mktemp /tmp/precompact-handoff-XXXXXX.txt)
    {
      printf 'Summarise this session for a successor with no memory of it. Under 20 lines.\n'
      printf 'Sections: State (done / not done, files touched) and Next (1-3 items, priority order).\n'
      printf 'Output only the note.\n\n---\n'
      cat "$ARCHIVE"
    } > "$PROMPT"
    RAW=$(cd /tmp && env -u CLAUDE_PROJECT_DIR timeout 60 claude -p \
            --model "${RENTWATCH_HANDOFF_MODEL:-claude-haiku-4-5}" --max-turns 1 \
            --output-format json < "$PROMPT" 2>/dev/null)
    SUMMARY=$(printf '%s' "$RAW" | jq -r '.result // empty' 2>/dev/null)
    if [[ -n "$SUMMARY" ]]; then
      { printf '\n## LLM narrative (RENTWATCH_HANDOFF_LLM=1)\n\n%s\n' "$SUMMARY"; } >> "$ARCHIVE"
      if (( LATEST_IS_MANUAL )); then
        log_obs INFO precompact-handoff "LLM narrative appended to $ARCHIVE; manual latest.md left intact"
      else
        cp -f "$ARCHIVE" "$LATEST" 2>/dev/null \
          || log_obs WARN precompact-handoff "could not refresh $LATEST after LLM narrative"
        log_obs INFO precompact-handoff "LLM narrative appended"
      fi
    else
      log_obs WARN precompact-handoff "LLM narrative requested but the call returned nothing"
    fi
    rm -f "$PROMPT" 2>/dev/null || true
  else
    log_obs WARN precompact-handoff "RENTWATCH_HANDOFF_LLM=1 but the claude CLI is not on PATH"
  fi
fi

log_obs INFO precompact-handoff "handoff written: $ARCHIVE"
printf '\n[handoff saved before compaction: %s]\n' "${ARCHIVE#"$CWD"/}" >&2
exit 0
