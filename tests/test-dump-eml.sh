#!/usr/bin/env bash
#
# Sabotage test FOR tools/dump-eml.php — the raw-capture tool.
#
# It reaches a real mailbox and writes UNSCRUBBED messages: the subscriber's address, usually their
# name, and every per-recipient token. Two things therefore have to hold, and until 2026-09-04
# neither was tested at all — the tool shipped with no docs and no test, the only entry in `tools/`
# without both.
#
#   1. It never writes under `tests/`. The one-step path from a mailbox to a committed fixture is
#      exactly how the two leaks this repo has already had would happen again, and the second line
#      of defence (`FixtureSecretsTest`) scans `tests/fixtures` only — so a raw capture landing at
#      `tests/` top level or in a new `tests/<dir>/` is missed by that too.
#   2. The IMAP password never reaches a stack trace.
#
# ISOLATED BY CONSTRUCTION. The tool is copied into a throwaway tree beside a stub autoloader and a
# `tests/` of its own, so no case can reach the network, read the real `.env`, or write into the
# real repo. That is the documented bash-script isolation pattern: `__DIR__` resolves to the copy.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0

ok() { printf '  \033[32m✓\033[0m %s\n' "$1"; pass=$((pass + 1)); }
ko() { printf '  \033[31m✗\033[0m %s\n' "$1"; printf '      %s\n' "${2:-}"; fail=$((fail + 1)); }

mkdir -p "$TMP/tools" "$TMP/vendor" "$TMP/tests/fixtures" "$TMP/var/claude"
cp "$ROOT/tools/dump-eml.php" "$TMP/tools/dump-eml.php"

# A stub autoloader: no Composer, no real DotEnv, so `.env` is never read and no credential of the
# developer's can enter this test even by accident.
cat > "$TMP/vendor/autoload.php" <<'STUB'
<?php
namespace Scout\Config;
class DotEnv { public static function load(string $path): void {} }
STUB

run() { ( cd "$TMP" && php tools/dump-eml.php "$@" 2>&1 ); }

# ── 1. it refuses every shape that lands under tests/ ────────────────────────────────────────────
#
# `tests` and `tests/` are the two the ORIGINAL guard missed: it built `realpath(dirname($outDir))
# . '/' . basename($outDir)` and asked whether that contained `/tests/`. Both resolve with NO
# trailing slash, so both walked straight through a check whose whole job is to stop them.
for target in tests tests/ tests/fixtures tests/fixtures/rent/pap tests/newdir; do
  out="$(run alerts@portal.test 1 "$target")"; code=$?
  if [[ $code -eq 2 && "$out" == *"jamais sous tests/"* ]]; then
    ok "refuses out-dir '$target'"
  else
    ko "refuses out-dir '$target'" "exit=$code out=$out"
  fi
done

# Traversal, both directions. A path may point back inside tests/ through `..` or through `.`, and
# the guard folds them before it compares rather than trusting the string it was handed.
for target in 'tests/../tests/fixtures' 'var/claude/../../tests' './tests/./fixtures'; do
  out="$(run alerts@portal.test 1 "$target")"; code=$?
  if [[ $code -eq 2 && "$out" == *"jamais sous tests/"* ]]; then
    ok "refuses traversal '$target'"
  else
    ko "refuses traversal '$target'" "exit=$code out=$out"
  fi
done

# An absolute path into the copy's own tests/ — the same guarantee stated without a relative path,
# so it cannot be satisfied by string-matching the argument.
out="$(run alerts@portal.test 1 "$TMP/tests/fixtures/rent")"; code=$?
if [[ $code -eq 2 && "$out" == *"jamais sous tests/"* ]]; then
  ok "refuses an absolute path under tests/"
else
  ko "refuses an absolute path under tests/" "exit=$code out=$out"
fi

# ── 2. it fails CLOSED when the destination cannot be resolved at all ────────────────────────────
#
# The original guard concatenated `realpath()`'s `false` into a string, so an out-dir whose parent
# did not exist was compared as `/basename` — garbage. That was the DEFAULT out-dir's own shape on a
# tree where `var/claude` had not been created: the guard was vacuous by default.
out="$(run alerts@portal.test 1 /no-such-top-level-dir-xyz/captures)"; code=$?
if [[ $code -eq 2 && "$out" == *"impossible de résoudre"* ]]; then
  ok "refuses an out-dir it cannot resolve, rather than guessing"
