#!/usr/bin/env bash
# Proves the fixture scrubber verifies what it CLAIMS to verify: that the subscriber's address is
# not RECOVERABLE from the output — not merely that it is not present as text.
#
# The distinction is not academic; it is the defect this file was written for. Every Bien'ici alert
# link carries `signedRecipient=eyJhbGciOi…`, a JWT whose payload base64url-decodes to
# `{"email":"<the subscriber>","iat":…}`. Measured 2026-08-25 on a real capture: the literal address
# is absent from the decoded body, and one `base64 -d` recovers it in full. The scrubber's own
# docblock promised it would refuse to write such a file. It wrote it, and said `scrubbed`.
#
# Three halves, because a guard needs all three to mean anything:
#   MUST STRIP    a JWT token is replaced, shape kept, and the address is gone from the decode
#   MUST REFUSE   an address encoded in a shape the scrubber cannot strip stops the write
#   MUST BE QUIET an ordinary capture with no tokens still scrubs cleanly
#
# The refusal half is the one that matters. A scrubber that strips what it knows about and stays
# silent about what it does not is worse than no scrubber, because its output looks scrubbed.
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

# `check ! grep …` cannot work — `!` is shell syntax, not a command, so `"$@"` runs it as one and
# every negated assertion errors out with `!: command not found`. That failure LOOKS like the
# assertion failing, which is the shape of a test that proves nothing.
# AN ERROR IS NOT A REFUTATION (round-5 panel, 2026-08-31). This used to treat EVERY non-zero exit
# as the negative it was asserting — including 2 (a usage error) and 127 (command not found). Round 4
# found an instance: a section-header comment fused onto the end of a `refute` line with no newline
# handed `test` eight arguments, which exited 2 and reported `ok` whatever the scrubber had done.
# That was fixed as an instance; the MECHANISM that made it invisible was not, so the next fused
# line, typo'd flag or renamed helper would do it again — in the one test file whose subject is a
# privacy guard, where a vacuous `ok` is exactly the failure being guarded against.
#
# So: exit 1 is the genuine falsity this asserts, and anything above it is the command itself
# breaking, which is a FAILURE of the test rather than a pass. `bash -n` and `shellcheck -S warning`
# are both silent on the shape, so this is the only place it can be caught.
refute() {
  local label="$1"
  local status=0
  shift
  "$@" || status=$?

  if [ "$status" -eq 0 ]; then
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
  elif [ "$status" -gt 1 ]; then
    printf '  \033[31mFAIL\033[0m %s (la commande a ÉCHOUÉ avec le code %d — ce n'"'"'est pas une réfutation)\n' "$label" "$status"
    fail=$((fail + 1))
  else
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  fi
}

address='subscriber.person@example.test'

# base64url, unpadded — the encoding a JWT actually uses.
b64url() {
  printf '%s' "$1" | base64 | tr -d '=\n' | tr '+/' '-_'
}

jwt="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.$(b64url "{\"email\":\"${address}\",\"iat\":1787680538}").YqW6nmGpd-YcbYStBwx5EPHOge5FVBXeaSumGzmfTcs"

message_with_jwt() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 2 nouvelles annonces
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises
EOF
}

# The same address, base64'd into a parameter that is NOT jwt-shaped. Nothing can strip this
# without knowing the portal's scheme, so the only correct answer is to refuse.
opaque="$(b64url "${address}")"

message_with_opaque() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?u=${opaque}
1 170 EUR par mois charges comprises
EOF
}

message_plain() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123
1 170 EUR par mois charges comprises
EOF
}

