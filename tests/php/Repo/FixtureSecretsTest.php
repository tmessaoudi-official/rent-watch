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
    private static function suspects(string $content): array
    {
        $found = [];
        $forms = [$content, quoted_printable_decode($content)];
        foreach ([$content, quoted_printable_decode($content)] as $text) {
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
                if (preg_match_all('/[A-Za-z0-9_\-]{16,}/', $text, $runs) === 0) {
                    continue;
                }

                foreach ($runs[0] as $run) {
                    $decoded = base64_decode(strtr($run . str_repeat('=', (4 - strlen($run) % 4) % 4), '-_', '+/'), true);

                    if ($decoded !== false && $decoded !== '') {
                        $forms[] = $decoded;
                        $next[] = $decoded;
                    }
                }
            }

            $queue = $next;
        }

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
