<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\Pacer;

/**
 * The Q37 ruling (2026-08-07) in executable form: poll every 15 min ± 5 min of jitter, at least 5 s
 * between requests to distinct hosts, at least 60 s between two requests to the same host, source
 * order shuffled each pass, and a pass finishing in under 60 s does not immediately start another.
 *
 * WHY THIS IS A UNIT TEST AND NOT A COMMENT. Pacing is the whole of `CLAUDE.md` hard rule 5 — low
 * rates, jitter, no fingerprint games — and it is enforced by numbers that nothing else checks. A
 * pacer that reads the real clock and calls `sleep()` cannot be tested at all: asserting a 15-minute
 * interval would take 15 minutes, so in practice nobody asserts it and the ruling ships unverified.
 * Every source of time here is injected, so the whole ruling is checked in microseconds.
 *
 * The failure mode this guards is not a crash. It is the developer's own IP being banned from a
 * landlord's site, which presents to the user as every source going quiet at once — indistinguishable
 * from a slow rental market, which is the failure shape hard rule 2 exists to make impossible.
 */
#[CoversClass(Pacer::class)]
final class PacerTest extends TestCase
{
    /**
     * A pacer over a fake clock. `$slept` accumulates every sleep the pacer asks for, and the clock
     * advances by exactly that much — so the pacer's own arithmetic is what moves time, and a pacer
     * that miscounts cannot hide behind a real clock that moved anyway.
     *
     * `$slept` and `$now` are untyped out-parameters on purpose: a test calls this with an
     * undeclared variable, which PHP passes as null, and a declared type would reject it before the
     * body ever assigns.
     *
     * @param list<int> $randReturns values `rand` yields in order; the last one repeats
     * @param-out list<float> $slept
     * @param-out float $now
     */
    /**
     * The window is measured from when the request WAS issued, not from when the pacer meant to
     * issue it. Those two coincide whenever a sleeper delivers exactly what it was asked for, which
     * is why every other test here is blind to the difference — and why a sabotage replacing the
     * re-read clock with the intended wake time went undetected until this test existed.
     *
     * They come apart for a real reason, not a contrived one: `WatchLoop::interruptibleSleeper`
     * returns EARLY when a signal has asked the loop to stop. Trusting the intended wake time then
     * records a request as having happened in the future, and the next request to that host is
     * released early — the pacer under-waits at exactly the moment it believes it over-waited.
     */
    public function testTheHostWindowIsMeasuredFromTheClockNotFromTheIntendedWakeTime(): void
    {
        $now = 1_000.0;
        $slept = [];
        // A sleeper that delivers only half of what it is asked for, as an interrupted one does.
        $p = new Pacer(
            clock: static function () use (&$now): float {
                return $now;
            },
            sleeper: static function (float $seconds) use (&$slept, &$now): void {
                $slept[] = $seconds;
                $now += $seconds / 2.0;
            },
            rand: static fn (int $min, int $max): int => $min,
        );

        $p->beforeFetch('a.test');   // issued at 1000; no wait
        $p->beforeFetch('a.test');   // wants +60, is asked to sleep 60, only reaches 1030
        $p->beforeFetch('a.test');   // must therefore still owe 60 s from 1030, not from 1060

        self::assertSame([60.0, 60.0], $slept, 'the second wait is computed from the real clock');
        self::assertSame(1_060.0, $now);
    }

    private function pacer(&$slept, &$now, array $randReturns = [900]): Pacer
    {
        $slept = [];
        $now = 1_000.0;
        $i = 0;

        return new Pacer(
            clock: static function () use (&$now): float {
                return $now;
            },
            sleeper: static function (float $seconds) use (&$slept, &$now): void {
                $slept[] = $seconds;
                $now += $seconds;
            },
            rand: static function (int $min, int $max) use ($randReturns, &$i): int {
                $v = $randReturns[min($i, count($randReturns) - 1)];
                $i++;

                return max($min, min($max, $v));
            },
        );
    }

    // ── intra-pass: the host rules ────────────────────────────────────────────────────────────────

    public function testTheFirstRequestOfAPassNeverWaits(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('example.test');

        self::assertSame([], $slept, 'a pass must start immediately; the inter-pass wait already happened');
    }