# A capture in the shape leboncoin actually sends: HTML-only, quoted-printable, greeting the
# subscriber by USERNAME rather than by address, and carrying an account-scoped saved-search UUID
# plus opaque analytics hexes. None of those is an email address, so the address checks all pass on
# it -- which is exactly how a file carrying personal data got reported as `scrubbed`.
message_with_personal_ids() {
  cat <<'EOF'
From: no.reply@portal.test
To: subscriber@example.test
Subject: 3 nouveaux biens a louer
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<p>Bonjour tmessaoudi,</p>
<a href=3D"https://www.portal.test/my-searches/e5ce7f30-114f-4d67-96be-28d6c8=
9cad0b/">Ma recherche</a>
<a href=3D"https://www.portal.test/vi/3256902167.htm?t=3D1d09633ac8dfb2e54bc9=
ffa92ba58ef3e7dffb26">Appartement 48 m2</a>
EOF
}

scrub() {
  php "$repo/tools/scrub-eml.php" "$1" "$2" "$address"
}

# Every long base64url run in a file, decoded and concatenated. This is the attacker's move, and
# the test performs it rather than trusting the scrubber's report.
decoded_runs() {
  python3 - "$1" <<'PY'
import base64, re, sys
raw = open(sys.argv[1], 'rb').read().decode('utf-8', 'replace')
out = []
for run in re.findall(r'[A-Za-z0-9_\-]{16,}', raw):
    padded = run + '=' * (-len(run) % 4)
    try:
        out.append(base64.urlsafe_b64decode(padded).decode('utf-8', 'replace'))
    except Exception:
        pass
print('\n'.join(out))
PY
}

# Quoted-printable decoded. Without this a `grep -F` for a FOLDED identifier finds nothing and the
# assertion passes on a file that still carries it -- QP breaks lines at 76 columns, straight through
# the middle of a UUID. Two assertions below were green for exactly that reason before this existed.
decoded_qp() {
  python3 -c 'import quopri,sys;sys.stdout.write(quopri.decodestring(open(sys.argv[1],"rb").read()).decode("utf-8","replace"))' "$1"
}

printf '\n== test-scrub-eml: is the address RECOVERABLE from a scrubbed fixture? ==\n\n'

# ── MUST STRIP ────────────────────────────────────────────────────────────────────────────────────
message_with_jwt >"$work/jwt.eml"
jwt_status=0
scrub "$work/jwt.eml" "$work/jwt.out.eml" >"$work/jwt.log" 2>&1 || jwt_status=$?

check "a capture whose links carry a JWT is scrubbed rather than refused" \
  test "$jwt_status" -eq 0
check "the scrubbed output exists" test -f "$work/jwt.out.eml"

if [[ -f "$work/jwt.out.eml" ]]; then
  decoded_runs "$work/jwt.out.eml" >"$work/jwt.decoded"

  refute "the address is not recoverable by decoding the output's tokens" \
    grep -qF "$address" "$work/jwt.decoded"
  refute "the local part alone is not recoverable either" \
    grep -qF "${address%%@*}" "$work/jwt.decoded"
  refute "the address is not present as literal text" \
    grep -qF "$address" "$work/jwt.out.eml"
  # The VALUE goes and the SHAPE stays, exactly as the qs= rule already says: a fixture whose links
  # have no token at all would not exercise the link handling it was captured to exercise.
  check "a three-segment token shape survives the scrub" \
    grep -qE '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+' "$work/jwt.out.eml"
  check "the surviving token announces itself as a placeholder" \
    grep -qi 'placeholder' "$work/jwt.out.eml"
  # The point of scrubbing rather than deleting: the parser must still see a listing.
  check "the listing URL survives" grep -q '/annonce/abc-123' "$work/jwt.out.eml"
fi

# ── MUST REFUSE A BASE64 BODY ──────────────────────────────────────────────────────────────────────
# The encoding after quoted-printable. A `Content-Transfer-Encoding: base64` body carries the same
# JWT as opaque 76-column lines: no run in the raw or QP-unfolded text decodes to the address, so the
# old check reported `scrubbed` on a file the address was one `base64 -d` away from (review panel,
# 2026-08-30). The tool cannot rewrite inside a base64 body without re-encoding it, so the honest
# answer is a REFUSAL, exit non-zero, no output file.
message_b64() {
  body="Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | base64 | fold -w 76)
EOF
}

