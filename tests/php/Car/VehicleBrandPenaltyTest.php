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
        self::assertSame(['peugeot', 'renault', 'opel'], $criteria->brandAvoid);
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
