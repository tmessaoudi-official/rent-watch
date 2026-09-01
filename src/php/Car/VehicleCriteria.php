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
     *
     * AN ENTRY IS A STEM, MATCHED TO A NON-LETTER BOUNDARY — and that is a measured repair, not a
     * generalisation. This was `in_array($folded, $brandAvoid, true)`, exact equality, and the live
     * store carries the SAME marque under two spellings, one per source: autohero emits
     * `ds automobiles`, leboncoin emits `ds`. A config entry `ds` caught one row and silently
     * missed the other — a configured preference inert on a whole source, which is this repo's
     * recurring defect (`exclude_title_patterns` on In'li, the two unread car params, PAP's
     * anchors). Nothing reads as a fault; the car merely ranks 10 points too high.
     *
     * The boundary is a NON-LETTER so `DS 3`, `DS-3` and `DS3` are all the same marque, and so the
     * stem can never reach a longer word that merely begins with it. The counterweight is asserted
     * against every make the live store actually contains — over-reaching here ranks a car BELOW
     * one that deserves less, which is as silent as under-reaching and worse.
     *
     * The residual, stated rather than left to be found: a make written with NO separator at all
     * (`alfaromeo`) is not caught. Under-matching is the safe direction, and no source emits it.
     */
    public function isAvoidedBrand(?string $make): bool
    {
        if ($make === null || trim($make) === '') {
            return false;
        }

        $folded = \Scout\Core\Text::fold($make);

        foreach ($this->brandAvoid as $stem) {
            if ($folded === $stem) {
                return true;
            }

            if (!str_starts_with($folded, $stem)) {
                continue;
            }

            // What ENDS the stem decides. A letter means this is a longer word that merely starts
            // the same way; anything else — space, hyphen, digit — is a boundary and the marque.
            if (!ctype_alpha($folded[\strlen($stem)])) {
                return true;
            }
        }

        return false;
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