message_b64 >"$work/b64.eml"
b64_status=0
scrub "$work/b64.eml" "$work/b64.out.eml" >"$work/b64.log" 2>&1 || b64_status=$?

check "a base64-encoded body from which the address is recoverable is REFUSED" test "$b64_status" -ne 0
refute "and nothing is written" test -f "$work/b64.out.eml"
check "the refusal says the address is recoverable" grep -qi 'recoverable' "$work/b64.log"
# The tail (round-3 panel): every line short. A 36-column fold was written outright.
message_b64_short() {
  body="Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | base64 -w 0 | fold -w 36)
EOF
}

message_b64_short >"$work/b64s.eml"
b64s_status=0
scrub "$work/b64s.eml" "$work/b64s.out.eml" >"$work/b64s.log" 2>&1 || b64s_status=$?

check "a base64 body folded at 36 columns is REFUSED as well" test "$b64s_status" -ne 0
refute "and nothing is written for it" test -f "$work/b64s.out.eml"

# ROUND 4: a per-line WIDTH floor is the same defect with a smaller number. Round 3 lowered it from
# 40 to 20 for the 36-column case above; at 19 the pattern matched nothing, the decode never ran,
# and the tool WROTE the file and reported it clean with the address one `base64 -d` away. The floor
# is gone — total decoded length was always the real constraint. Folded narrower than any real mailer
# would, on purpose: the guarantee is "any width", not "the widths seen so far".
message_b64_narrow() {
  body="Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises — envoye a ${address}"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | base64 -w 0 | fold -w 19)
EOF
}

message_b64_narrow >"$work/b64n.eml"
b64n_status=0
scrub "$work/b64n.eml" "$work/b64n.out.eml" >"$work/b64n.log" 2>&1 || b64n_status=$?

check "a base64 body folded at 19 columns is REFUSED too (no per-line width floor)" test "$b64n_status" -ne 0
refute "and nothing is written for the narrow fold" test -f "$work/b64n.out.eml"

# And UTF-16 (round-3 panel): every ASCII byte followed by a NUL, so a byte search never matches.
message_b64_utf16() {
  body="https://www.portal.test/annonce/abc-123?signedRecipient=${jwt} — envoye a ${address}"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-16le
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | iconv -f UTF-8 -t UTF-16LE | base64 -w 0 | fold -w 76)
EOF
}

message_b64_utf16 >"$work/b64u.eml"
b64u_status=0
scrub "$work/b64u.eml" "$work/b64u.out.eml" >"$work/b64u.log" 2>&1 || b64u_status=$?

check "a UTF-16 base64 body from which the address is recoverable is REFUSED" test "$b64u_status" -ne 0
refute "and nothing is written for it either" test -f "$work/b64u.out.eml"

# ── MUST STRIP THROUGH QUOTED-PRINTABLE ───────────────────────────────────────────────────────────
# The case the first version of this file did not have, and the one that mattered. Most alert mail
# is QP-encoded: `=` becomes `=3D`, and every line folds at 76 columns with a trailing `=`. So a
# real capture reads `signedRecipient=3DeyJhbGciOi…` with soft breaks through the middle of the
# token — and a `\b`-anchored pattern refuses to start, because the character before `eyJ` is the
# `D` of `=3D`. Measured 2026-08-25: the unencoded case above passed while the tool stripped
# NOTHING from all three real Bien'ici captures.
qp_head="${jwt:0:60}"
qp_tail="${jwt:60}"

message_qp() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Appartement 3 pi=C3=A8ces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=3D${qp_head}=
${qp_tail}
1 170 EUR par mois charges comprises
EOF
}

message_qp >"$work/qp.eml"
qp_status=0
scrub "$work/qp.eml" "$work/qp.out.eml" >"$work/qp.log" 2>&1 || qp_status=$?

