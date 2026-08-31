<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Car\VehicleCriteria;
use Scout\Car\VehicleCriteriaLoader;

#[CoversClass(VehicleCriteriaLoader::class)]
#[CoversClass(VehicleCriteria::class)]
final class VehicleCriteriaTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheShippedFileLoadsAndIsTheRuledShape(): void
    {
        $c = VehicleCriteriaLoader::load(self::ROOT . '/config/car/criteria.json');

        self::assertSame(30000, $c->maxPriceEur, 'decision 5');
        // EMPTY = national since Track 1c (2026-08-31), and that is the honest state rather than a
        // widening. The eight Île-de-France departements were copied from the rent side and were
        // INERT: no car source maps a postcode, so `matchesLocation()` answered true for every
        // vehicle regardless. Leaving them would have activated the filter silently and
        // asymmetrically the day a source first mapped one. `config/car/criteria.json` carries the
        // reasoning and the one line that reverses it.
        self::assertSame([], $c->postcodePrefixes, 'decision 6, revised by Track 1c');
        self::assertTrue($c->matchesLocation('69000'), 'and an empty list genuinely matches everything');
        self::assertTrue($c->matchesLocation(null), 'including an unstated location');
        self::assertSame(['suv', 'break', 'berline'], $c->bodyRank, 'decision 11');
        self::assertSame(5, $c->peakAgeYears);
        self::assertSame(80000, $c->peakMileageKm);
        self::assertSame(100, array_sum($c->weights));
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~max_floor~');
        VehicleCriteriaLoader::fromArray(self::minimal(['max_floor' => 3]));
    }

    public function testWeightsMustSumToAHundred(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~100~');
        VehicleCriteriaLoader::fromArray(self::minimal(['weights' => ['price' => 50, 'age' => 20, 'mileage' => 20, 'gearbox' => 10, 'fuel' => 10, 'body' => 15]]));
    }

    public function testALocalOverrideMergesFieldByField(): void
    {
        $dir = sys_get_temp_dir() . '/scout-car-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/criteria.json', json_encode(self::minimal()));
        file_put_contents($dir . '/criteria.local.json', json_encode(['max_price_eur' => 15000, 'notify' => ['channels' => ['ntfy']]]));

        $c = VehicleCriteriaLoader::load($dir . '/criteria.json', $dir . '/criteria.local.json');

        self::assertSame(15000, $c->maxPriceEur);
        self::assertSame(['ntfy'], $c->notify->channels);
        self::assertSame(70, $c->notify->highPriorityScore, 'untouched keys keep the base value');
    }

    public function testAnUnknownLocationNeverRejectsAndAStatedOneOutsideDoes(): void
    {
        $c = VehicleCriteriaLoader::fromArray(self::minimal());

        self::assertTrue($c->matchesLocation(null), 'hard rule 9');
        self::assertTrue($c->matchesLocation('78500'));
        self::assertFalse($c->matchesLocation('33000'));
        self::assertTrue(VehicleCriteriaLoader::fromArray(self::minimal(['postcode_prefixes' => []]))->matchesLocation('33000'), 'empty = national');
    }

    public function testBodyRankIsFoldedAndUnrankedIsNull(): void
    {
        $c = VehicleCriteriaLoader::fromArray(self::minimal());

        self::assertSame(1, $c->bodyRankOf('SUV'));
        self::assertSame(1, $c->bodyRankOf('4x4 - SUV'), 'a body that contains the ranked word');
        self::assertSame(3, $c->bodyRankOf('Berline'));
        self::assertNull($c->bodyRankOf('Monospace'));
        self::assertNull($c->bodyRankOf(null));
    }

    /** @return array<string,mixed> */
    public static function minimal(array $overrides = []): array
    {
        return $overrides + [
            'max_price_eur' => 30000,
            'postcode_prefixes' => ['75', '77', '78', '91', '92', '93', '94', '95'],
            'body_rank' => ['suv', 'break', 'berline'],
            'peak_age_years' => 5,
            'peak_mileage_km' => 80000,
            'weights' => ['price' => 25, 'age' => 20, 'mileage' => 20, 'gearbox' => 10, 'fuel' => 10, 'body' => 15],
            'exclude_patterns' => [],
            'notify' => ['channels' => ['console'], 'high_priority_score' => 70, 'price_drop_min_eur' => 300, 'price_drop_min_pct' => 3.0],
        ];
    }
}
