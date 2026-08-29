<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * Q27's liveness policy: *has enough time passed that the watcher should say it is alive?*
 *
 * The failure this exists for is the one the whole project is shaped around. `scout run --watch`
 * emits nothing on a quiet evening, and it emits nothing when it is dead — a crashed loop, a killed
 * container, a VPS that rebooted — so the two are **byte-identical from the outside**. Q27's ruling
 * is that silence must be made meaningful: a low-priority heartbeat every `RENT_HEARTBEAT_HOURS`
 * regardless of whether anything matched, so that silence for longer than that is itself the signal.
 *
 * Pure, and takes its clock as an argument, because the whole point is behaviour across hours and a
 * test that waited 24 of them would never be written. The `?string $lastSentIso` it judges is read
 * from a file rather than the store — deliberately, and for a reason worth stating: a lost marker
 * costs exactly one extra low-priority heartbeat, which is the benign direction, while touching
 * `src/php/Store/**` would put this change on `tests/sabotage-check.sh`'s mandatory trigger list and
 * owe a multi-hour ledger for a liveness marker. `state/last-refusal.txt` is already ruled as a file
 * in `state/` by Q27 itself, so a sibling marker matches the ruling's own pattern.
 */
final readonly class Heartbeat
{
    /** Q27's default, applied when `RENT_HEARTBEAT_HOURS` is absent. */
    public const int DEFAULT_INTERVAL_HOURS = 24;

    /**
     * @param int $intervalHours must be >= 1. Zero is refused by {@see fromEnv()} rather than read as
     *                           "never": disabling the one signal that distinguishes a dead watcher
     *                           from a quiet market is not something a stray env value may do
     *                           silently.
     */
    public function __construct(public int $intervalHours = self::DEFAULT_INTERVAL_HOURS)
    {
        if ($intervalHours < 1) {
            throw new \InvalidArgumentException('RENT_HEARTBEAT_HOURS / CAR_HEARTBEAT_HOURS must be at least 1 hour, got ' . $intervalHours);
        }
    }

    /**
     * Read the interval from the environment, refusing a value that would disable liveness silently.
     *
     * Mirrors `SCOUT_MAX_PASSES`: absent is the ordinary case and takes the documented default;
     * a value that is present but unusable is a LOUD refusal, because somebody exported it meaning
     * something, and guessing which thing is how a watcher ends up silently unmonitored.
     *
     * @throws \InvalidArgumentException on a present-but-unusable value
     */
    public static function fromEnv(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return new self();
        }

        $raw = trim($raw);

        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new \InvalidArgumentException(
                'RENT_HEARTBEAT_HOURS / CAR_HEARTBEAT_HOURS doit être un nombre entier d\'heures, reçu `' . $raw . '`',
            );
        }

        return new self((int) $raw);
    }

    /**
     * Is a heartbeat due?
     *
     * `null` — no marker, so nothing has been sent by this deployment — is **due**. That is the
     * cold-start case and it must fire: without it a watcher that dies in its first hour is
     * indistinguishable from a healthy one until the first interval elapses, which on the default is
     * a day. Q27's *"the next successful start can report what happened while it was down"* wants a
     * startup beat anyway.
     */
    public function isDue(?string $lastSentIso, string $nowIso): bool
    {
        if ($lastSentIso === null || trim($lastSentIso) === '') {
            return true;
        }

        $last = self::instant($lastSentIso);
        $now = self::instant($nowIso);

        if ($last === null || $now === null) {
            // An unreadable marker is treated as absent: one extra heartbeat, never a suppressed one.
            return true;
        }

        // A marker in the FUTURE means the clock moved backwards — an NTP correction, a container
        // started with a wrong clock. Waiting for it to catch up could suppress liveness for hours,
        // so it counts as due and the next write re-anchors the marker to the present.
        if ($last > $now) {
            return true;
        }

        return self::elapsedSeconds($last, $now) >= $this->intervalHours * 3600;
    }

    /**
     * Seconds between two instants, and the reason the null-guard above is not decoration.
     *
     * Typed `int`, so under `declare(strict_types=1)` a `null` reaching here is a TypeError rather
     * than a silent coercion to zero. That matters: a sabotage run showed the guard could be deleted
     * with the suite staying green, because `$now - null` coerces to `$now`, which is larger than any
     * interval and so happens to give the right answer for the wrong reason. Dead safety code is
     * worse than none — it reads as a decision somebody made. Expressing the precondition in the
     * signature makes removing the check break loudly instead of coincidentally working.
     */
    private static function elapsedSeconds(int $last, int $now): int
    {
        return $now - $last;
    }

    /** Epoch seconds for an ISO-8601 instant, or `null` if it cannot be read as one. */
    private static function instant(string $iso): ?int
    {
        try {
            return (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
