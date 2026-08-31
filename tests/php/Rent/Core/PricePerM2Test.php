<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;

/**
 * TRACK 1f — a rent that is implausible for the surface it claims.
 *
 * The failure it catches passes every numeric filter, because each number is individually plausible
 * and only their RATIO is not: a room in a shared flat advertised with the WHOLE flat's surface, and
 * a surface read off the wrong thing entirely (one real notified MATCH priced a 400 m² GARDEN).
 *
 * DIGEST, NEVER A REJECTION, and that is the load-bearing choice. The discriminating sentence
 * usually lives on the detail page, which the source structurally never reads — following SeLoger's
 * per-recipient redirect at ingest is a hard-rule-5 refusal — so the tool is guessing from a ratio.
 * A guess belongs in the bin the developer reads, not in the silent one.
 *
 * THE THRESHOLD IS DERIVED, and `config/rent/criteria.json` carries the derivation. Over the 1 392
 * stored listings surviving every OTHER exclusion the cheapest are 3.00, 3.75 and 4.54 €/m², then a
 * jump of 2.21 to 6.75 — the largest break anywhere in the tail, where every other gap below the
 * 12th percentile is ≤ 0.54. Not a percentile: p5 is 8.11 and would eat 113 rows.
 */
#[CoversClass(CriteriaEngine::class)]
final class PricePerM2Test extends TestCase
{
    private const string CRITERIA = __DIR__ . '/../../../../config/rent/criteria.json';

    public function testAnImplausiblyCheapListingGoesToTheDigestAndSaysWhy(): void
    {
        // The real shape: 449 € for a claimed 99 m² T5 — 4.54 €/m², a room advertised with the whole
        // flat's size. It clears `max_rent_cc`, `min_rooms` and `min_surface_m2` individually.
        $verdict = $this->judge(rentCc: 449, surfaceM2: 99.0, rooms: 5);

        self::assertSame(Outcome::DIGEST, $verdict->outcome, 'doubtful, not rejected — the tool is guessing from a ratio');
        self::assertNotSame(
            [],
            array_filter($verdict->reasons, static fn (string $r): bool => str_contains($r, '€/m²')),
            'the digest line must name the price-plausibility cause, or the entry is a bare link',
        );
    }

    public function testAnOrdinaryListingIsUntouched(): void
    {
        // 1 100 € for 82 m² is 13.4 €/m² — near the median of the stored distribution.
        self::assertSame(Outcome::MATCH, $this->judge(rentCc: 1100, surfaceM2: 82.0, rooms: 3)->outcome);
    }

    public function testAListingJustAboveTheGapIsNotCaught(): void
    {
        // 6.75 €/m² is the first row ABOVE the derived gap, and it is a real notified MATCH. The
        // threshold must not creep up into the dense data that starts there: 6.75, 6.88, 7.14 and
        // 7.87 sit within 1.1 of each other, so a threshold reaching them has no support in the
        // distribution. STATED COST: the Champs-sur-Marne pair at 6.88 and 7.14 that motivated this
        // track is therefore NOT caught.
        self::assertSame(Outcome::MATCH, $this->judge(rentCc: 850, surfaceM2: 126.0, rooms: 4)->outcome);
    }

    /**
     * A PARKING WOULD DIVIDE BY ZERO, and 15 stored Logirep rows are exactly that shape.
     *
     * The guard is `> 0`, not `!== null`: those rows carry `surface = 0, rooms = 0`, so a
     * `!== null` guard passes them straight into `rent / 0.0` — a `DivisionByZeroError` that takes
     * down the whole pass, on a source that returns its full catalogue every time.
     */
    public function testAZeroSurfaceDoesNotDivideByZero(): void
    {
        $verdict = $this->judge(rentCc: 90, surfaceM2: 0.0, rooms: 0);

        self::assertNotSame(Outcome::DIGEST, $verdict->outcome, 'a parking is rejected on its own merits, not by this heuristic');
    }

