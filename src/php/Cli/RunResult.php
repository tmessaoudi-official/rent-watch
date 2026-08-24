<?php

declare(strict_types=1);

namespace RentWatch\Cli;

/**
 * What one pass of the pipeline did. The CLI's exit code and every summary line derive from this.
 *
 * `undelivered` is the field that earns its place: a match that was found and could not be sent is
 * neither a success nor a fetch failure, and lumping it into either is how an unsent notification
 * becomes invisible. It is counted separately so the run can report it and the listing can stay
 * un-notified for the next attempt.
 */
final readonly class RunResult
{
    /**
     * @param list<string> $errors   one line per source that failed, already redacted
     * @param list<string> $rejected disqualifier lines, for `-v`
     */
    public function __construct(
        public int $sourcesRun = 0,
        public int $sourcesFailed = 0,
        public int $itemsParsed = 0,
        public int $matches = 0,
        public int $digested = 0,
        public int $rejectedCount = 0,
        public int $duplicates = 0,
        public int $rentDrops = 0,
        public int $undelivered = 0,
        /**
         * Listings whose PAYLOAD could not be encoded, so no evidence snapshot was captured.
         *
         * **Not "whose text is unreadable", which is what this said until a review panel checked
         * it.** `ListingSnapshot::encode()` refuses three distinct things: malformed UTF-8 anywhere
         * in the listing, a nesting depth over 512, and `Inf`/`NaN`. Only the first is unreadable
         * prose, and even that need not be prose — a structured FIELD carrying one bad byte while
         * the title and description are clean produces a listing that classifies normally and can
         * be a NOTIFIED MATCH which silently lost its evidence. The earlier wording told the
         * operator such a listing was *illisible* and *digested*, and both were wrong.
         *
         * Their verdict IS stored, so they are not mistaken for pre-v3 rows — and re-judging on
         * evidence that was never captured is the breach §1 forbids, so nothing may re-judge them.
         *
         * **"`scout reclassify` skips them for ever" is what this said, and it is TRUE ONLY OF AN
         * `UNKNOWN` ROW.** `Store::staleVerdicts()` selects `tenure IS NULL OR tenure = 'UNKNOWN'`,
         * so the very listing this paragraph is about — one that classified `LLI`, was NOTIFIED,
         * and lost its snapshot — is not skipped by that command, it is INVISIBLE to it. It is
         * outside `pendingDigest()` too, its outcome being `MATCH`. A round-3 commit message
         * claimed this correction had landed; it landed in `Pipeline` and in
         * `Store::evidencelessVerdictCount()` and not here, and a fourth review round found the
         * original wording still standing. `scout doctor`'s `preuves` line is what surfaces the
         * standing count, and it is the only thing that does.
         *
         * Counted so a pass says it happened rather than leaving it to be discovered months later
         * from a skip counter. This used to be an exception that took the whole pass.
         */
        public int $unencodable = 0,
        public array $errors = [],
        public array $rejected = [],
    ) {}

    /**
     * Did anything go wrong that the developer must act on?
     *
     * An undelivered match counts. A rejected listing does not — rejection is the filter working.
     */
    public function hasProblems(): bool
    {
        return $this->sourcesFailed > 0 || $this->undelivered > 0;
    }
}
