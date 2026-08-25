<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\DigestSchedule;

/**
 * Q34's daily floor: *has the local `digest_hour` come round again since the last emission?*
 *
 * The digest is §1's only landing zone, and until this existed `digest_hour` was parsed, printed by
 * `doctor`, and read by nothing — so on a day that produced no new entries, a backlog that had
 * failed to send simply sat there. The floor is what makes a silent day mean "nothing pending"
 * rather than "nobody looked".
 *
 * **THE ZONE IS AN ARGUMENT**, and the first version of this docblock justified that wrongly — it
 * said PHP ignores `TZ` so the default zone would put the floor two hours out. The measurement was
 * real and the conclusion was not: `bin/scout:44` already calls `date_default_timezone_set()` from
 * `TZ`, so the application's default zone is correct. What the explicit zone buys is a refusal on
 * an unusable `TZ` — which `date_default_timezone_set()` answers with `false` and a Notice while
 * leaving UTC standing — and independence from process-wide mutable state.
 *
 * Either way the tests below must DISCRIMINATE: one that could be satisfied by whatever zone
 * happens to be default asserts nothing about zone handling, so the case below pins BOTH directions
 * on one pair of instants — due in Paris, not due in UTC.
 *
 * The bias is inherited from {@see \RentWatch\Core\Heartbeat}: one emission too many, never one
 * suppressed. An extra low-priority rollup costs a glance; a suppressed one costs the guarantee.
 */
#[CoversClass(DigestSchedule::class)]
final class DigestScheduleTest extends TestCase
{
    // ── the hour ─────────────────────────────────────────────────────────────────────────────────

    #[DataProvider('impossibleHours')]
    public function testAnHourOutsideTheClockIsRefused(int $hour): void
    {
        // Loud, like HEARTBEAT_HOURS. `digest_hour: 24` is somebody meaning midnight and getting
        // silence, and a floor that never fires is indistinguishable from one that has nothing to
        // say — which is the ambiguity this whole class exists to remove.
        $this->expectException(\InvalidArgumentException::class);
        new DigestSchedule($hour);
    }

    /** @return iterable<string, array{int}> */
    public static function impossibleHours(): iterable
    {
        yield 'negative' => [-1];
        yield 'twenty-four' => [24];
        yield 'far past the clock' => [99];
    }

    public function testEveryHourOnTheClockIsAccepted(): void
    {
        for ($hour = 0; $hour <= 23; $hour++) {
            self::assertSame($hour, (new DigestSchedule($hour))->hour);
        }
    }

    // ── the zone, which PHP will not resolve for us ───────────────────────────────────────────────

    public function testTheZoneIsResolvedFromTheValueGivenNotFromProcessState(): void
    {
        // Deliberately independent of `date_default_timezone_get()`. Under PHPUnit that is UTC (the
        // bootstrap does not run `bin/scout`); under the real CLI it is Europe/Paris. A resolution
        // that read process state would give different answers in the two, and the scheduling of
        // §1's landing zone must not depend on which entrypoint happens to be running.
        self::assertSame('Europe/Paris', DigestSchedule::zoneFromEnv('Europe/Paris')->getName());
        self::assertSame('UTC', DigestSchedule::zoneFromEnv('UTC')->getName());
        self::assertSame('Australia/Sydney', DigestSchedule::zoneFromEnv('Australia/Sydney')->getName());
    }

    public function testAnAbsentZoneTakesTheDocumentedDefault(): void
    {
        // `.env.example` and `compose.yaml` both document Europe/Paris. Defaulting to UTC here would
        // silently move the floor two hours in summer on any deployment that did not set TZ.
        self::assertSame('Europe/Paris', DigestSchedule::zoneFromEnv(null)->getName());
        self::assertSame('Europe/Paris', DigestSchedule::zoneFromEnv('')->getName());
        self::assertSame('Europe/Paris', DigestSchedule::zoneFromEnv('   ')->getName());
    }