    public function testTwoDistinctHostsAreSeparatedByFiveSeconds(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');
        $p->beforeFetch('b.test');

        self::assertSame([5.0], $slept);
    }

    public function testTheSameHostTwiceIsSeparatedBySixtySeconds(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');
        $p->beforeFetch('a.test');

        self::assertSame([60.0], $slept, 'Q37: at least 60 s between two requests to the SAME host');
    }

    /**
     * The two rules are a maximum, not a sequence. Interleaving hosts must not let a site be hit
     * twice inside its own 60 s window just because a different host was polled in between — the
     * naive implementation (one "last request" timestamp) allows exactly that.
     */
    public function testAnInterleavedHostDoesNotResetTheSameHostWindow(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');   // t=1000
        $p->beforeFetch('b.test');   // t=1005, +5 distinct-host
        $p->beforeFetch('a.test');   // must wait until t=1060, i.e. 55 more

        self::assertSame([5.0, 55.0], $slept);
        self::assertSame(1_060.0, $now, 'a.test is polled exactly 60 s after its previous poll');
    }

    public function testAThirdDistinctHostStillOnlyWaitsFiveSeconds(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');
        $p->beforeFetch('b.test');
        $p->beforeFetch('c.test');

        self::assertSame([5.0, 5.0], $slept);
    }

    /**
     * `null` means the source issues no outbound web request — a fixture read from disk, or an IMAP
     * mailbox, which is one connection to one's own mail provider and not a site that can ban anyone.
     * Pacing it would add 5 s per source to every pass for no protective benefit, and — worse — it
     * would consume the distinct-host slot, delaying the real requests behind it.
     */
    public function testAHostlessSourceNeitherWaitsNorCounts(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');
        $p->beforeFetch(null);
        $p->beforeFetch(null);
        $p->beforeFetch('b.test');

        self::assertSame([5.0], $slept, 'only the two real requests are spaced');
    }

    /**
     * The half of "neither waits nor counts" that the test above cannot see. Both tests issue every
     * request at the same instant, so a hostless source that DID claim the distinct-host slot would
     * only ever overwrite it with the value already there — a sabotage doing exactly that stayed
     * undetected until this test existed.
     *
     * Time has to pass for the bug to surface, and in a real pass it does: reading a mailbox takes
     * seconds. A pacer that let the mailbox restart the 5 s window would delay every web request
     * queued behind it, growing the pass by 5 s per hostless source for no protective benefit.
     */
    public function testAHostlessSourceDoesNotRestartTheWindowAsTimePasses(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');
        $now += 30.0;              // the mailbox is read — wall-clock time, not a pacing sleep
        $p->beforeFetch(null);
        $p->beforeFetch('b.test'); // 30 s have already elapsed, so nothing is owed

        self::assertSame([], $slept, 'the mailbox must not restart the distinct-host window');
    }

    public function testTheHostWindowIsCaseInsensitive(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('Example.TEST');
        $p->beforeFetch('example.test');

        self::assertSame([60.0], $slept, 'DNS is case-insensitive; a capitalised host is the same site');
    }

    /**
     * Time that has genuinely passed counts against the window. A source whose fetch takes 70 s has
     * already satisfied its own 60 s gap, and re-sleeping would double the pass length for nothing.
     */
    public function testElapsedRealTimeCountsTowardTheWindow(): void
    {
        $p = $this->pacer($slept, $now);

        $p->beforeFetch('a.test');
        $now += 70.0;               // the fetch itself took 70 s
        $p->beforeFetch('a.test');

        self::assertSame([], $slept);
    }

    // ── shuffle ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Q37 requires the order to be shuffled so no site is always polled first — being reliably first
     * every 15 minutes is itself a fingerprint. PHP's `shuffle()` cannot be seeded, so this is
     * Fisher-Yates over the injected `rand`, which is what makes the property assertable at all.
     */
    public function testShuffleReordersUsingTheInjectedRandomSource(): void
    {
        $p = $this->pacer($slept, $now, [0]);   // always pick index 0 → a deterministic rotation

        $out = $p->shuffle(['a', 'b', 'c', 'd']);

        self::assertNotSame(['a', 'b', 'c', 'd'], $out, 'a shuffle that returns the input order is not a shuffle');
        self::assertSame(['a', 'b', 'c', 'd'], self::sorted($out), 'shuffling must not add, drop or duplicate a source');
    }

