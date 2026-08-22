<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Heartbeat;

/**
 * Q27's liveness policy, which exists because silence has two meanings.
 *
 * `scout run --watch` prints nothing on a quiet evening and prints nothing when it is dead — a
 * crashed loop, a stopped container, a VPS that rebooted. The two are byte-identical from outside,
 * so Q27 rules that the watcher must say it is alive on a schedule, **whether or not anything
 * matched**, making silence for longer than the interval a signal in itself.
 *
 * Everything here takes its clock as an argument. The behaviour under test spans hours, and a test
 * that waited 24 of them would never be written — which is exactly how a feature like this ends up
 * shipped untested and then quietly broken.
 *
 * The bias throughout is **toward sending one heartbeat too many, never one too few**: an extra
 * low-priority line costs a glance, while a suppressed one costs the entire guarantee.
 */
#[CoversClass(Heartbeat::class)]
final class HeartbeatTest extends TestCase
{
    // ── the interval ─────────────────────────────────────────────────────────────────────────────

    public function testNothingSentYetIsDueImmediately(): void
    {
        // The cold start, and it must fire. Without it a watcher that dies in its first hour is
        // indistinguishable from a healthy one until the first interval elapses — a full day on the
        // default. Q27 also wants a beat at startup so the previous refusal can be reported.
        self::assertTrue((new Heartbeat(24))->isDue(null, '2026-08-22T09:00:00+02:00'));
        self::assertTrue((new Heartbeat(24))->isDue('', '2026-08-22T09:00:00+02:00'));
    }

    public function testAHeartbeatIsNotDueBeforeTheIntervalHasElapsed(): void
    {
        $h = new Heartbeat(24);

        self::assertFalse($h->isDue('2026-08-22T09:00:00+02:00', '2026-08-22T10:00:00+02:00'));
        self::assertFalse($h->isDue('2026-08-22T09:00:00+02:00', '2026-08-23T08:59:59+02:00'));
    }

    public function testItIsDueExactlyAtTheIntervalAndAfterIt(): void
    {
        $h = new Heartbeat(24);

        self::assertTrue($h->isDue('2026-08-22T09:00:00+02:00', '2026-08-23T09:00:00+02:00'), 'exactly 24h is due');
        self::assertTrue($h->isDue('2026-08-22T09:00:00+02:00', '2026-08-25T09:00:00+02:00'));
    }

    public function testTheIntervalIsHonouredAtOtherValues(): void
    {
        $h = new Heartbeat(1);

        self::assertFalse($h->isDue('2026-08-22T09:00:00+02:00', '2026-08-22T09:59:00+02:00'));
        self::assertTrue($h->isDue('2026-08-22T09:00:00+02:00', '2026-08-22T10:00:00+02:00'));
    }

    public function testAnOffsetIsRespectedRatherThanComparedAsText(): void
    {
        // Same instant, two spellings. Comparing the strings would make this "23 hours ago" and
        // suppress a due heartbeat — the failure direction this class must never take.
        $h = new Heartbeat(24);

        self::assertTrue(
            $h->isDue('2026-08-22T09:00:00+02:00', '2026-08-23T07:00:00Z'),
            '07:00Z is 09:00+02:00 — exactly 24h later, so it is due',
        );
    }

    // ── the ways a marker goes wrong ─────────────────────────────────────────────────────────────

    public function testAnUnreadableMarkerIsTreatedAsAbsentRatherThanAsRecent(): void
    {
        // A truncated or hand-edited marker must not suppress liveness. One extra heartbeat is the
        // cost; a silently disabled one is the failure.
        $h = new Heartbeat(24);

        self::assertTrue($h->isDue('not a date', '2026-08-22T09:00:00+02:00'));
        self::assertTrue($h->isDue('2026-13-45T99:99:99', '2026-08-22T09:00:00+02:00'));
    }

    public function testAMarkerInTheFutureIsDueRatherThanWaitedOut(): void
    {
        // The clock moved backwards: an NTP correction, or a container started with a wrong clock.
        // Waiting for wall time to catch up could suppress the signal for hours, and on a marker
        // written far in the future, forever.
        $h = new Heartbeat(24);

        self::assertTrue($h->isDue('2027-01-01T00:00:00+02:00', '2026-08-22T09:00:00+02:00'));
    }

    // ── configuration ────────────────────────────────────────────────────────────────────────────

    public function testAnAbsentEnvValueTakesTheRuledDefaultOfTwentyFourHours(): void
    {
        self::assertSame(24, Heartbeat::fromEnv(null)->intervalHours);
        self::assertSame(24, Heartbeat::fromEnv('')->intervalHours);
        self::assertSame(24, Heartbeat::DEFAULT_INTERVAL_HOURS, 'the ruled default, per Q27');
    }

    public function testAUsableEnvValueIsRead(): void
    {
        self::assertSame(6, Heartbeat::fromEnv('6')->intervalHours);
        self::assertSame(1, Heartbeat::fromEnv(' 1 ')->intervalHours);
    }

    /**
     * A value somebody exported meaning something, which cannot be honoured.
     *
     * It REFUSES rather than falling back to the default. Silently defaulting would leave an
     * operator who typed `HEARTBEAT_HOURS=0` believing liveness is off, and an operator who typed
     * `HEARTBEAT_HOURS=6h` believing it is six hours — while the process does neither. This is the
     * same posture `RENT_WATCH_MAX_PASSES` takes and the same one the config loader takes on an
     * unknown key.
     */
    #[DataProvider('unusableValues')]
    public function testAPresentButUnusableEnvValueIsALoudRefusal(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Heartbeat::fromEnv($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableValues(): iterable
    {
        yield 'zero would silently disable the only liveness signal' => ['0'];
        yield 'negative' => ['-1'];
        yield 'a unit suffix nobody parses' => ['6h'];
        yield 'a float' => ['1.5'];
        yield 'words' => ['daily'];
    }

    public function testTheConstructorItselfRefusesAnIntervalBelowOneHour(): void
    {
        // Belt and braces with fromEnv(), and it covers the path a future caller takes when it
        // builds one in code rather than from the environment.
        $this->expectException(\InvalidArgumentException::class);

        new Heartbeat(0);
    }
}
