<?php

declare(strict_types=1);

/**
 * Scrub a captured alert email into a committable fixture.
 *
 * A `.eml` from a real mailbox is a fixture and a personal record at the same time. This removes
 * the second half and keeps the first, because **the structure IS the ground truth**: the awkward
 * `=_?:` MIME boundary, the 8bit UTF-8 transfer encoding, the folded headers and the RFC 2047
 * subject split mid-word are each a parser defect this project has already had. Rewriting the
 * message into something tidy would delete the evidence.
 *
 * What goes, and why each one is identity rather than structure:
 *
 * | Field                              | Carries |
 * |------------------------------------|---------|
 * | `Delivered-To`, `To`, `Received`   | the subscriber's address |
 * | `Return-Path`, `Reply-To`          | a per-subscriber bounce/reply token |
 * | `Feedback-ID`, `X-SFMC-*`          | the ESP's subscriber and job ids |
 * | `List-Unsubscribe*`                | a one-click token that unsubscribes without asking |
 * | `DKIM-Signature`, `ARC-*`          | signatures over headers this rewrites — kept would be a lie |
 * | every `qs=` value                  | the click-tracking token, tied to the subscriber |
 * | every JWT-shaped token             | the subscriber's address, base64url-encoded in the payload |
 * | the address in the body            | stated in the CNIL footer of every alert |
 *
 * Usage: `php tools/scrub-eml.php <in.eml> <out.eml> [address-to-mask]`
 *
 * It VERIFIES its own work: the address, the ESP list ids and every original `qs=` token must be
 * absent from the output, or it refuses to write. A scrubber that half-works is worse than none,
 * because its output looks scrubbed.
 *
 * **ABSENT IS THE WRONG TEST — the address must not be RECOVERABLE**, and Bien'ici defeated the old
 * one with no effort at all. Every link in its alerts carries `signedRecipient=eyJhbGciOi…`, a JWT
 * whose payload base64url-decodes to `{"email":"<the subscriber>","iat":…}`. Measured 2026-08-25 on
 * a real capture: the literal address is absent from the decoded body, and one `base64 -d` recovers
 * it in full. This wrote that file and reported `scrubbed`.
 *
 * So the verification decodes before it looks — every long base64url run, and the quoted-printable
 * form — and refuses when the address surfaces in any of them. That is stated as a general rule
 * rather than as a Bien'ici special case on purpose: the next portal's encoding is not known, and a
 * check that only understands the encodings already seen is the same defect with a later date.
 */

/**
 * Every form the message could be READ BACK in, beyond its own literal text.
 *
 * A token is only opaque until somebody decodes it, so this performs the decode the verification is
 * meant to defeat: each long base64url run, plus the quoted-printable form of the whole message.
 * An undecodable run is skipped rather than guessed at — it carries nothing readable either way.
 *
 * @return list<string>
 */
function recoverableForms(string $message): array
{
    $unfolded = quoted_printable_decode($message);
    $forms = [$unfolded];

    // Runs are taken from the UNFOLDED text as well as the raw: quoted-printable breaks a line
    // every 76 columns with a trailing `=`, straight through the middle of a token, so a run
    // scanned on the raw message is two short fragments that decode to nothing while the whole
    // token decodes to the address. Scanning only the raw text is how this check would pass on
    // most alert mail there is.
    // A `Content-Transfer-Encoding: base64` BODY is the encoding after quoted-printable: the token
    // sits inside opaque 76-column lines, so no run in the raw or unfolded text decodes to anything
    // and the old check reported `scrubbed` on a file the address was one `base64 -d` away from
    // (review panel, 2026-08-30). Every block of base64 lines is decoded whole and scanned like the
    // text it is — including the base64url runs INSIDE it, which is where a JWT payload lives.
    $texts = [$message, $unfolded];
    foreach ([$message, $unfolded] as $text) {
        if (preg_match_all('~(?:^[A-Za-z0-9+/]{40,}={0,2}\r?\n?){2,}~m', $text, $blocks) > 0) {
            foreach ($blocks[0] as $block) {
                $decoded = base64_decode((string) preg_replace('~\s+~', '', $block), true);
                if ($decoded !== false && $decoded !== '') {
                    $forms[] = $decoded;
                    $texts[] = $decoded;
                }
            }
        }
    }

    foreach ($texts as $text) {
        preg_match_all('~[A-Za-z0-9_\-]{16,}~', $text, $runs);

        foreach ($runs[0] as $run) {
            $padded = $run . str_repeat('=', (4 - strlen($run) % 4) % 4);
            $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

            if ($decoded !== false && $decoded !== '') {
                $forms[] = $decoded;
            }
        }
    }

    return $forms;
}

