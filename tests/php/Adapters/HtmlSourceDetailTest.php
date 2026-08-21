<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\HttpResponse;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Adapters\HtmlSource;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\RawListing;
use RentWatch\Store\Store;

/**
 * Detail-page hydration: the second request a listing sometimes needs, and the gate that decides.
 *
 * Cityloger (the Immobilière 3F group's lettings platform) is why this exists. Its search cards
 * carry rent, rooms, surface, commune and floor — and NO tenure at all. The tenure lives on the
 * detail page, which means that on a mixed-tenure source every listing would resolve UNKNOWN and go
 * to the à-vérifier digest forever: correct under §1, and useless.
 *
 * Two things make this safe rather than a request storm, and both are asserted below:
 *
 * - **A gate decides who is worth a second request.** It is the source's own geographic criteria —
 *   `Criteria::matchesCommune()`, the one filter whose inputs the CARD already carries in full, so
 *   gating on it cannot reject on a field the detail page would have filled (hard rule 8). 51
 *   national listings become the handful in the watched communes.
 * - **A detail map selects the listing's own content, never the page.** Measured 2026-08-20 on the
 *   real Antony payload: the scoped `.description` classifies LLI at 0.90, and the SAME listing fed
 *   its whole detail page classifies UNKNOWN at 0.00 — the page's furniture ("Commission
 *   d'attribution", "demande de logement social") appears on social and intermediate listings alike
 *   and conflicts the verdict away. That is the CDC `au plus près` failure class on a new surface.
 */
