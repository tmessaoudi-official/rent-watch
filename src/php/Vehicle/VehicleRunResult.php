<?php

declare(strict_types=1);

namespace Scout\Vehicle;

/** One pass of the car pipeline, counted — the rent side's `RunResult`, trimmed to what exists here. */
final readonly class VehicleRunResult
{
    /**
     * @param list<string> $errors   one line per failed source
     * @param list<string> $rejected one verbose line per rejected car
     */
    public function __construct(
        public int $sourcesRun = 0,
        public int $sourcesFailed = 0,
        public int $itemsParsed = 0,
        public int $matches = 0,
        public int $rejectedCount = 0,
        public int $priceDrops = 0,
        public int $notified = 0,
        public int $undelivered = 0,
        public array $errors = [],
        public array $rejected = [],
    ) {}
}
