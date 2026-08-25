<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\HttpResponse;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Adapters\Http\RobotsResolver;

/**
 * The status-code table in {@see RobotsResolver}, one row at a time.
 *
 * It lives here rather than in the CLI suite for a reason worth stating: every row is a POSTURE
 * decision — what a missing, forbidden or broken `robots.txt` licenses — and a posture that can only
 * be exercised through a full CLI round trip is a posture nobody will add a case to. The CLI suite
 * ({@see \RentWatch\Tests\Cli\ScoutRobotsTest}) proves the wiring exists; this one proves the wiring
 * carries the right verdict.
 *
 * The two rows that must never be collapsed into each other are `404` and `5xx`. They are both
 * "there is no usable robots.txt here", and they mean opposite things:
 *
 * - `404` — we successfully established that no file exists. RFC 9309 §2.3.1.3, *unavailable*: a
 *   crawler MAY access the site. Reading it as a disallow would silently disable every host that
 *   never wrote one, which is most of the web.
 * - `5xx` — we established nothing. RFC 9309 §2.3.1.4, *unreachable*: a crawler MUST assume complete
 *   disallow.
 */
#[CoversClass(RobotsResolver::class)]
final class RobotsResolverTest extends TestCase
{
    // ── the allow rows ────────────────────────────────────────────────────────────────────────────

