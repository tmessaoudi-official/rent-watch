<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\PatternMissLog;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Adapters\SourceError;

/**
 * Turns one raw payload item into a {@see RawListing}, following a source's field map.
 *
 * Shared by every adapter that reads a structured payload, so the traps below are solved once
 * rather than once per source — which is the whole reason adding a source is meant to be config-only.
 */
final readonly class ListingMapper
{
    /**
     * @param ?PatternMissLog $misses where a CONFIGURED field that extracted nothing is recorded —
     *                                Track 6-A3 / F27b. Optional, so a mapper built without one
     *                                behaves exactly as before; every production construction site
     *                                passes its source's log.
     */
    public function __construct(
        private readonly SourceDefinition $source,
        private readonly ?PatternMissLog $misses = null,
        /**
         * Prefix for every key this mapper records — `'detail.'` for a detail-page map, `''` for a
         * card map. NOT cosmetic, and found by running the deployed image rather than by design.
         *
         * In'li maps `cp` on BOTH maps: the card reads it from the URL slug, the detail page from
         * the `<title>` (the `683a31b` fix). Pooled under one key the first live pass reported
         * `cp 171/342` — and `PatternMissLog::total()` speaks only at 100 %, so **a card pattern
         * that missed on all 171 cards was averaged with 171 detail successes into a silent 50 %**.
         * One whole map dead, reported as half-working, WARN unreachable. That is the same
         * dilution `RunStore`'s seven-day flaky window had, one layer down and shipped the same
         * evening.
         */
        private readonly string $missPrefix = '',
    ) {}

    /**
     * Record one extraction attempt — and ONLY for a field the map configures.
     *
     * `map()` calls every `Payload::` reader unconditionally, so an UNMAPPED field yields null on
     * every item. Counting those reports a permanent 100 % miss on a field nobody asked for, and
     * `PatternMissLog::total()`'s rule is exactly 100 %, so it would fire every pass for ever.
     * Measured: logirep maps no `floor` and no `elevator`, and its 123 rows are 123/123 null on
     * both. That is F30's shape — a signal demanding a field the source structurally does not carry
     * — rebuilt on four sources at once, so the emptiness check is the guard that makes the whole
     * signal usable rather than a detail.
     *
     * Returns the value unchanged, so instrumenting a field is a wrapper and never a second
     * extraction — one call site per field, and no way for the counted value and the stored one to
     * drift apart.
     *
     * `!==` is strict on purpose: hard rule 9 lives in this class. `floor === 0` is RDC and REAL,
     * `hasElevator === false` is an explicitly absent lift, and both must count as FOUND — a loose
     * comparison would record the two facts this codebase most often loses as extraction failures.
     *
     * @param list<string> $paths the field's configured paths; empty means the field is not mapped
     */
    private function noted(string $field, array $paths, mixed $value): mixed
    {
        if ($this->misses === null || $paths === []) {
            return $value;
        }

        // An empty string is not an answer — the rule `PatternMissLog` already states for the email
        // readers, and the same one that made `$plain ??= ''` hide four MIME defects.
        $this->misses->record($this->missPrefix . $field, $value !== null && $value !== '');

        return $value;
    }

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
            title: $this->noted('title', $map->title, Payload::string($item, $map->title)) ?? '',
            description: $this->noted('description', $map->description, Payload::string($item, $map->description)) ?? '',
            // The WHOLE structured surface, not only the mapped fields. The classifier reads field
            // NAMES as tier-1 evidence, so a `financement` nobody thought to map must still be seen.
            fields: $this->fields($item, $map),
            url: $this->url($item, $map),
            commune: $this->noted('commune', $map->commune, Payload::string($item, $map->commune)),
            postcode: $this->noted('cp', $map->postcode, Payload::string($item, $map->postcode)),
            rentCc: $rentCc,
            rentHc: $rentHc,
            charges: $this->noted('charges', $map->charges, Payload::int($item, $map->charges)),
            surfaceM2: $this->noted('surface', $map->surface, Payload::float($item, $map->surface)),
            rooms: $this->noted('rooms', $map->rooms, Payload::int($item, $map->rooms)),
            bedrooms: $this->noted('bedrooms', $map->bedrooms, Payload::int($item, $map->bedrooms)),
            floor: $this->noted('floor', $map->floor, Payload::floor($item, $map->floor)),
            hasElevator: $this->noted('elevator', $map->elevator, Payload::bool($item, $map->elevator)),
            proseAbsent: $this->source->proseAbsent,
        );
    }

    /**
     * The whole structured surface, plus this project's own tenure declaration under the literal
     * key the classifier knows (`tenureField`) — audit N5, Track 6-A3.
     *
     * `HtmlSource::flatMapped()` rewrites every selector to a literal key before the mapper runs, so
     * on the HTML path the extracted array already carries `tenureField` and `TenureClassifier`
     * reads it as a structured declaration. The JSON path has no such renaming: `$item` carries the
     * portal's own key names and `$map->tenureField` was READ BY NOBODY. Same config key, two
     * adapter types, one of them acting on it — *a mechanism landing on one of two symmetric
     * surfaces*, which is this repo's named recurring defect.
     *
     * **HONESTY ABOUT WHAT THIS BUYS.** The audit filed N5 as "§1-adjacent latent: a future JSON
     * source mapping a non-standard tenure key would silently lose the signal". Measured against
     * the classifier before writing this, that is NOT reproducible: `financement: PLS`,
     * `regime: PLS` and `tenureField: PLS` all classify PLS at tier 1, and `financement: LLI` and
     * `regime: LLI` both give LLI 97 — because the unknown-field path was already hardened to scan
     * any field's value for excluded vocabulary (see the long note at `TenureClassifier`'s
     * `TENURE_FIELDS` check, written after that closed list stood between a spelled-out PLAI and a
     * notification). So NO VERDICT CHANGES TODAY. What this removes is the asymmetry itself: a
     * configured key that means something on one adapter and nothing on the other, whose safety
     * currently rests on a fallback rather than on the declaration the operator wrote.
     *
     * The raw surface is still passed WHOLE and first — the classifier reads field NAMES as
     * evidence, so a `financement` nobody thought to map must still be seen, and a mapped value
     * must never REPLACE the payload it came from.
     *
     * @return array<string, scalar|null>
     */
    private function fields(mixed $item, FieldMap $map): array
    {
        $fields = Payload::flatten($item);

        if ($map->tenureField === []) {
            return $fields;
        }

        // COUNTED, and this one is §1. `tenureField` is the classifier's TIER-1 signal — the
        // explicit structured field that outranks every label, tell and default. A selector that
        // dies here does not fail loudly: the key is simply absent, the classifier falls through to
        // the SOURCE DEFAULT, and a mixed-stock portal starts judging every listing by its most
        // optimistic assumption. Nothing else moves — `item_count` is unchanged, no run fails, and
        // `SourceHealth` stays `ok` — which is hard rule 2's exact shape on the one field §1 rests
        // on. It was the last configured key in this class counting nothing (C2 round 5, found
        // independently by two lenses).
        $declared = $this->noted('tenure_field', $map->tenureField, Payload::string($item, $map->tenureField));

        if ($declared === null || $declared === '') {
            return $fields;
        }

        // Idempotent on the HTML path: there the value was extracted FROM `tenureField`, so this
        // writes back what is already there.
        $fields['tenureField'] = $declared;

        return $fields;
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
        // DELIBERATELY UNBANDED, and it was banded for one day (Track 6-A3 half 3, 2026-09-04).
        // Both attempts failed the same way, on opposite bounds, and the second was written by the
        // fix for the first — which is why the reasoning is here rather than in a commit message.
        //
        // Every downstream guard in this codebase reads a rent as `!== null`, so on a SINGLE
        // labelled value "outside the band" and "not stated" become the same input, and nulling
        // does not reject the listing — it DELETES THE EVIDENCE the guard needed:
        //
        //   - the ceiling. `CriteriaEngine::disqualify()` guards `max_rent_cc` with
        //     `$rentCc !== null`, so nulling 25 000 € turned a REJECT into a MATCH, with the push
        //     saying *"loyer non communiqué"* about a rent the portal communicated. Shipped.
        //   - the floor. `CriteriaEngine::pricePerM2()` returns null when the rent is null, so
        //     nulling 119 € skips the Track 1f plausibility branch — `{119 €, 60 m²}` judged
        //     DIGEST unbanded and MATCH with the floor in place. Caught before it shipped.
        //
        // The band belongs to `EmailAlertSource::rentIn()`, where it sits inside a loop over
        // CANDIDATES: there "refused" means KEEP LOOKING, and discarding one implausible figure
        // costs nothing because the real rent is on the next line. That is a different statement
        // with the opposite safety direction, and it does not transfer.
        //
        // Measured before removing it: the "7 price-history rows at 119–290 €" that motivated the
        // band contain ONE below the floor, already digested on tenure. Zero rows either way. The
        // mechanism for a mis-mapped low rent is the price-per-m² floor — which the band disabled.
        $rent = $this->noted('rent', $map->rent, Payload::int($item, $map->rent));
        $explicitHc = $this->noted('rent_hc', $map->rentHc, Payload::int($item, $map->rentHc));

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
        // COUNTED, and it is the reason a detail fetch can go dark: hydration needs this URL, so a
        // selector that dies here stops every detail page being read while the card still maps.
        $url = $this->noted('url', $map->url, Payload::string($item, $map->url));

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
