<?php

declare(strict_types=1);

namespace Scout\Rent\Store;

/**
 * What the store currently believes about a listing it has seen.
 *
 * The notification layer needs the URL and the title to produce anything actionable, and both are
 * carried across runs rather than re-supplied — a run whose field map partially broke must not be
 * able to erase them. Exposing them is also what makes that guarantee assertable: it was silently
 * violated for a whole review round because nothing could read the stored row back.
 */
final readonly class StoredListing
{
    public function __construct(
        public string $dedupKey,
        public string $sourceName,
        public string $externalId,
        public ?string $url,
        public string $title,
        public ?int $rentCc,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public ?string $notifiedAt,
    ) {}
}
