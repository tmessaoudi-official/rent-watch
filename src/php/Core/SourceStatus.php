<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * How a source is doing — the answer `scout doctor` prints, one row per source.
 *
 * The reason this is an enum with several distinct "not healthy" members rather than a boolean is
 * `CLAUDE.md` hard rule 2: the classic silent failure here is a broken selector returning zero
 * results forever while the user concludes the market is quiet. "Not failing" and "healthy" are
 * different states, and each way of not-being-healthy needs a different response from the user —
 * a never-run source is a config bug, a never-productive one is a wrong field map, a flaky one is
 * a rate limit or a flaky host.
 */
enum SourceStatus: string
{
    /** No run has ever been recorded. Says nothing about the source — that is the point. */
    case NEVER_RUN = 'never_run';

    /**
     * Has run, has succeeded, and has never once returned a single item.
     *
     * The twin of `NEVER_RUN`, and it hid behind `OK` until a reviewer went looking. A source
     * onboarded with a wrong field map answers HTTP 200 and parses zero items, forever. It never
     * fails, so nothing alerts; its baseline is zero, so the empty-run rule correctly declines to
     * fire; and the result was a source that had never worked reporting `OK` for thirty days.
     */
    case NEVER_PRODUCED = 'never_produced';

    /**
     * Has run and produced, but not recently — the schedule itself has stopped.
     *
     * A reclaimed container, a removed cron entry, a disabled systemd timer. `NEVER_RUN` covered
     * "never" and nothing covered "stopped": one successful run three hundred days ago reported
     * `OK`, non-alerting, forever. Same silent-absence class as `NEVER_PRODUCED`, arrived at from
     * the other end. Only derivable when the caller supplies the current time.
     */
    case STALE = 'stale';

    /** Ran, succeeded, and returned a count consistent with its own recent history. */
    case OK = 'ok';

    /** Returned far fewer items than its rolling mean. Not proof of breakage, but worth reading. */
    case WARN_DROP = 'warn_drop';

    /**
     * Succeeding, but failing often enough that a large share of the market is being missed.
     *
     * `trailingEmptyRuns()` resets on any success and the BROKEN rule reads only the last run, so
     * a source erroring on half its fetches — half the listings missed, every day — was
     * indistinguishable from a healthy one.
     */
    case WARN_FLAKY = 'warn_flaky';

    /** The last run failed outright, or the source has gone empty against a non-zero baseline. */
    case BROKEN = 'broken';

    /**
     * Whether this status should reach the user rather than sit in a table.
     *
     * `CLAUDE.md` hard rule 2: "An alert that is computed and never sent is worse than none." This
     * is the predicate that decides, so everything except `OK` answers true — including
     * `NEVER_RUN` and `NEVER_PRODUCED`, whose whole problem is that they never produce a failure
     * to notice.
     */
    public function isAlerting(): bool
    {
        return $this !== self::OK;
    }
}
