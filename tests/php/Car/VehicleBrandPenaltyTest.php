<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleScorer;
use Scout\Car\VehicleVerdict;

/**
 * TRACK 1d — the brand penalty, and the INVERSION is the ruling rather than a detail.
 *
 * The developer ruled (2026-08-31) that disfavoured makes score LOWER, with the weight taken out
 * of the existing 100. Mirroring `body_rank` — the mechanism already in this scorer — would have
 * done the exact opposite: that one gives its TOP entry the full share, so a `brand_rank` naming
 * the three makes to avoid would have ranked them first. Hence a LIST of makes to avoid, no
 * ordering among them, and the share EARNED by being absent from it.
 *
 * Everything here is asserted as a strict INEQUALITY at otherwise-identical specs rather than as a
 * fixed number: an expectation pinned to 10 points goes stale the day the weight is re-allocated,
 * and the guarantee is the ordering, not the arithmetic.
 */
#[CoversClass(VehicleScorer::class)]
final class VehicleBrandPenaltyTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function avoidedMakes(): iterable
    {
        yield 'peugeot' => ['Peugeot'];
        yield 'renault' => ['Renault'];
        yield 'opel' => ['Opel'];
        // FOLDED at load and at comparison, so the payload's casing is irrelevant — a real
        // `make_model_pattern` capture arrives however the portal typed it.
        yield 'shouting' => ['PEUGEOT'];
        yield 'padded' => ['  Renault '];
    }

    #[DataProvider('avoidedMakes')]
    public function testAnAvoidedMakeScoresStrictlyBelowAnUnlistedOneAtIdenticalSpecs(string $make): void
    {
        $avoided = $this->judge($make);
        $unlisted = $this->judge('Toyota');

        self::assertLessThan($unlisted->score, $avoided->score, 'the penalty is the whole mechanism');
        self::assertContains(trim($make) . ' — marque à éviter', $avoided->reasons);
        self::assertContains('Toyota — hors des marques à éviter', $unlisted->reasons);
    }

    /**
     * IT IS A SCORE, NEVER A DISQUALIFIER (hard rule 8), and this is the half that matters most:
     * the developer asked to see fewer of these makes, not none of them.
     */
    #[DataProvider('avoidedMakes')]
    public function testAnAvoidedMakeIsStillNotified(string $make): void
    {
        self::assertSame(\Scout\Car\VehicleOutcome::MATCH, $this->judge($make)->outcome);
        self::assertGreaterThan(0, $this->judge($make)->score, 'penalised on one component, not zeroed overall');
    }

    /**
     * AN UNEXTRACTED MAKE SCORES 0 AND SAYS SO — the arm every other component here takes, and a
     * deliberate deviation from the plan's line that it should take the full share.
     *
     * Hard rule 9 forbids reading unknown as BELOW A MINIMUM, which is a disqualifier; nothing here
     * disqualifies. Awarding the share would rank an extraction failure as a definitely-not-Peugeot
     * — a fact manufactured from its own absence, which is this repo's recurring defect. Both
     * shipped car sources extract a make, so an absent one is a fault worth ranking below a
     * documented car rather than above one.
     */
    public function testAnUnextractedMakeIsUnscoredAndSaysSo(): void
    {
        $unknown = $this->judge(null);

        self::assertContains('marque inconnue — hors score', $unknown->reasons);
        self::assertLessThan($this->judge('Toyota')->score, $unknown->score);
        self::assertSame(
            $this->judge('Peugeot')->score,
            $unknown->score,
            'no fact is invented in either direction: an unknown make earns the share no more than an avoided one does',
        );
    }

    /**
     * THE COUNTERWEIGHT, and without it the whole feature is satisfied by deleting it.
     *
     * With no `brand_avoid` configured no make is disfavoured, so EVERY make earns the share — and
     * the achievable maximum stays 100. Withholding it there would shrink the scale to 90 for such
     * a deployment, and `high_priority_score` is an ABSOLUTE threshold, so the marker would quietly
     * become harder to reach. Same failure the rent side measured on 2026-08-26, where `!!` was
     * unreachable by construction.
     */
    public function testWithNoListConfiguredEveryMakeEarnsTheShare(): void
    {
        $criteria = VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['brand_avoid' => []]));

        $peugeot = $this->judgeWith('Peugeot', $criteria);
        $toyota = $this->judgeWith('Toyota', $criteria);

        self::assertSame($toyota->score, $peugeot->score, 'nothing is configured, so nothing is disfavoured');
        self::assertContains('marque — aucune préférence configurée', $peugeot->reasons);
        self::assertGreaterThan(
            $this->judge('Peugeot')->score,
            $peugeot->score,
            'the share is genuinely awarded here, not merely unmentioned',
        );
    }

    /** The weights still sum to 100 — the ruled part of the mechanism, and the one that is checkable. */
    public function testTheShippedWeightsStillSumToAHundredAndReserveABrandShare(): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertSame(100, array_sum($criteria->weights));
        self::assertGreaterThan(0, $criteria->weights['brand'], 'a zero share is the feature switched off');

        // The 22 ruled 2026-09-01. Asserted as a SET rather than spot-checked, because the
        // failure this list has is a make quietly going missing from it — which no sample catches.
        self::assertSame([
            'peugeot', 'citroen', 'ds', 'opel', 'vauxhall', 'fiat', 'abarth', 'lancia',
            'alfa', 'jeep', 'dodge', 'chrysler', 'ram', 'maserati',
            'ford', 'chevrolet',
            'renault', 'dacia', 'nissan', 'alpine', 'mitsubishi', 'leapmotor',
        ], $criteria->brandAvoid);
    }

    /**
     * A MARQUE IS CAUGHT WHATEVER SUFFIX THE SOURCE SPELLS IT WITH — and this is a measured
     * defect, not a hypothetical.
     *
     * The live car store carries the SAME marque under two spellings, one per source: autohero
     * emits `ds automobiles` and leboncoin emits `ds`. `in_array($folded, $brandAvoid, true)` is
     * exact equality, so a config entry `ds` caught the leboncoin row and SILENTLY MISSED the
     * autohero one — a configured preference that is inert on one source, which is the failure
     * class this repo has already paid for four times (`exclude_title_patterns` on In'li, the two
     * unread car params, PAP's anchors). It costs 10 points of ordering and nothing reads as a
     * fault.
     *
     * So the entry is a STEM and the match runs to a non-letter boundary. The stem is the shortest
     * unambiguous form on purpose — `alfa`, not `alfa romeo` — because the gap runs BOTH ways: an
     * entry longer than the make misses just as silently the day a source emits the short form.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function suffixedSpellings(): iterable
    {
        yield 'ds automobiles (autohero, live)' => ['DS Automobiles'];
        yield 'fiat professional' => ['Fiat Professional'];
        yield 'alfa romeo' => ['Alfa Romeo'];
        yield 'ram trucks' => ['RAM Trucks'];
        // A hyphen is a boundary too — a portal writing the badge rather than the marque.
        yield 'hyphenated' => ['Citroen-DS'];
        // A digit is a boundary: `DS 3` and `DS3` are the same car typed two ways.
        yield 'digit boundary' => ['DS3 Crossback'];
    }

    #[DataProvider('suffixedSpellings')]
    public function testAMarqueIsCaughtWhateverSuffixTheSourceSpellsItWith(string $make): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertTrue(
            $criteria->isAvoidedBrand($make),
            "`{$make}` must be penalised — a stem that misses a real spelling is an inert filter",
        );
    }

    /**
     * THE COUNTERWEIGHT, and without it the guarantee above is satisfied by penalising everything.
     *
     * A stem match can over-reach as silently as an exact match under-reaches, and the direction is
     * worse: a make wrongly penalised is a car ranked below one that deserves less. Every spelling
     * below is one the LIVE store actually contains (26 distinct makes across the three sources,
     * 2026-09-01) — real inputs, not invented ones, which is the rule this repo's surface matrix
     * already carries.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function unlistedSpellings(): iterable
    {
        foreach ([
            'audi', 'autres', 'bmw', 'hyundai', 'kia', 'lexus', 'mazda',
            'mercedes', 'mercedes-benz', 'seat', 'skoda', 'smart', 'toyota', 'volkswagen',
        ] as $make) {
            yield $make => [$make];
        }
    }

    #[DataProvider('unlistedSpellings')]
    public function testAStemNeverReachesAMakeNobodyListed(string $make): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertFalse(
            $criteria->isAvoidedBrand($make),
            "`{$make}` is on nobody's list — a stem reaching it ranks a car below one that deserves less",
        );
    }

    /**
     * THE COUNTERWEIGHT ABOVE DOES NOT REACH THE BOUNDARY CHECK, which is why this provider exists
     * separately rather than as two more rows in it.
     *
     * Measured: deleting the boundary check left the whole suite GREEN. Not one of the 26 makes the
     * live store contains BEGINS with one of the 22 stems, so the guard is never entered and was
     * dead safety code the moment it was written — the trap this repo already documents ("a
     * guarantee whose branch no fixture reaches is dead safety code until something reaches it").
     * The sabotage case pins it, and it needs an input that enters the branch.
     *
     * Both below are REAL marques, not invented ones, and both are plausible on a classic listing
     * — the rule that a cell must be fed something a real feed could emit. `Rambler` (American
     * Motors) begins with the `ram` stem; `Fordson` (Ford's tractors) begins with `ford`. Neither
     * is the marque its stem names, and penalising either would rank a car below one that deserves
     * less, silently.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function wordsThatMerelyBeginLikeAStem(): iterable
    {
        yield 'rambler (begins with the `ram` stem)' => ['Rambler'];
        yield 'fordson (begins with the `ford` stem)' => ['Fordson'];
    }

    #[DataProvider('wordsThatMerelyBeginLikeAStem')]
    public function testAStemStopsAtALetterAndDoesNotSwallowALongerWord(string $make): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertFalse(
            $criteria->isAvoidedBrand($make),
            "`{$make}` merely BEGINS like a stem — the boundary check is the whole difference",
        );
    }

    /**
     * A BRAND THAT FOLDS TO NOTHING IS REFUSED, and the input matters more than the assertion.
     *
     * A first draft used `'  '` and passed — against `Reader::requireStringList`, which already
     * refuses a `trim()`-blank entry. Deleting the loader's own guard left that draft green: it was
     * a true observation attached to the wrong mechanism, which is this repo's named failure. The
     * shapes that actually REACH the guard are the ones `trim()` does not see — a non-breaking
     * space, a zero-width space — and folding is what collapses them. An entry that folds to
     * nothing would match no make at all while reading as a configured preference.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function emptyBrands(): iterable
    {
        yield 'non-breaking space' => ["\u{00A0}"];
        yield 'zero-width space' => ["\u{200B}"];
    }

    #[DataProvider('emptyBrands')]
    public function testABrandThatFoldsToNothingIsRefused(string $brand): void
    {
        $this->expectException(\Scout\Config\ConfigError::class);
        $this->expectExceptionMessageMatches('~brand_avoid~');
        VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['brand_avoid' => [$brand]]));
    }

    /** The plain-blank case, caught one layer up — asserted so the two guards cannot both be lost. */
    public function testAPlainBlankBrandIsRefusedByTheReader(): void
    {
        $this->expectException(\Scout\Config\ConfigError::class);
        $this->expectExceptionMessageMatches('~brand_avoid~');
        VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['brand_avoid' => ['  ']]));
    }

    private function judge(?string $make): VehicleVerdict
    {
        return $this->judgeWith($make, VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()));
    }

    private function judgeWith(?string $make, \Scout\Car\VehicleCriteria $criteria): VehicleVerdict
    {
        // Identical specs on every other component, so the ONLY difference between two runs is the
        // make — an inequality that survived a change to any other weight would prove nothing.
        $car = new VehicleListing(
            sourceName: 'test',
            externalId: 'x',
            title: 'Voiture d\'occasion',
            priceEur: 15000,
            year: 2024,
            month: 1,
            mileageKm: 40000,
            gearbox: 'automatique',
            fuel: 'essence',
            body: 'suv',
            postcode: null,
            make: $make,
        );

        return (new VehicleScorer())->judge($car, (new VehicleClassifier())->classify($car), $criteria, 2026, 8);
    }
}
