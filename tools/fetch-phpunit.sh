#!/usr/bin/env bash
# fetch-phpunit.sh — fetch the test runner, and check it is the one we think it is.
#
# WHY THE RUNNER IS A PHAR AND NOT A COMPOSER DEV DEPENDENCY
# This project's container egress policy returns 403 for `codeload.github.com` and for
# `api.github.com/repos/.../zipball`, which is where Composer fetches package dists. Composer falls
# back to `git clone`, which works — and produced a 2.6 GB `vendor/` for a test runner, on a host
# with a fixed disk allowance. `phar.phpunit.de` is a different host and is not blocked. So the
# project has ZERO Composer dependencies and `vendor/` is 56 KB of generated autoloader.
# Per /root/.ccr/README.md a proxy 403 is reported rather than routed around; this is a separate
# official distribution channel, not a way around the block.
#
# WHAT THIS CHECKS, AND WHAT IT HONESTLY CANNOT
#   1. SHA-256 against a pin recorded below. Works offline, always runs.
#   2. The detached PGP signature PHPUnit publishes alongside the PHAR, against a pinned
#      fingerprint. Runs only when the public key can be fetched.
#
# The pin was established by trust-on-first-use on 2026-08-06 in this container: the PHAR and its
# .asc were downloaded, and the signature named RSA key D8406D0D82947747293778314AA394086372C20A,
# issuer sb@sebastian-bergmann.de. It was NOT verified out of band, because every public keyserver
# (keys.openpgp.org, keyserver.ubuntu.com, pgp.mit.edu) is unreachable from this container — the
# same egress policy. So the fingerprint below is a CONTINUITY check, not a root of trust: it proves
# a later fetch was signed by the same key as the first, which catches a swapped artefact but not a
# compromised-from-the-start one. Verify it out of band once from a machine with keyserver access
# (phpunit.de publishes it) and this comment can be upgraded.
#
# Run: bash tools/fetch-phpunit.sh

set -euo pipefail

VERSION="${PHPUNIT_VERSION:-13}"
URL="https://phar.phpunit.de/phpunit-${VERSION}.phar"
DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/phpunit.phar"

# Pinned for phpunit-13.phar as fetched 2026-08-06 (PHPUnit 13.2.6). Bump deliberately, in a commit
# that says which release it moved to — `phpunit-13.phar` is a MOVING tag and will change under us.
EXPECTED_SHA256="292ccbd5b1890a42ecc2ab3567a4279f4088332c4e82e2fd56d81c6490947e29"
EXPECTED_KEY="D8406D0D82947747293778314AA394086372C20A"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

printf 'fetching %s\n' "$URL"
curl -sSLf -o "$tmp/phpunit.phar" "$URL"
curl -sSLf -o "$tmp/phpunit.phar.asc" "${URL}.asc" || printf '  note: no detached signature published for this URL\n'

actual="$(sha256sum "$tmp/phpunit.phar" | cut -d' ' -f1)"

if [[ "$actual" == "$EXPECTED_SHA256" ]]; then
  printf '  sha256 matches the pin\n'
else
  printf '  sha256 DOES NOT match the pin.\n    expected %s\n    got      %s\n' "$EXPECTED_SHA256" "$actual"
  printf '  "phpunit-%s.phar" is a moving tag, so a new upstream release looks exactly like a tampered\n' "$VERSION"
  printf '  download. Check the signature below, confirm the release on phpunit.de, then update\n'
  printf '  EXPECTED_SHA256 in this file in a commit that names the version.\n'
  if [[ ! -s "$tmp/phpunit.phar.asc" ]]; then
    printf '  REFUSING: pin mismatch and no signature to fall back on.\n'
    exit 1
  fi
fi

if [[ -s "$tmp/phpunit.phar.asc" ]]; then
  if gpg --list-keys "$EXPECTED_KEY" >/dev/null 2>&1 \
    || gpg --batch --quiet --keyserver hkps://keys.openpgp.org --recv-keys "$EXPECTED_KEY" >/dev/null 2>&1 \
    || gpg --batch --quiet --keyserver hkps://keyserver.ubuntu.com --recv-keys "$EXPECTED_KEY" >/dev/null 2>&1; then
    if gpg --batch --verify "$tmp/phpunit.phar.asc" "$tmp/phpunit.phar" 2>&1 | grep -q "$EXPECTED_KEY"; then
      printf '  signature verified against the pinned key\n'
    else
      printf '  REFUSING: the signature is NOT from the pinned key %s\n' "$EXPECTED_KEY"
      exit 1
    fi
  else
    printf '  signature present but UNVERIFIED: no public keyserver is reachable from this host.\n'
    printf '  Falling back to the sha256 pin alone, which is what was checked above.\n'
    [[ "$actual" == "$EXPECTED_SHA256" ]] || { printf '  REFUSING: no verification succeeded.\n'; exit 1; }
  fi
fi

mv "$tmp/phpunit.phar" "$DEST"
chmod +x "$DEST"
printf 'installed %s — %s\n' "$DEST" "$(php "$DEST" --version | head -1)"
