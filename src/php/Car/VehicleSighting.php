<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * What the car store says about one observation — the rent side's `Sighting`, price for rent.
 *
 * `isCurrent` is false for an observation OLDER than what the store already believes (a re-read
 * message, a delayed alert): such a sighting changes nothing, and is never a price drop whatever
 * its arithmetic says. That one bit is the whole defence against the 128-email loop.
 */
final readonly class VehicleSighting
{
    public function __construct(
        public string $dedupKey,
        public bool $isNew,
        public bool $isCurrent = true,
        public ?int $priceEur = null,
        public ?int $previousPriceEur = null,
        public bool $isPriceDrop = false,
    ) {}
}
