<?php

declare(strict_types=1);

namespace Scout\Tests\Vehicle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Scout\Vehicle\VehicleClassifier;
use Scout\Vehicle\VehicleListing;
use Scout\Vehicle\VehicleOutcome;

#[CoversClass(VehicleClassifier::class)]
final class VehicleCorpusTest extends TestCase
{
    #[DataProviderExternal(VehicleCorpus::class, 'provider')]
    public function testCorpusCaseClassifiesAsLabelled(VehicleListing $listing, array $expect, string $why, string $id): void
    {
        $result = (new VehicleClassifier())->classify($listing);

        self::assertSame(
            $expect['outcome'],
            $result->outcome->value,
            sprintf("fixture %s\nwhy: %s\ngot: %s", $id, $why, json_encode($result->toArray(), JSON_UNESCAPED_UNICODE)),
        );
        if ($result->outcome === VehicleOutcome::REJECT) {
            self::assertNotSame([], $result->reasons, 'a rejection names its term');
        }
    }

    /**
     * Both forms of EVERY negatable term are in the corpus — the positive form that rejects and the
     * negated reassurance form that matches. A term with only one form is a term whose other
     * direction nobody decided to guarantee.
     */
    public function testEveryNegatableTermIsCoveredInBothDirections(): void
    {
        $cases = VehicleCorpus::load()['cases'];
        $text = static fn (array $c): string => mb_strtolower($c['title'] . ' ' . $c['description'] . ' ' . json_encode($c['fields'], JSON_UNESCAPED_UNICODE));
        foreach (['accident', 'gag', 'opposition'] as $stem) {
            $reject = array_filter($cases, static fn (array $c): bool => $c['expect']['outcome'] === 'REJECT' && str_contains($text($c), $stem));
            $match = array_filter($cases, static fn (array $c): bool => $c['expect']['outcome'] === 'MATCH' && str_contains($text($c), $stem));
            self::assertNotSame([], $reject, "no REJECT case for '{$stem}'");
            self::assertNotSame([], $match, "no negated MATCH case for '{$stem}' — the reassurance form an honest ad writes");
        }
    }

    public function testEveryCaseDeclaresItsProvenance(): void
    {
        foreach (VehicleCorpus::load()['cases'] as $case) {
            self::assertContains($case['provenance'] ?? null, ['synthetic', 'captured'], $case['id']);
        }
    }
}
