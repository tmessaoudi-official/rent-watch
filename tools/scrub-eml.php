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
 * | the address in the body            | stated in the CNIL footer of every alert |
 *
 * Usage: `php tools/scrub-eml.php <in.eml> <out.eml> [address-to-mask]`
 *
 * It VERIFIES its own work: the address, the ESP list ids and every original `qs=` token must be
 * absent from the output, or it refuses to write. A scrubber that half-works is worse than none,
 * because its output looks scrubbed.
 */

$argvLocal = $_SERVER['argv'] ?? [];

if (count($argvLocal) < 3) {
    fwrite(STDERR, "usage: php tools/scrub-eml.php <in.eml> <out.eml> [address]\n");

    exit(2);
}

[$in, $out] = [$argvLocal[1], $argvLocal[2]];
$address = $argvLocal[3] ?? null;

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

if ($leaks !== []) {
    fwrite(STDERR, "REFUSING to write — scrub incomplete:\n  - " . implode("\n  - ", array_unique($leaks)) . "\n");

    exit(1);
}

if (file_put_contents($out, $message) === false) {
    fwrite(STDERR, "cannot write {$out}\n");

    exit(1);
}

fwrite(STDOUT, sprintf(
    "scrubbed %s -> %s (%d bytes, %d tracking tokens replaced)\n",
    basename($in),
    $out,
    strlen($message),
    $tokenSeq,
));
