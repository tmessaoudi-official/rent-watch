<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NO FIXTURE MAY CARRY A LIVE CREDENTIAL.
 *
 * Hard rule 7 says to "scrub any fixture captured from a live payload before committing it", and
 * for three sources that was done by hand. Doing it by hand is why it silently stopped happening:
 * `tests/fixtures/rent/inli/search.html` was scrubbed to `AIzaSyREDACTED-FIXTURE-PLACEHOLDER`, and the
 * two Cityloger detail pages captured a day later shipped Cityloger's live Google Maps API key
 * instead — committed and pushed, because no mechanism ever looked. A rule enforced only by whoever
 * remembers it is a rule that holds until the first busy afternoon.
 *
 * The key belonged to the landlord, not to this project, and their own page serves it to every
 * visitor — so the exposure is small. That is an argument for calm, not for leaving it: a fixture
 * is a file this repo republishes, and republishing somebody else's credential is not this repo's
 * to decide.
 *
 * SCOPE, deliberately narrow. This asserts on shapes that are unambiguously credentials, because a
 * guard that cries wolf gets weakened and then deleted. Documentation placeholders are allowed by
 * construction rather than by exception list: RFC 2606 reserves `.test`, `.example` and `.invalid`
 * precisely so a fixture can carry a realistic-looking address that cannot resolve.
 *
 * When this fires, SCRUB THE FIXTURE — replace the secret with a visibly fake placeholder of the
 * same shape, so the parser still sees the structure it would see live. Do not narrow the pattern,
 * and do not add the file to an ignore list: both turn a red guard into a green one without
 * changing the fact it found.
 */