    /**
     * THE SURFACE GUARD ON ITS OWN, with rooms deliberately non-zero.
     *
     * The case above cannot reach it: a real Logirep parking carries `surface = 0` AND `rooms = 0`,
     * so the rooms guard short-circuits first and deleting the surface guard leaves the suite green.
     * A sabotage run proved exactly that. A surface that failed to extract as `0` on a listing that
     * DID state its rooms is the shape that reaches `rent / 0.0`.
     */
    public function testAZeroSurfaceAloneDoesNotDivideByZero(): void
    {
        // WITHOUT `min_surface_m2`, which is the only configuration that reaches this guard — and
        // finding that out is the point. Under the SHIPPED criteria a zero-surface listing is
        // disqualified on the surface floor long before any ratio is computed, so the guard is
        // unreachable and deleting it leaves the suite green (a sabotage run proved it twice, first
        // with rooms 0 and then with rooms 3). `min_surface_m2` is optional in the loader, so a
        // deployment that omits it walks straight into `rent / 0.0` — a `DivisionByZeroError` that
        // takes down the whole pass on a source returning its full catalogue every time.
        $path = sys_get_temp_dir() . '/ppm-nofloor-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode([
            'communes' => [],
            'postcode_prefixes' => ['78'],
            'min_price_per_m2' => 5.5,
        ], JSON_THROW_ON_ERROR));

        try {
            $listing = $this->listing(rentCc: 900, surfaceM2: 0.0, rooms: 3);
            $classification = (new TenureClassifier())->classify(
                $listing,
                new SourceProfile(name: 'seloger', defaultTenure: Tenure::LIBRE, mixedTenure: false),
            );

            $verdict = (new CriteriaEngine(ConfigLoader::loadCriteria($path)))->judge($listing, $classification);

            self::assertNotSame(Outcome::DIGEST, $verdict->outcome, 'an unreadable surface asks no ratio question');
        } finally {
            @unlink($path);
        }
    }

    /**
     * INERT ON AN HC-ONLY SOURCE, which is correct rather than a gap.
     *
     * It reads `effectiveRentCc()` — the same figure `max_rent_cc` uses — so on the ~157 stored rows
     * that quote rent *hors charges* (Logirep, leboncoin, PAP) it asks nothing — `effectiveRentCc()`
     * returns null unless BOTH `rentHc` and `charges` are known. A ratio built on a
     * rent that excludes charges is not comparable with one that includes them, and inventing an
     * uplift is what this repo already refused for those sources.
     */
    public function testItAsksNothingOfAnHcOnlyListing(): void
    {
        $listing = $this->listing(rentCc: null, surfaceM2: 99.0, rooms: 5);
        $listing = new RawListing(
            sourceName: 'logirep', externalId: 'x', title: $listing->title, description: $listing->description,
            commune: 'Sartrouville', postcode: '78500',
            rentHc: 449, surfaceM2: 99.0, rooms: 5,
        );

        self::assertNotSame(
            Outcome::DIGEST,
            $this->judgeListing($listing)->outcome,
            'no CC rent, no ratio — and the score line already says the ceiling is unverifiable here',
        );
    }

    private function judge(?int $rentCc, float $surfaceM2, int $rooms): Verdict
    {
        return $this->judgeListing($this->listing($rentCc, $surfaceM2, $rooms));
    }

    private function listing(?int $rentCc, float $surfaceM2, int $rooms): RawListing
    {
        return new RawListing(
            sourceName: 'seloger',
            externalId: 'https://www.seloger.com/annonces/1',
            title: 'Appartement',
            description: 'Bel appartement proche gare.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: $rentCc,
            surfaceM2: $surfaceM2,
            rooms: $rooms,
        );
    }

    private function judgeListing(RawListing $listing): Verdict
    {
        $criteria = ConfigLoader::loadCriteria(self::CRITERIA);

        // The REAL classifier, for the reason the commute test gives: a fabricated Classification
        // would let this drift from what the pipeline actually feeds `judge()`.
        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: $listing->sourceName, defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        return (new CriteriaEngine($criteria))->judge($listing, $classification);
    }
}