    public function testShufflePreservesEveryElement(): void
    {
        $p = $this->pacer($slept, $now, [2, 0, 1]);

        $out = $p->shuffle(['a', 'b', 'c', 'd', 'e']);

        self::assertSame(['a', 'b', 'c', 'd', 'e'], self::sorted($out));
        self::assertCount(5, $out);
    }

    public function testShuffleReturnsAListWithoutHoles(): void
    {
        $p = $this->pacer($slept, $now, [1]);

        $out = $p->shuffle(['a', 'b', 'c']);

        self::assertSame([0, 1, 2], array_keys($out), 'a caller doing foreach must not see preserved keys');
    }

    public function testShuffleOfFewerThanTwoIsANoOp(): void
    {
        $p = $this->pacer($slept, $now);

        self::assertSame([], $p->shuffle([]));
        self::assertSame(['only'], $p->shuffle(['only']));
    }

    // ── inter-pass: the interval ──────────────────────────────────────────────────────────────────

    public function testTheIntervalIsMeasuredFromTheStartOfThePass(): void
    {
        $p = $this->pacer($slept, $now, [900]);
        $started = $now;
        $now += 120.0;                          // the pass itself took two minutes

        $p->betweenPasses($started);

        self::assertSame([780.0], $slept, '900 s cadence minus the 120 s the pass consumed');
        self::assertSame($started + 900.0, $now);
    }

    /**
     * The jitter bounds ARE the ruling: 15 min ± 5 min is [600, 1200]. A pacer that asked for a
     * narrower band would be over-regular, and a wider one would drift from the cadence the
     * developer chose. Asserted through the `rand` call itself, not by sampling outputs.
     */
    public function testTheJitterBandIsExactlyFifteenMinutesPlusOrMinusFive(): void
    {
        $seen = [];
        $p = new Pacer(
            clock: static fn (): float => 0.0,
            sleeper: static function (float $s): void {},
            rand: static function (int $min, int $max) use (&$seen): int {
                $seen[] = [$min, $max];

                return $min;
            },
        );

        $p->betweenPasses(0.0);

        self::assertSame([[600, 1200]], $seen);
    }

    /**
     * The explicit floor from the ruling's last sentence. It is redundant against the 600 s minimum
     * today — and it is written down anyway, with a test, so that a later change to the cadence
     * cannot silently reintroduce a tight loop. A rule that is only true by accident is not enforced.
     */
    public function testAPassIsNeverFollowedImmediatelyByAnother(): void
    {
        // The floor must be ISOLATED to be tested at all. Routed through the helper it is never the
        // binding constraint — the helper clamps `rand` back into [600, 1200], so the band alone
        // produces a long sleep and the assertion passes without the guard existing. That is a test
        // that reports a covered cell while covering nothing. Here `rand` deliberately ignores its
        // bounds, making the 60 s floor the only thing that can produce the wait.
        $slept = [];
        $now = 1_000.0;
        $started = $now;
        $now += 2.0;                            // a pass that finished in two seconds

        $p = new Pacer(
            clock: static function () use (&$now): float {
                return $now;
            },
            sleeper: static function (float $s) use (&$slept): void {
                $slept[] = $s;
            },
            rand: static fn (int $min, int $max): int => 1,
        );

        $p->betweenPasses($started);

        self::assertSame([60.0], $slept, 'Q37: a fast pass does not immediately start another');
    }

    public function testAnOverrunningPassStartsTheNextOneWithoutWaiting(): void
    {
        $p = $this->pacer($slept, $now, [600]);
        $started = $now;
        $now += 5_000.0;                        // the pass took far longer than the whole interval

        $p->betweenPasses($started);

        self::assertSame([60.0], $slept, 'no negative sleep, and still never an immediate restart');
    }

    /** @param list<string> $in @return list<string> */
    private static function sorted(array $in): array
    {
        sort($in);

        return $in;
    }
}