    public function testAnUnusableZoneIsALoudRefusal(): void
    {
        // Same rule as HEARTBEAT_HOURS: absent is ordinary and takes the default, present-but-broken
        // is somebody meaning something. Falling back silently would put the floor two hours out on
        // a deployment whose operator believed they had configured it.
        $this->expectException(\InvalidArgumentException::class);
        DigestSchedule::zoneFromEnv('Europe/Pariss');
    }

    // ── due-ness ─────────────────────────────────────────────────────────────────────────────────

    public function testNothingEmittedYetIsDue(): void
    {
        // Cold start. With an empty bin this costs nothing at all — the caller returns without
        // sending — and with a non-empty one it drains a backlog that survived a restart.
        $paris = new \DateTimeZone('Europe/Paris');
        self::assertTrue((new DigestSchedule(8))->isDue(null, '2026-08-26T09:00:00+02:00', $paris));
        self::assertTrue((new DigestSchedule(8))->isDue('', '2026-08-26T09:00:00+02:00', $paris));
    }

    public function testAnEmissionAfterTodaysHourSuppressesUntilTomorrow(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $schedule = new DigestSchedule(8);

        // Emitted at 08:05 today; at 23:00 today the window has not come round again.
        self::assertFalse($schedule->isDue('2026-08-26T08:05:00+02:00', '2026-08-26T23:00:00+02:00', $paris));

        // …and at 07:59 tomorrow it still has not.
        self::assertFalse($schedule->isDue('2026-08-26T08:05:00+02:00', '2026-08-27T07:59:00+02:00', $paris));

        // At 08:00 tomorrow it has.
        self::assertTrue($schedule->isDue('2026-08-26T08:05:00+02:00', '2026-08-27T08:00:00+02:00', $paris));
    }

    public function testAWindowMissedWhileTheContainerWasDownStillFires(): void
    {
        // The container was down at 08:00 and came back at 11:00. Emitting late is the documented
        // bias; skipping the day would make a restart silently cost a rollup.
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertTrue(
            (new DigestSchedule(8))->isDue('2026-08-25T08:05:00+02:00', '2026-08-26T11:00:00+02:00', $paris),
        );
    }

    public function testTheZoneDecidesTheAnswerOnOnePairOfInstants(): void
    {
        // THE DISCRIMINATING CASE. Marker 07:00 Paris (05:00 UTC), now 08:30 Paris (06:30 UTC).
        //
        //   in Paris — the window opened at 08:00 local, the marker predates it            => DUE
        //   in UTC   — 08:00 UTC has not arrived yet, so the window is YESTERDAY's 08:00,
        //              which the marker is comfortably after                               => NOT DUE
        //
        // A test whose two zones agree asserts nothing about zone handling; this one fails the
        // moment the resolution goes back to PHP's default.
        $schedule = new DigestSchedule(8);

        self::assertTrue(
            $schedule->isDue(
                '2026-08-26T07:00:00+02:00',
                '2026-08-26T08:30:00+02:00',
                new \DateTimeZone('Europe/Paris'),
            ),
            'in Europe/Paris the 08:00 window has opened and the marker predates it',
        );

        self::assertFalse(
            $schedule->isDue(
                '2026-08-26T07:00:00+02:00',
                '2026-08-26T08:30:00+02:00',
                new \DateTimeZone('UTC'),
            ),
            'in UTC the same instants sit before 08:00, so the window is yesterday and already served',
        );
    }

    public function testAMarkerInTheFutureIsDue(): void
    {
        // The clock moved backwards — an NTP correction, a container started with a wrong clock.
        // Waiting for it to catch up could suppress the floor for hours; the next write re-anchors.
        self::assertTrue(
            (new DigestSchedule(8))->isDue(
                '2026-09-01T08:00:00+02:00',
                '2026-08-26T09:00:00+02:00',
                new \DateTimeZone('Europe/Paris'),
            ),
        );
    }

