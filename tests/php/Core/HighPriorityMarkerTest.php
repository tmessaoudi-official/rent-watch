<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Config\ConfigLoader;
use RentWatch\Core\Classification;
use RentWatch\Core\CriteriaEngine;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;
use RentWatch\Core\Verdict;

/**
 * The `!!` high-priority marker, and the TWO conditions it needs.
 *
 * `CriteriaEngine` requires `score >= high_priority_score` **AND**
 * `confidenceBp >= HIGH_PRIORITY_MIN_CONFIDENCE_BP` (80). The second half had no test of its own
 * until this file: deleting the confidence clause left the whole suite green, so a *"drop what
 * you're doing"* marker could have started appearing on listings whose tenure was a guess.
 *
 * **THE MARKER WAS DEAD FOR A REASON NOBODY HAD WRITTEN DOWN, and it is the reason this file
 * exists.** `CLAUDE.md` said scores ran 16–48 so `high_priority_score: 70` could never fire.
 * Measured 2026-08-26 by re-judging all 256 stored v7 snapshots offline: scores now run **0–70**
 * (commute lifted the ceiling that day), and the marker STILL could not fire, because the two
 * conditions are satisfied by **disjoint sets of listings**. The top scorers are private-portal
 * listings whose tenure is the source default at `conf 50`; the listings that clear the confidence
 * floor top out at **55**. At any threshold ≥ 60 the marker is unreachable *by construction*.
 *
 * Hence the developer's ruling of 2026-08-26: `high_priority_score` 70 → **50**, which marks 3 of
 * the 47 confident listings (~6%). The confidence floor is deliberately UNCHANGED — lowering the
 * score threshold while it stands tightens what the marker means rather than loosening it.
 *
 * Criteria are built INLINE rather than read from `config/criteria.json` on purpose: the pair below
 * turns on a listing scoring 52, and a future change to `max_rent_cc` or a weight would move that
 * number and redden this file for a reason that has nothing to do with the marker. The shipped
 * value is asserted separately, in `ConfigTest`.
 */
#[CoversClass(CriteriaEngine::class)]
final class HighPriorityMarkerTest extends TestCase
{
    public function testAConfidentListingAtTheThresholdCarriesTheMarker(): void
    {
        $verdict = $this->judge($this->confidentlyIntermediate());

        self::assertSame(52, $verdict->score, 'guard: the pair below is only meaningful at this score');
        self::assertTrue(
            $verdict->highPriority,
            'a confident LLI verdict scoring at or above the threshold is exactly what !! is for',
        );
    }

    /**
     * The counterweight, and the half that was unpinned.
     *
     * IDENTICAL listing facts, so the score is identical — only the tenure evidence differs. The
     * private-portal card states no regime at all, so its `LIBRE` comes from the source default at
     * 50/100, and the marker must NOT fire however well the flat scores.
     */
    public function testTheConfidenceFloorOutranksTheScore(): void
    {
        $confident = $this->judge($this->confidentlyIntermediate());
        $guessed = $this->judge($this->tenureFromTheSourceDefault());

        self::assertSame(
            $confident->score,
            $guessed->score,
            'guard: the two listings must score the same, or this test is about the score and not the floor',
        );
        self::assertFalse(
            $guessed->highPriority,
            'a tenure taken from the source default is a guess, and a guess must never carry !!',
        );
    }

    /** And the demotion is VISIBLE — a marker withheld in silence is indistinguishable from a bug. */
    public function testTheWithheldMarkerSaysWhyOnThePhone(): void
    {
        $reasons = $this->judge($this->tenureFromTheSourceDefault())->reasons;

        self::assertNotSame(
            [],
            array_filter($reasons, static fn (string $r): bool => str_contains($r, 'priorité normale')),
            'the notification must say the marker was withheld for want of confidence, not just omit it',
        );
    }

    /**
     * A listing whose tenure is confident but which scores BELOW the threshold stays normal.
     *
     * Without this, "confident ⇒ !!" would satisfy the first test and the marker would fire on
     * every well-classified listing — the marker meaning *nothing*, which is the failure mode
     * lowering a threshold is most likely to cause.
     */
    public function testAConfidentButMediocreListingStaysNormalPriority(): void
    {
        $mediocre = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'mediocre',
            title: 'Logement intermédiaire 3 pièces',
            description: 'Appartement en logement locatif intermédiaire (LLI).',
            commune: 'Dourdan',
            postcode: '91410',
            rentCc: 1150,
            surfaceM2: 52.0,
            rooms: 3,
        );

        $verdict = $this->judge($mediocre);

        self::assertNotNull($verdict->score);
        self::assertLessThan(50, $verdict->score, 'guard: this listing must sit below the threshold');
        self::assertFalse($verdict->highPriority);
    }

    // ------------------------------------------------------------------ helpers

    /** Same flat, stated as intermediate in its own copy: tier-2 label, 0.90 confidence. */
    private function confidentlyIntermediate(): RawListing
    {
        return new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'confident',
            title: 'Logement intermédiaire 3 pièces',
            description: 'Appartement en logement locatif intermédiaire (LLI).',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 900,
            surfaceM2: 80.0,
            rooms: 3,
        );
    }

    /** The same flat as a private portal advertises it: no regime stated anywhere. */
    private function tenureFromTheSourceDefault(): RawListing
    {
        return new RawListing(
            sourceName: 'seloger',
            externalId: 'guessed',
            title: 'Appartement 3 pièces',
            description: 'Bel appartement lumineux.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 900,
            surfaceM2: 80.0,
            rooms: 3,
        );
    }

    private function judge(RawListing $listing): Verdict
    {
        $criteria = ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => ['75', '77', '78', '91', '92', '93', '94', '95'],
            'min_rooms' => 3,
            'min_surface_m2' => 50,
            'max_rent_cc' => 1200,
            'commune_rank' => ['Sartrouville' => 1],
            'weights' => [
                'commune' => 25,
                'commute' => 0,
                'rent_headroom' => 15,
                'surface' => 10,
                'lift' => 15,
                'high_floor_no_lift' => -20,
                'freshness' => 10,
            ],
            'freshness_minutes' => 60,
            'notify' => [
                'channels' => ['console'],
                'high_priority_score' => 50,
                'rent_drop_min_eur' => 20,
                'rent_drop_min_pct' => 2,
                'source_broken_cooldown_hours' => 24,
                'digest_hour' => 8,
            ],
        ]);

        // The REAL classifier, never a fabricated Classification: the confidence the floor reads is
        // produced by the classifier's own signal priority, and inventing a number here would let
        // this test agree with itself while the pipeline disagreed.
        $classification = (new TenureClassifier())->classify(
            $listing,
            $listing->sourceName === 'seloger'
                ? new SourceProfile(name: 'seloger', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false)
                : new SourceProfile(name: 'cdc_habitat', mixedTenure: true),
        );

        self::assertInstanceOf(Classification::class, $classification);

        // A first-seen age far past `freshness_minutes`, so the freshness bonus cannot fire and the
        // scores stay stable whatever the clock says when the suite runs.
        return (new CriteriaEngine($criteria))->judge($listing, $classification, 999_999);
    }
}
