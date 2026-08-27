<?php

declare(strict_types=1);

namespace Scout\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\CurlHttpClient;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Cli\Scout;

/**
 * Hard rule 5, at the ONE place it was never enforced: the CLI's own wiring.
 *
 * `Robots` is implemented, and `HtmlSource`/`HttpJsonSource` consult it for the index, for every
 * paginated page and for each detail page — but every one of those checks is guarded by
 * `$this->robots !== null`, and until this suite existed both production construction sites in
 * {@see Scout::buildSource()} passed `null`. So robots.txt was enforced in tests, by injection, and
 * never once on a real poll. A rule the project states and never checks is a rule it does not have
 * — which is the sentence {@see \Scout\Adapters\Http\Robots} opens with.
 *
 * These tests therefore refuse to go anywhere near adapter injection. They drive the real `Scout`,
 * against a real `config/sources.json`, and assert on what the CLI prints. The failing-first shape
 * was: the fake client received ZERO requests for `/robots.txt`, so
 * {@see testTheCliFetchesRobotsBeforePollingASource} failed on its request-log assertion, which is
 * the defect expressed in one line.
 */
#[CoversNothing]
final class ScoutRobotsTest extends TestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->removeTree($root);
        }

        $this->tempRoots = [];
    }

    public function testTheCliFetchesRobotsBeforePollingASource(): void
    {
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $this->doctor($client);

        self::assertContains(
            'https://example.test/robots.txt',
            $client->urls,
            'the CLI must read robots.txt before polling — this is the assertion the defect failed',
        );
    }

    public function testRobotsIsReadBeforeTheSearchPageNotAfterIt(): void
    {
        // Order is the whole point. Reading robots.txt *after* the request it was supposed to
        // authorise turns the check into a post-mortem: the site has already been polled against
        // its own stated wishes, and refusing afterwards changes nothing it observed.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $this->doctor($client);

        $robotsAt = array_search('https://example.test/robots.txt', $client->urls, true);
        $searchAt = array_search('https://example.test/annonces', $client->urls, true);

        self::assertIsInt($robotsAt, 'robots.txt was never requested');
        self::assertIsInt($searchAt, 'the search page was never requested');
        self::assertLessThan($searchAt, $robotsAt, 'robots.txt must be read BEFORE the page it authorises');
    }

    public function testADisallowedSourceIsRefusedByTheCliRatherThanPolled(): void
    {
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /annonces\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $result = $this->doctor($client);

        self::assertStringContainsString('robots.txt', $result['out'] . $result['err']);
        self::assertNotContains(
            'https://example.test/annonces',
            $client->urls,
            'a disallowed source must never be fetched — refusing AFTER the request defeats the rule',
        );
    }

    public function testTheRobotsRequestDoesNotOverrideTheHonestUserAgent(): void
    {
        // The robots request is a request, and it is the one whose entire purpose is to ask
        // permission — so it must identify as honestly as the search request does.
        //
        // The way to get that right is counter-intuitive, and writing this test the obvious way got
        // it backwards: {@see CurlHttpClient} pins the honest User-Agent via CURLOPT_USERAGENT and
        // THROWS on any caller-supplied `User-Agent` header, because in cURL such a header silently
        // overrides the constant. So a resolver that "helpfully" set its own UA would not be more
        // honest — it would be refused outright by the real client, and the failure would only
        // appear on a live poll, where no test runs. The invariant is therefore the ABSENCE of an
        // override: the resolver shares the client, and the client is what identifies.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $this->doctor($client);

        $headers = $client->headersFor('https://example.test/robots.txt');
        self::assertNotNull($headers, 'robots.txt was never requested');

        foreach (array_keys($headers) as $name) {
            self::assertNotSame(
                'user-agent',
                strtolower((string) $name),
                'the resolver must not set a User-Agent — CurlHttpClient pins the honest one and refuses overrides',
            );
        }

        self::assertStringContainsString(
            'scout',
            CurlHttpClient::USER_AGENT,
            'the honest UA the shared client sends on this request',
        );
    }

    public function testRobotsIsFetchedOncePerHostAndNotOncePerPage(): void
    {
        // A paginated source visits many URLs on one host. Re-reading robots.txt for each is a
        // request the site did not need to serve, and the docblock on HttpJsonSource promises one
        // read per host per pass.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $this->doctor($client);

        $reads = count(array_filter($client->urls, static fn (string $u): bool => str_ends_with($u, '/robots.txt')));
        self::assertSame(1, $reads, 'robots.txt must be read once per host, not once per request');
    }

    public function testTwoSourcesOnOneHostShareASingleRobotsRead(): void
    {
        // The test above passes even with NO cache at all, because one source makes one call — which
        // is exactly what a sabotage run found: deleting the per-host cache went UNDETECTED. The
        // guarantee only has teeth when two sources share a host, so that is what this exercises.
        // Hard rule 5 is about request rates as well as permission, and a landlord with four
        // configured sources should serve one robots.txt per pass, not four.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
            'https://example.test/autres' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $this->doctor($client, [
            'robots_probe' => self::htmlSource('https://example.test/annonces'),
            'robots_probe_deux' => self::htmlSource('https://example.test/autres'),
        ], null);

        $reads = count(array_filter($client->urls, static fn (string $u): bool => str_ends_with($u, '/robots.txt')));
        self::assertSame(1, $reads, 'two sources on one host must share one robots.txt read');
        self::assertContains('https://example.test/annonces', $client->urls);
        self::assertContains('https://example.test/autres', $client->urls, 'both sources must still run');
    }

    public function testTwoSourcesOnDIFFERENTHostsEachGetTheirOwnRobots(): void
    {
        // The counterweight. A cache keyed on something coarser than the origin would let one
        // landlord's robots.txt govern another's — a fail-OPEN if the first host is permissive.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /private/\n"),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
            'https://autre.test/robots.txt' => new HttpResponse(200, "User-agent: *\nDisallow: /autres\n"),
        ]);

        $this->doctor($client, [
            'robots_probe' => self::htmlSource('https://example.test/annonces'),
            'robots_probe_deux' => self::htmlSource('https://autre.test/autres'),
        ], null);

        $reads = array_values(array_filter($client->urls, static fn (string $u): bool => str_ends_with($u, '/robots.txt')));
        sort($reads);
        self::assertSame(['https://autre.test/robots.txt', 'https://example.test/robots.txt'], $reads);
        self::assertNotContains(
            'https://autre.test/autres',
            $client->urls,
            "the second host's own Disallow must be honoured, not the first host's",
        );
    }

    public function testAServerErrorOnRobotsFailsClosedAndSaysWhy(): void
    {
        // RFC 9309: a 5xx makes robots.txt *unreachable*, and a crawler MUST assume complete
        // disallow. The message must not read as "robots.txt disallows /annonces", because no rule
        // said that — the file was never read.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(500, 'boom'),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $result = $this->doctor($client);
        $text = $result['out'] . $result['err'];

        self::assertNotContains('https://example.test/annonces', $client->urls, '5xx robots must fail closed');
        self::assertStringContainsString('500', $text, 'the refusal must name the cause, not invent a rule');
    }

    public function testAMissingRobotsIsNotTreatedAsADisallow(): void
    {
        // The counterweight to the test above, and the one that stops this fix from silently
        // disabling every source. RFC 9309: a 404 makes robots.txt *unavailable*, and a crawler MAY
        // access the site. A 404 is not "we could not read it" — it is "we successfully established
        // that no file exists", which is the ordinary no-restrictions case on the open web.
        $client = new ScriptedHttpClient([
            'https://example.test/robots.txt' => new HttpResponse(404, 'Not Found'),
            'https://example.test/annonces' => new HttpResponse(200, '<html><body></body></html>'),
        ]);

        $this->doctor($client);

        self::assertContains(
            'https://example.test/annonces',
            $client->urls,
            'a 404 robots.txt must NOT disable the source — that would break every site without one',
        );
    }

    /**
     * Run `doctor` against a throwaway root holding exactly one enabled html source.
     *
     * @return array{code: int, out: string, err: string}
     */
    /** @param array<string, array<string, mixed>>|null $sources */
    private function doctor(ScriptedHttpClient $client, ?array $sources = null, ?string $only = 'robots_probe'): array
    {
        $root = $this->tempRoot($sources);

        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Scout($root, $out, $err, '2026-08-07T12:00:00+02:00', $client))
            ->run($only === null ? ['doctor'] : ['doctor', '--source=' . $only]);

        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /**
     * @param array<string, array<string, mixed>>|null $sources defaults to one html source on
     *                                                          `example.test`
     */
    private function tempRoot(?array $sources = null): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-robots-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        $this->tempRoots[] = $root;

        file_put_contents($root . '/config/criteria.json', json_encode([
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 4,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/sources.json', json_encode([
            'sources' => $sources ?? ['robots_probe' => self::htmlSource('https://example.test/annonces')],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    /** @return array<string, mixed> */
    private static function htmlSource(string $url): array
    {
        return [
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'html',
            'mixed_tenure' => true,
            'url' => $url,
            'item_selector' => 'article.annonce',
            'map' => ['ref' => '.ref', 'title' => '.titre'],
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}

/**
 * An {@see HttpClient} that answers from a table and REMEMBERS every url it was asked for, in order.
 *
 * The request log is the point: most of these assertions are about what was requested and in which
 * order, which is not observable from a response alone. An unlisted url throws rather than
 * defaulting to 200 — a silent default would let a test pass while the CLI asked for something
 * nobody wrote down.
 */
final class ScriptedHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @param array<string, HttpResponse> $table */
    public function __construct(private readonly array $table) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;
        $this->requests[] = $request;

        if (!isset($this->table[$request->url])) {
            throw new HttpError('ScriptedHttpClient: no scripted response for ' . $request->url);
        }

        return $this->table[$request->url];
    }

    /** @return array<string,string>|null */
    public function headersFor(string $url): ?array
    {
        foreach ($this->requests as $request) {
            if ($request->url === $url) {
                return $request->headers;
            }
        }

        return null;
    }
}
