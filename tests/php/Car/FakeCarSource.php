<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use Scout\Core\SourceHealth;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSource;
use Scout\Car\VehicleStore;

/** A car source the test drives: fixed listings or a fixed failure; health from the store it is given. */
final class FakeCarSource implements VehicleSource
{
    private static ?VehicleStore $fallback = null;

    /** @param list<VehicleListing> $listings */
    public function __construct(
        private readonly string $name,
        private readonly array $listings,
        private readonly ?\Throwable $throw = null,
        private readonly ?VehicleStore $store = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return 'portal';
    }

    public function host(): ?string
    {
        return null;
    }

    public function fetch(): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->listings;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        $store = $this->store ?? (self::$fallback ??= VehicleStore::open(':memory:'));

        return $store->runs()->health($this->name, $nowIso);
    }
}
