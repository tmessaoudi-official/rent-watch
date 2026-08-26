<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Config\ConfigLoader;
use RentWatch\Core\CriteriaEngine;
use RentWatch\Core\Outcome;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;

/**
 * S8 — the commute component.
 *
 * It is the largest single weight in the tree (30, ahead of commune's 25) and it exists because
 * nothing else discriminates: live scores ran 16–48 across 83 matches spread over all eight
 * departements, so `high_priority_score: 70` could never fire and the `!!` marker was dead.
 *
 * **IT IS A SCORE COMPONENT AND NEVER A DISQUALIFIER.** Developer ruling 2026-08-26, verbatim:
 * *"1 hour 15 max ! but keep showing even those with more anyway !"* — which is also hard rule 8,
 * where disqualifiers and score are two different mechanisms. `max_minutes` is the ZERO POINT of the
 * scale, not a gate.
 *
 * And an unknown commute is UNKNOWN, never far (hard rule 9): the component is not scored and the
 * reasons say so, in the same shape `rentHc` already uses for an unverifiable ceiling.
 */
#[CoversClass(CriteriaEngine::class)]
final class CommuteScoreTest extends TestCase
{
    private const string CRITERIA = __DIR__ . '/../../fixtures/criteria/commute.json';

    public function testAShorterCommuteScoresHigherThanALongerOne(): void
    {
        $near = $this->judge(15);
        $far = $this->judge(65);

        self::assertGreaterThan(
            $far->score,
            $near->score,
            'the whole point of the component is that it separates listings nothing else separates',
        );
    }

    public function testTheCeilingIsTheZeroPointAndNotAGate(): void
    {
        // 90 minutes is past the configured 75. The developer asked to keep seeing those anyway, so
        // it must still be a MATCH — it simply earns nothing from this component.
        $over = $this->judge(90);

        self::assertSame(Outcome::MATCH, $over->outcome, 'a long commute must never reject a listing');

        // And it is not scored NEGATIVELY either: the floor is zero, so a three-hour commute and a
        // 76-minute one are equally unrewarded rather than progressively punished.
        self::assertSame($this->judge(200)->score, $over->score);
    }

    public function testAnUnknownCommuteLosesTheComponentAndSaysSo(): void
    {
        // The API was unreachable, or the commune did not geocode. That is absence of evidence, and
        // it must not read as a short commute (which would be a free 30 points) nor as a long one
        // (which would silently demote every listing whenever the API had a bad afternoon).
        $unknown = $this->judge(null);

        self::assertSame(Outcome::MATCH, $unknown->outcome);
        self::assertLessThan($this->judge(5)->score, $unknown->score);

        self::assertNotSame(
            [],
            array_filter($unknown->reasons, static fn (string $r): bool => str_contains($r, 'trajet')),
            'an unscored commute must be VISIBLE on the phone, not silently absent',
        );
    }

    public function testAKnownCommuteIsNamedInTheReasons(): void
    {
        $reasons = $this->judge(38)->reasons;

        self::assertNotSame(
            [],
            array_filter($reasons, static fn (string $r): bool => str_contains($r, '38')),
            'the number that moved the score the most must appear in the notification',
        );
    }

    private function judge(?int $commuteMinutes): \RentWatch\Core\Verdict
    {
        $criteria = ConfigLoader::loadCriteria(self::CRITERIA);

        $listing = new RawListing(
            sourceName: 'pap',
            externalId: 'https://www.pap.fr/annonces/-r1',
            title: 'Location appartement 3 pièces',
            description: 'Bel appartement',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1100,
            surfaceM2: 82.0,
            rooms: 3,
            commuteMinutes: $commuteMinutes,
        );

        // The REAL classifier rather than a fabricated Classification: its constructor carries a
        // typed signal list and its own outcome, and inventing those would let this test drift from
        // what the pipeline actually feeds `judge()`.
        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'pap', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        return (new CriteriaEngine($criteria))->judge($listing, $classification);
    }
}
