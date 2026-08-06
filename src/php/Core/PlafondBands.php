<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * Signal tier 4 — income-ceiling bands, compared against the ceiling a listing quotes.
 *
 * THIS SHIPS EMPTY, AND THAT IS THE HONEST STATE, NOT AN OVERSIGHT.
 *
 * The tier is real and named in `spec/PROJECT_BRIEF.md` §4: a quoted `plafond de ressources` does
 * discriminate LLI from PLUS/PLAI reliably, because the bands are far apart. What this repo does not
 * have is the figures. They vary by zone (A bis / A / B1) and by household size, they are revised
 * annually, and `CLAUDE.md` hard rule 1 forbids writing them from memory. Invented numbers would be
 * worse than an absent tier: the tier would appear to work, and would be wrong in the direction that
 * silently drops eligible listings.
 *
 * So the ladder has the rung, the table is injectable, and with no bands loaded it emits nothing.
 * `TenureClassifierTest::testPlafondTierIsInertUntilRealBandsAreSourced()` asserts exactly that, so
 * the day someone loads real figures the test tells them the tier woke up.
 *
 * Sourcing them is tracked in `docs/OPEN-QUESTIONS.md`.
 */
final readonly class PlafondBands
{
    /**
     * @param array<string, array{max: int, tenure: Tenure}> $bands keyed by zone code
     */
    public function __construct(public array $bands = []) {}

    public function isEmpty(): bool
    {
        return $this->bands === [];
    }

    /**
     * Which tenure does an annual income ceiling of `$ceilingEur` in `$zone` indicate?
     *
     * Returns null when there is nothing to compare against — which, until real figures land, is
     * always.
     */
    public function classifyCeiling(int $ceilingEur, string $zone): ?Tenure
    {
        $band = $this->bands[$zone] ?? null;

        if ($band === null) {
            return null;
        }

        return $ceilingEur <= $band['max'] ? $band['tenure'] : null;
    }
}
