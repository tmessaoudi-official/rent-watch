<?php

declare(strict_types=1);

namespace Scout\Store;

/**
 * What the store remembers about a listing's own detail page.
 *
 * A row exists as soon as the first ATTEMPT is made, which is what makes the failure case
 * expressible: `fields === null` with `attempts > 0` is *"we tried and could not read it"*, and it
 * is a different fact from *"we read it and it said nothing"* (`fields === []`). Collapsing the two
 * is hard rule 3 in new clothes — a detail page that 404s would present as a listing that simply
 * has no floor, for ever, while the source's health stayed green.
 *
 * `fields` holds the RAW extracted strings, not mapped values, for the same reason the tenure
 * verdict is stored rather than the tenure alone: a `ListingMapper` improvement must reach rows
 * that were captured before it existed. Mapping happens on read.
 */
final readonly class StoredDetail
{
    /**
     * @param array<string,string>|null $fields raw extracted strings; null means every attempt so
     *                                          far has FAILED, and `lastError` says how
     */
    public function __construct(
        public string $sourceName,
        public string $externalId,
        public ?string $urlFetched,
        public ?array $fields,
        public ?string $fetchedAt,
        public int $attempts,
        public ?string $lastAttemptAt,
        public ?string $lastError,
    ) {}

    /** A usable hydration is on record; no request is owed for this listing. */
    public function isHydrated(): bool
    {
        return $this->fields !== null;
    }
}
