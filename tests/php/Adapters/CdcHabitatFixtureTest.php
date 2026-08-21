<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\HttpResponse;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Adapters\HtmlSource;
use RentWatch\Config\ConfigLoader;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\Outcome;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;
use RentWatch\Store\Store;

/**
 * The frozen CDC Habitat payload, asserted field by field — `/add-source` Step 3.
 *
 * Offline: the fixture IS the ground truth, and no request leaves this process. What it pins is the
 * FIELD MAP, which is the part of a source that rots silently. A selector that stops matching does
 * not throw; it returns nothing, and nothing is indistinguishable from a rental market that went
 * quiet (hard rule 2).
 *
 * Written because a sabotage run found the map had no test at all: swapping `Payload::floor()` back
 * to the generic number reader — which turns `3 pièces - 4ème étage - 82m²` into floor 3, the ROOM
 * COUNT — left the whole suite green [measured 2026-08-20]. Unit tests on the parser proved the
 * parser; nothing proved the wiring.
 */
final class CdcHabitatFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testTheFrozenPageYieldsEveryCardTheSiteDeclares(): void
    {
        $rows = $this->extract();

        self::assertCount(16, $rows, 'the results list holds 16 cards; two more sit outside it');

        // The scoping this asserts is not cosmetic. `article.residenceCard` alone matches 18
        // elements on this page — the extra two live outside `#listeResultats` — and those would
        // enter the pipeline as listings the search never returned.
        foreach ($rows as $row) {
            self::assertNotSame('', $row->externalId, 'every card must carry a stable ref');
            self::assertNotNull($row->url, 'every card must carry the listing url');
        }
    }

    /**
     * One card, every mapped field, against values read off the frozen page.
     *
     * `RDC` is the point of this test. It is a real floor — zero — and five of the thirteen distinct
     * floor strings on CDC's Yvelines page say it; read with a generic number parser the card's own
     * heading yields `2`, which is the room count wearing a floor's name (hard rule 9).
     */
    public function testOneCardMapsFieldForFieldIncludingAGroundFloor(): void
    {
        $row = $this->extract()[0];

        self::assertSame('1283627', $row->externalId);
        self::assertSame('NANGIS', $row->commune);
        self::assertSame('77370', $row->postcode);
        self::assertSame(699, $row->rentCc, 'the card price is charges comprises — verified on the detail page');
        self::assertSame(48.0, $row->surfaceM2);
        self::assertSame(2, $row->rooms);
        self::assertSame(0, $row->floor, 'RDC is the ground floor, not an unknown one');
        self::assertNotSame(2, $row->floor, 'the room count must never be read as the floor');
        self::assertSame(
            'https://www.cdc-habitat.fr/annonces-immobilieres/location/ile-de-france/seine-et-marne/nangis/1283627',
            $row->url,
        );
        self::assertSame('Logement intermédiaire', $row->fields['tenureField'] ?? null);
    }

    /** Every card states a floor, and the ground-floor ones are present rather than parsed away. */
    public function testTheFloorIsReadFromEveryCardAndGroundFloorsSurvive(): void
    {
        $floors = array_map(static fn ($r): ?int => $r->floor, $this->extract());

        self::assertNotContains(null, $floors, 'every card on this page states its floor');
        self::assertContains(0, $floors, 'the RDC cards must survive as floor 0');
    }

    /**
     * The whole reason this source was chosen: it is the first that actually exercises §1.
     *
     * In'li is pure LLI, so until CDC nothing put the classifier in front of a real payload that
     * mixes tenures. Sixteen cards, two tenure badges, and every one resolving above the fail-closed
     * floor — the run this pins previously produced 16 × `UNKNOWN / 0.00 / DIGEST`.
     */
    public function testEveryCardClassifiesAboveTheFailClosedFloorAndNoneIsSocial(): void
    {
        $definition = $this->definition();
        $source = $this->source($definition);
        $classifier = new TenureClassifier();
        $tally = [];

        foreach ($source->extract($this->html(), (string) $definition->itemSelector) as $row) {
            $verdict = $classifier->classify($row, $source->profile());

            self::assertTrue(
                $verdict->tenure->isEligible(),
                'a listing on this page resolved to an excluded tenure: ' . $verdict->tenure->name,
            );
            self::assertGreaterThanOrEqual(
                0.6,
                $verdict->confidence(),
                'below the fail-closed floor, which routes to the digest and reads as a quiet market',
            );

            $tally[$verdict->tenure->name] = ($tally[$verdict->tenure->name] ?? 0) + 1;
        }

        self::assertSame(['LLI' => 14, 'LIBRE' => 2], $tally);
    }

    /** The badges the page actually carries, so a new one shows up as a failing test, not silence. */
    public function testTheTenureBadgesOnThePageAreTheTwoWeMapped(): void
    {
        $badges = [];

        foreach ($this->extract() as $row) {
            $badge = $row->fields['tenureField'] ?? '(none)';
            $badges[$badge] = ($badges[$badge] ?? 0) + 1;
        }

        self::assertSame(
            ['Logement intermédiaire' => 14, 'Logement à loyer libre' => 2],
            $badges,
            'a badge the classifier has never seen must be noticed here rather than silently digested',
        );
    }

    /** A card whose text is a match must still be one end to end — including the tooltip prose. */
    public function testTheTooltipProseIsCarriedIntoTheClassifiersTextSurface(): void
    {
        $row = $this->extract()[0];

        self::assertStringContainsString(
            'au plus près des bassins',
            $row->text(),
            'the tooltip is part of the card text the classifier reads',
        );
        self::assertStringContainsString('Logement intermédiaire', $row->text());
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<\RentWatch\Core\RawListing> */
    private function extract(): array
    {
        $definition = $this->definition();

        return $this->source($definition)->extract($this->html(), (string) $definition->itemSelector);
    }

    private function html(): string
    {
        return (string) file_get_contents(self::ROOT . '/tests/fixtures/cdc_habitat/search.html');
    }

    private function definition(): SourceDefinition
    {
        return ConfigLoader::loadSources(self::ROOT . '/config/sources.json')['cdc_habitat'];
    }

    private function source(SourceDefinition $definition): HtmlSource
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-cdc-' . bin2hex(random_bytes(8)) . '.sqlite3';

        // The client refuses to be called: this test reads a frozen page, and a fixture test that
        // can reach the network is a monitoring check wearing a test's name.
        return new HtmlSource($definition, Store::open($this->dbPath), new class implements HttpClient {
            public function send(HttpRequest $request): HttpResponse
            {
                throw new \LogicException('a fixture test must not make a request: ' . $request->url);
            }
        }, Robots::parse(''));
    }
}