check "a quoted-printable capture is scrubbed rather than refused" test "$qp_status" -eq 0

if [[ -f "$work/qp.out.eml" ]]; then
  # The attacker unfolds first. So does the check.
  python3 - "$work/qp.out.eml" >"$work/qp.decoded" <<'PY'
import base64, quopri, re, sys
raw = open(sys.argv[1], 'rb').read()
forms = [raw.decode('utf-8', 'replace'), quopri.decodestring(raw).decode('utf-8', 'replace')]
out = []
for text in forms:
    for run in re.findall(r'[A-Za-z0-9_\-]{16,}', text):
        padded = run + '=' * (-len(run) % 4)
        try:
            out.append(base64.urlsafe_b64decode(padded).decode('utf-8', 'replace'))
        except Exception:
            pass
print('\n'.join(out))
PY

  refute "the address is not recoverable by unfolding and decoding" \
    grep -qF "$address" "$work/qp.decoded"
  refute "nor is the local part" \
    grep -qF "${address%%@*}" "$work/qp.decoded"
  check "the report says a signed token was replaced" \
    grep -qE '[1-9][0-9]* signed tokens' "$work/qp.log"
  # The replacement is re-folded when the original was folded, so the scrub cannot LENGTHEN a line.
  # Emitting one long token where a folded one stood would leave a non-conformant line where a
  # conformant one had been, and the capture's STRUCTURE is what this whole tool exists to preserve.
  #
  # Stated as "no longer than the input" rather than "at most 76 octets" because 76 is a claim about
  # the input, and it is false of real mail: Bien'ici's own captures carry 258-column lines. An
  # assertion that fails on a conformant scrub of a non-conformant capture measures the fixture.
  longest() { awk '{ if (length($0) > m) m = length($0) } END { print m + 0 }' "$1"; }
  check "the scrub did not lengthen any line" \
    test "$(longest "$work/qp.out.eml")" -le "$(longest "$work/qp.eml")"
fi

# ── A NEEDLE THAT OVERLAPS THE ADDRESS MUST NOT DESTROY IT FIRST ─────────────────────────────────
# Round-6 panel, 2026-08-31. The needle loop ran BEFORE the address replacement, and a needle is
# typically the subscriber's NAME — which is usually IN the address. So `Takieddine` + `MESSAOUDI`
# rewrote `takieddine.messaoudi.official@gmail.com` into `abonne.abonne.official@gmail.com` before
# anything looked for the address; `str_replace($address)` then matched nothing, the local-part
# fallback matched nothing, and the final verification matched nothing either — so the tool wrote
# the file, printed `scrubbed` and exited 0 while the remainder reconstructed the address beside the
# commit author. Two fixtures shipped exactly that, hours after ALERT-CAPTURE.md started telling
# operators to pass the name. This is the procedure the docs prescribe, so it is the procedure the
# suite must exercise.
message_needle_overlaps_address() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <jeanne.dubois.official@example.test>
Subject: 1 nouvelle annonce

Bonjour Jeanne DUBOIS,
https://www.portal.test/annonce/abc?email=jeanne.dubois.official%40example.test&md5=aa
EOF
}

message_needle_overlaps_address >"$work/overlap.eml"
overlap_status=0
php "$repo/tools/scrub-eml.php" "$work/overlap.eml" "$work/overlap.out.eml" \
  'jeanne.dubois.official@example.test' Jeanne DUBOIS >"$work/overlap.log" 2>&1 || overlap_status=$?

check "a capture whose NEEDLE overlaps the address is scrubbed rather than refused" test "$overlap_status" -eq 0
refute "and no fragment of the local part survives beside an untouched remainder" \
  grep -qiE 'abonne[.-]?abonne' "$work/overlap.out.eml"
refute "and the greeting name is gone" grep -qiF 'DUBOIS' "$work/overlap.out.eml"
check "and the listing survives, because it is the payload" \
  grep -qF 'annonce/abc' "$work/overlap.out.eml"

