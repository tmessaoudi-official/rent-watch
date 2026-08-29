<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleClassification;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleOutcome;
use Scout\Car\VehicleScorer;

#[CoversClass(VehicleScorer::class)]
final class VehicleScorerTest extends TestCase
{
    public function testTheIdealCarScoresFullMarks(): void
    {
        $v = $this->judge($this->car(priceEur: 1, year: 2025, month: 8, mileageKm: 1000, gearbox: 'automatique', fuel: 'essence', body: 'suv'));

        self::assertSame(VehicleOutcome::MATCH, $v->outcome);
        self::assertSame(100, $v->score);
        self::assertTrue($v->highPriority);
    }

    public function testAnOldHighMileageManualDieselUnrankedBodyScoresLowButIsStillNotified(): void
    {
        $v = $this->judge($this->car(priceEur: 29000, year: 2015, month: 1, mileageKm: 170000, gearbox: 'manuelle', fuel: 'diesel', body: 'monospace'));

        self::assertSame(VehicleOutcome::MATCH, $v->outcome, 'decisions 7 and 11: components, never disqualifiers');
        self::assertLessThan(10, $v->score);
        self::assertFalse($v->highPriority);
        self::assertContains('diesel — préférence, pas une règle ZFE', $v->reasons, 'never a regulatory claim');
        self::assertContains('monospace — carrosserie non classée', $v->reasons);
    }

    public function testThePriceCeilingIsTheOneHardLine(): void
    {
        self::assertSame(VehicleOutcome::REJECT, $this->judge($this->car(priceEur: 30001))->outcome);
        self::assertSame(VehicleOutcome::MATCH, $this->judge($this->car(priceEur: 30000))->outcome, 'at the ceiling is inside it');
    }

    public function testAStatedLocationOutsideTheSetRejectsAndAnUnknownOneDoesNot(): void
    {
        self::assertSame(VehicleOutcome::REJECT, $this->judge($this->car(postcode: '33000'))->outcome);
        self::assertSame(VehicleOutcome::MATCH, $this->judge($this->car(postcode: null))->outcome, 'hard rule 9: unknown is not elsewhere');
    }

    public function testTheClassifierVerdictOutranksEverything(): void
    {
        $v = (new VehicleScorer())->judge(
            $this->car(priceEur: 1, year: 2025, mileageKm: 10),
            new VehicleClassification(VehicleOutcome::REJECT, ['gagé — « gage »']),
            VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()),
            2026, 8,
        );

        self::assertSame(VehicleOutcome::REJECT, $v->outcome);
        self::assertSame(['exclu : gagé — « gage »'], $v->reasons);
    }

    public function testUnknownComponentsAreUnscoredAndSaySo(): void
    {
        $v = $this->judge($this->car(priceEur: 15000));

        self::assertSame(VehicleOutcome::MATCH, $v->outcome);
        self::assertSame(13, $v->score, '25 × 0.5 for the price and nothing else — unknown is never rewarded');
        foreach (['année inconnue — hors score', 'kilométrage inconnu — hors score', 'boîte inconnue — hors score', 'énergie inconnue — hors score', 'carrosserie inconnue — hors score'] as $line) {
            self::assertContains($line, $v->reasons);
        }
    }

    public function testThePeaksDecayToZeroAtTwiceThePeakNeverBelow(): void
    {
        // 80 000 km peak: 120 000 is halfway, 160 000 is zero, 300 000 is still zero (clamped).
        $half = $this->judge($this->car(mileageKm: 120000));
        $zero = $this->judge($this->car(mileageKm: 160000));
        $far = $this->judge($this->car(mileageKm: 300000));

        self::assertSame(10, $half->score);
        self::assertSame(0, $zero->score);
        self::assertSame(0, $far->score, 'clamped: a component can never go negative and act as a back-door rejection');
    }

    public function testBodyRankScoresByPosition(): void
    {
        self::assertSame(15, $this->judge($this->car(body: 'SUV'))->score);
        self::assertSame(10, $this->judge($this->car(body: 'Break'))->score);
        self::assertSame(5, $this->judge($this->car(body: 'Berline'))->score);
        self::assertSame(0, $this->judge($this->car(body: 'Coupé'))->score);
    }

    public function testAnExtraExcludePatternRejects(): void
    {
        $criteria = VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['exclude_patterns' => ['\\butilitaire\\b']]));
        $v = (new VehicleScorer())->judge($this->car(title: 'Renault Kangoo utilitaire'), new VehicleClassification(VehicleOutcome::MATCH, []), $criteria, 2026, 8);

        self::assertSame(VehicleOutcome::REJECT, $v->outcome);
    }

    private function judge(VehicleListing $car): \Scout\Car\VehicleVerdict
    {
        return (new VehicleScorer())->judge(
            $car,
            (new VehicleClassifier())->classify($car),
            VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()),
            2026, 8,
        );
    }

    private function car(
        ?int $priceEur = null, ?int $year = null, ?int $month = null, ?int $mileageKm = null,
        ?string $gearbox = null, ?string $fuel = null, ?string $body = null, ?string $postcode = null,
        string $title = 'Renault Austral',
    ): VehicleListing {
        return new VehicleListing(
            sourceName: 'test', externalId: 'x', title: $title, priceEur: $priceEur, year: $year, month: $month,
            mileageKm: $mileageKm, gearbox: $gearbox, fuel: $fuel, body: $body, postcode: $postcode,
        );
    }
}