    public function testATwoHundredIsParsedAndItsRulesApply(): void
    {
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
        ]);

        $robots = $resolver->forUrl('https://example.test/annonces');

        self::assertTrue($robots->parsed);
        self::assertTrue($robots->allows('/annonces'));
        self::assertFalse($robots->allows('/private/x'));
    }

    /**
     * The row that keeps this fix from disabling the open web.
     *
     * @param int $status a status meaning "asked and answered: no such file"
     */
    #[DataProvider('absentStatuses')]
    public function testAnAbsentRobotsAllowsEverything(int $status): void
    {
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse($status, 'Not Found'),
        ]);

        $robots = $resolver->forUrl('https://example.test/annonces');

        self::assertTrue($robots->parsed, 'an absent robots.txt is KNOWLEDGE, not a failure to read');
        self::assertTrue($robots->allows('/annonces'));
        self::assertTrue($robots->allows('/anything/at/all'));
        self::assertNull($robots->unavailableReason);
    }

    /** @return iterable<string, array{int}> */
    public static function absentStatuses(): iterable
    {
        yield '404 Not Found' => [404];
        yield '410 Gone' => [410];
    }

    public function testAnEmptyTwoHundredAlsoAllowsEverything(): void
    {
        // A zero-byte robots.txt is served by a great many sites and means exactly nothing is
        // restricted. It must not be confused with a body we failed to fetch.
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse(200, ''),
        ]);

        self::assertTrue($resolver->forUrl('https://example.test/x')->allows('/x'));
    }

    // ── the fail-closed rows ──────────────────────────────────────────────────────────────────────

    /** @param int $status a status after which we know nothing, or are being actively refused */
    #[DataProvider('failClosedStatuses')]
    public function testAnUnreadableRobotsDisallowsEverythingAndNamesTheCause(int $status): void
    {
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse($status, 'nope'),
        ]);

        $robots = $resolver->forUrl('https://example.test/annonces');

        self::assertFalse($robots->parsed);
        self::assertFalse($robots->allows('/annonces'));
        self::assertNotNull($robots->unavailableReason);
        self::assertStringContainsString((string) $status, (string) $robots->unavailableReason);
    }

    /** @return iterable<string, array{int}> */
    public static function failClosedStatuses(): iterable
    {
        // 401/403: RFC 9309 would permit access, and this project refuses anyway — a site answering
        // 403 to robots.txt is refusing this client, and hard rule 5 takes that at face value.
        yield '401 Unauthorized' => [401];
        yield '403 Forbidden' => [403];
        // 5xx: RFC 9309 §2.3.1.4 — MUST assume complete disallow.
        yield '500 Internal Server Error' => [500];
        yield '503 Service Unavailable' => [503];
        // A teapot is not a robots.txt either. The else-branch must not be a 5xx-only branch.
        yield '418 anything unrecognised' => [418];
    }

    public function testATransportFailureFailsClosedAndCarriesTheMessage(): void
    {
        $resolver = new RobotsResolver(new ThrowingHttpClient(new HttpError('resolving timed out after 20000 ms')));

        $robots = $resolver->forUrl('https://example.test/annonces');

        self::assertFalse($robots->allows('/annonces'));
        self::assertStringContainsString('timed out', (string) $robots->unavailableReason);
    }

    public function testAUrlWithNoHostFailsClosedRatherThanBeingSkipped(): void
    {
        // The failure mode this guards is the one the whole change is about: an input that cannot be
        // checked must be REFUSED, never waved through. A `null` robots was exactly "waved through".
        $resolver = $this->resolver([]);

        $robots = $resolver->forUrl('not-a-url');

        self::assertFalse($robots->allows('/anything'));
        self::assertStringContainsString('sans hôte', (string) $robots->unavailableReason);
    }

    // ── origins, which are what a caller memoises on ──────────────────────────────────────────────

    public function testTheResolverItselfDoesNotCacheAndSaysSo(): void
    {
        // Stated as a test rather than left implicit, because the guarantee "one robots.txt per host
        // per pass" is real and lives ELSEWHERE — in `Scout::sources()`, keyed on `originFor()`. A
        // future reader who assumes this class memoises would remove that local and reintroduce a
        // per-page robots fetch, which is load a landlord did not need to serve.
        $client = new CountingHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
        ]);
        $resolver = new RobotsResolver($client);

        $resolver->forUrl('https://example.test/a');
        $resolver->forUrl('https://example.test/b');

        self::assertSame(2, $client->calls, 'the resolver is stateless — Scout holds the cache');
    }

    #[DataProvider('origins')]
    public function testTheOriginIsTheCacheKeyACallerShouldUse(string $url, ?string $expected): void
    {
        self::assertSame($expected, (new RobotsResolver(new CountingHttpClient([])))->originFor($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function origins(): iterable
    {
        yield 'plain https' => ['https://example.test/annonces', 'https://example.test'];
        yield 'deep path and query' => ['https://example.test/a/b?page=2', 'https://example.test'];
        yield 'host is lowercased' => ['https://EXAMPLE.test/x', 'https://example.test'];
        yield 'scheme separates' => ['http://example.test/x', 'http://example.test'];
        yield 'port is part of it' => ['https://example.test:8443/x', 'https://example.test:8443'];
        yield 'no host at all' => ['not-a-url', null];
    }

    public function testEachOriginGetsItsOwnVerdict(): void
    {
        // A cached verdict keyed on something coarser than the origin would let one host's rules
        // silently govern another's — which is the same class of error as trusting a robots.txt
        // served by a different host.
        $resolver = $this->resolver([
            'https://a.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /annonces\n"),
            'https://b.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /autre\n"),
        ]);

        self::assertFalse($resolver->forUrl('https://a.test/annonces')->allows('/annonces'));
        self::assertTrue($resolver->forUrl('https://b.test/annonces')->allows('/annonces'));
        self::assertNotSame(
            $resolver->originFor('https://a.test/x'),
            $resolver->originFor('https://b.test/x'),
            'two hosts must not share a cache key',
        );
    }

    public function testHttpAndHttpsOnOneHostAreTwoDifferentFiles(): void
    {
        // RFC 9309 §2.3: robots.txt is scoped to scheme AND authority. Sharing one verdict between
        // them would let a permissive cleartext file license the https poll, or the reverse.
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /x\n"),
            'http://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow:\n"),
        ]);

        self::assertFalse($resolver->forUrl('https://example.test/x')->allows('/x'));
        self::assertTrue($resolver->forUrl('http://example.test/x')->allows('/x'));
        self::assertNotSame(
            $resolver->originFor('https://example.test/x'),
            $resolver->originFor('http://example.test/x'),
            'the scheme must separate the two files, and therefore the two cache keys',
        );
    }

    public function testThePortIsPartOfTheOrigin(): void
    {
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /x\n"),
            'https://example.test:8443/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow:\n"),
        ]);

        self::assertFalse($resolver->forUrl('https://example.test/x')->allows('/x'));
        self::assertTrue($resolver->forUrl('https://example.test:8443/x')->allows('/x'));
    }

    // ── the request itself ────────────────────────────────────────────────────────────────────────

    public function testItAsksForRobotsTxtAtTheOriginRootAndNotBesideThePage(): void
    {
        // `/a/b/robots.txt` is not a robots file. RFC 9309 §2.3 puts it at the authority root, and a
        // resolver that appended it to the page path would fetch a 404 forever and — under the 404
        // row above — conclude the site has no restrictions at all. That is the fail-open shape this
        // whole change exists to remove, reintroduced by a string-concatenation slip.
        $client = new CountingHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, ''),
        ]);

        (new RobotsResolver($client))->forUrl('https://example.test/deep/path/annonces?page=2');

        self::assertSame(['https://example.test/robots.txt'], $client->urls);
    }

    public function testItSetsNoUserAgentHeaderBecauseTheClientPinsTheHonestOne(): void
    {
        // CurlHttpClient THROWS on a caller-supplied User-Agent, since in cURL such a header
        // silently overrides CURLOPT_USERAGENT. A resolver that set one would therefore break only
        // against the real client, on a live poll, where no test runs.
        $client = new CountingHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, ''),
        ]);

        (new RobotsResolver($client))->forUrl('https://example.test/annonces');

        self::assertNotNull($client->lastRequest);
        foreach (array_keys($client->lastRequest->headers) as $name) {
            self::assertNotSame('user-agent', strtolower((string) $name));
        }
    }

    public function testTheProductTokenIsWhatMatchesAUserAgentGroup(): void
    {
        // RFC 9309 §2.2.1 matches on the product token. A group naming this tool must beat the
        // wildcard group, which is what lets a site grant us access it denies to everyone else.
        $resolver = new RobotsResolver(
            new CountingHttpClient([
                'https://example.test/robots.txt' => new HttpResponse(
                    200,
                    "User-agent: *\nDisallow: /\n\nUser-agent: rent-watch\nDisallow: /private/\n",
                ),
            ]),
            'rent-watch',
        );

        $robots = $resolver->forUrl('https://example.test/annonces');

        self::assertTrue($robots->allows('/annonces'), 'our own group must win over the wildcard');
        self::assertFalse($robots->allows('/private/x'));
    }

    // ── the refusal wording ───────────────────────────────────────────────────────────────────────

    public function testAnUnreadableFileIsNotReportedAsARuleThatDisallows(): void
    {
        // Reporting "robots.txt disallows /annonces" after a 500 sends the reader hunting through a
        // robots file for a line that does not exist, when the fault is a broken server.
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse(500, 'boom'),
        ]);

        $message = $resolver->forUrl('https://example.test/annonces')->refusal('/annonces');

        self::assertStringNotContainsString('robots.txt disallows', $message);
        self::assertStringContainsString('illisible', $message);
        self::assertStringContainsString('500', $message);
    }

    public function testARealRuleStillSaysDisallowsInTheWordsEverySuiteAsserts(): void
    {
        $resolver = $this->resolver([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /annonces\n"),
        ]);

        self::assertSame(
            'robots.txt disallows /annonces',
            $resolver->forUrl('https://example.test/annonces')->refusal('/annonces'),
        );
    }

    public function testAnUnavailableRobotsWithNoReasonStillProducesAReadableRefusal(): void
    {
        // `Robots::unavailable()` keeps its no-argument form, so a caller that supplies no reason
        // must not produce "illisible () — ".
        self::assertStringContainsString('cause inconnue', Robots::unavailable()->refusal('/x'));
    }

    // ── a 200 that is not a robots.txt ────────────────────────────────────────────────────────────

    /**
     * **A 200 CARRYING MARKUP IS "THERE IS NO ROBOTS.TXT", NOT "THERE ARE NO RULES".**
     *
     * Single-page applications serve their `index.html` for every unmatched path, so `/robots.txt`
     * answers `200 text/html` with an app shell. Parsed as robots that yields ZERO directives —
     * which reads as *allow everything*, and the whole fail-closed posture is defeated by a 200.
     *
     * Measured on a real candidate: `al-in.fr/robots.txt` returns the Angular app shell,
     * `Content-Type: text/html;charset=UTF-8`, HTTP 200 [2026-08-25]. AL'in is the one remaining
     * route to the Action Logement stock, so this is not a hypothetical host.
     *
     * It belongs on the *unreachable* side of the table rather than the *absent* side, and the
     * class docblock already says why: 404 is knowledge — we asked and established no file exists.
     * A 200 of HTML establishes nothing. The server answered something, and we cannot tell a
     * catch-all from a robots.txt served through a broken rewrite.
     */
    public function testATwoHundredOfHtmlIsUnreadableRatherThanPermissive(): void
    {
        $resolver = $this->resolver([
            'https://spa.test/robots.txt' => new HttpResponse(
                200,
                "<!DOCTYPE html>\n<html lang=\"fr\"><body><app-root></app-root></body></html>",
                ['content-type' => 'text/html;charset=UTF-8'],
            ),
        ]);

        $robots = $resolver->forUrl('https://spa.test/annonces');

        self::assertFalse($robots->parsed);
        self::assertFalse($robots->allows('/annonces'), 'an app shell is not a licence');
        self::assertNotNull($robots->unavailableReason);
        self::assertStringContainsString('text/html', (string) $robots->unavailableReason);
    }

    /**
     * The header alone is not enough, which is why the body is sniffed too.
     *
     * Plenty of SPA servers label the catch-all `text/plain`, or label everything from one static
     * handler. A real `robots.txt` never begins with `<`: the grammar allows a comment (`#`) or a
     * field name, and nothing else.
     */
    public function testAnHtmlBodyLabelledAsTextIsStillUnreadable(): void
    {
        $resolver = $this->resolver([
            'https://spa.test/robots.txt' => new HttpResponse(
                200,
                "<!DOCTYPE html>\n<html><body>app</body></html>",
                ['content-type' => 'text/plain'],
            ),
        ]);

        self::assertFalse($resolver->forUrl('https://spa.test/x')->parsed);
    }

    /**
     * A JSON catch-all is the same hole with a different first character.
     *
     * An API gateway answering `{"error":"not found"}` with a 200 parses to zero directives exactly
     * as an app shell does. `{` is as impossible at the start of a robots.txt as `<`.
     */
    public function testAJsonBodyIsUnreadableRatherThanPermissive(): void
    {
        $resolver = $this->resolver([
            'https://api.test/robots.txt' => new HttpResponse(
                200,
                '{"error":"not found"}',
                ['content-type' => 'application/json'],
            ),
        ]);

        self::assertFalse($resolver->forUrl('https://api.test/x')->parsed);
    }

    /**
     * A PARAMETERISED media type still parses. `text/plain; charset=utf-8` is the ordinary way to
     * serve one, and a check comparing the whole header string would fail every real host.
     */
    public function testAParameterisedMediaTypeStillParses(): void
    {
        $resolver = $this->resolver([
            'https://ok.test/robots.txt' => new HttpResponse(
                200,
                "User-agent: *\nDisallow: /private/\n",
                ['content-type' => 'text/plain; charset=utf-8'],
            ),
        ]);

        $robots = $resolver->forUrl('https://ok.test/annonces');

        self::assertTrue($robots->parsed);
        self::assertFalse($robots->allows('/private/x'), 'the rules must still apply');
    }

    /**
     * An ABSENT `Content-Type` still parses, because absence is not evidence.
     *
     * Servers omit it; RFC 9309 says the file MUST be `text/plain` but a missing header does not
     * tell us the body is markup, and the body sniff already covers the case where it is. Failing
     * closed here would take out real hosts for a header nobody looks at.
     */
    public function testAnAbsentContentTypeStillParses(): void
    {
        $resolver = $this->resolver([
            'https://bare.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /x/\n"),
        ]);

        $robots = $resolver->forUrl('https://bare.test/annonces');

        self::assertTrue($robots->parsed);
        self::assertFalse($robots->allows('/x/y'));
    }

    /**
     * And the row this fix must NOT creep into: a 404 body is not sniffed, because a 404 never
     * reaches the parser with its body in the first place. Pinned so the two paths stay separate.
     */
    public function testAnHtml404StillAllowsEverything(): void
    {
        $resolver = $this->resolver([
            'https://gone.test/robots.txt' => new HttpResponse(
                404,
                '<!DOCTYPE html><html><body>Not found</body></html>',
                ['content-type' => 'text/html'],
            ),
        ]);

        $robots = $resolver->forUrl('https://gone.test/annonces');

        self::assertTrue($robots->parsed, 'an absent file is knowledge, whatever the error page looks like');
        self::assertTrue($robots->allows('/annonces'));
    }

    /** @param array<string, HttpResponse> $table */
    private function resolver(array $table): RobotsResolver
    {
        return new RobotsResolver(new CountingHttpClient($table));
    }
}

/** Answers from a table, counts calls, and remembers the urls — an unlisted url is a 404. */
final class CountingHttpClient implements HttpClient
{
    public int $calls = 0;

    /** @var list<string> */
    public array $urls = [];

    public ?HttpRequest $lastRequest = null;

    /** @param array<string, HttpResponse> $table */
    public function __construct(private readonly array $table) {}

    public function send(HttpRequest $request): HttpResponse
    {
        ++$this->calls;
        $this->urls[] = $request->url;
        $this->lastRequest = $request;

        return $this->table[$request->url] ?? new HttpResponse(404, 'Not Found');
    }
}

/** Every request is a transport failure — a timeout, a DNS failure, a refused cross-host redirect. */
final class ThrowingHttpClient implements HttpClient
{
    public function __construct(private readonly HttpError $error) {}

    public function send(HttpRequest $request): HttpResponse
    {
        throw $this->error;
    }
}
