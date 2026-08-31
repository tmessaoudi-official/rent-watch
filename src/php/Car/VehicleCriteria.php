<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * The developer's car criteria, as ruled (decisions 5–11): ONE hard ceiling (the displayed price),
 * one geography filter that only acts on a STATED location, and score components for everything
 * else. Age and mileage are PEAKS, never disqualifiers — the Q5 precedent that removed `max_floor`
 * from the rent side, and hard rule 8.
 */
final readonly class VehicleCriteria
{
    /**
     * @param list<string>       $postcodePrefixes empty = any location (decision 6: settable to any set)
     * @param list<string>       $bodyRank         folded, best first; unranked bodies score 0 and are still notified
     * @param array<string,int>  $weights          price, age, mileage, gearbox, fuel, body — summing to 100
     * @param list<string>       $excludePatterns  extra regexes (folded text); the §1 vehicle set is NOT here
     */
    public function __construct(
        public int $maxPriceEur,
        public array $postcodePrefixes,
        public array $bodyRank,
        public int $peakAgeYears,
        public int $peakMileageKm,
        public array $weights,
        public array $excludePatterns,
        public VehicleNotifyPolicy $notify,
        /**
         * Makes to score DOWN, folded — the developer's 2026-08-31 ruling.
         *
         * A LIST, not a rank, and the name says so. `body_rank` scores its top entry HIGHEST, so
         * mirroring it here would have made the disfavoured brands beat an unlisted one — the
         * opposite of the ruling. No ordering among these was ruled either: they are equal.
         *
         * @var list<string>
         */
        public array $brandAvoid = [],
    ) {}

    /** Hard rule 9: an UNKNOWN location never rejects; a stated one outside the set does. */
    public function matchesLocation(?string $postcode): bool
    {
        if ($this->postcodePrefixes === [] || $postcode === null || trim($postcode) === '') {
            return true;
        }
        foreach ($this->postcodePrefixes as $prefix) {
            if (str_starts_with($postcode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this make one the developer would rather avoid?
     *
     * `null` — no make extracted — is NOT avoided (hard rule 9: unknown is not disfavoured), which
     * is the same direction every other unknown takes here.
     */
    public function isAvoidedBrand(?string $make): bool
    {
        if ($make === null || trim($make) === '') {
            return false;
        }

        return \in_array(\Scout\Core\Text::fold($make), $this->brandAvoid, true);
    }

    /** 1-based rank of a body in the preference list, or null when unranked. */
    public function bodyRankOf(?string $body): ?int
    {
        if ($body === null) {
            return null;
        }
        try {
            $key = Text::fold($body);
        } catch (MalformedText) {
            return null;
        }
        foreach ($this->bodyRank as $i => $ranked) {
            if ($key === $ranked || str_contains($key, $ranked)) {
                return $i + 1;
            }
        }

        return null;
    }

    /** The first extra exclusion pattern matching the listing's folded text, or null. */
    public function excludedBy(string $text): ?string
    {
        try {
            $folded = Text::fold($text);
        } catch (MalformedText) {
            return null;
        }
        foreach ($this->excludePatterns as $pattern) {
            if (@preg_match('~' . $pattern . '~u', $folded) === 1) {
                return $pattern;
            }
        }

        return null;
    }
}
