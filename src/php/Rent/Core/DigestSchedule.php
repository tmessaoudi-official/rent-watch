<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * Q34's daily floor: *has the local `digest_hour` come round again since the last emission?*
 *
 * Q34 rules three emission paths for the *à vérifier* rollup. Two were built: automatically at the
 * end of any pass that produced new entries, and on demand via `scout digest`. The third — *"the
 * daily emission stays as a floor for days with nothing new"* — was not. `digest_hour` was parsed
 * into {@see \Scout\Rent\Config\NotifyPolicy}, printed by `doctor`, and read by nothing else, so a
 * backlog that failed to send simply sat there until somebody typed the command. The digest is §1's
 * only landing zone; a bin nobody drains is the fail-closed rule quietly costing the user listings.
 *
 * **THE ZONE IS AN ARGUMENT, and the reason written here first was WRONG — the correction is worth
 * more than the original claim.** It said PHP does not consult `TZ`, so a floor computed from the
 * default zone would fire at 10:00 Paris in summer. The measurement behind that is real (`php -r`
 * in this very container reports `UTC` with `TZ=Europe/Paris` set), and the conclusion does not
 * follow: `bin/scout` calls `date_default_timezone_set()` from `TZ` at line 44, so INSIDE the
 * application the default zone is already correct. A true number attached to an invented cause,
 * which is precisely the failure this repo keeps naming — committed here while building the feature
 * whose docblock warns about it.
 *
 * What the explicit zone actually buys, both measured:
 *
 * 1. **An unusable `TZ` becomes a LOUD refusal.** `date_default_timezone_set('Europe/Pariss')`
 *    returns `false`, emits a *Notice*, and leaves the previous zone standing — so a typo in a
 *    compose file silently runs the whole deployment on UTC, and the floor fires two hours late all
 *    summer with nothing but a notice in a log nobody reads. {@see zoneFromEnv()} refuses instead.
 * 2. **The zone stops being global mutable state.** `date_default_timezone_set()` is process-wide
 *    and anything may change it; a scheduling decision that reads it is deciding from a variable it
 *    does not own.
 *
 * Pure, and it takes its clock as an argument, for the same reason {@see Heartbeat} does: the
 * behaviour spans days, and a test that waited one out would never be written — which is how a
 * feature like this ends up shipped and quietly broken.
 *
 * The bias is inherited from `Heartbeat` and stated here so it is not "simplified" away: **one
 * emission too many, never one suppressed.** An unreadable marker, an unreadable clock and a marker
 * dated in the future are all due. An extra low-priority rollup costs a glance; a suppressed one
 * costs the guarantee. The caller closes the loop by returning silently when the bin is empty, so a
 * spurious due-check is free.
 */