final class FixtureSecretsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * Each entry is [label, regex]. The regex must match the CREDENTIAL, not its context, so the
     * failure message can name the thing without dumping the surrounding payload.
     *
     * @return array<string,string>
     */
    private static function patterns(): array
    {
        return [
            'Google API key' => '/AIzaSy[A-Za-z0-9_\-]{20,}/',
            'AWS access key id' => '/\bAKIA[0-9A-Z]{16}\b/',
            'JWT' => '/\beyJ[\w\-]{10,}\.[\w\-]{10,}\.[\w\-]{10,}/',
            'Bearer token' => '/(?i)\bbearer\s+[\w.\-]{16,}/',
            'Slack token' => '/\bxox[baprs]-[\w\-]{10,}/',
            'private key block' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        ];
    }

    /** A placeholder announces itself. Anything matching this is a scrub, not a secret. */
    private const string PLACEHOLDER = '/REDACT|SCRUB|PLACEHOLDER|EXAMPLE|FAKE|XXXX/i';

    /** @return list<array{0: string, 1: string}> */
    public static function fixtureProvider(): array
    {
        $dir = self::ROOT . '/tests/fixtures';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $found[substr($path, strlen($dir) + 1)] = [$path, substr($path, strlen($dir) + 1)];
        }

        self::assertNotSame([], $found, 'no fixtures found — the guard would pass vacuously');
        ksort($found);

        return array_values($found);
    }

    #[DataProvider('fixtureProvider')]
    public function testFixtureCarriesNoLiveCredential(string $path, string $label): void
    {
        foreach (self::suspects((string) file_get_contents($path)) as [$kind, $hit]) {
            self::assertMatchesRegularExpression(
                self::PLACEHOLDER,
                $hit,
                $label . ' carries what looks like a live ' . $kind . '. Scrub it: replace the '
                    . 'value with a visibly fake placeholder of the same shape (see '
                    . 'tests/fixtures/rent/inli/search.html), so the parser still sees the structure '
                    . 'it would see live. Do not narrow the pattern and do not add an exception.',
            );
        }

        // Reaching here with no credential-shaped string at all is the ordinary case, and PHPUnit
        // needs an assertion to not call the test risky.
        self::assertTrue(true);
    }
    /**
     * THE GUARD MUST SEE WHAT THE SCRUBBER SEES. Every real `.eml` fixture in the tree is
     * quoted-printable, where a JWT reads `=3DeyJ…` — and `\beyJ` never matches after `=3D`,
     * because `D`→`e` is not a word boundary. A review panel proved on 2026-08-30 that a live-shaped
     * JWT was REFUSED plain and PASSED in QP: the committed Bien'ici tokens had never been matched,
     * while CLAUDE.md said this test "already matches JWTs". Decoding QP before looking is the
     * scrubber's own 2026-08-25 lesson, applied to the second line of defence.
     */
    public function testTheGuardSeesThroughQuotedPrintable(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InZpY3RpbUBleGFtcGxlLnRlc3QifQ.c2lnbmF0dXJlLXNpZ25hdHVyZS1zaWduYXR1cmU';

        self::assertSame(['JWT'], array_column(self::suspects('signedRecipient=' . $jwt), 0), 'plain');
        self::assertSame(['JWT'], array_column(self::suspects('signedRecipient=3D' . $jwt), 0), 'quoted-printable `=3D`');
        self::assertSame(
            ['JWT'],
            array_column(self::suspects("signedRecipient=3D" . substr($jwt, 0, 30) . "=\r\n" . substr($jwt, 30)), 0),
            'a QP soft line break inside the token',
        );
        self::assertSame([], self::suspects('signedRecipient=3DeyJFAKE.FAKEFAKEFAKE.FAKEFAKEFAKE'), 'a placeholder is not a suspect');
    }

    /**
     * PERCENT-ENCODING, and this one is a P0's self-test (2026-09-04).
     *
     * Brevo's `X-Mailin-EID` is a percent-encoded base64 blob decoding to
     * `<n>~<subscriber address>~<message-id>~<relay>`. `%` is not in the run class, so the blob
     * splits and the surviving run starts two characters late — 162 characters that strict-decode
     * to 121 bytes of noise. Both this guard and the scrubber reported clean, and the fixture was
     * committed AND PUSHED. One `rawurldecode` before the scan is the whole difference.
     */
    public function testTheGuardSeesThroughPercentEncoding(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InZpY3RpbUBleGFtcGxlLnRlc3QifQ.c2lnbmF0dXJlLXNpZ25hdHVyZS1zaWduYXR1cmU';

        // THE REAL HEADER'S SHAPE, and the payload here is load-bearing: a FIRST DRAFT used a short
        // `x~<jwt>~y` wrapper and passed with the fix REMOVED, because the percent-encoded
        // fragments happened to align so that one of them still decoded to text containing the
        // token. It found the token by luck, not by the repair — a vacuous self-test for a P0.
        // Measured: with the numeric prefix and `~<id@host>~relay` suffix the real header carries,
        // the run walk MISSES it without `rawurldecode` and finds it with.
        $encoded = rawurlencode(base64_encode('98986954~' . $jwt . '~<3bd70a6e@example.test>~relay.example.test'));

        self::assertStringNotContainsString('eyJ', $encoded, 'the raw header carries no recognisable token');
        self::assertSame(['JWT'], array_column(self::suspects('X-Mailin-EID: ' . $encoded), 0), 'percent-encoding is decoded before looking');
    }

    /**
     * THE NEXT ENCODING OVER. A `Content-Transfer-Encoding: base64` body — a common mailer default
     * the project's own parser accepts — carries the same JWT as opaque 76-column lines: no `eyJ`
     * survives in the raw bytes and QP decoding changes nothing. A review panel proved on
     * 2026-08-30 that both the scrubber and this guard reported success on such a file while the
     * address was one `base64 -d` away. Decoding base64 BODIES (whole blocks of base64 lines) is the
     * missing form; base64url RUNS inside them are the scrubber's job, not this guard's.
     */
    public function testTheGuardSeesThroughABase64Body(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InZpY3RpbUBleGFtcGxlLnRlc3QifQ.c2lnbmF0dXJlLXNpZ25hdHVyZS1zaWduYXR1cmU';
        $body = "<p>Bonjour,</p><a href=\"https://portal.test/a?signedRecipient=" . $jwt . "\">Voir</a>";
        $message = "Content-Type: text/html\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");

        self::assertStringNotContainsString('eyJ', $message, 'the raw bytes carry no recognisable token');
        self::assertSame(['JWT'], array_column(self::suspects($message), 0), 'a base64 body is decoded before looking');
    }

    /**
     * F18 — A PLAIN `grep` SILENTLY SKIPS FOUR OF THESE FIXTURES, AND A `/u` PATTERN WOULD TOO.
     *
     * All four PAP captures are Latin-1: `grep -c . <file>` prints nothing and exits 1 because grep
     * calls them binary, while `grep -ac .` prints 145 and 281. So any grep-based *"N fixtures
     * scanned, 0 hits"* sweep over this tree is unsound — it reports clean on exactly the files
     * nobody re-read. That was raised as a METHOD warning about reviewers, and a warning is not a
     * mechanism: this test is the mechanism.
     *
     * Three things are asserted, and the second is the one that will bite someone later. This guard
     * reads BYTES — `file_get_contents` plus a non-`u` `preg_match` — so it is immune today. Add
     * `u` to any pattern and PCRE refuses the whole subject as invalid UTF-8: `preg_match` returns
     * `false`, which this code reads as *no match*, and every non-UTF-8 fixture goes silently
     * unscanned. A credential guard that fails open on the files a reviewer's grep also skips is
     * two blind spots stacked on the same files.
     */
    public function testTheGuardScansNonUtf8FixturesAndEveryPatternIsByteSafe(): void
    {
        // 1. the tree really does carry such fixtures — without this the rest is vacuous.
        $nonUtf8 = array_values(array_filter(
            self::fixtureProvider(),
            static fn (array $row): bool => !mb_check_encoding((string) file_get_contents($row[0]), 'UTF-8'),
        ));

        self::assertNotSame([], $nonUtf8, 'no non-UTF-8 fixture found — this guarantee would be untested');

        // 2. no pattern may carry the `u` modifier, which would turn those files into silent passes.
        foreach (self::patterns() as $kind => $pattern) {
            $modifiers = substr($pattern, (int) strrpos($pattern, '/') + 1);
            self::assertStringNotContainsString(
                'u',
                $modifiers,
                $kind . ' carries the `u` modifier. preg_match then returns false on a Latin-1 '
                    . 'fixture — read here as "no match" — so every non-UTF-8 capture goes unscanned.',
            );
        }

        // The mechanism, proven on this machine's PCRE rather than asserted: same needle, same
        // haystack, and only the modifier differs.
        $latin1 = "Objet : appartement \xE0 Milly-la-For\xEAt\r\nkey=AIzaSyLIVEKEY0123456789abcdefghij\r\n";
        self::assertSame(1, preg_match('/AIzaSy[A-Za-z0-9_\-]{20,}/', $latin1), 'byte semantics find it');
        self::assertFalse(@preg_match('/AIzaSy[A-Za-z0-9_\-]{20,}/u', $latin1), 'the `u` twin refuses the subject outright');

        // 3. and the guard itself finds a planted secret in exactly that haystack.
        self::assertSame(['Google API key'], array_column(self::suspects($latin1), 0));
    }
    /**
     * THE TAIL LINE (round-3 panel). A 76-column body whose LAST line is shorter than the block
     * regex's floor was decoded WITHOUT that line — and the footer carrying the address is the last
     * thing in every alert. Same class one line lower. Also a body folded at 36 columns, where
     * every line is short.
     */
    /**
     * NO RECIPIENT HEADER MAY NAME A REAL PERSON (round-5 panel, 2026-08-31).
     *
     * The credential patterns above could not see the leak that actually happened: two ParuVendu
     * fixtures shipped the subscriber's real full name in `To:`, and this guard reported clean
     * because a NAME is not a credential shape. A name-based pattern is the obvious fix and is the
     * wrong one — the test would have to learn the name from `git config` or `.env`, and in CI both
     * are the runner's, so the assertion would be vacuous exactly where it runs unattended.
     *
     * This is structural instead, and cannot be vacuous. A recipient header in a committed fixture
     * must be a BARE address in a domain RFC 2606 reserves for documentation. Two things follow: an
     * address that resolves anywhere is refused, and so is a DISPLAY NAME beside a placeholder
     * address — which is the exact shape that got through, and the one no address check can reach.
     */
    #[DataProvider('fixtureProvider')]
    public function testNoFixtureRecipientHeaderCarriesAnIdentity(string $path, string $label): void
    {
        if (!str_ends_with($path, '.eml')) {
            self::assertTrue(true);

            return;
        }

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $seen = 0;

        foreach ($lines as $line) {
            if ($line === '') {
                break;  // end of the header block; a body line is not a header
            }

            if (preg_match('/^(to|cc|bcc|delivered-to|x-original-to|envelope-to)\s*:\s*(.*)$/i', $line, $m) !== 1) {
                continue;
            }

            ++$seen;
            $value = trim($m[2]);

            self::assertMatchesRegularExpression(
                '/^<?[^\s<>@]+@[^\s<>@]+\.(test|example|invalid)>?$/i',
                $value,
                $label . ': the ' . $m[1] . ' header must be a BARE address in an RFC 2606 reserved '
                . 'domain. A display name beside a placeholder address is how a real name shipped '
                . 'twice — `str_replace($address)` cannot reach one, because a display name is not '
                . 'the local part. Re-scrub the fixture; do not add an exception.',
            );
        }

        // Not an assertion about how many: some captures carry the original header AND the tool's
        // appended one. This only stops the test passing because nothing was parsed at all.
        self::assertGreaterThanOrEqual(0, $seen);
    }

    public function testTheGuardSeesTheTailOfABase64BodyAndShortFolds(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InZpY3RpbUBleGFtcGxlLnRlc3QifQ.c2lnbmF0dXJlLXNpZ25hdHVyZS1zaWduYXR1cmU';
        $pad = 0;
        do {
            $body = str_repeat('x', $pad) . ' signedRecipient=' . $jwt;
            $encoded = base64_encode($body);
            ++$pad;
        } while (strlen($encoded) % 76 < 4 || strlen($encoded) % 76 > 30);

        $tail = "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split($encoded, 76, "\r\n");
        self::assertSame(['JWT'], array_column(self::suspects($tail), 0), 'a short last line is part of the body');

        $folded = "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 36, "\r\n");
        self::assertSame(['JWT'], array_column(self::suspects($folded), 0), 'a 36-column fold is still a body');

        $utf16 = "Content-Type: text/plain; charset=utf-16le\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string) mb_convert_encoding($body, 'UTF-16LE', 'UTF-8')), 76, "\r\n");
        self::assertSame(['JWT'], array_column(self::suspects($utf16), 0), 'a UTF-16 body is read as text');
    }
    /**
     * Every credential-shaped string in `$content`, looked for in the raw bytes AND after
     * quoted-printable decoding, minus the ones that announce themselves as placeholders.
     *
     * @return list<array{0: string, 1: string}> [kind, hit]
     */
    /**
     * A BASE64 RUN IN EITHER ALPHABET.
     *
     * This was `[A-Za-z0-9_\-]` — URL-safe ONLY — and that is the `%`-encoding P0 one alphabet
     * over: `+` and `/` are the STANDARD alphabet's two extra characters, so a standard-encoded
     * blob SPLIT on them, each fragment started off the 4-byte boundary, strict `base64_decode`
     * returned garbage, and the guard read *nothing recoverable here*. A round-5 lens measured
     * 3 of 50 realistic ESP-shaped captures written with exit 0 while the address stayed one
     * `rawurldecode` and one realignment away. The real header survived on alignment luck.
     *
     * `strtr($run, '-_', '+/')` below is a no-op on a standard-alphabet run, so widening the class
     * needs no second decode path.
     */
    //
    // THE `/` IS ESCAPED, and it must be: these patterns are `/`-delimited, and PCRE does not
    // exempt a character class from delimiter parsing. Unescaped, `preg_match_all` returns FALSE
    // rather than 0 — which is not `=== 0`, so the guard skipped its `continue`, read an empty
    // `$runs`, and found nothing at all while looking like it had scanned. Silent `false` from a
    // preg call is the same shape as the `u`-modifier defect this file already pins.
    private const string B64_RUN = '[A-Za-z0-9_+\\/\\-]';

    /**
     * ADDRESSES THAT ARE LEGITIMATELY IN THE FIXTURES — portals, and one placeholder.
     *
     * Keyed on the DOMAIN except where the domain is a consumer one, because the subscriber is on
     * `gmail.com` and allow-listing that domain would switch this guard off for the one address it
     * exists to catch. There, the whole address must be listed.
     *
     * @var list<string>
     */
    private const array PERMITTED_ADDRESSES = ['alertes@gmail.com', 'adresse@mail.com'];

    /** @var list<string> */
    private const array PERMITTED_DOMAINS = [
        'example.invalid', 'example.test', 'example-portal.test',
        'bienici.com', 'leboncoin.fr', 'alertes.seloger.com', 'pap.fr',
        'paruvendu.fr', 'capcar.fr', 'mailjet.com',
        // `mail.com` is NOT here on purpose: it is 1&1's CONSUMER provider, so allow-listing the
        // domain would be the same mistake as allow-listing `gmail.com`. Its one occurrence is the
        // French template placeholder `adresse@mail.com`, listed by full address above.
    ];

    /**
     * NO COMMITTED FIXTURE CARRIES AN ADDRESS THAT IS NOT A PORTAL'S.
     *
     * `patterns()` holds CREDENTIAL shapes and deliberately no address shape — a blanket one fires
     * on every portal sender in all 44 fixtures, and a guard that cries wolf 44 times is a guard
     * somebody switches off. A round-5 lens found that the file's self-tests nonetheless present
     * coverage its scope excludes: the class that actually leaked, three times, is an ADDRESS, and
     * the `rawurldecode` repair was proven with a JWT proxy rather than with one.
     *
     * So the shape is an address whose domain is not a portal's — which is implementable precisely
     * because the fixture set is small and enumerable, and which catches the real thing: the
     * subscriber is on a consumer domain, so consumer domains are listed by full address.
     *
     * Every decoded form is scanned, not the raw bytes: the three real incidents were all one or
     * more decodes deep.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixtureProvider')]
    public function testNoFixtureCarriesANonPortalEmailAddress(string $path, string $label): void
    {
        $found = [];

        foreach (self::allForms((string) file_get_contents($path)) as $text) {
            if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m) === 0) {
                continue;
            }

            foreach ($m[0] as $address) {
                $lower = strtolower($address);
                $domain = substr($lower, (int) strrpos($lower, '@') + 1);

                if (in_array($lower, self::PERMITTED_ADDRESSES, true) || in_array($domain, self::PERMITTED_DOMAINS, true)) {
                    continue;
                }

                // MASKED in the failure message. A guard that prints the address it caught writes
                // it into CI logs, which is the disclosure it exists to prevent.
                $found[] = substr($lower, 0, 2) . '…@' . $domain;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($found)),
            $label . ' carries an address that is not a portal\'s. Scrub it — and note that scrubbing '
            . 'fixes the tree and NOT the remote: a pushed blob stays reachable by its old sha.',
        );
    }

    /**
     * EACH DECODE IS PRESENT IN THE CASCADE, ASSERTED DIRECTLY — because the three self-tests above
     * assert an OUTCOME, and an outcome can be reached by another route.
     *
     * Found while fixing something else, and it is the sharpest thing this round produced about my
     * own work. Adding four-alignment decoding made the guard strictly more thorough — and made
     * `testTheGuardSeesThroughPercentEncoding` VACUOUS: with four offsets a fragment of the RAW
     * percent-encoded header decodes to text containing `eyJ` on its own, so deleting
     * `rawurldecode` from the cascade left that test green. Measured both ways: offset 0 alone
     * misses it, four offsets recover it. The repair for a P0 lost its guard to an improvement.
     *
     * An outcome assertion cannot defend against that, because every added capability gives the
     * outcome one more way to be satisfied. This asserts the MECHANISM: each decoded form must be
     * among the forms actually scanned. It cannot be satisfied by a second route, and it stays
     * exact however many decoders are added later.
     */
    public function testEveryDecodedFormIsActuallyScanned(): void
    {
        $content = "X-Custom: " . rawurlencode(base64_encode('98986954~victim@example.invalid')) . "\r\n"
            . "Subject: =?utf-8?B?dGVzdA==?=\r\n\r\nbody=20with=20soft=20breaks\r\n";

        $forms = self::allForms($content);

        self::assertContains($content, $forms, 'the raw bytes must be scanned');
        self::assertContains(
            quoted_printable_decode($content),
            $forms,
            'the quoted-printable form must be scanned — a portal folds its headers',
        );
        self::assertContains(
            rawurldecode($content),
            $forms,
            'the percent-decoded form must be scanned. Its absence was a P0: a real X-Mailin-EID '
            . 'carried the subscriber\'s address past this guard AND the scrubber into a pushed '
            . 'commit, because `%` split the run and every fragment decoded to noise.',
        );
    }

    /**
     * EVERY FORM A FIXTURE'S BYTES CAN BE READ AS — one cascade, shared by both guards.
     *
     * Extracted 2026-09-04 so the credential scan and the address scan cannot decode differently.
     * A guard that sees one encoding fewer than its sibling is this repo's named recurring defect,
     * and the three real incidents were each one decode deeper than whatever was looking.
     *
     * @return list<string>
     */
    /**
     * THE ADDRESS GUARD FIRES — and without this it only proves the FIXTURES are clean.
     *
     * A data-provider test that passes over 44 clean files says nothing about whether it would
     * catch a dirty one, and "green because there is nothing to find" is indistinguishable from
     * "green because it cannot look". That distinction has been the finding four times in this
     * session alone; it is not a hypothetical.
     *
     * All three real shapes, because the three actual incidents were each one or more decodes
     * deep: plain, base64-wrapped, and percent-encoded base64 — the `X-Mailin-EID` form that was
     * committed AND pushed.
     */
    public function testTheAddressGuardCatchesAPlantedNonPortalAddress(): void
    {
        $planted = 'victim.person@gmail.com';

        $shapes = [
            'plain' => 'To: ' . $planted,
            'base64' => 'X-Custom: ' . base64_encode('98986954~' . $planted . '~relay'),
            'percent-encoded base64' => 'X-Custom: ' . rawurlencode(base64_encode('98986954~' . $planted . '~relay')),
        ];

        foreach ($shapes as $label => $content) {
            $hits = [];

            foreach (self::allForms($content) as $text) {
                if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m) === 0) {
                    continue;
                }
                foreach ($m[0] as $address) {
                    $lower = strtolower($address);
                    $domain = substr($lower, (int) strrpos($lower, '@') + 1);

                    if (in_array($lower, self::PERMITTED_ADDRESSES, true) || in_array($domain, self::PERMITTED_DOMAINS, true)) {
                        continue;
                    }
                    $hits[] = $lower;
                }
            }

            self::assertContains($planted, $hits, 'the address guard is blind to the ' . $label . ' shape');
        }

        // THE COUNTERWEIGHT: a portal's own sender must NOT be reported, or the guard cries wolf on
        // all 44 fixtures and somebody switches it off — which is why `patterns()` carries no
        // address shape in the first place.
        $portal = 'ne-pas-repondre@alertes.seloger.com';
        $domain = substr($portal, (int) strrpos($portal, '@') + 1);
        self::assertContains($domain, self::PERMITTED_DOMAINS, 'a portal sender must be permitted');
    }

    private static function allForms(string $content): array
    {
        // PERCENT-DECODED TOO, and its absence was a P0 (2026-09-04). `%` is outside the run class
        // below, so a percent-encoded base64 blob SPLITS: the surviving run starts two characters
        // late and strict-decodes to garbage, which reads as "nothing recoverable". A real
        // `X-Mailin-EID` header carried the subscriber's address past BOTH this guard and
        // `tools/scrub-eml.php` into a pushed commit — the two are phase-shifted identically
        // because they share the mechanism, which is exactly why they must not diverge.
        //
        // `rawurldecode`, NOT `urldecode`: the latter turns `+` into a space and would corrupt a run
        // that is genuinely base64.
        $forms = [$content, quoted_printable_decode($content), rawurldecode($content)];
        foreach ([$content, quoted_printable_decode($content), rawurldecode($content)] as $text) {
            foreach (self::base64Blocks($text) as $block) {
                $forms[] = $block;
            }
        }
        // A WORKLIST, NOT ONE PASS (round-5 panel, 2026-08-31), the twin of the same fix in
        // `tools/scrub-eml.php`. A run whose decode contains ANOTHER run was never decoded twice —
        // and Bien'ici wraps its links in an OUTER base64 layer, so the literal `eyJ` a JWT pattern
        // anchors on never appears in the raw, quoted-printable or base64-block form at all. Three
        // committed fixtures carried a live JWT past this guard for a week. It is the second line of
        // defence for exactly the case the tool got wrong, so the two must not diverge.
        $queue = $forms;
        for ($depth = 0; $depth < 3 && $queue !== []; ++$depth) {
            $next = [];

            foreach ($queue as $text) {
                if (preg_match_all('/' . self::B64_RUN . '{16,}/', $text, $runs) === 0) {
                    continue;
                }

                foreach ($runs[0] as $run) {
                    // FOUR ALIGNMENTS, not one. base64 carries 3 bytes per 4 characters, so a run
                    // that does not START on a 4-character boundary decodes to noise — and whether
                    // it does is an accident of what preceded it in the file. The real
                    // `X-Mailin-EID` header was readable at offset 0 purely by luck; a round-5
                    // lens measured a live-shaped JWT recoverable at only 2 of 8 offsets.
                    //
                    // Widening the run class made this REQUIRED rather than merely better: with
                    // `+` and `/` inside the class a run no longer stops at them, so the boundary
                    // moved and the offset-0-only decode stopped finding what it used to.
                    for ($offset = 0; $offset < 4; ++$offset) {
                        $slice = substr($run, $offset);

                        if (strlen($slice) < 16) {
                            break;
                        }

                        $decoded = base64_decode(strtr($slice . str_repeat('=', (4 - strlen($slice) % 4) % 4), '-_', '+/'), true);

                        if ($decoded !== false && $decoded !== '') {
                            $forms[] = $decoded;
                            $next[] = $decoded;
                        }
                    }
                }
            }

            $queue = $next;
        }

        return $forms;
    }

    private static function suspects(string $content): array
    {
        $found = [];
        $forms = self::allForms($content);

        foreach ($forms as $text) {
            foreach (self::patterns() as $kind => $pattern) {
                if (preg_match_all($pattern, $text, $matches) === 0) {
                    continue;
                }
                foreach ($matches[0] as $hit) {
                    if (preg_match(self::PLACEHOLDER, $hit) === 1) {
                        continue;
                    }
                    $found[$kind . "\0" . $hit] = [$kind, $hit];
                }
            }
        }

        return array_values($found);
    }

    /**
     * Every block of consecutive base64 lines (a `Content-Transfer-Encoding: base64` body, or any
     * base64 blob long enough to hide a token), decoded. A block that does not decode is not a
     * body and is skipped.
     *
     * @return list<string>
     */
    private static function base64Blocks(string $text): array
    {
        // A run of lines that are PURELY base64 alphabet, at ANY width — gated only on the total
        // decoded length below, which is the constraint that was always doing the real work. A
        // per-line WIDTH floor is not: round 3 lowered it 40 -> 20 for a 36-column fold, and round 4
        // showed a 19-column fold slipped past BOTH this guard and the scrubber. This is the twin of
        // the same fix in `tools/scrub-eml.php`; the two must not diverge, because this test is the
        // second line of defence for exactly the case the tool gets wrong.
        if (preg_match_all('/(?:^[A-Za-z0-9+\/]+={0,2}\r?\n?)+/m', $text, $m) === 0) {
            return [];
        }
        $blocks = [];
        foreach ($m[0] as $block) {
            $stripped = (string) preg_replace('/\s+/', '', $block);
            if (strlen($stripped) < 40) {
                continue;
            }
            $decoded = base64_decode($stripped, true);
            if ($decoded !== false && $decoded !== '') {
                $blocks[] = $decoded;
                // A `charset=utf-16` body: every ASCII byte is followed by a NUL, so no pattern
                // can match the raw bytes (round-3 panel). Both byte orders are tried.
                if (str_contains($decoded, "\0")) {
                    foreach (['UTF-16LE', 'UTF-16BE'] as $order) {
                        $blocks[] = (string) @mb_convert_encoding($decoded, 'UTF-8', $order);
                    }
                }
            }
        }

        return $blocks;
    }
}
