<?php

declare(strict_types=1);

namespace Scout\Tests\Adapters\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\ReplayHttpClient;

/**
 * The three answers of the replay client, each pinned because each is a rule the obvious
 * implementation ("serve the file for everything") breaks:
 *
 * - `/robots.txt` must be 404 — the payload there would be HTML handed to the robots parser,
 *   which fails closed on markup and refuses the replay;
 * - the search URL AND its paginated forms get the payload;
 * - a detail page does NOT — it would be the search page selected as a listing's detail.
 */
#[CoversClass(ReplayHttpClient::class)]
final class ReplayHttpClientTest extends TestCase
{
    public function testRobotsIsAbsentSoTheResolverReadsAllow(): void
    {
        // A search URL at the SITE ROOT, so `/robots.txt` would satisfy the prefix rule — this is
        // the case in which the robots branch is load-bearing rather than shadowed by the 404
        // fallthrough (the ledger found the shadowed form undetectable on 2026-08-29).
        $client = new ReplayHttpClient('https://portal.test/', '<html>payload</html>', 'text/html');

        $r = $client->send(new HttpRequest('https://portal.test/robots.txt'));

        self::assertSame(404, $r->status);
        self::assertSame('', $r->body, 'never the payload — markup would fail the robots parser closed');
    }

    public function testTheSearchUrlAndItsPagesGetThePayload(): void
    {
        $client = new ReplayHttpClient('https://portal.test/search', '<html>payload</html>', 'text/html; charset=utf-8');

        foreach (['https://portal.test/search', 'https://portal.test/search?page=2', 'https://portal.test/search/page-3'] as $url) {
            $r = $client->send(new HttpRequest($url));
            self::assertSame(200, $r->status, $url);
            self::assertSame('<html>payload</html>', $r->body, $url);
            self::assertSame('text/html; charset=utf-8', $r->header('Content-Type'), $url);
        }
    }

    public function testAPageTemplateIsMatchedOnItsPrefix(): void
    {
        // Cityloger's shape: the page number sits mid-path.
        $client = new ReplayHttpClient('https://portal.test/resultats-location-{page}-defaut-', '{}', 'application/json');

        self::assertSame(200, $client->send(new HttpRequest('https://portal.test/resultats-location-1-defaut-'))->status);
        self::assertSame(200, $client->send(new HttpRequest('https://portal.test/resultats-location-7-defaut-'))->status);
    }

    public function testADetailPageIsNotServedTheSearchPayload(): void
    {
        $client = new ReplayHttpClient('https://portal.test/search', '<html>payload</html>', 'text/html');

        $r = $client->send(new HttpRequest('https://portal.test/annonce/12345'));

        self::assertSame(404, $r->status, 'a detail_map must not select from the search page');
        self::assertSame('', $r->body);
    }
}