else
  ko "refuses an out-dir it cannot resolve, rather than guessing" "exit=$code out=$out"
fi

# ── 3. THE COUNTERWEIGHT — the legitimate destination is still accepted ──────────────────────────
#
# Without this the guard is satisfied by refusing everything, which is a tool that does not work
# rather than a tool that is safe. `var/claude/captures` does not exist in the throwaway tree, which
# is the point: it is the shape that used to resolve to the literal `/captures`.
out="$(run alerts@portal.test 1 var/claude/captures)"; code=$?
if [[ "$out" != *"jamais sous tests/"* && "$out" != *"impossible de résoudre"* ]]; then
  ok "still accepts var/claude/captures, which does not exist yet"
else
  ko "still accepts var/claude/captures, which does not exist yet" "exit=$code out=$out"
fi
# …and it gets there by passing the guard, not by skipping it: with the stub DotEnv there are no
# credentials, so the next refusal is the one it must reach.
if [[ "$out" == *"manquants dans .env"* ]]; then
  ok "and reaches the credential check, so the guard ran and passed"
else
  ko "and reaches the credential check, so the guard ran and passed" "out=$out"
fi

# ── 4. usage, so a bare invocation cannot connect to anything ────────────────────────────────────
out="$(run)"; code=$?
if [[ $code -eq 2 && "$out" == *"usage:"* ]]; then
  ok "a bare invocation prints usage and exits 2"
else
  ko "a bare invocation prints usage and exits 2" "exit=$code out=$out"
fi

# ── 5. the IMAP password never reaches a stack trace ─────────────────────────────────────────────
#
# PHP ships `zend.exception_ignore_args = Off` and `zend.exception_string_param_max_len = 15`, so an
# uncaught trace prints the first 15 characters of every call ARGUMENT. `$cmd('LOGIN "u" "…"')` put
# the credential in that position; nothing leaked only because the real IMAP_USER is long enough to
# consume the budget first — luck, not a guard.
#
# First prove the MECHANISM, on this machine's own PHP rather than by assertion: an argument leaks,
# a `use` binding does not.
leak="$(php -r '
    $arg = static function (string $line) { throw new RuntimeException("refused"); };
    try { $arg("LOGIN \"u\" \"SUPERSECRETPW\""); } catch (Throwable $e) { echo $e->getTraceAsString(); }
' 2>&1)"
if [[ "$leak" == *"LOGIN"* ]]; then
  ok "an argument DOES reach the trace on this PHP (the mechanism is real)"
else
  ko "an argument DOES reach the trace on this PHP (the mechanism is real)" "trace=$leak"
fi

quiet="$(php -r '
    $pass = "SUPERSECRETPW";
    $bound = static function () use ($pass) { throw new RuntimeException("refused"); };
    try { $bound(); } catch (Throwable $e) { echo $e->getTraceAsString(); }
' 2>&1)"
if [[ "$quiet" != *"SUPERSECRETPW"* ]]; then
  ok "a use-binding does NOT reach the trace (the fix's mechanism)"
else
  ko "a use-binding does NOT reach the trace (the fix's mechanism)" "trace=$quiet"
fi

# Then tie the tool to that mechanism: LOGIN is sent by a ZERO-ARGUMENT closure, never handed to the
# generic command helper. Structural, and deliberately so — the behavioural half above is what makes
# it mean something.
# COMMENT LINES ARE STRIPPED FIRST, and finding that out cost a red run: the fix's own docblock
# explains why LOGIN is not `$cmd('LOGIN …')`, and a naive grep matched that sentence. A structural
# assertion has to read the CODE, or the documentation of a guarantee reads as its violation.
code_only="$(grep -vE '^[[:space:]]*(\*|/\*|//|#)' "$ROOT/tools/dump-eml.php")"
if printf '%s' "$code_only" | grep -qF '$cmd(' && ! printf '%s' "$code_only" | grep -qE '\$cmd\(.LOGIN'; then
  ok "LOGIN is not passed through the generic \$cmd helper"
else
  ko "LOGIN is not passed through the generic \$cmd helper" "grep found a \$cmd('LOGIN …) call"
fi

if grep -qE '\$login = static function \(\) use \(' "$ROOT/tools/dump-eml.php"; then
  ok "LOGIN is sent by a closure that takes no arguments"
else
  ko "LOGIN is sent by a closure that takes no arguments" "the zero-argument \$login closure is gone"
fi

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
(( fail == 0 ))