final class HtmlSourceDetailTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testOnlyTheListingsThatPassTheGateCostASecondRequest(): void
    {
        $client = new DetailHttpClient();
        $source = $this->source($client, $this->definition(), static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $rows = $source->fetch();

        self::assertCount(3, $rows, 'every card is returned whether or not it was hydrated');
        self::assertSame(
            ['https://example.test/detail-2'],
            $client->detailUrls,
            'exactly one detail request, for the one listing in a watched commune — a source that '
                . 'hydrated all three would be 51 requests per pass against the real site',
        );
    }

    public function testTheDetailPageSuppliesTheTenureThatTheCardNeverCarries(): void
    {
        $source = $this->source(new DetailHttpClient(), $this->definition(), static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $hydrated = null;
        foreach ($source->fetch() as $row) {
            if ($row->commune === 'HOUILLES') {
                $hydrated = $row;
            }
        }

        self::assertNotNull($hydrated);
        self::assertSame('LI15P', $hydrated->fields['tenureField'] ?? null);
        self::assertStringContainsString(
            'Logement intermédiaire',
            $hydrated->description,
            'the detail description is what carries the tier-2 label',
        );
        self::assertStringNotContainsString(
            'Commission d\'attribution',
            $hydrated->description,
            'the page furniture must NOT come with it — it appears on social and intermediate '
                . 'listings alike, and conflicts a correct verdict into UNKNOWN',
        );
    }

    /** Hard rule 9: a detail page that omits a field states nothing about it. */
    public function testADetailPageThatOmitsAFieldNeverErasesWhatTheCardKnew(): void
    {
        $client = new DetailHttpClient(detailBody: '<html><body><div class="description">Un texte sans rien d\'autre</div></body></html>');
        $source = $this->source($client, $this->definition(), static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $rows = $source->fetch();

        $hydrated = null;
        $untouched = null;
        foreach ($rows as $row) {
            if ($row->commune === 'HOUILLES') {
                $hydrated = $row;
            }
            if ($row->commune === 'NANTES') {
                $untouched = $row;
            }
        }

        // The control. Without it this test would also pass if the CARD had never parsed a rent at
        // all — proving the merge harmless by proving there was nothing there to harm.
        self::assertSame(700, $untouched?->rentCc, 'a listing nobody hydrated still has its card rent');

        self::assertNotNull($hydrated);
        self::assertSame(880, $hydrated->rentCc, 'the card knew the rent; the detail page said nothing about it');
        self::assertSame('HOUILLES', $hydrated->commune);
        self::assertNull($hydrated->fields['tenureField'] ?? null, 'and an absent tenure stays absent, not empty-string');
    }

    /** Hard rule 3: a failed detail request must not quietly yield an unhydrated listing. */
    public function testAFailedDetailRequestIsLoudRatherThanASilentlyUnhydratedListing(): void
    {
        $client = new DetailHttpClient(failDetail: true);
        $source = $this->source($client, $this->definition(), static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/detail/i');

        $source->fetch();
    }

    /** Hard rule 5: the detail page is a different path, so it gets its own robots verdict. */
    public function testRobotsIsCheckedForEveryDetailUrlAndNotOnlyForTheSearchPage(): void
    {
        $robots = Robots::parse("User-agent: *\nDisallow: /detail-\n");
        $source = $this->source(
            new DetailHttpClient(),
            $this->definition(),
            static fn (RawListing $l): bool => $l->commune === 'HOUILLES',
            $robots,
        );

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/robots\.txt disallows/');

        $source->fetch();
    }

    /**
     * A detail map with no gate REFUSES, rather than defaulting either way.
     *
     * Both defaults are wrong and one is silent: hydrating everything turns a config-only source
     * into a per-listing crawl of somebody else's site, and hydrating nothing leaves a mixed-tenure
     * source resolving UNKNOWN forever while looking perfectly healthy. Refusing is the only option
     * that cannot be mistaken for working.
     */
    public function testADetailMapWithoutAGateRefusesInsteadOfPickingADefault(): void
    {
        $source = $this->source(new DetailHttpClient(), $this->definition(), null);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/gate/i');

        $source->fetch();
    }

    /**
     * Hard rule 5: a detail walk is N extra requests, so it is paced like every other one.
     *
     * Asserted on the clock rather than on a seam because `usleep` is what the walk uses, and a test
     * that mocked a sleeper would prove the mock was called, not that the source waits. The margin
     * is generous in the one direction that matters: it can only fail if the wait vanished.
     */
    public function testDetailFetchesArePacedLikeEveryOtherRequest(): void
    {
        $definition = new SourceDefinition(
            name: 'cityloger',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            url: 'https://example.test/results',
            baseUrl: 'https://example.test',
            itemSelector: 'a.card',
            map: new FieldMap(ref: ['@href => -(\d+)$'], url: ['@href'], commune: ['.commune'], postcode: ['.cp']),
            rateLimitMs: 60,
            detailMap: new FieldMap(description: ['.description']),
        );

        $source = $this->source(new DetailHttpClient(), $definition, static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $started = microtime(true);
        $source->fetch();
        $elapsed = (microtime(true) - $started) * 1000;

        self::assertGreaterThanOrEqual(
            50.0,
            $elapsed,
            'one hydration must wait rate_limit_ms before its request — an unpaced detail walk is '
                . 'the burst that gets an IP banned, and it presents as every source going quiet',
        );
    }

    // ---------------------------------------------------------------- helpers

    private function definition(): SourceDefinition
    {
        return new SourceDefinition(
            name: 'cityloger',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            url: 'https://example.test/results',
            baseUrl: 'https://example.test',
            itemSelector: 'a.card',
            map: new FieldMap(
                ref: ['@href => -(\d+)$'],
                url: ['@href'],
                commune: ['.commune'],
                postcode: ['.cp'],
                rent: ['.price'],
                chargesIncluded: true,
            ),
            // Zero, so the suite does not pay real seconds for a fake host. The pacing itself is
            // pinned by testDetailFetchesArePacedLikeEveryOtherRequest below, which sets its own.
            rateLimitMs: 0,
            detailMap: new FieldMap(
                description: ['.description'],
                tenureField: ['table.financement => Financement\s*([A-Z0-9]+)'],
            ),
        );
    }

    private function source(
        HttpClient $client,
        SourceDefinition $definition,
        ?\Closure $gate,
        ?Robots $robots = null,
    ): HtmlSource {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-detail-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return new HtmlSource($definition, Store::open($this->dbPath), $client, $robots ?? Robots::parse(''), $gate);
    }
}

/**
 * A search partial of three cards, one of them in a watched commune, plus detail pages behind them.
 */
final class DetailHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $detailUrls = [];

    public function __construct(
        private readonly ?string $detailBody = null,
        private readonly bool $failDetail = false,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        if (str_contains($request->url, '/detail-')) {
            $this->detailUrls[] = $request->url;

            if ($this->failDetail) {
                throw new HttpError('HTTP 503 from ' . $request->url);
            }

            return new HttpResponse(200, $this->detailBody ?? $this->detail());
        }

        return new HttpResponse(200, $this->search());
    }

    private function search(): string
    {
        $card = static fn (int $n, string $commune, string $cp, int $rent): string => <<<HTML
            <a class="card" href="https://example.test/detail-{$n}">
                <span class="commune">{$commune}</span><span class="cp">{$cp}</span>
                <span class="price">{$rent} € cc</span>
            </a>
            HTML;

        return '<html><body>'
            . $card(1, 'NANTES', '44000', 700)
            . $card(2, 'HOUILLES', '78800', 880)
            . $card(3, 'LYON', '69003', 950)
            . '</body></html>';
    }

    /**
     * Shaped after the real Antony payload: the listing's own prose carries the tier-2 label, and
     * the page furniture around it carries social vocabulary that belongs to neither listing.
     */
    private function detail(): string
    {
        return <<<'HTML'
            <html><body>
                <div class="description">Bel appartement de type F4 de 80 m2. Logement intermédiaire
                    géré par un bailleur social - attribution sous conditions de ressources.</div>
                <table class="financement"><tr><td>Financement</td><td>LI15P</td></tr></table>
                <div class="page-furniture">
                    <p>Numéro de demande de logement social</p>
                    <p>Pour proposer la location, la Commission d'attribution vérifie les revenus.</p>
                </div>
            </body></html>
            HTML;
    }
}
