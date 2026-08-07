<?php

declare(strict_types=1);

namespace RentWatch\Config;

/**
 * The score components of `spec/PROJECT_BRIEF.md` §5, with the weights ruled on 2026-08-07.
 *
 * **Weights are normalised, not summed raw.** A component that is disabled (commute, weight 0 until
 * an IDFM key exists — `docs/OPEN-QUESTIONS.md` Q1) would otherwise depress every score by its share
 * for the whole life of the project, so a perfect flat would top out at 75/100 and the notification
 * threshold would quietly mean something different from what it says. {@see positiveTotal()} is what
 * the criteria engine divides by.
 *
 * `highFloorNoLift` is NEGATIVE and is deliberately excluded from that total: a penalty is not part
 * of what a perfect listing can earn, it is subtracted from what it earned. Including it in the
 * denominator would make the penalty smaller the larger it was set, which is the opposite of the
 * intent.
 */
final readonly class Weights
{
    public function __construct(
        public int $commune = 25,
        public int $commute = 0,
        public int $rentHeadroom = 15,
        public int $surface = 10,
        public int $lift = 15,
        public int $highFloorNoLift = -20,
        public int $freshness = 10,
    ) {}

    /**
     * Sum of the components a listing can EARN. Never zero — see the guard.
     *
     * A config that zeroed every positive weight would make the score a division by zero, and the
     * honest reading of it is "the developer disabled scoring", not "every listing scores 0". The
     * criteria engine treats a zero total as "no scoring configured" and scores every match 100,
     * because ordering by an all-zero score is meaningless and a silent 0 would look like a bad
     * listing rather than an unconfigured one.
     */
    public function positiveTotal(): int
    {
        return max(0, $this->commune)
            + max(0, $this->commute)
            + max(0, $this->rentHeadroom)
            + max(0, $this->surface)
            + max(0, $this->lift)
            + max(0, $this->freshness);
    }

    public static function fromReader(Reader $r): self
    {
        $w = new self(
            commune: $r->optInt('commune', 25, 0, 1000) ?? 0,
            commute: $r->optInt('commute', 0, 0, 1000) ?? 0,
            rentHeadroom: $r->optInt('rent_headroom', 15, 0, 1000) ?? 0,
            surface: $r->optInt('surface', 10, 0, 1000) ?? 0,
            lift: $r->optInt('lift', 15, 0, 1000) ?? 0,
            // The one weight allowed to be negative, and required to be: a positive "high floor, no
            // lift" weight would be a bonus for the exact thing the developer is escaping.
            highFloorNoLift: $r->optInt('high_floor_no_lift', -20, -1000, 0) ?? 0,
            freshness: $r->optInt('freshness', 10, 0, 1000) ?? 0,
        );
        $r->done();

        return $w;
    }
}
