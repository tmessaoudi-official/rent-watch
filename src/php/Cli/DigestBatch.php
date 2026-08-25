<?php

declare(strict_types=1);

namespace RentWatch\Cli;

use RentWatch\Core\RawListing;
use RentWatch\Core\Verdict;

/**
 * One drain of the *à vérifier* bin, collected but not yet sent.
 *
 * This type exists so that `scout digest` and Q34's daily floor share ONE implementation of the
 * drain. The bin is §1's only landing zone: everything the classifier could not resolve confidently
 * lands here, and a second copy of the collection logic is exactly how one of them drifts into
 * announcing a listing the other would have withheld. The seam is drawn so that the shared half
 * decides WHAT is in the batch and the callers decide what to print, whether to send, and what a
 * failure means for their exit code.
 *
 * Collection **never throws** — a per-row snapshot that will not decode becomes a warning and a
 * degraded listing, never an exception. That is load-bearing rather than defensive: the floor runs
 * inside the watch loop's `finally`, where a throw would be caught by `WatchLoop` and counted as a
 * failed pass, so one damaged row would report every source as broken.
 */
final readonly class DigestBatch
{
    /**
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $entries
     *        the capped batch, ready for `Formatter::digest()`
     * @param int          $waiting         total pending rows, which may exceed the batch
     * @param int          $withoutSnapshot rows carrying no snapshot at all — a live source fault
     *                                      (an unencodable payload), never an old row: the query
     *                                      filters on `outcome`, itself a schema-v7 column that is
     *                                      not backfilled, so a genuinely pre-v7 row has
     *                                      `outcome = NULL` and is never returned here
     * @param list<string> $warnings        already redacted, one per row whose snapshot would not
     *                                      decode
     */
    public function __construct(
        public array $entries = [],
        public int $waiting = 0,
        public int $withoutSnapshot = 0,
        public array $warnings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * Pending rows this batch did not take.
     *
     * Said out loud by every caller, because a capped batch that stayed silent about the remainder
     * would look like the whole backlog — and the operator would stop draining it.
     */
    public function overflow(): int
    {
        return max(0, $this->waiting - $this->count());
    }

    public function unreadable(): int
    {
        return \count($this->warnings);
    }
}