    public function testAnUnreadableMarkerIsDue(): void
    {
        // Treated as absent: one extra rollup, never a suppressed one. A half-written marker file
        // must not be able to silence §1's landing zone.
        self::assertTrue(
            (new DigestSchedule(8))->isDue('not an instant', '2026-08-26T09:00:00+02:00', new \DateTimeZone('Europe/Paris')),
        );
    }

    public function testAnUnreadableClockIsDue(): void
    {
        // Same bias applied to the other argument. There is no correct answer here, so take the one
        // that cannot suppress.
        self::assertTrue(
            (new DigestSchedule(8))->isDue('2026-08-26T08:05:00+02:00', 'not an instant', new \DateTimeZone('Europe/Paris')),
        );
    }

    public function testMidnightIsAnHourLikeAnyOther(): void
    {
        // `digest_hour: 0` is a legal configuration and the window computation must not treat the
        // day boundary specially — an off-by-one here would make midnight either never fire or fire
        // every pass.
        $paris = new \DateTimeZone('Europe/Paris');
        $schedule = new DigestSchedule(0);

        self::assertTrue($schedule->isDue('2026-08-25T23:00:00+02:00', '2026-08-26T00:30:00+02:00', $paris));
        self::assertFalse($schedule->isDue('2026-08-26T00:10:00+02:00', '2026-08-26T23:59:00+02:00', $paris));
    }

    // ── DST ──────────────────────────────────────────────────────────────────────────────────────

    public function testAnHourInsideTheSpringForwardGapStillResolves(): void
    {
        // Paris springs forward 2026-03-29 at 02:00 -> 03:00, so 02:00 local does not exist that
        // day. `digest_hour: 2` is legal, so the window computation must still answer rather than
        // throwing or landing on the previous day. PHP normalises the missing hour forward to 03:00,
        // which is the benign direction: the window opens late, not never.
        $paris = new \DateTimeZone('Europe/Paris');
        $schedule = new DigestSchedule(2);

        // 04:00 local on the transition day is after the normalised 03:00 window, and the marker is
        // from the day before, so the floor is due.
        self::assertTrue($schedule->isDue('2026-03-28T02:05:00+01:00', '2026-03-29T04:00:00+02:00', $paris));

        // An emission just after that window suppresses for the rest of the day.
        self::assertFalse($schedule->isDue('2026-03-29T03:05:00+02:00', '2026-03-29T23:00:00+02:00', $paris));
    }

    public function testTheAutumnRepeatedHourReopensTheWindowAndThatIsBenign(): void
    {
        // Paris falls back 2026-10-25 at 03:00 -> 02:00, so 02:30 local happens twice. This test
        // asserted the OPPOSITE of what follows until it was measured, which is worth recording:
        // `setTime()` keeps the offset of the instant it is called on, so during the repeated hour
        // the window re-opens one hour later in absolute time —
        //
        //   now 02:30 +02:00  ->  window 02:00 +02:00  (00:00 UTC)
        //   now 02:30 +01:00  ->  window 02:00 +01:00  (01:00 UTC)
        //
        // The expectation was written from reasoning and the behaviour from measurement, so the
        // expectation is what changes. Firing twice is BENIGN in every reachable case, and it is the
        // direction this class's stated bias asks for: a successful first emission empties the bin,
        // so the second check sends nothing; a FAILED first emission leaves the bin full, and Q34
        // rules that an unsent digest is retried; and entries arriving in that hour are already
        // handled by the automatic end-of-pass path. Suppressing it would need machinery whose only
        // job is preventing a once-a-year no-op, in the one direction the bias forbids.
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertTrue(
            (new DigestSchedule(2))->isDue('2026-10-25T02:30:00+02:00', '2026-10-25T02:30:00+01:00', $paris),
        );

        // And the SHIPPED hour is nowhere near the transition, so none of this reaches the default
        // deployment: 08:00 happens exactly once on that day like any other.
        self::assertFalse(
            (new DigestSchedule(8))->isDue('2026-10-25T08:05:00+01:00', '2026-10-25T23:00:00+01:00', $paris),
        );
    }
}
