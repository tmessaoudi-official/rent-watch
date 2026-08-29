<?php

declare(strict_types=1);

namespace Scout\Vehicle;

/** The car domain's notification routing — the rent side's `NotifyPolicy`, minus the digest (no mixed stock here). */
final readonly class VehicleNotifyPolicy
{
    /** @param list<string> $channels */
    public function __construct(
        public array $channels,
        public int $highPriorityScore,
        public int $priceDropMinEur,
        public float $priceDropMinPct,
        public int $sourceAlertCooldownHours = 12,
    ) {}

    /** A drop worth a push: at least the euro floor OR the percentage floor, on a known previous price. */
    public function isNotableDrop(int $previousEur, int $currentEur): bool
    {
        if ($currentEur >= $previousEur) {
            return false;
        }
        $delta = $previousEur - $currentEur;

        return $delta >= $this->priceDropMinEur || ($previousEur > 0 && $delta * 100 / $previousEur >= $this->priceDropMinPct);
    }
}