$argvLocal = $_SERVER['argv'] ?? [];

if (count($argvLocal) < 3) {
    fwrite(STDERR, "usage: php tools/scrub-eml.php <in.eml> <out.eml> [address]\n");

    exit(2);
}

[$in, $out] = [$argvLocal[1], $argvLocal[2]];
$address = $argvLocal[3] ?? null;

/**
 * Extra identifiers to mask, named by the operator.
 *
 * A portal greets you by USERNAME, not by address — leboncoin's alert opens `Bonjour tmessaoudi`.
 * That is personal data by any reading of hard rule 7, and no address check can find it: it is not
 * derivable from the address either (`takieddine.messaoudi.official` does not contain `tmessaoudi`).
 * Measured 2026-08-26 on the first real leboncoin capture, where this tool reported
 * `0 tracking tokens and 0 signed tokens replaced` and wrote the username straight through.
 *
 * Each needle is masked AND verified as unrecoverable, exactly like the address — an advisory
 * argument is the shape of a guard that gets quietly dropped.
 *
 * @var list<string> $needles
 */
$needles = array_values(array_filter(
    array_slice($argvLocal, 4),
    static fn (string $a): bool => trim($a) !== '',
));

$raw = @file_get_contents($in);
if ($raw === false) {
    fwrite(STDERR, "cannot read {$in}\n");

    exit(1);
}

// Headers dropped whole, including their folded continuation lines.
$drop = [
    'delivered-to', 'received', 'x-received', 'return-path', 'received-spf',
    'authentication-results', 'arc-seal', 'arc-message-signature',
    'arc-authentication-results', 'dkim-signature', 'reply-to', 'feedback-id',
    'list-unsubscribe', 'list-unsubscribe-post', 'x-sfmc-stack', 'x-delivery',
    'x-csa-complaints', 'message-id',
];

$eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
$parts = preg_split('~\R\R~', $raw, 2);
$headerBlock = $parts[0] ?? '';
$body = $parts[1] ?? '';

$kept = [];
$dropping = false;

foreach (preg_split('~\R~', $headerBlock) ?: [] as $line) {
    if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
        // A continuation belongs to whatever header preceded it — dropped or kept, both.
        if (!$dropping) {
            $kept[] = $line;
        }

        continue;
    }

    $name = strtolower(trim(explode(':', $line, 2)[0] ?? ''));
    $dropping = in_array($name, $drop, true);

    if (!$dropping) {
        $kept[] = $line;
    }
}

// A synthetic Message-ID, because the parser has a `messageId()` and a fixture with none tests the
// null path by accident rather than on purpose.
$kept[] = 'Message-ID: <fixture-' . substr(sha1($in), 0, 12) . '@example.invalid>';
$kept[] = 'To: <alertes@example.invalid>';

$message = implode($eol, $kept) . $eol . $eol . $body;

// Tracking tokens: the VALUE goes, the shape stays. A fixture whose links have no query at all
// would not exercise the identity collapse that made `id_from: content` necessary.
$tokenSeq = 0;
$message = preg_replace_callback(
    '~qs=[A-Za-z0-9_\-]+~',
    static function () use (&$tokenSeq): string {
        ++$tokenSeq;

        return 'qs=FIXTURE' . str_pad((string) $tokenSeq, 3, '0', STR_PAD_LEFT);
    },
    $message,
) ?? $message;

