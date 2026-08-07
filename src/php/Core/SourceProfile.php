<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * The slice of a `Source` that the tenure classifier needs.
 *
 * Separated from the full adapter interface so the classifier stays pure: it can be exercised
 * against `tests/fixtures/tenure/corpus.json` with no adapter, no HTTP and no config loader in the
 * way. That is what lets the highest-risk module in the project be built before any source exists.
 */
final readonly class SourceProfile
{
    /**
     * @param string       $name          matches the key in `config/sources.json`
     * @param 'institutional'|'private' $family
     * @param Tenure|null  $defaultTenure a HINT, the lowest-priority signal there is. The classifier
     *                                    still runs, and consults this only when nothing else fires.
     * @param bool         $mixedTenure   does this landlord publish social AND intermediate stock?
     *
     *   `$mixedTenure` DEFAULTS TO TRUE, and that default is the point. It is the switch that arms
     *   the fail-closed rule, so a source added to config without declaring itself must be assumed
     *   capable of carrying social stock. The opposite default would let a forgotten line in a YAML
     *   file silently disable the one protection this project exists to provide.
     */
    public function __construct(
        public string $name,
        public string $family = 'institutional',
        public ?Tenure $defaultTenure = null,
        public bool $mixedTenure = true,
    ) {}
}
