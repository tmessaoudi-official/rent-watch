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
