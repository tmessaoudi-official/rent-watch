<?php

declare(strict_types=1);

namespace Scout\Tests\Vehicle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\SourceError;
use Scout\Vehicle\SitemapVehicleSource;
use Scout\Vehicle\VehicleSourceDefinition;
use Scout\Vehicle\VehicleSourceLoader;
use Scout\Vehicle\VehicleStore;

/**
 * Autohero through the shipped block, against the frozen sitemap (5 of the real 3 387 lots) and the
 * measured lot page reduced to its JSON-LD. The novelty gate and the budget are the source's whole
 * economics; the Nissan facts are hand-read from the block captured on 2026-08-29.
 */
#[CoversClass(SitemapVehicleSource::class)]
final class AutoheroFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string SITEMAP = 'https://www.autohero.com/fr/sitemap_search.xml';
    private const string NISSAN = 'https://www.autohero.com/fr/nissan-note/id/61bd7f63-e508-43a3-8ba0-a2908629f24d/';

    public function testTheMeasuredLotMapsToEveryHandReadFact(): void
    {
        $client = $this->client();
        $source = $this->source($client, budget: 5);

        $lots = $source->fetch();

        self::assertCount(5, $lots);
        $nissan = null;
        foreach ($lots as $lot) {
            if ($lot->externalId === '61bd7f63-e508-43a3-8ba0-a2908629f24d') {
                $nissan = $lot;
            }
        }
        self::assertNotNull($nissan, 'identity is the uuid in the lot URL');
        self::assertSame('Nissan Note 1.2 DIG-S Tekna CVT', $nissan->title);
        self::assertSame(7990, $nissan->priceEur);
        self::assertSame(2015, $nissan->year);
        self::assertSame(9, $nissan->month);
        self::assertSame(136278, $nissan->mileageKm, '"136 278 KMT" — narrow no-break space and the UN/CEFACT unit');
        self::assertSame('essence', $nissan->fuel);
        self::assertSame('automatique', $nissan->gearbox, '"Boite de vitesse automatique", no circumflex');
        self::assertSame('monospace', $nissan->body);
        self::assertSame('nissan', $nissan->make);
        self::assertSame('note', $nissan->model);
        self::assertSame('professional', $nissan->sellerType, 'a reseller');
        self::assertSame('DK55532', $nissan->fields['ref']);
        self::assertSame(self::NISSAN, $nissan->url);
        self::assertNull($nissan->postcode, 'no location field at all — decision 6 is inert here by measurement');
        self::assertNull($nissan->observedAt, 'a polled page is observed at the pass time');
    }

    public function testTheNoveltyGateAndTheBudgetBoundTheFetch(): void
    {
        $store = VehicleStore::open(':memory:');
        $client = $this->client();
        $source = $this->source($client, budget: 2, store: $store);

        $first = $source->fetch();
        self::assertCount(2, $first, 'budget 2 of 5 novel lots');
        self::assertCount(3, $client->urls, 'the sitemap plus two lot pages — never the whole catalogue');

        foreach ($first as $lot) {
            $store->record($lot, '2026-08-29T10:00:00Z');
        }
        $second = $source->fetch();
        self::assertCount(2, $second);
        self::assertNotSame(
            array_map(static fn ($l) => $l->externalId, $first),
            array_map(static fn ($l) => $l->externalId, $second),
            'recorded lots are never fetched again — steady state is the day\'s new lots',
        );
    }

    public function testSeedingRecordsTheWholeIndexWithoutFetchingALot(): void
    {
        $store = VehicleStore::open(':memory:');
        $client = $this->client();
        $source = $this->source($client, budget: 50, store: $store);

        $seed = $source->seedIndex();
        self::assertCount(5, $seed);
        self::assertSame([self::SITEMAP], $client->urls, 'the index only');
        foreach ($seed as $bare) {
            self::assertNull($bare->priceEur, 'nothing is known, nothing is judged');
            $store->record($bare, '2026-08-29T10:00:00Z');
        }

        self::assertSame([], $source->fetch(), 'after the seed the catalogue is the market already watched');
    }

    public function testEveryRequestIsRobotsCheckedAndARefusalIsLoud(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~robots~');

        $this->source($this->client(), budget: 2, robots: Robots::parse("User-agent: *\nDisallow: /fr/\n"))->fetch();
    }

    public function testABrokenLotPageIsWarnedAndSkippedNeverAnEmptyPass(): void
    {
        $table = $this->table();
        $table[self::NISSAN] = new HttpResponse(404, 'gone');
        $warnings = [];
        $source = $this->source(new TableHttpClient($table), budget: 5, warn: static function (string $w) use (&$warnings): void { $warnings[] = $w; });

        $lots = $source->fetch();

        self::assertCount(4, $lots);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('404', $warnings[0]);
    }

    public function testTheRateLimitIsHonouredBetweenLotFetches(): void
    {
        $slept = [];
        $source = $this->source($this->client(), budget: 2, rateLimitMs: 2000, sleeper: static function (int $ms) use (&$slept): void { $slept[] = $ms; });

        $source->fetch();

        self::assertSame([2000, 2000], $slept, 'one pause per lot page, none for the sitemap');
    }

    public function testAnEmptyOrObsoleteSitemapIsRefusedNotReadAsAQuietMarket(): void
    {
        $this->expectException(SourceError::class);
        $table = $this->table();
        $table[self::SITEMAP] = new HttpResponse(200, '<urlset></urlset>');
        $this->source(new TableHttpClient($table), budget: 2)->fetch();
    }

    // ------------------------------------------------------------------------------------------

    /** @return array<string, HttpResponse> */
    private function table(): array
    {
        $sitemap = (string) file_get_contents(self::ROOT . '/tests/fixtures/autohero/sitemap_search.xml');
        $lot = (string) file_get_contents(self::ROOT . '/tests/fixtures/autohero/lot-61bd7f63.html');
        $table = [self::SITEMAP => new HttpResponse(200, $sitemap, ['content-type' => 'text/xml'])];
        preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~', $sitemap, $m);
        foreach ($m[1] as $url) {
            $table[$url] = new HttpResponse(200, $lot, ['content-type' => 'text/html']);
        }

        return $table;
    }

    private function client(): TableHttpClient
    {
        return new TableHttpClient($this->table());
    }

    private function source(
        HttpClient $client,
        int $budget,
        ?VehicleStore $store = null,
        ?Robots $robots = null,
        ?\Closure $warn = null,
        int $rateLimitMs = 0,
        ?\Closure $sleeper = null,
    ): SitemapVehicleSource {
        $shipped = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')['autohero'];
        $definition = new VehicleSourceDefinition(
            name: $shipped->name, enabled: true, family: $shipped->family, type: $shipped->type,
            url: $shipped->url, itemUrlPattern: $shipped->itemUrlPattern, map: $shipped->map,
            lotBudgetPerPass: $budget, rateLimitMs: $rateLimitMs,
        );

        return new SitemapVehicleSource(
            $definition,
            $store ?? VehicleStore::open(':memory:'),
            $client,
            $robots ?? Robots::parse((string) file_get_contents(self::ROOT . '/tests/fixtures/autohero/robots.txt')),
            $warn,
            $sleeper,
        );
    }
}

/** Answers from a table; an unscripted URL is an error, never a silent empty body. */
final class TableHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<string, HttpResponse> $table */
    public function __construct(private readonly array $table) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;
        if (!isset($this->table[$request->url])) {
            throw new HttpError('TableHttpClient: no response scripted for ' . $request->url);
        }

        return $this->table[$request->url];
    }
}
