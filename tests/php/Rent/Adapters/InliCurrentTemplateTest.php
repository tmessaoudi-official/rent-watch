<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\Html\Selector;
use Scout\Rent\Adapters\HtmlSource;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Store\Store;

/**
 * THE CURRENT In'li TEMPLATE — captured 2026-09-04, and the reason it had to be captured.
 *
 * On 2026-09-02 the card `cp` was removed from In'li's shipped field map: the portal moved the
 * postcode out of its URLs (`/location-appartement-sceaux-92330/441-…` became
 * `/locations/offre/paris/PRV-251653`), so `a@href => -(\d{5})/` matched 0 of 190 rows. The
 * removal shipped with **no test coverage in either direction**, because the only frozen payload
 * was the OLD template — 19 old-shape hrefs, 0 new — where restoring the pattern extracts cleanly
 * and the whole suite stays green (C2 round-1 completeness lens, P2-2).
 *
 * A removal nothing can redden is a removal that gets undone. This file is the redness.
 *
 * ## THE CAPTURE IS APPENDED, NOT SUBSTITUTED
 *
 * `tests/fixtures/rent/inli/search.html` stays exactly as it is. That follows the precedent this
 * repo already set for a portal changing its template — `pap/2026-08-31-003-lieusaint-nouveau-
 * gabarit.eml` sits beside the older PAP captures rather than replacing them — and it is the right
 * shape for two reasons: every existing assertion keeps its ground truth, and the old template
 * remains available as the thing the new one is contrasted against. Captured with `robots.txt`
 * re-read first (unchanged: `Disallow: /espace-membre/` only), HTTP 200, and In'li's live Google
 * Maps key replaced with the documented placeholder before the file was written.
 *
 * ## WHAT THIS PINS, AND WHAT IT DELIBERATELY DOES NOT
 *
 * It does not assert a card COUNT. A live search page's inventory changes daily and an exact count
 * would redden on a quiet Tuesday rather than on a defect — the failure mode this repo calls a
 * guard that cries wolf. It asserts that the template still yields listings, that the fields the
 * shipped map reads still resolve, and that the postcode genuinely is not in the URL any more.
 */
#[CoversClass(HtmlSource::class)]
#[CoversClass(Selector::class)]
final class InliCurrentTemplateTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private const string CURRENT = self::ROOT . '/tests/fixtures/rent/inli/search-2026-09-04-nouveau-gabarit.html';
    private const string OLD = self::ROOT . '/tests/fixtures/rent/inli/search.html';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * THE ASSERTION THE REMOVAL NEVER HAD. On the current template the URL capture finds nothing —
     * so restoring `cp` to the card map is now a red suite rather than a silent 100 % null field.
     */
    public function testThePostcodeIsNoLongerInTheCardUrl(): void
    {
        $current = (string) file_get_contents(self::CURRENT);
        $old = (string) file_get_contents(self::OLD);

        self::assertSame(0, preg_match_all('~href="[^"]*-\d{5}/~', $current), 'the current template carries no postcode-bearing href');
        self::assertGreaterThan(0, preg_match_all('~href="[^"]*-\d{5}/~', $old), 'the OLD one did — which is why the removal looked untestable');
        self::assertGreaterThan(0, substr_count($current, 'locations/offre/'), 'and it carries the new shape instead');
    }

    /** The shipped config must not carry the dead pattern — the structural half of the same fact. */
    public function testTheShippedCardMapDoesNotReadAPostcode(): void
    {
        $inli = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['inli'];

        self::assertSame([], $inli->map->postcode, 'the card map reads no postcode; the detail map reads it off the page title');
        self::assertNotNull($inli->detailMap);
        self::assertNotSame([], $inli->detailMap->postcode, 'and the detail map is where it comes from now');
    }

    /**
     * The current template still parses through the SHIPPED map — the counterweight, without which
     * the assertions above are satisfied by a page that yields nothing at all.
     */
    public function testTheCurrentTemplateStillYieldsListingsThroughTheShippedMap(): void
    {
        $listings = $this->fetchCurrent();

        self::assertNotSame([], $listings, 'a template change that yields zero listings is the F1 shape, not a pass');

        foreach ($listings as $listing) {
            self::assertNotSame('', $listing->externalId, 'every card must key on something');
            self::assertNotNull($listing->url);
        }

        // At least one card must carry each field the map reads. Per-card would redden on a single
        // odd listing; "none at all" is the selector-died shape this exists to catch.
        $with = static fn (callable $f): int => count(array_filter($listings, $f));

        self::assertGreaterThan(0, $with(static fn ($l): bool => $l->rentCc !== null || $l->rentHc !== null), 'rent');
        self::assertGreaterThan(0, $with(static fn ($l): bool => $l->surfaceM2 !== null), 'surface');
        self::assertGreaterThan(0, $with(static fn ($l): bool => $l->rooms !== null), 'rooms');
        self::assertGreaterThan(0, $with(static fn ($l): bool => $l->commune !== null), 'commune');
    }

    /**
     * And the card carries NO postcode, which is the stated cost of the removal rather than a bug.
     *
     * `matchesCommune()` refuses a null postcode in region mode, so an UNHYDRATED In'li listing
     * cannot match until its detail page is read. That was the developer's ruling; it is asserted
     * here so the cost stays visible instead of being rediscovered as a defect.
     */
    public function testAnUnhydratedCardCarriesNoPostcode(): void
    {
        foreach ($this->fetchCurrent() as $listing) {
            self::assertNull($listing->postcode, 'the card cannot supply one — only the detail page can');
        }
    }

    /** @return list<\Scout\Rent\Core\RawListing> */
    private function fetchCurrent(): array
    {
        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['inli'];

        // Page one only: `page_param` would walk, and the frozen payload is a single page. Rebuilt
        // without pagination rather than by mutating the map, so the FIELD MAP under test is the
        // shipped one byte for byte.
        $single = new SourceDefinition(
            name: $definition->name,
            enabled: $definition->enabled,
            family: $definition->family,
            type: $definition->type,
            defaultTenure: $definition->defaultTenure,
            mixedTenure: $definition->mixedTenure,
            url: $definition->url,
            baseUrl: $definition->baseUrl,
            itemSelector: $definition->itemSelector,
            map: $definition->map,
        );

        $source = new HtmlSource(
            $single,
            $this->store(),
            new FakeHttpClient(new HttpResponse(200, (string) file_get_contents(self::CURRENT))),
            Robots::parse(''),
        );

        return $source->fetch();
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/inli-template-' . bin2hex(random_bytes(6)) . '.sqlite3';

        return Store::open($this->dbPath);
    }
}
