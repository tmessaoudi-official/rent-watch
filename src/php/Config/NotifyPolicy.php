<?php

declare(strict_types=1);

namespace Scout\Config;

/**
 * Notification routing, ruled 2026-08-07 (`docs/OPEN-QUESTIONS.md` § "Resolution of 1c").
 *
 * Three of these five settings exist to stop the tool training its own user to ignore it, which is
 * the failure mode a watcher dies of:
 *
 * - `sourceBrokenCooldownHours` — a source broken for a week must not send seven identical alerts.
 * - `rentDropMinEur` / `rentDropMinPct` — a 5 € correction is noise; 20 €/month is 240 €/year.
 * - `highPriorityScore` — if everything is urgent, nothing is.
 *
 * Nothing is batched, deliberately. The brief's premise is that good LLI stock goes within hours, so
 * a batching window would defeat the tool for the listings it exists to catch.
 */
final readonly class NotifyPolicy
{
    /**
     * @param list<string> $channels enabled channel names; `console` is always available
     * @param int          $digestHour local hour (0–23) at which the "à vérifier" digest is emitted
     */
    public function __construct(
        public array $channels = ['console'],
        public int $highPriorityScore = 70,
        public int $rentDropMinEur = 20,
        public float $rentDropMinPct = 2.0,
        public int $sourceBrokenCooldownHours = 24,
        public int $digestHour = 8,
    ) {}

    /**
     * Is this rent change worth telling the developer about?
     *
     * EITHER threshold is enough — a 20 € drop on a 500 € studio is 4% and a 20 € drop on a 1800 €
     * house is 1.1%, and both are worth knowing. Requiring both would silence the second.
     */
    public function isNotableDrop(int $previousCc, int $currentCc): bool
    {
        if ($currentCc >= $previousCc) {
            return false;
        }

        $drop = $previousCc - $currentCc;
        if ($drop >= $this->rentDropMinEur) {
            return true;
        }

        // A previous rent of 0 is not a real rent; treating it as a denominator would divide by zero
        // and calling it a 100% drop would be a fabricated event.
        if ($previousCc <= 0) {
            return false;
        }

        return ($drop * 100.0 / $previousCc) >= $this->rentDropMinPct;
    }

    public static function fromReader(Reader $r): self
    {
        $p = new self(
            channels: $r->has('channels') ? $r->requireStringList('channels') : ['console'],
            highPriorityScore: $r->optInt('high_priority_score', 70, 0, 100) ?? 70,
            rentDropMinEur: $r->optInt('rent_drop_min_eur', 20, 0) ?? 0,
            rentDropMinPct: $r->has('rent_drop_min_pct') ? $r->requireFloat('rent_drop_min_pct', 0.0, 100.0) : 2.0,
            sourceBrokenCooldownHours: $r->optInt('source_broken_cooldown_hours', 24, 0) ?? 0,
            digestHour: $r->optInt('digest_hour', 8, 0, 23) ?? 8,
        );
        $r->done();

        return $p;
    }
}
