<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * How a source is doing — the answer `scout doctor` prints, one row per source.
 *
 * The reason this is an enum with a `NEVER_RUN` member rather than a boolean is `CLAUDE.md` hard
 * rule 2: the classic silent failure here is a broken selector returning zero results forever while
 * the user concludes the market is quiet. "Not failing" and "healthy" are different states, and a
 * source that has never successfully run has produced no evidence of either.
 */
enum SourceStatus: string
{
    /** No run has ever been recorded. Says nothing about the source — that is the point. */
    case NEVER_RUN = 'never_run';

    /** Ran, succeeded, and returned a count consistent with its own recent history. */
    case OK = 'ok';

    /** Returned far fewer items than its rolling mean. Not proof of breakage, but worth reading. */
    case WARN_DROP = 'warn_drop';

    /** The last run failed outright, or the source has gone empty against a non-zero baseline. */
    case BROKEN = 'broken';

    /**
     * Whether this status should reach the user rather than sit in a table.
     *
     * `NEVER_RUN` alerts too: a source configured months ago that has never once run is a
     * configuration bug, and it is invisible precisely because it produces no failures.
     */
    public function isAlerting(): bool
    {
        return $this !== self::OK;
    }
}
