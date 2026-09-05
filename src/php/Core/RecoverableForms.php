<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * Every form a captured message could be READ BACK in, beyond its own literal text — THE ONE
 * decode cascade, shared by `tools/scrub-eml.php` (the write-time refusal) and
 * `tests/php/Repo/FixtureSecretsTest.php` (the CI guard over every committed fixture).
 *
 * It was two implementations until 2026-09-05, each docblock claiming they "share the mechanism"
 * and "must not diverge" — and they had diverged on exactly the shape this project has been bitten
 * by three times: the CI guard percent-decoded the raw content once, up front, and never
 * percent-decoded a freshly decoded base64 layer inside its depth loop, while the tool did. An
 * address wrapped as base64(percent-encoded(…)) — one layer deeper than the real `X-Mailin-EID`
 * incident — passed the guard and was refused by the tool (C2 round 6, resilience lens). A guard
 * that is a COPY of the tool is the same defect with a later date; this class is the fix.
 *
 * A token is only opaque until somebody decodes it, so this performs the decode the verification
 * is meant to defeat: quoted-printable, header unfolding, block base64, every long base64 run in
 * BOTH alphabets at all four alignments, three levels deep, percent-decoding before each scan,
 * HTML entities and percent-encoding of every result. An undecodable run is skipped rather than
 * guessed at — it carries nothing readable either way. The literal message is form 0, so a caller
 * scanning the result needs no separate look at the raw text.
 */
