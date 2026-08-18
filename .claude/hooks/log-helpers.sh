#!/usr/bin/env bash
# Shared observability helper (project CLAUDE.md / global Rule 13). Source this from hooks that need
# structured logging. Never fatal — every write ends in `|| true`, because a logging failure must not
# take down the hook that is logging.
#
# Format: YYYY-MM-DDTHH:MM:SS±ZZ | LEVEL | script | message
#
# Destination: $OBS_LOG, else $CLAUDE_PROJECT_DIR/var/claude/logs/hooks-errors.log — IN THE REPO
# (gitignored via /var), not ~/.claude/logs/. Changed 2026-07-29: the default was
# ~/.claude/logs/hooks-errors.log, which is wiped when the container is reclaimed, so every line a
# hook logged in a real session was written somewhere nobody could ever read. That also contradicted
# the framework's own adaptation note ("Rule 13 observability writes to var/claude/logs/"), which had
# been corrected in the prose while the code kept the old path. Nothing but the test harness sets
# OBS_LOG, so this default is what actually runs.

umask 077  # hook-created files are owner-only

log_obs() {
  local level="$1" script="$2"; shift 2
  local dest="${OBS_LOG:-${CLAUDE_PROJECT_DIR:-$PWD}/var/claude/logs/hooks-errors.log}"
  mkdir -p "$(dirname "$dest")" 2>/dev/null || true
  printf '%s | %s | %s | %s\n' "$(date -Iseconds)" "$level" "$script" "$*" >> "$dest" 2>/dev/null || true
}
