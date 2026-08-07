<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * The health verdict for one source, derived from its recorded run history.
 *
 * Every field beyond `status` exists so the verdict can be argued with. `CLAUDE.md` hard rule 2 asks
 * for last-success, last-count and a rolling mean to be persisted; carrying them here means
 * `scout doctor` can show WHY a source is `BROKEN` instead of asserting it, and a false alarm is
 * diagnosable without opening the database.
 */
final readonly class SourceHealth
{
    /**
     * @param string      $sourceName          key in `config/sources.yaml`
     * @param string      $detail              human-readable justification, in French, for the status
     * @param int         $consecutiveEmptyRuns trailing runs that succeeded and returned nothing
     * @param string|null $lastSuccessAt       ISO-8601 timestamp of the last run that succeeded
     * @param int|null    $lastCount           items returned by the most recent run
     * @param float|null  $rollingMean         mean item count over the rolling window, excluding the
     *                                         most recent run; null when there is nothing to average
     * @param int         $totalRuns           runs on record, successful or not
     */
    public function __construct(
        public string $sourceName,
        public SourceStatus $status,
        public string $detail = '',
        public int $consecutiveEmptyRuns = 0,
        public ?string $lastSuccessAt = null,
        public ?int $lastCount = null,
        public ?float $rollingMean = null,
        public int $totalRuns = 0,
    ) {}
}
