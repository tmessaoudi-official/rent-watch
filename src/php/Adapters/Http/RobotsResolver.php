<?php

declare(strict_types=1);

namespace RentWatch\Adapters\Http;

/**
 * Fetches one `robots.txt` per origin and turns the HTTP answer into a {@see Robots} verdict.
 *
 * `Robots` knows how to PARSE a robots file and how to answer *"may we fetch this path?"*. What it
 * cannot know is what a missing, forbidden or broken robots file means — that is a decision about
 * posture, and it is the whole content of this class. It exists as a separate object so the table
 * below can be unit-tested one status code at a time, rather than through a full CLI round trip.
 *
 * ## The table, and why each row is what it is
 *
 * | Answer | Verdict | Why |
 * |---|---|---|
 * | `2xx` | parse the body | The ordinary case. |
 * | `404` / `410` | **allow everything** | RFC 9309 §2.3.1.3: robots.txt *unavailable* — a crawler MAY access the site. This is NOT "we could not read it": it is *we successfully established that no file exists*, which is the ordinary no-restrictions case on the open web. Reading it as a disallow would silently disable every site that never wrote one. |
 * | any other `4xx` (`401`, `403`, …) | **fail closed**, with the status in the reason | RFC 9309 would permit access here too, but a site answering `403` to `robots.txt` is actively refusing this client, and hard rule 5 says to take that at face value. This is stricter than the standard, deliberately — see `docs/SOURCES.md` § the A10/A12 round, where exactly this distinction was recorded. |
 * | `5xx` | **fail closed**, with the status in the reason | RFC 9309 §2.3.1.4: robots.txt *unreachable* — a crawler MUST assume complete disallow. |
 * | transport failure | **fail closed**, with the cURL message in the reason | A timeout, a DNS failure, a TLS error, `RENT_WATCH_OFFLINE=1`, or a cross-host redirect refused by {@see CurlHttpClient}. Nothing was learned, so nothing is permitted. |
 *
 * **Redirects need no row of their own.** {@see CurlHttpClient} already follows up to three
 * redirects and refuses to leave the original host, so an ordinary apex→www or http→https redirect
 * on `/robots.txt` is followed transparently and never reaches this table. A robots file that
 * redirects to a DIFFERENT host arrives here as a transport failure and fails closed — which is
 * correct rather than incidental: `robots.txt` speaks for one origin, and a file served by another
 * host has no authority over this one.
 *
 * ## This class does NOT cache, deliberately
 *
 * Reading one `robots.txt` per host per pass is a hard-rule-5 obligation rather than a speed
 * concern — re-reading it for every page of a four-page walk is load a landlord did not need to
 * serve. So the guarantee is real and is asserted, in
 * {@see \RentWatch\Tests\Cli\ScoutRobotsTest::testRobotsIsFetchedOncePerHostAndNotOncePerPage}.
 *
 * It simply is not implemented *here*. Holding the cache would make this class mutable, and
 * `TenureCorpusTest::testEveryCoreValueObjectIsImmutable()` requires every class under `src/php/` to
 * be readonly unless it implements {@see \RentWatch\Core\MutableByDesign} — whose bar is
 * explicitly *"its mutation must BE the mechanism, not an optimisation"*. A memoisation table does
 * not clear that bar, and taking the exemption anyway is how such a rule stops meaning anything. So
 * the table lives where it belongs: as a local array in {@see \RentWatch\Cli\Scout::sources()},
 * whose lifetime is exactly one build of the source list.
 *
 * One consequence worth stating plainly: because sources are built once per process and
 * `scout run --watch` then re-polls them for days, the verdict a watcher holds is the one read at
 * startup. RFC 9309 §2.4 asks crawlers not to reuse a cached robots.txt for more than 24 hours, so
 * a watcher running longer than a day is outside that norm. That is a known, bounded limitation
 * rather than an oversight — closing it means handing the adapters a resolver instead of a
 * `Robots`, which changes the `Source` construction contract. Recorded in
 * `docs/plans/milestone-1-pipeline.plan.md`.
 */
final readonly class RobotsResolver
{
    /**
     * @param HttpClient $client the SAME client the adapters poll with. Sharing it is what makes the
     *                           robots request identify honestly: {@see CurlHttpClient} pins the
     *                           User-Agent itself and refuses a caller-supplied one, so this class
     *                           must not set that header — and deliberately does not.
     * @param string     $userAgent the product token matched against `User-agent:` groups. RFC 9309
     *                              §2.2.1 matches on the token, not on the full UA string.
     */
    public function __construct(
        private readonly HttpClient $client,
        private readonly string $userAgent = 'rent-watch',
    ) {}

    /**
     * The verdict governing this url. ONE request, every time — see the class docblock on caching.
     *
     * @return Robots never `null`. A url this cannot even derive an origin from is REFUSED rather
     *                than waved through; `null` was the shape of the defect this whole class exists
     *                to remove.
     */
    public function forUrl(string $url): Robots
    {
        $origin = self::originOf($url);

        if ($origin === null) {
            return Robots::unavailable('url sans hôte exploitable : ' . $url);
        }

        return $this->fetch($origin);
    }

    /** The origin a url belongs to — the cache key a caller should memoise on. */
    public function originFor(string $url): ?string
    {
        return self::originOf($url);
    }

    private function fetch(string $origin): Robots
    {
        $url = $origin . '/robots.txt';

        try {
            // No User-Agent header on purpose — see the constructor's docblock. A shorter timeout
            // than a page fetch because this is a small file on the critical path of every poll,
            // and a host that cannot serve it quickly fails closed rather than stalling the run.
            $response = $this->client->send(new HttpRequest(url: $url, timeoutSeconds: 10));
        } catch (HttpError $e) {
            return Robots::unavailable($e->getMessage());
        }

        if ($response->isSuccess()) {
            return Robots::parse($response->body, $this->userAgent);
        }

        // The one row that ALLOWS. See the class docblock: "no file exists" is knowledge, and it is
        // different in kind from "we could not find out".
        if ($response->status === 404 || $response->status === 410) {
            return Robots::parse('', $this->userAgent);
        }

        return Robots::unavailable('HTTP ' . $response->status . ' sur ' . $url);
    }

    /** `https://host:port` for a url, or `null` when it carries no usable host. */
    private static function originOf(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (!is_string($host) || $host === '') {
            return null;
        }

        // robots.txt is per scheme AND authority, so `http://` and `https://` on the same host are
        // two different files. Defaulting a missing scheme to https matches everything this project
        // polls and keeps a malformed config from silently downgrading to cleartext.
        $scheme = is_string($scheme) && $scheme !== '' ? strtolower($scheme) : 'https';

        return $scheme . '://' . strtolower($host) . (is_int($port) ? ':' . $port : '');
    }
}
