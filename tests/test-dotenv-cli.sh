#!/usr/bin/env bash
# Proves `bin/scout` really loads `.env`, and that a value with a SPACE survives the trip.
#
# The unit tests in tests/php/Config/DotEnvTest.php cover the parser. They cannot cover the wiring:
# `.env` is loaded in `bin/scout` rather than in `Scout`, deliberately, so that the PHPUnit suite —
# which constructs `Scout` directly against the real repo root — never pulls the developer's actual
# credentials into a test run. That placement is correct and it leaves exactly one line untested by
# PHP, which is the line the whole feature depends on.
#
# So this runs the real executable, in a throwaway root of its own, against a `.env` whose
# RENT_SCOUT_DB contains a space — the exact shape that broke under `set -a; . ./.env; set +a`, where
# bash read it as a one-command prefix and never exported it.
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

pass=0
fail=0

check() {
  local label="$1"
  shift
  if "$@"; then
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
  fi
}

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
  printf '  vendor/autoload.php is missing — run `composer install`\n' >&2
  exit 1
fi

# A root the CLI resolves for itself: bin/scout takes `dirname(__DIR__)`, so copying it here makes
# $work the root. vendor/ is SYMLINKED on purpose — composer's PSR-4 map resolves from its own
# location, so the symlink points back at the real src/, which is what we want to exercise. (That
# same property is a trap in tests/sabotage-check.sh, where the real src/ is precisely what must NOT
# be used; there it is copied.)
mkdir -p "$work/bin" "$work/config/rent"
cp "$repo/bin/scout" "$work/bin/scout"
ln -s "$repo/vendor" "$work/vendor"

cat > "$work/config/rent/criteria.json" <<'JSON'
{
  "communes": ["Sartrouville"],
  "postcode_prefixes": ["78"],
  "min_rooms": 3,
  "max_rent_cc": 1200
}
JSON

cat > "$work/config/rent/sources.json" <<'JSON'
{
  "sources": {
    "demo": {
      "enabled": false,
      "family": "institutional",
      "type": "json",
      "mixed_tenure": true,
      "map": { "ref": "id" }
    }
  }
}
JSON

printf '\n  .env loading, through the real executable\n\n'

# ── the control: no .env at all ───────────────────────────────────────────────────────────────────
#
# Without this, a test asserting "the path from .env appears" could be satisfied by a default that
# happens to match, and the whole file would prove nothing.
control="$(cd "$work" && php bin/scout --domain=rent doctor --source=demo 2>&1 || true)"
check "with no .env the CLI still starts (a fresh clone has none)" \
  grep -q 'scout --domain=rent doctor' <<<"$control"
check "…and uses the built-in default database path" \
  grep -q 'state/rent-watch.sqlite3' <<<"$control"

# ── the regression: a value containing a space ────────────────────────────────────────────────────
mkdir -p "$work/a dir with spaces"
cat > "$work/.env" <<'ENV'
# a comment, and a blank line follow

RENT_SCOUT_DB=a dir with spaces/rw.sqlite3
ENV

loaded="$(cd "$work" && php bin/scout --domain=rent doctor --source=demo 2>&1 || true)"
check "a .env value CONTAINING SPACES reaches the CLI intact" \
  grep -q 'a dir with spaces/rw.sqlite3' <<<"$loaded"
check "…and the shell never ran any of it (no 'command not found')" \
  bash -c '! grep -qi "command not found" <<<"$1"' _ "$loaded"

# ── precedence: the real environment outranks the file ────────────────────────────────────────────
#
# `RENT_SCOUT_DB=/tmp/throwaway bin/scout --domain=rent run` is how a live source is measured without touching the
# real seen-set. A file that could override it would silently redirect that at the real database.
override="$(cd "$work" && RENT_SCOUT_DB='env-wins.sqlite3' php bin/scout --domain=rent doctor --source=demo 2>&1 || true)"
check "an environment variable outranks the same key in .env" \
  grep -q 'env-wins.sqlite3' <<<"$override"

# ── a malformed .env refuses, loudly, without quoting the line ────────────────────────────────────
cat > "$work/.env" <<'ENV'
RENT_SCOUT_DB=fine.sqlite3
this line is not an assignment hunter2
ENV

set +e
malformed="$(cd "$work" && php bin/scout --domain=rent doctor --source=demo 2>&1)"
malformed_code=$?
set -e

check "a malformed .env is a non-zero refusal, not a silent skip" \
  test "$malformed_code" -ne 0
check "…the refusal names the line NUMBER" \
  grep -q '\.env:2' <<<"$malformed"
check "…and does NOT quote the line, which would leak a credential" \
  bash -c '! grep -q "hunter2" <<<"$1"' _ "$malformed"

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"

if (( fail > 0 )); then
  exit 1
fi

exit 0
