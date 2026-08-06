#!/usr/bin/env bash
# lint-on-write.sh — PostToolUse (Edit|Write)
#
# Lints only the file that was just written. Advisory: never blocks, exit code is
# always 0, findings go to stderr so they land in the transcript.
#
# Python  -> ruff check
# YAML    -> yamllint (if installed)
# Shell   -> shellcheck (if installed)
# JSON    -> python3 -m json.tool

set -uo pipefail

file_path="$(python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit
i = d.get("tool_input", {}) or {}
print(i.get("file_path") or i.get("notebook_path") or "")
' 2>/dev/null)" || exit 0

[[ -z "$file_path" || ! -f "$file_path" ]] && exit 0

out=""
case "$file_path" in
  *.py)
    command -v ruff >/dev/null 2>&1 && out="$(ruff check "$file_path" 2>&1)" || true
    ;;
  *.yaml|*.yml)
    command -v yamllint >/dev/null 2>&1 && out="$(yamllint -f parsable "$file_path" 2>&1)" || true
    ;;
  *.sh|*.bash)
    command -v shellcheck >/dev/null 2>&1 && out="$(shellcheck -f gcc "$file_path" 2>&1)" || true
    ;;
  *.json)
    out="$(python3 -m json.tool "$file_path" >/dev/null 2>&1 || echo "invalid JSON: $file_path")"
    ;;
  *)
    exit 0
    ;;
esac

[[ -z "${out// }" ]] && exit 0

{
  echo "lint-on-write: findings in $file_path"
  printf '%s\n' "$out" | head -40
} >&2

exit 0
