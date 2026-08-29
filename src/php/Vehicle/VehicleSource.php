<?php

declare(strict_types=1);

namespace Scout\Vehicle;

use Scout\Core\SourceHealth;

/** The car domain's adapter contract — `Adapters\Source`, for cars. `fetch()` throws, never returns `[]` on failure (hard rule 3). */
interface VehicleSource
{
    public function name(): string;

    /** portal | dealer | auction */
    public function family(): string;

    /** The host outbound requests go to, or null when the source issues none (an email source). */
    public function host(): ?string;

    /**
     * @return list<VehicleListing>
     *
     * @throws \Scout\Adapters\SourceError
     */
    public function fetch(): array;

    public function health(?string $nowIso = null): SourceHealth;
}
