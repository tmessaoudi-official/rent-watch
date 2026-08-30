<?php

declare(strict_types=1);

namespace Scout\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\SourceError;
use Scout\Cli\WatchLoop;
use Scout\Core\Pacer;

/**
 * The loop that turns `scout run --once` into a watcher.
 *
 * IT IS A SEPARATE CLASS SO THAT THIS FILE CAN EXIST. Written inline in `RentScout::runCommand()` the
 * same behaviour would need config files, a store path, a notifier and a database on disk before it
 * could be exercised at all — so in practice it would be tested by running it, which for a
 * fifteen-minute cadence means it would not be tested.
 *
 * The property that matters most is not the cadence. It is that **the loop outlives a failing
 * pass.** A watcher that exits on the first `SourceError` stops watching, and `CLAUDE.md` hard rule
 * 2 is explicit that a source failing silently is the defining failure of this project: the user
 * concludes the market is quiet. A dead watcher is that same failure with a wider blast radius —
 * every source at once — and it is *more* invisible, because there is no process left to report it.
 */
#[CoversClass(WatchLoop::class)]
final class WatchLoopTest extends TestCase
{
    public function testItRunsThePassRepeatedly(): void
    {
        $passes = 0;
        $loop = $this->loop(static function () use (&$passes): void {
            $passes++;
        });

        $loop->run(maxPasses: 3);

        self::assertSame(3, $passes);
    }

    public function testItPacesBetweenPassesButNotBeforeTheFirst(): void
    {
        $slept = [];
        $loop = $this->loop(static function (): void {}, $slept);

        $loop->run(maxPasses: 3);

        // Two gaps for three passes. A leading sleep would mean the user waits a quarter of an hour
        // to find out whether their configuration even works.
        self::assertCount(2, $slept);
    }

    // ── the property this class exists for ───────────────────────────────────────────────────────

    public function testAThrowingPassDoesNotKillTheLoop(): void
    {
        $passes = 0;
        $loop = $this->loop(static function () use (&$passes): void {
            $passes++;
            if ($passes === 1) {
                throw new SourceError('cdc', 'HTTP 503');
            }
        });

        $loop->run(maxPasses: 3);

        self::assertSame(3, $passes, 'pass 1 threw; passes 2 and 3 must still have run');
    }

    public function testAThrownFailureIsReportedRatherThanSwallowed(): void
    {
        // Hard rule 3's shape at the loop level: surviving the exception must not mean hiding it.
        // A loop that caught and continued in silence would be a watcher that has stopped fetching
        // from a source and says nothing — the exact failure the health subsystem exists to expose.
        $seen = [];
        $loop = $this->loop(
            static function (): void {
                throw new SourceError('cdc', 'HTTP 503');
            },
            onError: static function (\Throwable $e) use (&$seen): void {
                $seen[] = $e->getMessage();
            },
        );

        $loop->run(maxPasses: 2);

        self::assertCount(2, $seen);
        self::assertStringContainsString('503', $seen[0]);
    }

    public function testEveryPassSurvivesEvenWhenEveryPassThrows(): void
    {
        $passes = 0;
        $loop = $this->loop(static function () use (&$passes): void {
            $passes++;
            throw new \RuntimeException('always');
        });

        self::assertSame(0, $loop->run(maxPasses: 4), 'a transient failure is not an exit code');
        self::assertSame(4, $passes);
    }

    /**
     * An `Error` is not a transient source failure — it is a bug in this program (a type error, a
     * missing method). Swallowing it would leave a watcher looping forever over code that cannot
     * work, reporting nothing anyone would act on. It propagates, and the process dies loudly.
     */
    public function testAProgrammingErrorIsNotTreatedAsATransientFailure(): void
    {
        $loop = $this->loop(static function (): void {
            throw new \TypeError('this is a bug, not a flaky landlord');
        });

        $this->expectException(\TypeError::class);
        $loop->run(maxPasses: 2);
    }

    // ── stopping ─────────────────────────────────────────────────────────────────────────────────

    public function testStopDuringAPassLetsThatPassFinish(): void
    {
        $finished = 0;
        $loop = null;
        $loop = $this->loop(static function () use (&$finished, &$loop): void {
            $loop->stop();          // the signal lands mid-pass
            $finished++;            // and the pass still completes
        });

        self::assertSame(0, $loop->run(maxPasses: 10));
        self::assertSame(1, $finished, 'the pass in flight completes; no further pass begins');
    }