# ── MUST REFUSE ───────────────────────────────────────────────────────────────────────────────────
message_with_opaque >"$work/opaque.eml"
opaque_status=0
scrub "$work/opaque.eml" "$work/opaque.out.eml" >"$work/opaque.log" 2>&1 || opaque_status=$?

check "an address encoded in an unknown shape REFUSES the write" \
  test "$opaque_status" -ne 0
check "and nothing is written" test ! -f "$work/opaque.out.eml"
check "and the refusal says the address is recoverable" \
  grep -qi 'recover\|encod' "$work/opaque.log"

# ── MUST STAY QUIET ───────────────────────────────────────────────────────────────────────────────
message_plain >"$work/plain.eml"
plain_status=0
scrub "$work/plain.eml" "$work/plain.out.eml" >"$work/plain.log" 2>&1 || plain_status=$?

check "an ordinary capture with no tokens still scrubs" test "$plain_status" -eq 0
refute "and the subscriber's address is gone from its headers" \
  grep -qF "$address" "$work/plain.out.eml"

# ── MUST STRIP PERSONAL IDENTIFIERS THAT ARE NOT ADDRESSES ────────────────────────────────────────
# Measured on the first real leboncoin alert, 2026-08-26: the scrubber reported
# `0 tracking tokens and 0 signed tokens replaced` and wrote a file containing `Bonjour tmessaoudi`,
# the account's saved-search UUID, and three 40-char analytics hexes. Every address check passed,
# correctly -- a username is not an address. The tool verified the thing it knew how to verify and
# said nothing about the rest, which is the same failure the JWT round found wearing a new hat.
message_with_personal_ids >"$work/ids.eml"
ids_status=0
php "$repo/tools/scrub-eml.php" "$work/ids.eml" "$work/ids.out.eml" "$address" tmessaoudi \
  >"$work/ids.log" 2>&1 || ids_status=$?

check "a capture greeting the subscriber by username is scrubbed rather than refused" \
  test "$ids_status" -eq 0
refute "and the username is gone" grep -qF 'tmessaoudi' "$work/ids.out.eml"
decoded_qp "$work/ids.out.eml" >"$work/ids.decoded"
refute "and the account-scoped saved-search UUID is gone (checked DECODED, not folded)" \
  grep -qiF 'e5ce7f30-114f-4d67-96be-28d6c89cad0b' "$work/ids.decoded"
check "but a UUID-SHAPED placeholder remains, because the shape is what the fixture exercises" \
  grep -qiE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' "$work/ids.decoded"
refute "and the opaque analytics hex is gone (checked DECODED)" \
  grep -qiF '1d09633ac8dfb2e54bc9ffa92ba58ef3e7dffb26' "$work/ids.decoded"
check "and the LISTING id survives, because it is the payload not the subscriber" \
  grep -qF '3256902167' "$work/ids.decoded"

# ROUND 4: the `To:` DISPLAY NAME. The tool appends its own `To: <alertes@example.invalid>`, so it
# always meant to own the header — but it did not DROP the original, and `str_replace($local, …)`
# cannot reach a display name because a display name is not the local part. Two committed ParuVendu
# fixtures shipped the subscriber's real full name in plaintext while the tool reported
# `scrubbed … 0 named identifier(s) replaced` and exited 0. No needle is passed here on purpose:
# the guarantee is that dropping the header does not DEPEND on the operator guessing a needle.
message_with_display_name() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: Jeanne DUBOIS-MARTIN <${address}>
Cc: Jeanne DUBOIS-MARTIN <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123
EOF
}

message_with_display_name >"$work/disp.eml"
disp_status=0
scrub "$work/disp.eml" "$work/disp.out.eml" >"$work/disp.log" 2>&1 || disp_status=$?

