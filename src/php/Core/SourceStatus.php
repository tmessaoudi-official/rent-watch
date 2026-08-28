<?php

declare(strict_types=1);

namespace Scout\Core;

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
     * The source is working; its FEED has stopped delivering.
     *
     * Measured on the live watcher 2026-08-28: `leboncoin` reported `item_count = 3` on 263
     * consecutive passes off ONE email dated 26 August, which `SEARCH SINCE 7 days` kept matching.
     * Every other verdict was right and every one said healthy — the baseline is 3 and the last
     * count is 3, so nothing dropped; no run failed, so nothing is flaky; the schedule never
     * stopped, so it is not {@see STALE}. A source re-reading one frozen message is
     * indistinguishable from a source receiving a steady trickle, and that is hard rule 2's shape
     * arrived at from a direction nothing was watching.
     *
     * **`STALE` is the twin, from the other end.** That one says the WATCHER stopped; this one says
     * the PORTAL did. Both are silent absences and neither produces a failure to notice.
     *
     * Derived from the age of the newest MESSAGE the source saw, never from listing novelty:
     * "no new listing for N days" is also what a quiet rental market looks like — Logirep returns
     * the same 113 listings on every pass by design — so it would restate the ambiguity rather than
     * resolve it. Only derivable when the caller supplies both a clock and a threshold, and only
     * for a source that reports message dates at all.
     */
    case FEED_SILENT = 'feed_silent';

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