    /**
     * A signal arriving mid-pass must not abort between the notification send and the seen-set
     * write. That gap is not a crash — it is a watcher that re-notifies every listing it already
     * sent the next time it starts, which is the same user-visible damage as deleting the database.
     */
    public function testStopDoesNotSleepBeforeExiting(): void
    {
        $slept = [];
        $loop = null;
        $loop = $this->loop(static function () use (&$loop): void {
            $loop->stop();
        }, $slept);

        $loop->run(maxPasses: 10);

        self::assertSame([], $slept, 'exiting must be prompt, not after a fifteen-minute nap');
    }

    /**
     * The inter-pass wait is up to twenty minutes, so a `SIGTERM` during it has to take effect now
     * rather than when the nap ends. `docker stop` and systemd both send `SIGTERM`, wait, then send
     * `SIGKILL` — a loop that only checks its flag after sleeping would be killed uncleanly every
     * single time, in the middle of whatever the next pass had started.
     */
    public function testTheInterPassWaitIsInterruptible(): void
    {
        $ticks = 0;
        $loop = null;
        $stop = static function () use (&$loop): void {
            $loop->stop();
        };
        $sleeper = WatchLoop::interruptibleSleeper(
            // A regular closure with `use (&$loop)`, NOT an arrow function: an arrow fn binds by
            // VALUE at the point it is written, which here is before `$loop` exists.
            shouldStop: static function () use (&$loop): bool {
                return $loop->isStopping();
            },
            tick: static function (float $seconds) use (&$ticks, $stop): void {
                $ticks++;
                if ($ticks === 3) {
                    $stop();        // the signal lands three ticks into a 900 s wait
                }
            },
        );

        $passes = 0;
        $loop = new WatchLoop(
            pass: static function () use (&$passes): void {
                $passes++;
            },
            pacer: new Pacer(
                clock: static fn (): float => 0.0,
                sleeper: $sleeper,
                rand: static fn (int $min, int $max): int => $min,
            ),
        );

        $loop->run(maxPasses: 10);

        self::assertSame(3, $ticks, 'the wait aborted at the tick the signal arrived, not at 900 s');
        self::assertSame(1, $passes, 'and no further pass began');
    }

    public function testInterruptibleSleeperRunsToCompletionWhenNoSignalArrives(): void
    {
        $slept = 0.0;
        $sleeper = WatchLoop::interruptibleSleeper(
            shouldStop: static fn (): bool => false,
            tick: static function (float $seconds) use (&$slept): void {
                $slept += $seconds;
            },
        );

        $sleeper(3.5);

        self::assertEqualsWithDelta(3.5, $slept, 0.000_1, 'the full wait is served, in ticks');
    }

    public function testInterruptibleSleeperTicksInSmallSlicesRatherThanOneLongSleep(): void
    {
        $slices = [];
        $sleeper = WatchLoop::interruptibleSleeper(
            shouldStop: static fn (): bool => false,
            tick: static function (float $seconds) use (&$slices): void {
                $slices[] = $seconds;
            },
        );

        $sleeper(900.0);

        self::assertGreaterThan(1, count($slices));
        self::assertLessThanOrEqual(
            WatchLoop::TICK_SECONDS,
            max($slices),
            'a slice longer than the tick is a window in which a signal is ignored',
        );
    }

    public function testIsStoppingReportsTheFlag(): void
    {
        $loop = $this->loop(static function (): void {});

        self::assertFalse($loop->isStopping());
        $loop->stop();
        self::assertTrue($loop->isStopping());
    }

    /**
     * @param list<float> $slept
     * @param-out list<float> $slept
     */
    private function loop(\Closure $pass, &$slept = null, ?\Closure $onError = null): WatchLoop
    {
        $slept = [];
        $now = 0.0;

        return new WatchLoop(
            pass: $pass,
            pacer: new Pacer(
                clock: static function () use (&$now): float {
                    return $now;
                },
                sleeper: static function (float $seconds) use (&$slept, &$now): void {
                    $slept[] = $seconds;
                    $now += $seconds;
                },
                rand: static fn (int $min, int $max): int => $min,
            ),
            onError: $onError,
        );
    }
}
