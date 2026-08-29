<?php

declare(strict_types=1);

namespace Scout\Tests\Vehicle;

use Scout\Vehicle\VehicleListing;

/** Reader for `tests/fixtures/vehicle/corpus.json` — the car domain's §1 corpus. */
final class VehicleCorpus
{
    public const string PATH = __DIR__ . '/../../fixtures/vehicle/corpus.json';

    /** @return array{cases: list<array<string, mixed>>} */
    public static function load(): array
    {
        $raw = file_get_contents(self::PATH);
        if ($raw === false) {
            throw new \RuntimeException('vehicle corpus is unreadable: ' . self::PATH);
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{VehicleListing, array<string, mixed>, string, string}> */
    public static function provider(): iterable
    {
        foreach (self::load()['cases'] as $index => $case) {
            $listing = new VehicleListing(
                sourceName: 'corpus',
                externalId: sprintf('CAR-%04d', $index),
                title: $case['title'],
                description: $case['description'],
                fields: $case['fields'],
            );
            yield $case['id'] => [$listing, $case['expect'], $case['why'], $case['id']];
        }
    }
}