// JWT-shaped tokens: same rule as `qs=`, different encoding. The VALUE goes and the three-segment
// SHAPE stays, because a fixture whose links carry no token at all would not exercise the link
// handling it was captured to exercise. The replacement announces itself in plain text so that
// tests/php/Repo/FixtureSecretsTest.php — which refuses a committed JWT — reads it as a placeholder
// rather than as the live credential it is shaped like.
//
// THE PATTERN IS QUOTED-PRINTABLE-AWARE, and it has to be. Most alert mail is QP-encoded, and QP
// folds every line at 76 columns with a trailing `=` soft break — straight through the middle of a
// token. A pattern that only understands unbroken tokens matches nothing on such a capture and
// leaves the address in place. Bien'ici's subscription confirmation is exactly that message: the
// verification below caught it and refused the write, which is the guard working and the tool not.
$jwtSeq = 0;
$softBreak = '(?:=\r?\n)';
$b64 = '(?:[A-Za-z0-9_\-]|' . $softBreak . ')';

// The left anchor has TWO branches, and the second one is not decoration. A JWT sits in a query
// parameter, so it is preceded by `=` — which quoted-printable escapes to `=3D`. A plain `\b` or a
// "not a base64 character" lookbehind then sees the `D` and refuses to start, so the pattern
// matched nothing at all on QP mail and left every address in place. That is how the first version
// of this passed its own unit test (an unencoded fixture) and failed on all three real captures.
$startsToken = '(?:(?<![A-Za-z0-9_\-])|(?<==3D))';
$message = preg_replace_callback(
    '~' . $startsToken . 'eyJ' . $b64 . '{6,}\.' . $b64 . '{6,}\.' . $b64 . '{6,}~',
    static function (array $m) use (&$jwtSeq, $eol): string {
        ++$jwtSeq;

        $payload = rtrim(strtr(base64_encode(
            '{"PLACEHOLDER":"FIXTURE' . str_pad((string) $jwtSeq, 3, '0', STR_PAD_LEFT) . '"}',
        ), '+/', '-_'), '=');

        // The header decodes to {"alg":"none","typ":"JWT"} — true structure, no claim.
        $token = 'eyJhbGciOiJub25lIiwidHlwIjoiSldUIn0.' . $payload . '.PLACEHOLDER0SIGNATURE0FIXTURE';

        // Re-fold if the original was folded. Emitting one long line instead would leave a
        // non-conformant QP line where a conformant one stood, and the structure of the capture is
        // the part this whole tool exists to preserve.
        return str_contains($m[0], '=')
            && preg_match('~=\r?\n~', $m[0]) === 1
            ? rtrim(chunk_split($token, 60, '=' . $eol), '=' . $eol)
            : $token;
    },
    $message,
) ?? $message;

// Opaque ACCOUNT-SCOPED identifiers: a saved-search UUID, an analytics hex. Neither is an address,
// so every address check passes on them; both identify the subscriber's account to anyone holding
// the file. The VALUE goes and the SHAPE stays, same rule as `qs=` and the JWT — a fixture whose
// UUID had become `xxxx` would stop exercising the link handling it was captured for.
//
// Matched LOOSELY and validated STRICTLY in the callback, because quoted-printable folds lines at
// 76 columns straight through the middle of a 36-character token; a pattern that only understood
// unbroken tokens matched nothing on real mail and left the identifier in place. That exact defect
// is why the JWT pattern above carries the same note.
$unfold = static fn (string $s): string => (string) preg_replace('~=\r?\n~', '', $s);
$refold = static fn (string $replacement, string $original): string
    => preg_match('~=\r?\n~', $original) === 1
        ? rtrim(chunk_split($replacement, 60, '=' . $eol), '=' . $eol)
        : $replacement;

$uuidSeq = 0;
$message = preg_replace_callback(
    '~(?<![0-9a-fA-F-])(?:[0-9a-fA-F-]|=\r?\n){36,60}(?![0-9a-fA-F-])~',
    static function (array $m) use (&$uuidSeq, $unfold, $refold): string {
        $flat = $unfold($m[0]);
        if (preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $flat) !== 1) {
            return $m[0];   // not a UUID after all — the loose match earns nothing here
        }
        ++$uuidSeq;
        $n = str_pad((string) $uuidSeq, 4, '0', STR_PAD_LEFT);

        return $refold('ffffffff-0000-4000-8000-00000000' . $n, $m[0]);
    },
    $message,
) ?? $message;

