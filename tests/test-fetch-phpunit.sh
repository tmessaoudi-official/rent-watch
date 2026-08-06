#!/usr/bin/env bash
# test-fetch-phpunit.sh — does the runner-fetch script actually refuse what it promises to refuse?
#
# `README.md` and `CLAUDE.md` both say this script "refuses to install on a mismatch". Until
# 2026-08-06 that was false in the most dangerous way available: the signature check was
#
#     if gpg --batch --verify sig file 2>&1 | grep -q "$EXPECTED_KEY"; then
#
# whose exit status is GREP's, not gpg's — and gpg prints `using RSA key <fingerprint>` even when
# the next line reads `BAD signature`. A tampered PHAR carrying a signature that merely NAMED the
# pinned key was accepted and installed. `bash -n` cannot see that, and there was no test.
#
# This exercises the verification LOGIC against a throwaway key, without touching the network or
# the installed runner. It is deliberately not a mock: it makes gpg produce a real BAD signature.
#
# Run: bash tests/test-fetch-phpunit.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="$repo/tools/fetch-phpunit.sh"

pass=0
fail=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$expected" == "$actual" ]]; then
    printf '  \033[32mok\033[0m   %-56s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %-56s (expected %s, got %s)\n' "$label" "$expected" "$actual"
    fail=$((fail + 1))
  fi
}

work="$(mktemp -d)"
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed\n' >&2; exit 1; }
export GNUPGHOME="$work/gnupg"
mkdir -p "$GNUPGHOME"
chmod 700 "$GNUPGHOME"
trap 'rm -rf "$work"' EXIT

printf '\n== fetch-phpunit: refuses a bad signature, accepts a good one ==\n\n'

# ── A throwaway signing key, so this test needs no network and no real PHPUnit release ───────────
gpg --batch --quiet --passphrase '' --pinentry-mode loopback --quick-generate-key \
  'rent-watch test signer <test@example.invalid>' rsa2048 sign never >/dev/null 2>&1

key="$(gpg --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '/^fpr:/ {print $10; exit}')"

if [[ -z "$key" ]]; then
  printf '  \033[33mSKIP\033[0m gpg could not create a test key in this environment\n\n'
  exit 0
fi

printf 'artefact contents\n' > "$work/art"
gpg --batch --quiet --yes --detach-sign --armor -o "$work/art.asc" "$work/art" 2>/dev/null

# The verification logic, lifted verbatim in shape from tools/fetch-phpunit.sh.
verify() {
  local sig="$1" file="$2" expected_key="$3" out rc
  out="$(gpg --batch --status-fd=1 --verify "$sig" "$file" 2>/dev/null)"
  rc=$?
  [[ $rc -eq 0 ]] \
    && grep -q '^\[GNUPG:\] GOODSIG' <<<"$out" \
    && grep -q "^\[GNUPG:\] VALIDSIG $expected_key" <<<"$out"
}

# The OLD check, in a subshell so its shell options are controlled rather than inherited. That
# distinction is the whole story: piping `gpg --verify` into `grep` yields GREP's exit status, and
# gpg prints `using RSA key <fingerprint>` even on a BAD signature — so the form is vulnerable in a
# shell WITHOUT `pipefail`, and safe in one with it. `tools/fetch-phpunit.sh` has always had
# `set -euo pipefail`, so the bypass was never live here; a review reported it as live after testing
# the line in isolation. Both facts are asserted below so neither can be re-litigated from memory.
verify_old_form() {
  local opts="$1" sig="$2" file="$3" expected_key="$4"
  bash -c "set $opts; gpg --batch --verify '$sig' '$file' 2>&1 | grep -q '$expected_key'"
}

verify "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "a GOOD signature from the pinned key is accepted" accept "$r"

printf 'artefact contents TAMPERED\n' > "$work/art"

verify "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "a BAD signature is REFUSED" refuse "$r"

# The old form, both ways round — the record of what was and was not actually broken.
verify_old_form '-u' "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "old grep-piped form WITHOUT pipefail accepts a bad signature" accept "$r"

verify_old_form '-euo pipefail' "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "old grep-piped form WITH pipefail refuses it (what shipped)" refuse "$r"

grep -qE '^set -euo pipefail' "$script" && r=yes || r=no
check "the script still sets pipefail (the old form's only protection)" yes "$r"

printf 'artefact contents\n' > "$work/art"
verify "$work/art.asc" "$work/art" "0000000000000000000000000000000000000000" && r=accept || r=refuse
check "a good signature from an UNPINNED key is refused" refuse "$r"

# ── The script must still carry a pin, and must not have regressed to the piped form ─────────────
grep -qE '^EXPECTED_SHA256="[0-9a-f]{64}"' "$script" && r=yes || r=no
check "the script pins a sha256" yes "$r"

grep -qE '^EXPECTED_KEY="[0-9A-F]{40}"' "$script" && r=yes || r=no
check "the script pins a key fingerprint" yes "$r"

# Comments are stripped first: the script deliberately QUOTES the old broken form in a comment so a
# future reader knows why the current shape is what it is, and matching that would be a false alarm.
grep -vE '^\s*#' "$script" | grep -qE 'gpg[^|]*--verify[^|]*\|[^|]*grep' && r=piped || r=not-piped
check "the verification code does not depend on pipefail (no gpg|grep)" not-piped "$r"

grep -q 'status-fd=1' "$script" && r=yes || r=no
check "the script uses gpg --status-fd for a machine-readable verdict" yes "$r"

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"

[[ $fail -eq 0 ]]
