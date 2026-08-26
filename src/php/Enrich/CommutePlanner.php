<?php

declare(strict_types=1);

namespace RentWatch\Enrich;

/**
 * Door-to-door public-transport minutes from a commune to the configured destination.
 *
 * An interface for the same reason `HttpClient` is one: without it the offline guarantee is a
 * discipline rather than a structure. Tests inject a fake and no test reaches the network.
 *
 * **Every failure returns `null`, and `null` means UNKNOWN, never FAR.** An unreachable API, an
 * unrecognised commune, a destination that does not geocode — all of them lose the score component
 * rather than inventing a number for it (hard rule 9). The one thing an implementation must never
 * do is throw: enrichment runs inside a pass that is polling live sources, and hard rule 3's
 * converse applies as much as the rule itself — a commute lookup must not be able to void a pass
 * that has already fetched real listings.
 */
interface CommutePlanner
{
    /**
     * @param string|null $commune  as the listing reported it, unnormalised
     * @param string|null $postcode the five-digit code, which disambiguates repeated commune names
     *
     * @return int|null whole minutes, or null when it could not be determined
     */
    public function minutesFrom(?string $commune, ?string $postcode): ?int;
}
