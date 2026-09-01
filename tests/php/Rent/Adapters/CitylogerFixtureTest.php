<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\Html\Selector;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\HtmlSource;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Store\Store;

/**
 * The frozen Cityloger payloads, asserted field by field — `/add-source` Step 3.
 *
 * Three fixtures, because this source has three surfaces that can rot independently: the search
 * card, an intermediate detail page and a social one. Offline; no request leaves this process.
 *
 * The pair of detail pages is the point. Cityloger is the first source where the tenure is NOT on
 * the card, so §1 depends entirely on a second fetch parsing correctly — and the two frozen pages
 * are the two answers it must be able to tell apart, captured from the live site rather than
 * invented.
 */
final class CitylogerFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testTheFrozenSearchPageYieldsItsTenCards(): void
    {
        $rows = $this->cards();

        self::assertCount(10, $rows, 'the partial holds one page of ten cards');

        foreach ($rows as $row) {
            self::assertNotSame('', $row->externalId);
            self::assertNotNull($row->url, 'every card must carry the url its detail page is fetched from');
            self::assertStringStartsWith(
                'https://www.cityloger.fr/logement-a-louer-',
                (string) $row->url,
                'the card href is RELATIVE — a url that stayed relative would be unfetchable',
            );
        }
    }

    /** One card, every mapped field, read off the frozen page. */
    public function testTheAntonyCardMapsFieldForField(): void
    {
        $row = $this->cardFor('229605');

        self::assertSame('229605', $row->externalId);
        self::assertSame('ANTONY', $row->commune);
        self::assertSame('92160', $row->postcode);
        self::assertSame(1520, $row->rentCc, 'the card price is `1 520 € cc` — charges comprises, and it says so');
        self::assertSame(4, $row->rooms);
        self::assertSame(3, $row->floor, '`3e etg` is the third floor');
        self::assertSame('https://www.cityloger.fr/logement-a-louer-229605', $row->url);
    }

    /**
     * The card carries NO tenure whatsoever, and that is the fact the whole detail path exists for.
     *
     * Asserted rather than assumed: if Cityloger ever puts a financement badge on the card, this
     * fails and the detail fetch can be reconsidered — instead of quietly costing a request per
     * gated listing forever.
     */
    public function testNoCardCarriesAnyTenureSignalAtAll(): void
    {
        $classifier = new TenureClassifier();
        $profile = $this->definition()->profile();

        foreach ($this->cards() as $row) {
            $verdict = $classifier->classify($row, $profile);

            self::assertSame(
                Tenure::UNKNOWN,
                $verdict->tenure,
                'a card resolved to ' . $verdict->tenure->name . ' — if the tenure is now ON the card, '
                    . 'the detail fetch is no longer the only way to get it',
            );
        }
    }

    /**
     * The two frozen detail pages, told apart — the assertion §1 rests on for this source.
     *
     * Both carry the same page furniture: "Commission d'attribution", "demande de logement social".
     * What differs is each listing's OWN prose, which is exactly what the detail map selects.
     */
    public function testTheTwoDetailPagesClassifyAsWhatTheyAre(): void
    {
        $classifier = new TenureClassifier();
        $profile = $this->definition()->profile();

        $intermediate = $classifier->classify($this->hydratedFrom('detail-intermediate.html'), $profile);
        self::assertSame(Tenure::LLI, $intermediate->tenure, 'the Antony flat is a logement intermédiaire');
        self::assertGreaterThanOrEqual(0.6, $intermediate->confidence(), 'and above the fail-closed floor');

        $social = $classifier->classify($this->hydratedFrom('detail-social.html'), $profile);
        self::assertFalse(
            $social->tenure->isEligible(),
            'the Occitanie flat is social stock and must never be eligible — it asks for a numéro '
                . 'unique d\'enregistrement in its own description',
        );
    }

    /**
     * The furniture stays out, and this is the test that would have caught the CDC bug a page earlier.
     *
     * Measured on these very bytes 2026-08-20: the scoped description classifies LLI 0.90, the whole
     * page classifies UNKNOWN 0.00. So the assertion is not "the selector matches something" — it is
     * that the selector matches the listing and NOT the page around it.
     */
    public function testTheDetailMapSelectsTheListingsOwnProseAndNotThePagesFurniture(): void
    {
        $listing = $this->hydratedFrom('detail-intermediate.html');

        self::assertStringContainsString('Logement intermédiaire', $listing->description);
        self::assertStringContainsString('bailleur social', $listing->description, 'including the phrase that nearly vetoes it');

        foreach (['Commission d\'attribution', 'catégories de logements sociaux', 'Numéro de demande'] as $furniture) {
            self::assertStringNotContainsString(
                $furniture,
                $listing->description,
                'page furniture reached the listing — on the whole page this listing classifies UNKNOWN at 0.00',
            );
        }
    }

    /** The fields only the detail page can supply. */
    public function testTheDetailPageSuppliesTenureSurfaceAndLift(): void
    {
        $listing = $this->hydratedFrom('detail-intermediate.html');

        self::assertSame('LI15P', $listing->fields['tenureField'] ?? null);
        self::assertSame(80.0, $listing->surfaceM2, 'the card never states a surface');
        self::assertTrue($listing->hasElevator);

        $social = $this->hydratedFrom('detail-social.html');
        self::assertSame('PEXNC', $social->fields['tenureField'] ?? null);

        // THE SECOND PAGE'S SURFACE, and its absence here cost 92 % of this source's surfaces.
        //
        // The two frozen pages spell the unit DIFFERENTLY — `80 m2` on the intermediate one,
        // `63m²` on this one — and the selector's regex required the ASCII `m2`. So the assertion
        // above passed, this fixture sat in the repo already proving the bug, and nothing asserted
        // against it: measured on the live store, 56 of 61 cached detail rows carried
        // `elevator, description, tenureField` and NO surface, while `min_surface_m2` could not act
        // on any of them (hard rule 9 — an unknown surface is not one below the minimum).
        //
        // A fixture that contains the passing spelling is not coverage of the failing one. Both
        // spellings are asserted from here on, which is what makes the character class provable.
        self::assertSame(63.0, $social->surfaceM2, 'the `m²` spelling must read exactly like `m2`');
    }

    /**
     * The tenure selector is anchored on its LABEL, not on its position.
     *
     * Both frozen pages hold three indistinguishable `table.table` elements, and the financement
     * value happens to be the first `span.label.label-success` on each. Selecting by position would
     * pass today and feed a DATE into the tenure field the day a label is added above it.
     */
    public function testTheTenureSelectorCannotReturnADate(): void
    {
        foreach (['detail-intermediate.html', 'detail-social.html'] as $file) {
            $document = \Dom\HTMLDocument::createFromString($this->fixture($file), LIBXML_NOERROR);
            $root = $document->documentElement;
            self::assertNotNull($root);

            $labels = $document->querySelectorAll('span.label.label-success');
            self::assertGreaterThan(1, $labels->count(), 'more than one such label exists — position is not identity');

            $value = Selector::parse((string) $this->definition()->detailMap?->tenureField[0])->resolve($root);

            self::assertMatchesRegularExpression('/^[A-Z0-9]{3,10}$/', (string) $value);
            self::assertDoesNotMatchRegularExpression('#\d{2}/\d{2}/\d{4}#', (string) $value, 'a date is not a financement code');
        }
    }

    /** Hard rule 5: every url this source visits, checked against the site's own frozen robots.txt. */
    public function testEveryUrlThisSourceVisitsIsAllowedByCitylogersRealRobotsTxt(): void
    {
        $robots = Robots::parse($this->fixture('robots.txt'));
        $definition = $this->definition();

        for ($page = 1; $page <= $definition->maxPages; ++$page) {
            $url = str_replace('{page}', (string) $page, (string) $definition->url);
            self::assertTrue($robots->allows(Robots::pathOf($url)), 'page ' . $page . ' is disallowed: ' . $url);
        }

        foreach ($this->cards() as $row) {
            self::assertTrue(
                $robots->allows(Robots::pathOf((string) $row->url)),
                'the detail page for ' . $row->externalId . ' is disallowed by robots.txt',
            );
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<RawListing> */
    private function cards(): array
    {
        $definition = $this->definition();

        return $this->source($definition)->extract($this->fixture('results.html'), (string) $definition->itemSelector);
    }

    private function cardFor(string $ref): RawListing
    {
        foreach ($this->cards() as $row) {
            if ($row->externalId === $ref) {
                return $row;
            }
        }

        self::fail('no card with ref ' . $ref . ' in the frozen page');
    }

    /**
     * A card plus its own detail page, merged exactly as a run would — through `fetch()`.
     *
     * Not by hand: a helper that assembled the merged listing itself would test the helper. The
     * fake client answers the frozen search page and the frozen detail page, and the gate admits
     * only the listing under test, so this is the adapter's real hydration path end to end.
     */
    private function hydratedFrom(string $detailFixture): RawListing
    {
        $ref = $detailFixture === 'detail-intermediate.html' ? '229605' : '226537';
        $client = new CitylogerFixtureClient(
            $this->fixture('results.html'),
            $this->fixture($detailFixture),
            $ref,
        );

        $source = new HtmlSource(
            $this->definition(),
            $this->store(),
            $client,
            // Not the subject of this test: an explicit allow-all verdict, stated rather than
            // defaulted. `HtmlSource` requires a `Robots` precisely because the old `= null` default
            // meant "never check" and both production call sites silently took it.
            Robots::parse(''),
            static fn (RawListing $l): bool => $l->externalId === $ref,
        );

        foreach ($source->fetch() as $row) {
            if ($row->externalId === $ref) {
                return $row;
            }
        }

        self::fail('the fixture walk returned no listing with ref ' . $ref);
    }

    /**
     * The COMMITTED definition, with one field overridden: `rate_limit_ms`.
     *
     * Every selector, url, gate rule and map entry here is the real one — that is what this suite
     * exists to pin. The pacing is not, because `HtmlSource` sleeps for real and this test drives
     * the real `fetch()`: at the committed 2 000 ms it costs ~21 s, and `tests/sabotage-check.sh`
     * runs the whole suite 303 times, so honest pacing here would add nearly two hours to a ledger.
     *
     * The guarantee is not dropped, only moved: {@see testTheCommittedRateLimitIsRealPacing} asserts
     * the value in the file, and {@see HtmlSourceDetailTest::testDetailFetchesArePacedLikeEveryOtherRequest}
     * asserts the adapter honours it.
     */
    private function definition(): SourceDefinition
    {
        /** @var array<string, mixed> $raw */
        $raw = json_decode((string) file_get_contents(self::ROOT . '/config/rent/sources.json'), true, 512, JSON_THROW_ON_ERROR);
        $raw['sources']['cityloger']['rate_limit_ms'] = 0;

        return ConfigLoader::sourcesFromArray($raw)['cityloger'];
    }

    /** The pacing this test overrides for speed is still real in the file that ships. */
    public function testTheCommittedRateLimitIsRealPacing(): void
    {
        $committed = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['cityloger'];

        self::assertGreaterThanOrEqual(
            1000,
            $committed->rateLimitMs,
            'a detail walk is one request per gated listing on top of the page walk — hard rule 5 '
                . 'keeps request rates low, and this is the value that does it',
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::ROOT . '/tests/fixtures/rent/cityloger/' . $name);
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-cityloger-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }

    private function source(SourceDefinition $definition): HtmlSource
    {
        return new HtmlSource($definition, $this->store(), new class implements HttpClient {
            public function send(HttpRequest $request): HttpResponse
            {
                throw new \LogicException('a fixture test must not make a request: ' . $request->url);
            }
        }, Robots::parse(''));
    }
}

/** The frozen search page on page 1, the frozen detail page for one ref, and nothing else. */
final class CitylogerFixtureClient implements HttpClient
{
    public function __construct(
        private readonly string $results,
        private readonly string $detail,
        private readonly string $ref,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        if (str_contains($request->url, 'logement-a-louer-' . $this->ref)) {
            return new HttpResponse(200, $this->detail);
        }

        // Page 1 is the frozen payload; every later page is the empty body the real walk ends on,
        // so the walk terminates here the way it does against the live site.
        if (str_contains($request->url, 'resultats-location-1-')) {
            return new HttpResponse(200, $this->results);
        }

        return new HttpResponse(200, '');
    }
}