check "a capture whose To: carries a display name is scrubbed rather than refused" test "$disp_status" -eq 0
refute "and the display name is gone from the output" grep -qiF 'DUBOIS-MARTIN' "$work/disp.out.eml"
refute "and so is the one in Cc:" grep -qiF 'Jeanne' "$work/disp.out.eml"
check "and exactly one To: header remains — the tool's own" \
  test "$(grep -ci '^To:' "$work/disp.out.eml")" -eq 1
check "and the listing survives, because it is the payload" \
  grep -qF 'annonce/abc-123' "$work/disp.out.eml"

# An extra needle that survives as an ENCODING must REFUSE, exactly as an unstrippable address does.
# `dXNlcj1zdXJ2aXZvciZzcmM9YWxlcnQ` is base64url for `user=survivor&src=alert`, so a literal replace
# cannot reach the name and only the recoverable-forms check can. It is 31 characters on purpose:
# `recoverableForms()` only decodes runs of 16 or more, so a shorter token sits BELOW its floor and
# a test using one would fail for a reason that says nothing about the guard. Without this case the
# new argument would be advisory -- and an advisory guard is one somebody drops when it is
# inconvenient.
printf 'From: a@b.test\nSubject: x\n\nhttps://p.test/x?ref=dXNlcj1zdXJ2aXZvciZzcmM9YWxlcnQ\n' >"$work/needle.eml"
needle_status=0
php "$repo/tools/scrub-eml.php" "$work/needle.eml" "$work/needle.out.eml" "$address" survivor \
  >"$work/needle.log" 2>&1 || needle_status=$?
check "a named identifier recoverable only by decoding REFUSES the write" \
  test "$needle_status" -ne 0
check "and nothing is written" test ! -f "$work/needle.out.eml"

# R6-5 — THE ADDRESS IS REQUIRED, and it is the VERIFICATION that makes it so.
#
# It used to be optional, and omitting it made this tool a silent no-op that reported success: the
# literal replace had nothing to replace, and the final recoverability check had no needle to look
# for, so it passed VACUOUSLY and the file was written with a green light. `docs/ALERT-CAPTURE.md`
# already said to pass the address, so the gap only ever caught someone following the shorter of two
# documented forms — and caught them with a `scrubbed … 0 named identifier(s) replaced` line.
#
# Both halves are asserted, because a refusal that still writes the file is not a refusal.
printf 'From: a@b.test\nSubject: x\n\nBonjour, votre alerte.\n' >"$work/noaddr.eml"
noaddr_status=0
php "$repo/tools/scrub-eml.php" "$work/noaddr.eml" "$work/noaddr.out.eml" \
  >"$work/noaddr.log" 2>&1 || noaddr_status=$?
check "omitting the subscriber address REFUSES rather than writing a no-op" \
  test "$noaddr_status" -ne 0
check "and nothing is written" test ! -f "$work/noaddr.out.eml"
check "and the refusal SAYS the address is mandatory, not just 'usage'" \
  grep -qi 'OBLIGATOIRE' "$work/noaddr.log"

# An EMPTY address is the same trap wearing an argument, so it is refused on the same terms.
empty_status=0
php "$repo/tools/scrub-eml.php" "$work/noaddr.eml" "$work/empty.out.eml" "" \
  >"$work/empty.log" 2>&1 || empty_status=$?
check "an EMPTY address argument is refused too" test "$empty_status" -ne 0
check "and nothing is written for it either" test ! -f "$work/empty.out.eml"

# THE COUNTERWEIGHT: the ordinary invocation must still work, or the guard is satisfied by breaking
# the tool.
ok_status=0
php "$repo/tools/scrub-eml.php" "$work/noaddr.eml" "$work/ok.out.eml" "$address" \
  >"$work/ok.log" 2>&1 || ok_status=$?
check "a call WITH an address still scrubs and writes" test "$ok_status" -eq 0
check "and its output exists" test -f "$work/ok.out.eml"

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
[[ "$fail" -eq 0 ]]
