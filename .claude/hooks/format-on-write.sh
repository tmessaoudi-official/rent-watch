#!/usr/bin/env bash
# format-on-write.sh — PostToolUse (Edit|Write)
#
# Reports formatting drift on the file that was just written. Does NOT rewrite the
# file — silently reformatting behind Claude's back desynchronises its view of the
# file and causes stale-edit failures. Advisory only, always exits 0.

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

drift=""
case "$file_path" in
  *.py)
    command -v ruff >/dev/null 2>&1 \
      && drift="$(ruff format --check "$file_path" 2>&1 | grep -v '^[0-9]* file' || true)"
    ;;
  *.sh|*.bash)
    command -v shfmt >/dev/null 2>&1 \
      && drift="$(shfmt -d "$file_path" 2>&1 | head -20 || true)"
    ;;
  *)
    exit 0
    ;;
esac

[[ -z "${drift// }" ]] && exit 0

{
  echo "format-on-write: $file_path is not formatted."
  printf '%s\n' "$drift"
  case "$file_path" in
    *.py)        echo "  fix: ruff format $file_path" ;;
    *.sh|*.bash) echo "  fix: shfmt -w $file_path" ;;
  esac
} >&2

exit 0
