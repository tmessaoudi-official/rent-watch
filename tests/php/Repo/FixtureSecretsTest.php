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
        $content = (string) file_get_contents($path);

        foreach (self::patterns() as $kind => $pattern) {
            if (preg_match_all($pattern, $content, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $hit) {
                self::assertMatchesRegularExpression(
                    self::PLACEHOLDER,
                    $hit,
                    $label . ' carries what looks like a live ' . $kind . '. Scrub it: replace the '
                        . 'value with a visibly fake placeholder of the same shape (see '
                        . 'tests/fixtures/rent/inli/search.html), so the parser still sees the structure '
                        . 'it would see live. Do not narrow the pattern and do not add an exception.',
                );
            }
        }

        // Reaching here with no credential-shaped string at all is the ordinary case, and PHPUnit
        // needs an assertion to not call the test risky.
        self::assertTrue(true);
    }
}