$hexSeq = 0;
$message = preg_replace_callback(
    '~(?<![0-9a-fA-F])(?:[0-9a-fA-F]|=\r?\n){24,}(?![0-9a-fA-F])~',
    static function (array $m) use (&$hexSeq, $unfold, $refold): string {
        $flat = $unfold($m[0]);
        if (preg_match('~^[0-9a-fA-F]{24,}$~', $flat) !== 1) {
            return $m[0];
        }
        ++$hexSeq;

        return $refold(str_pad(substr('f0f0f0f0', 0, 8) . $hexSeq, strlen($flat), '0'), $m[0]);
    },
    $message,
) ?? $message;

foreach ($needles as $needle) {
    // Case-insensitively, because a portal's greeting capitalises what its account record does not.
    $message = (string) preg_replace('~' . preg_quote($needle, '~') . '~i', 'abonne', $message);
}

if ($address !== null && $address !== '') {
    $message = str_replace($address, 'alertes@example.invalid', $message);
    // The local part alone appears in some ESP ids.
    $local = explode('@', $address)[0];
    if ($local !== '') {
        $message = str_replace($local, 'alertes', $message);
    }
}

// The ESP's list/subscriber ids, which survive in bounce addresses and campaign strings.
$message = preg_replace('~\b51000[0-9]\b~', '510000', $message) ?? $message;

// ---- verification. Refuse rather than write something that looks scrubbed and is not.
$leaks = [];

if ($address !== null && $address !== '' && stripos($message, $address) !== false) {
    $leaks[] = 'the address is still present';
}

if ($address !== null && $address !== '') {
    foreach (recoverableForms($message) as $form) {
        if (stripos($form, $address) !== false) {
            $leaks[] = 'the address is RECOVERABLE from the output — it survives ENCODED (base64url '
                . 'or quoted-printable) inside a token this scrubber does not know how to strip. '
                . 'Teach it that token, or drop the parameter; do not relax this check.';

            break;
        }
    }

    // The local part alone is enough to identify the subscriber, and it is what ESP ids embed. Only
    // checked when it is long enough to be distinctive: a short local part matched against decoded
    // binary would fire on noise, and a guard that cries wolf gets weakened and then deleted.
    $local = explode('@', $address)[0];

    if (strlen($local) >= 6) {
        foreach (recoverableForms($message) as $form) {
            if (stripos($form, $local) !== false) {
                $leaks[] = 'the local part of the address is RECOVERABLE from the output — it '
                    . 'survives ENCODED inside a token this scrubber does not know how to strip.';

                break;
            }
        }
    }
}

foreach (['510006', '510008'] as $listId) {
    if (str_contains($message, $listId)) {
        $leaks[] = 'ESP list id ' . $listId . ' is still present';
    }
}

preg_match_all('~qs=([A-Za-z0-9_\-]+)~', $message, $remaining);
foreach ($remaining[1] as $token) {
    if (!str_starts_with($token, 'FIXTURE')) {
        $leaks[] = 'an original tracking token survives: ' . substr($token, 0, 12) . '…';
    }
}

// Each named identifier, held to the SAME standard as the address: gone from the text, and not
// recoverable from any encoding of it either. Anything weaker makes the argument advisory, and an
// advisory guard is one somebody drops the first time it is inconvenient.
foreach ($needles as $needle) {
    if (stripos($message, $needle) !== false) {
        $leaks[] = 'the identifier `' . $needle . '` is still present';

        continue;
    }

    foreach (recoverableForms($message) as $form) {
        if (stripos($form, $needle) !== false) {
            $leaks[] = 'the identifier `' . $needle . '` is RECOVERABLE from the output — it '
                . 'survives ENCODED inside a token this scrubber does not know how to strip. Teach '
                . 'it that token, or drop the parameter; do not relax this check.';

            break;
        }
    }
}

if ($leaks !== []) {
    fwrite(STDERR, "REFUSING to write — scrub incomplete:\n  - " . implode("\n  - ", array_unique($leaks)) . "\n");

    exit(1);
}

if (file_put_contents($out, $message) === false) {
    fwrite(STDERR, "cannot write {$out}\n");

    exit(1);
}

fwrite(STDOUT, sprintf(
    "scrubbed %s -> %s (%d bytes, %d tracking tokens, %d signed tokens, %d UUIDs, %d opaque hexes"
    . " and %d named identifier(s) replaced)\n",
    basename($in),
    $out,
    strlen($message),
    $tokenSeq,
    $jwtSeq,
    $uuidSeq,
    $hexSeq,
    count($needles),
));
