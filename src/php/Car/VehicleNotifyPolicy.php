<?php

declare(strict_types=1);

namespace Scout\Car;

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
        /**
         * A5 (row 6, 2026-09-05): a MATCH scoring below this is not pushed on its own — it is
         * queued and drained by the daily ROLLUP (`scout --domain=car rollup`, and the floor at
         * `rollup_hour` under `--watch`). `null` keeps every match pushed. Separate from
         * `highPriorityScore`, which drives the `!!` marker. Measured 2026-09-05 over 646 stored
         * MATCHes: p10 29 · p50 61 · p90 78; the shipped 73 is the marker's own calibrated bar and
         * lets about one match in four through individually.
         */
        public ?int $pushMinScore = null,
        /** The hour (local zone) of the daily rollup floor under `--watch`; `null` = no floor, the verb only. */
        public ?int $rollupHour = null,
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