final readonly class DigestSchedule
{
    /** Matches `config/rent/criteria.json`'s shipped `digest_hour` and Q34's own wording. */
    public const int DEFAULT_HOUR = 8;

    /**
     * Applied when `TZ` is absent.
     *
     * `.env.example` and `compose.yaml` both document `Europe/Paris`, and Q34 names it explicitly.
     * Defaulting to UTC instead would move the floor two hours every summer on any deployment whose
     * operator had not thought about the question — silently, and in the direction that looks like
     * the feature working.
     */
    public const string DEFAULT_ZONE = 'Europe/Paris';

    /**
     * @param int $hour local hour of the daily floor, 0–23. Outside that range is a LOUD refusal
     *                  rather than a clamp: `digest_hour: 24` is somebody meaning midnight, and
     *                  clamping it to 23 would emit an hour early for ever while looking configured.
     */
    public function __construct(public int $hour = self::DEFAULT_HOUR)
    {
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException(
                'digest_hour doit être une heure de 0 à 23, reçu ' . $hour,
            );
        }
    }

    /**
     * Resolve the deployment's local zone, refusing what `bin/scout` would swallow.
     *
     * Absent takes the documented default — the same `Europe/Paris` that `bin/scout:45` and
     * `.env.example` use, so this agrees with the process-wide zone rather than competing with it.
     * Present-but-unusable is a LOUD refusal, and that is the half that is new: PHP's own
     * `date_default_timezone_set()` answers a bad zone name with `false` and a Notice, keeps the
     * zone it already had, and lets the process run on. Measured, not assumed.
     *
     * Same asymmetry {@see Heartbeat::fromEnv()} applies to `RENT_HEARTBEAT_HOURS`, for the same reason:
     * a missing value is the ordinary case, while a broken one is somebody who meant something, and
     * guessing which thing puts the floor hours out on a deployment whose operator believes they
     * configured it.
     *
     * @throws \InvalidArgumentException on a present-but-unusable zone name
     */
    public static function zoneFromEnv(?string $raw): \DateTimeZone
    {
        if ($raw === null || trim($raw) === '') {
            return new \DateTimeZone(self::DEFAULT_ZONE);
        }

        $raw = trim($raw);

        try {
            return new \DateTimeZone($raw);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                'TZ n\'est pas un fuseau horaire utilisable : `' . $raw . '` — '
                . 'le récapitulatif quotidien ne peut pas être planifié sans lui',
                0,
                $e,
            );
        }
    }

    /**
     * Is the daily floor due?
     *
     * Due when the most recent local `$hour:00` at or before `$nowIso` is later than the last
     * emission — which covers the case the floor exists for AND the case a restart creates: a
     * container down at 08:00 and back at 11:00 emits late rather than skipping the day.
     *
     * `$lastEmittedIso` is read from a marker file rather than the store, deliberately, and for the
     * reason `Heartbeat`'s docblock already gives: a lost marker costs exactly one extra
     * low-priority rollup, which is the benign direction, while touching `src/php/Rent/Store/**` would
     * put this on `tests/sabotage-check.sh`'s mandatory trigger list and owe a multi-hour ledger for
     * a scheduling marker.
     */
    public function isDue(?string $lastEmittedIso, string $nowIso, \DateTimeZone $zone): bool
    {
        if ($lastEmittedIso === null || trim($lastEmittedIso) === '') {
            // Cold start. Free when the bin is empty, and it drains a backlog that survived a
            // restart when it is not.
            return true;
        }

        $now = self::instant($nowIso);
        $last = self::instant($lastEmittedIso);

        if ($now === null || $last === null) {
            // Neither an unreadable clock nor a half-written marker may silence §1's landing zone.
            return true;
        }

        if (self::isAfter($last, $now)) {
            // The clock moved backwards — an NTP correction, a container started wrong. Waiting for
            // it to catch up could suppress the floor for hours; the next write re-anchors it.
            return true;
        }

        return self::isBefore($last, $this->windowOpenedAt($now, $zone));
    }

    /**
     * Ordering helpers, and the reason they exist rather than a bare `>` / `<`.
     *
     * A sabotage run showed the null guard above could be DELETED with the whole suite staying
     * green: PHP compares `null` against a `DateTimeImmutable` by coercion, so `null > $now` is
     * false and `null < $window` is true — which happens to produce "due", the right answer, for
     * entirely the wrong reason. Dead safety code is worse than none, because it reads as a decision
     * somebody made and verified.
     *
     * Typed parameters under `declare(strict_types=1)` turn that coercion into a `TypeError`, so
     * removing the guard now breaks loudly instead of coincidentally working. Exactly the remedy
     * {@see Heartbeat::elapsedSeconds()} documents for the identical defect in the identical place.
     */
    private static function isAfter(\DateTimeImmutable $a, \DateTimeImmutable $b): bool
    {
        return $a > $b;
    }

    private static function isBefore(\DateTimeImmutable $a, \DateTimeImmutable $b): bool
    {
        return $a < $b;
    }

    /**
     * The most recent local `$hour:00` at or before `$now`.
     *
     * The comparison that follows is between absolute instants, so this may be built in any zone; it
     * is built in `$zone` because that is the only zone in which "08:00" means what the operator
     * wrote in `criteria.json`.
     *
     * DST is handled by `setTime()`'s own normalisation rather than by special cases here, and both
     * directions are pinned by test. On the spring-forward day a `$hour` inside the missing hour
     * normalises FORWARD (02:00 becomes 03:00), so the window opens late rather than never — the
     * benign direction. On the autumn day the repeated hour resolves to its first occurrence, so an
     * emission during the first pass suppresses the second and the floor does not send twice.
     */
    private function windowOpenedAt(\DateTimeImmutable $now, \DateTimeZone $zone): \DateTimeImmutable
    {
        $local = $now->setTimezone($zone);
        $candidate = $local->setTime($this->hour, 0, 0);

        if ($candidate > $local) {
            // Today's window has not opened yet, so the one currently in force is yesterday's.
            // `setTime` is re-applied after the day shift because a DST transition between the two
            // days would otherwise leave the wall-clock hour off by one.
            $candidate = $candidate->modify('-1 day')->setTime($this->hour, 0, 0);
        }

        return $candidate;
    }

    /** An ISO-8601 instant, or `null` if it cannot be read as one. */
    private static function instant(string $iso): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }
    }
}