final class RecoverableForms
{
    /** @return list<string> */
    public static function of(string $message): array
    {
        // HEADER FOLDING FIRST (2026-09-05). RFC 5322 folds a long header across continuation lines
        // (`\r\n` + SP/TAB), and a base64 blob folded that way is N fragments to the run scan below:
        // each decodes to noise or to a slice of the payload, and the ADDRESS straddling a fold is in
        // none of them. A real La Centrale `X-MSFBL` header carried the subscriber's address that way
        // past this tool — `scrubbed`, exit 0 — and only `FixtureSecretsTest` stopped the commit, by
        // the luck of one fragment decoding to the local part. The quoted-printable decode does NOT
        // unfold headers: QP soft breaks end in `=`, a header fold does not. Joining continuation lines
        // can only REVEAL text, never hide it, so the joined form is scanned beside the raw one.
        $headersUnfolded = (string) preg_replace('~\r?\n[ \t]+~', '', $message);
        $unfolded = quoted_printable_decode($message);
        $forms = [$message, $unfolded, $headersUnfolded, quoted_printable_decode($headersUnfolded)];

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
        $texts = [$message, $unfolded, $headersUnfolded];
        foreach ([$message, $unfolded, $headersUnfolded] as $text) {
            // A run of lines that are PURELY base64 alphabet, at ANY width, gated only on the total
            // decoded length below.
            //
            // Round 3 lowered a per-line floor from 40 to 20 to catch a 36-column fold; round 4 showed
            // that is the same defect with a smaller number — at a 19-column fold the pattern matched
            // nothing, the decode never ran, and the tool wrote the file and reported it clean with the
            // address recoverable by one `base64 -d`. A per-line WIDTH was never the real constraint;
            // total length is, and `strlen($stripped) < 40` one line below already enforces it. This
            // file's own rule: "a check that only understands the encodings already seen is the same
            // defect with a later date."
            //
            // Prose cannot be swept up by accident: a line with a space in it does not match at all, so
            // only single-token lines join a run, and a run still has to decode strictly AND contain the
            // address to refuse anything. Over-refusing a fixture is the safe direction anyway.
            if (preg_match_all('~(?:^[A-Za-z0-9+/]+={0,2}\r?\n?)+~m', $text, $blocks) > 0) {
                foreach ($blocks[0] as $block) {
                    $stripped = (string) preg_replace('~\s+~', '', $block);
                    if (strlen($stripped) < 40) {
                        continue;
                    }
                    $decoded = base64_decode($stripped, true);
                    if ($decoded !== false && $decoded !== '') {
                        $forms[] = $decoded;
                        $texts[] = $decoded;
                        // A `charset=utf-16` body: every ASCII byte is followed by a NUL, so `stripos`
                        // on the raw bytes never matches the address (round-3 panel). Both orders.
                        if (str_contains($decoded, "\0")) {
                            foreach (['UTF-16LE', 'UTF-16BE'] as $order) {
                                $forms[] = (string) @mb_convert_encoding($decoded, 'UTF-8', $order);
                            }
                        }
                    }
                }
            }
        }

        // A WORKLIST, NOT ONE PASS (round-5 panel, 2026-08-31). This used to iterate `$texts` once and
        // append every decode to `$forms` ALONE — so a run whose decode contains ANOTHER run was never
        // decoded twice, and Bien'ici wraps its links in an outer base64 layer, which means the literal
        // `eyJ` never appears in the raw, unfolded or base64-block form. Three committed, pushed
        // fixtures carried the subscriber's address one `base64 -d | base64 -d` away while this tool and
        // `FixtureSecretsTest` both reported clean. Round 4's own re-scan of "raw/QP/unfolded forms" was
        // exactly one decode short of a shape already in the tree.
        //
        // Bounded at three rounds: deep enough for outer-b64 -> JWT -> payload, shallow enough that a
        // pathological file cannot make this run for ever. Over-refusing a fixture is the safe direction
        // anyway — measured, all committed fixtures that should write still write.
        $queue = $texts;
        for ($depth = 0; $depth < 3 && $queue !== []; ++$depth) {
            $next = [];

            foreach ($queue as $text) {
                // PERCENT-DECODE FIRST, and this line is a P0's whole cause (2026-09-04).
                // `%` is outside the run class, so a percent-encoded blob SPLITS: the
                // surviving run starts two characters late and strict base64 decodes it to
                // garbage, which reads as "nothing recoverable here". Measured on a real
                // `X-Mailin-EID`: `starts: 2BdGFr… len 162` decoding to 121 bytes of noise,
                // while one `rawurldecode` first yields the subscriber's address in clear.
                //
                // `rawurldecode`, NOT `urldecode`: the latter turns `+` into a space, which
                // would corrupt any run that is genuinely base64 rather than percent-encoded.
                $text = rawurldecode($text);

                // BOTH ALPHABETS. This class was URL-safe only — `[A-Za-z0-9_\-]` — which is the
                // percent-encoding P0 one alphabet over: `+` and `/` are the STANDARD alphabet's two
                // extra characters, so a standard-encoded blob SPLIT on them, each fragment started off
                // the 4-byte boundary, strict decoding returned garbage, and the tool reported
                // `scrubbed` while the address stayed one decode away. A round-5 panel measured 3 of 50
                // realistic ESP-shaped captures written with exit 0. `strtr` below is a no-op on a
                // standard run, so one decode path still serves both.
                preg_match_all('~[A-Za-z0-9_+/\-]{16,}~', $text, $runs);

                foreach ($runs[0] as $run) {
                    // FOUR ALIGNMENTS. base64 carries 3 bytes per 4 characters, so a run that does not
                    // START on a boundary decodes to noise — and whether it does is an accident of what
                    // preceded it in the file. The real `X-Mailin-EID` was readable at offset 0 by
                    // luck; a live-shaped JWT was measured recoverable at only 2 of 8 offsets.
                    for ($offset = 0; $offset < 4; ++$offset) {
                        $slice = substr($run, $offset);

                        if (strlen($slice) < 16) {
                            break;
                        }

                        $padded = $slice . str_repeat('=', (4 - strlen($slice) % 4) % 4);
                        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

                        if ($decoded !== false && $decoded !== '') {
                            $forms[] = $decoded;
                            $next[] = $decoded;
                        }
                    }
                }
            }

            $queue = $next;
        }

        // AN ENTITY-ENCODED AND A PERCENT-ENCODED ADDRESS ARE BOTH RECOVERABLE (round-5 panel).
        // `CLAUDE.md` already records that a portal's `text/plain` alternative is generated from its HTML
        // and does not decode entities on the way, so `&#116;&#97;…` is a shape this class of mail really
        // emits; and one `%2E` in place of a dot defeats both the literal address check and the local-part
        // fallback. Neither is reachable by `str_replace($address)`, so only a decoding check can see it.
        // Applied to every form gathered above, including the decoded ones.
        foreach ($forms as $form) {
            $entity = html_entity_decode($form, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($entity !== $form) {
                $forms[] = $entity;
            }

            $percent = rawurldecode($form);
            if ($percent !== $form) {
                $forms[] = $percent;
            }
        }

        return $forms;
    }
}
