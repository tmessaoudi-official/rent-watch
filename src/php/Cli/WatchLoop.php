<?php

declare(strict_types=1);

namespace Scout\Cli;

use Scout\Core\MutableByDesign;
use Scout\Core\Pacer;

/**
 * `scout run --watch`: run a pass, wait out the Q37 cadence, run another, until told to stop.
 *
 * A SEPARATE CLASS SO IT CAN BE TESTED. Inline in `Scout::runCommand()` the same code would need
 * config files, a store on disk, a notifier and a database before it could be exercised at all —
 * which for a fifteen-minute cadence means it would be "tested" by watching it run, i.e. never.
 * Everything here is injected: the pass is a closure, the clock and the sleeper live in {@see Pacer},
 * and the signal handler is installed by a method the tests do not call.
 *
 * THE PROPERTY THIS CLASS IS FOR IS NOT THE CADENCE — IT IS SURVIVING A BAD PASS. `CLAUDE.md` hard
 * rule 2 says a source that fails silently is the defining failure of this project, because the user
 * concludes the market is quiet rather than that the tool is broken. A watcher that exits on the
 * first `SourceError` is that same failure with every source inside it, and it is *more* invisible:
 * there is no process left to report anything. So a pass that throws is reported and the loop
 * continues.
 *
 * The one exception is `Error` (as opposed to `Exception`): a `TypeError` or a call to a missing
 * method is a bug in this program, not a flaky landlord. Looping forever over code that cannot work
 * would produce an endless identical complaint that nobody can act on, so those propagate and the
 * process dies loudly.
 *
 * {@see MutableByDesign} — the stop flag is the mechanism. A signal handler has nowhere to write
 * except shared state, and the loop is never handed to a caller as a result.
 */
final class WatchLoop implements MutableByDesign
{
    /**
     * How long a single slice of the inter-pass wait may be.
     *
     * The wait itself is up to twenty minutes, and `docker stop` and systemd both send `SIGTERM`,
     * wait a grace period, then `SIGKILL`. A loop that only noticed the signal after its nap would
     * be killed uncleanly every time — in the middle of whatever the next pass had just started.
     * One second is short enough to be indistinguishable from instant and long enough to cost
     * nothing.
     */
    public const float TICK_SECONDS = 1.0;

    private bool $stopping = false;

    /**
     * @param \Closure(): void            $pass    one complete run; may throw
     * @param \Closure(\Throwable): void  $onError reports a survivable pass failure to the operator
     */
    public function __construct(
        private readonly \Closure $pass,
        private readonly Pacer $pacer,
        private readonly ?\Closure $onError = null,
    ) {}

    /**
     * Asks the loop to finish the pass in flight and then exit.
     *
     * Deliberately NOT an immediate abort. A signal landing between a notification being sent and
     * the seen-set being written would make the next start re-notify every listing already sent —
     * the same user-visible damage as deleting the database, arriving as a burst of duplicate
     * pushes for flats the user has already seen.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Installs SIGTERM/SIGINT handlers, if this build can.
     *
     * `pcntl_async_signals(true)` is not optional decoration: without it a registered handler is
     * never dispatched outside `pcntl_signal_dispatch()`, so the process would look correctly wired
     * and ignore every signal. That is the failure this comment exists to prevent someone
     * "simplifying" back in.
     *
     * Absent `ext-pcntl` (it is not built into every SAPI) the loop still works — it simply ends on
     * whatever the shell does to it, which is the pre-existing behaviour of every other command
     * here. Returns whether handlers were installed so the caller can say so out loud rather than
     * letting the operator assume a clean shutdown they will not get.
     */
    public function installSignalHandlers(): bool
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return false;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stop();
            });
        }

        return true;
    }

    /**
     * Runs until stopped.
     *
     * `$maxPasses` exists for the tests and for a bounded run under a supervisor that prefers to
     * restart the process itself; `null` is the watcher proper. Returns a process exit code: `0` for
     * a graceful stop, INCLUDING a stop after passes that failed. A failing source is a condition
     * the health subsystem reports through notifications, not something to encode in the exit status
     * of a process designed to run for weeks — a supervisor that restarts on non-zero would flap.
     */
    public function run(?int $maxPasses = null): int
    {
        $completed = 0;

        while (!$this->stopping && ($maxPasses === null || $completed < $maxPasses)) {
            $startedAt = $this->pacer->now();

            try {
                ($this->pass)();
            } catch (\Exception $e) {
                // Survivable: a landlord returned 503, a mailbox timed out, a payload changed shape.
                // Reported, never hidden — see the class docblock.
                if ($this->onError !== null) {
                    ($this->onError)($e);
                }
            }

            $completed++;

            if ($this->stopping || ($maxPasses !== null && $completed >= $maxPasses)) {
                break;
            }

            $this->pacer->betweenPasses($startedAt);
        }

        return 0;
    }

    /**
     * A sleeper that wakes up often enough to notice a signal.
     *
     * Kept here rather than in `Pacer` because it is mechanism, not policy: `Pacer` decides HOW LONG
     * to wait, and this decides HOW to wait. That split is what lets `Pacer` stay a pure function of
     * its injected clock, and it is why an interrupted wait is safe — `Pacer::beforeFetch()` re-reads
     * the clock after sleeping rather than assuming the full duration elapsed.
     *
     * @param \Closure(): bool       $shouldStop polled between slices
     * @param \Closure(float): void  $tick       waits one slice; defaults to a real sleep
     *
     * @return \Closure(float): void
     */
    public static function interruptibleSleeper(\Closure $shouldStop, ?\Closure $tick = null): \Closure
    {
        $tick ??= static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000.0));
        };

        return static function (float $seconds) use ($shouldStop, $tick): void {
            $remaining = $seconds;
            while ($remaining > 0.0) {
                if ($shouldStop()) {
                    return;
                }
                $slice = min(self::TICK_SECONDS, $remaining);
                $tick($slice);
                $remaining -= $slice;
            }
        };
    }
}
