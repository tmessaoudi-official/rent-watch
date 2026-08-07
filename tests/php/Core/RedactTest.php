<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Redact;

/**
 * `CLAUDE.md` hard rule 7 forbids credentials reaching a log or a committed file. This class is the
 * choke point that enforces it for adapter error text, which travels from an exception into
 * `source_runs.error` and out again in a user-facing notification.
 *
 * The tests are written from both sides, because a masker has two failure modes and only one of
 * them is loud: leaking a secret (silent, and the reason this exists) and destroying the diagnostic
 * (loud, but it makes people delete the masker).
 */
#[CoversClass(Redact::class)]
final class RedactTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function secrets(): iterable
    {
        yield 'IDFM key as a query parameter' => [
            'GET https://prim.iledefrance-mobilites.fr/v2/journey?apikey=abc123XYZ&from=admin',
            ['abc123XYZ'],
        ];

        yield 'generic key parameter' => ['https://api.test/search?key=s3cr3tvalue', ['s3cr3tvalue']];
        yield 'access token' => ['refused: access_token=eyJhbGciOi.J9.sig', ['eyJhbGciOi.J9.sig']];
        yield 'password in a connection string' => ['imap connect failed password=hunter2', ['hunter2']];
        yield 'JSON body' => ['{"client_secret": "abcdefgh12345678"}', ['abcdefgh12345678']];
        yield 'URL userinfo' => ['https://takieddine:hunter2@imap.test/INBOX', ['hunter2', 'takieddine']];
        yield 'bearer header' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9', ['eyJhbGciOiJIUzI1NiJ9']];
        yield 'basic header' => ['Authorization: Basic dGFraTpodW50ZXIy', ['dGFraTpodW50ZXIy']];
        yield 'mailbox' => ['LOGIN failed for jean.dupont@example.com', ['jean.dupont@example.com']];
        yield 'signature parameter' => ['?sig=9f8e7d6c5b4a3210&page=2', ['9f8e7d6c5b4a3210']];

        // Every case below got through the first version, and four of them are keys `.env.example`
        // itself names. They share a shape the original patterns could not see: the secret carries
        // no `name=value` delimiter, because its transport is a space or a URL path segment.

        yield 'IMAP LOGIN, space-separated' => [
            'A01 NO [AUTHENTICATIONFAILED] in command: A01 LOGIN alertes-immo@example.net Hunter2Secret',
            ['Hunter2Secret'],
        ];

        yield 'POP3 PASS' => ['-ERR authorization failed: PASS Hunter2Secret', ['Hunter2Secret']];

        yield 'pass= (what imap_open emits)' => [
            'imap_open(): Login failed for user=alertes host=imap.example.net pass=Hunter2Secret',
            ['Hunter2Secret'],
        ];

        yield 'Telegram bot token in the URL PATH' => [
            'HTTP 404 https://api.telegram.org/bot7412345678:AAH9xQvKQ_fake_token_value/sendMessage',
            ['AAH9xQvKQ_fake_token_value'],
        ];

        yield 'ntfy topic in the URL path' => [
            // docs/OPEN-QUESTIONS.md Q9 calls the topic a secret: anyone who knows it reads the
            // notifications.
            'HTTP 500 https://ntfy.sh/rentwatch-a8f3d9c1-private',
            ['rentwatch-a8f3d9c1-private'],
        ];

        yield 'RFR income figure, French spacing' => [
            'critère de ressources : RFR N-2 = 41 250 € — au-dessus du plafond',
            ['41 250'],
        ];

        yield 'RFR income figure, env-var spelling' => ['RFR_N2=41250 dépasse le plafond', ['41250']];
    }

    /**
     * The masker must not eat the words that say what went wrong.
     *
     * Each of these is a real diagnostic shape that an over-eager pattern destroyed: `auth:` before
     * a URL, `key:` in a YAML error, `Login failed for` before the actual failure, and a `?` query
     * containing an `@` which made the userinfo pattern mask the HOST — pointing a debugging human
     * at the wrong server entirely.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function diagnostics(): iterable
    {
        yield 'auth: before a URL' => [
            'Erreur auth: https://www.inli.fr/api/recherche a répondu 403 Forbidden',
            ['https://www.inli.fr/api/recherche', '403'],
        ];

        yield 'key: in a config error' => [
            'erreur config à la ligne 12 : key: sources n\'est pas une liste',
            ['sources', 'ligne 12'],
        ];

        yield 'Login failed for' => [
            'imap_open(): Login failed for user=alertes host=imap.example.net',
            ['Login failed for', 'imap.example.net'],
        ];

        yield 'host:port with an @ in the query' => [
            'GET https://host.example.com:8443/api?redirect=agent@example.com timed out',
            ['host.example.com:8443', '/api'],
        ];

        yield 'a value that is itself a URL is not the secret' => [
            // The secret would be INSIDE such a URL, and the query-parameter branch reaches it on
            // its own pass. Masking the whole value destroys the endpoint instead.
            'callback refused: key=https://callback.test/hook?apikey=abc123XYZ',
            ['https://callback.test/hook'],
        ];

        yield 'rents and counts are not secrets' => [
            '0 annonces — loyer=1450 charges=120 surface=68',
            ['loyer=1450', 'charges=120', 'surface=68'],
        ];
    }

    /** @param list<string> $mustSurvive */
    #[DataProvider('diagnostics')]
    public function testTheDiagnosticIsNotDestroyed(string $raw, array $mustSurvive): void
    {
        $masked = Redact::text($raw);

        self::assertNotNull($masked);

        foreach ($mustSurvive as $fragment) {
            self::assertStringContainsString(
                $fragment,
                $masked,
                sprintf('« %s » was masked away; a masker that eats diagnostics gets deleted', $fragment),
            );
        }
    }

    /** @param list<string> $mustVanish */
    #[DataProvider('secrets')]
    public function testTheSecretDoesNotSurvive(string $raw, array $mustVanish): void
    {
        $masked = Redact::text($raw);

        self::assertNotNull($masked);

        foreach ($mustVanish as $secret) {
            self::assertStringNotContainsString($secret, $masked, sprintf('« %s » survived masking', $secret));
        }
    }

    /**
     * The message must stay diagnosable. A masker that eats the host and the status code gets
     * deleted by the next person who has to debug a broken source at midnight, and then nothing
     * masks anything.
     */
    public function testTheDiagnosticSurvives(): void
    {
        $masked = Redact::text('HTTP 503 from https://prim.iledefrance-mobilites.fr/v2/journey?apikey=abc123XYZ');

        self::assertNotNull($masked);
        self::assertStringContainsString('503', $masked);
        self::assertStringContainsString('prim.iledefrance-mobilites.fr', $masked);
        self::assertStringContainsString('/v2/journey', $masked);
    }

    /** An ordinary adapter error passes through unchanged — masking is not paraphrasing. */
    public function testAnErrorWithNoSecretIsUntouched(): void
    {
        $plain = 'HTTP 503 Service Unavailable after 3 attempts (selector .listing-card matched 0 nodes)';

        self::assertSame($plain, Redact::text($plain));
    }

    public function testNullAndEmptyPassThrough(): void
    {
        self::assertNull(Redact::text(null));
        self::assertSame('', Redact::text(''));
    }

    /** Masking must not be defeated by the case a real header actually uses. */
    public function testMaskingIsCaseInsensitive(): void
    {
        foreach (['APIKEY=abc123XYZ', 'ApiKey: abc123XYZ', 'TOKEN=abc123XYZ'] as $raw) {
            $masked = Redact::text($raw);

            self::assertNotNull($masked);
            self::assertStringNotContainsString('abc123XYZ', $masked, $raw);
        }
    }

    /** Malformed bytes are still masked — they are an adapter artefact, not an escape hatch. */
    public function testMalformedInputNeverReturnsTheRawText(): void
    {
        $masked = Redact::text("token=s3cr3tvalue \xC3\x28 \xFF\xFE broken");

        self::assertNotNull($masked);
        self::assertStringNotContainsString('s3cr3tvalue', $masked);
    }

    /**
     * A PCRE failure must fail CLOSED, and the branch that does so is reachable.
     *
     * `preg_replace` returns null on a backtrack-limit exhaustion. Passing that through would hand
     * the caller the ORIGINAL unmasked text — the one outcome this class exists to prevent — so it
     * returns the mask instead and loses the diagnostic. Losing a diagnostic is recoverable;
     * leaking a credential into a database and a push notification is not.
     *
     * The limit is lowered here rather than a catastrophic input constructed, because that makes
     * the trigger exact and the test fast. Without this the fail-closed branch is unreachable in
     * practice, i.e. dead safety code — which this repo has deleted once already for good reason.
     */
    public function testAPcreFailureFailsClosed(): void
    {
        $original = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $masked = Redact::text('token=s3cr3tvalue ' . str_repeat('ab ', 200));

            self::assertSame(Redact::MASK, $masked);
            self::assertStringNotContainsString('s3cr3tvalue', (string) $masked);
        } finally {
            ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
        }
    }
}
