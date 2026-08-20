<?php

declare(strict_types=1);

namespace RentWatch\Adapters;

use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\RawListing;

/**
 * Turns one raw payload item into a {@see RawListing}, following a source's field map.
 *
 * Shared by every adapter that reads a structured payload, so the traps below are solved once
 * rather than once per source — which is the whole reason adding a source is meant to be config-only.
 */
final readonly class ListingMapper
{
    public function __construct(private readonly SourceDefinition $source) {}

    /**
     * @throws SourceError if the item has no id — see below
     */
    public function map(mixed $item): RawListing
    {
        $map = $this->source->map;

        $ref = Payload::string($item, $map->ref);
        if ($ref === null || $ref === '') {
            // NOT skipped, and not given a synthetic id. A synthetic id (a hash of the content, an
            // array index) changes every time the ad's text is touched or the result order shifts,
            // so the listing is "new" on every run and notifies forever. The store's own contract
            // calls this out: "a listing is new exactly once" is a guarantee, and an unstable key
            // breaks it invisibly. A missing id is a broken field map, which is a source failure.
            throw new SourceError(
                $this->source->name,
                'an item carried no value at any of the `ref` paths (' . implode(', ', $map->ref)
                    . '). Without a stable id every run re-notifies every listing',
            );
        }

        [$rentCc, $rentHc] = $this->rents($item, $map);

        return new RawListing(
            sourceName: $this->source->name,
            externalId: $ref,
            title: Payload::string($item, $map->title) ?? '',
            description: Payload::string($item, $map->description) ?? '',
            // The WHOLE structured surface, not only the mapped fields. The classifier reads field
            // NAMES as tier-1 evidence, so a `financement` nobody thought to map must still be seen.
            fields: Payload::flatten($item),
            url: $this->url($item, $map),
            commune: Payload::string($item, $map->commune),
            postcode: Payload::string($item, $map->postcode),
            rentCc: $rentCc,
            rentHc: $rentHc,
            charges: Payload::int($item, $map->charges),
            surfaceM2: Payload::float($item, $map->surface),
            rooms: Payload::int($item, $map->rooms),
            bedrooms: Payload::int($item, $map->bedrooms),
            floor: Payload::floor($item, $map->floor),
            hasElevator: Payload::bool($item, $map->elevator),
        );
    }

    /**
     * Split the single mapped `rent` into the right one of the two fields.
     *
     * `charges_included` is REQUIRED in config whenever `rent` is mapped, and the loader refuses a
     * block that omits it — so there is no default to get wrong here. Sources genuinely disagree,
     * and guessing mis-files a whole source's rents by the size of its charges, which is roughly
     * 10% of the budget: comfortably enough to move listings across the 1800 € cutoff in both
     * directions, silently.
     *
     * @return array{0: ?int, 1: ?int} `[rentCc, rentHc]`
     */
    private function rents(mixed $item, FieldMap $map): array
    {
        $rent = Payload::int($item, $map->rent);
        $explicitHc = Payload::int($item, $map->rentHc);

        if ($rent === null) {
            return [null, $explicitHc];
        }

        return $map->chargesIncluded === true ? [$rent, $explicitHc] : [null, $rent];
    }

    /**
     * Resolve a possibly-relative href against the source's `base_url`.
     *
     * Restored with `base_url` on 2026-08-07: a real payload commonly carries `/annonce/1234`, and a
     * relative URL in a notification is a link the developer cannot click from a phone.
     */
    private function url(mixed $item, FieldMap $map): ?string
    {
        $url = Payload::string($item, $map->url);

        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url) === 1) {
            return $url;
        }
        if ($this->source->baseUrl === null) {
            // Returned as-is rather than dropped. A relative URL is degraded, but it still tells the
            // developer which ad this is; dropping it would hide a missing `base_url` entirely.
            return $url;
        }

        return rtrim($this->source->baseUrl, '/') . '/' . ltrim($url, '/');
    }
}
