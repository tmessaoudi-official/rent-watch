<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * Decides whether two listings from DIFFERENT sources are the same flat.
 *
 * Within a source, dedup is exact: the source's own id is the key, and `Store::dedupKey()` handles
 * it. This is the other problem — the same flat advertised on SeLoger and on Logic-Immo, which are
 * the same group (`docs/SOURCES.md` B5) and duplicate heavily.
 *
 * **TRACKS DO NOT MERGE, and that is a product rule, not an optimisation.** `docs/SOURCES.md` §
 * "Two tracks": *"a flat listed by In'li AND on SeLoger is two findings, not a duplicate, because the
 * application route differs."* Applying to In'li directly is a different act from applying through
 * an agency, with different competition and different paperwork, so collapsing them would hide the
 * better route behind the worse one.
 *
 * **THE TWO FAILURE MODES ARE NOT SYMMETRIC**, and every threshold below leans the same way:
 *
 * - **Over-merge hides a flat.** Two genuinely different T4s in Chatou collapse into one, one
 *   notification is sent, and the other flat is never seen. It is silent — indistinguishable from
 *   that flat never having been published.
 * - **Under-merge notifies twice.** Mildly annoying, entirely visible, and self-correcting: the
 *   developer sees two entries and recognises them.
 *
 * So the rule is: **merge only on positive evidence, never on the absence of a difference.** Two
 * listings that agree on nothing because they state nothing are NOT duplicates. That is what
 * {@see MIN_CORROBORATING_FACTS} enforces, and it is the single most important line here — without
 * it, every listing with an unparsed rent and surface in the same commune becomes one listing.
 */
final class Dedup
{
    /**
     * How many of {rent, surface, rooms} must be KNOWN ON BOTH sides and agree.
     *
     * Two, not one. With one, a pair agreeing only on room count merges every T4 in a commune — and
     * a T4 filter means every candidate is a T4, so that is the whole result set collapsing to one
     * notification. With three, an ordinary listing that omits its surface can never be deduplicated
     * at all, and omitted surfaces are common.
     */
    public const int MIN_CORROBORATING_FACTS = 2;

    /** Rents may differ by this much and still be the same flat, whichever bound is LARGER. */
    public const int RENT_TOLERANCE_EUR = 30;
    public const float RENT_TOLERANCE_RATIO = 0.03;

    /** Surfaces may differ by this much, whichever bound is larger — portals round differently. */
    public const float SURFACE_TOLERANCE_M2 = 2.0;
    public const float SURFACE_TOLERANCE_RATIO = 0.03;

    /**
     * Are these two listings the same flat?
     *
     * Returns `null` when they are not, and a human-readable justification when they are. A boolean
     * would have been enough for the code and useless for the developer: a merge that hid a flat is
     * only diagnosable if the tool can say what it merged on.
     */
    public function duplicateReason(RawListing $a, RawListing $b, string $familyA, string $familyB): ?string
    {
        if ($a->sourceName === $b->sourceName) {
            // Same source: the source's own id is authoritative and exact. Fuzzy matching here would
            // second-guess the only identifier that is actually reliable.
            return null;
        }

        if ($familyA !== $familyB) {
            return null;
        }

        return $this->sameFlatReason($a, $b);
    }

    /**
     * Is this the same flat on the OTHER track — and therefore two findings that deserve ONE push?
     *
     * Developer ruling, 2026-08-29, amending the two-tracks rule rather than reversing it: 43 flats
     * had been pushed twice, once from the landlord and once from the agency copy on SeLoger or
     * Bien'ici. Identities, groups and histories stay per track — this method never feeds
     * {@see cluster()} — it answers only the notification question, with exactly the same
     * positive-evidence bar as {@see duplicateReason()}. Same track is a duplicate, not a twin.
     */
    public function twinReason(RawListing $a, RawListing $b, string $familyA, string $familyB): ?string
    {
        if ($a->sourceName === $b->sourceName || $familyA === $familyB) {
            return null;
        }

        return $this->sameFlatReason($a, $b);
    }

    /**
     * Is this the same DWELLING as one the store already judged excluded — whatever the source?
     *
     * The §1 question, and it is not the identity question. {@see duplicateReason()} refuses a
     * same-source pair because the source's own id is authoritative and exact, and fuzzy matching
     * there would second-guess the only reliable identifier; {@see twinReason()} refuses a same-track
     * pair because that is a duplicate rather than a twin. Both refusals are right about IDENTITY and
     * were being applied to the VETO, which is a fact about the flat rather than about the
     * advertisement — so a portal re-advertising an excluded flat under a new ad id inherited
     * nothing, and was pushed as a match (C2 round-1 correctness lens, 2026-09-02).
     *
     * Same bar as both of them: `sameFlatReason()`, unchanged, merging only on POSITIVE evidence.
     * What is dropped is the source and family test, and nothing else.
     *
     * **This must never feed identity.** It answers the veto and only the veto — not `cluster()`,
     * not `dedup_key`, not the history, and not the *autre voie* wording, because a same-source copy
     * is not another route.
     */
    public function sameDwellingReason(RawListing $a, RawListing $b): ?string
    {
        return $this->sameFlatReason($a, $b);
    }

    private function sameFlatReason(RawListing $a, RawListing $b): ?string
    {
        // Location must AGREE POSITIVELY. Two unknown communes are not a match — they are two
        // unknowns, and treating them as equal is the over-merge that hides a flat.
        $communeA = $a->commune === null ? null : \Scout\Rent\Config\Criteria::communeKey($a->commune);
        $communeB = $b->commune === null ? null : \Scout\Rent\Config\Criteria::communeKey($b->commune);

        if ($communeA === null || $communeB === null || $communeA === '' || $communeA !== $communeB) {
            return null;
        }

        $facts = [];

        $rentA = $a->effectiveRentCc();
        $rentB = $b->effectiveRentCc();
        if ($rentA !== null && $rentB !== null) {
            if (!self::within((float) $rentA, (float) $rentB, self::RENT_TOLERANCE_EUR, self::RENT_TOLERANCE_RATIO)) {
                // A stated disagreement is decisive, not merely unhelpful: two flats whose rents
                // differ by more than the tolerance are two flats, whatever else matches.
                return null;
            }
            $facts[] = 'loyer ' . $rentA . ' € ≈ ' . $rentB . ' €';
        }

        if ($a->surfaceM2 !== null && $b->surfaceM2 !== null) {
            if (!self::within($a->surfaceM2, $b->surfaceM2, self::SURFACE_TOLERANCE_M2, self::SURFACE_TOLERANCE_RATIO)) {
                return null;
            }
            $facts[] = 'surface ' . self::m2($a->surfaceM2) . ' ≈ ' . self::m2($b->surfaceM2);
        }

        if ($a->rooms !== null && $b->rooms !== null) {
            if ($a->rooms !== $b->rooms) {
                return null;
            }
            $facts[] = $a->rooms . ' pièces';
        }

        // The floor is corroboration when both state it and a REFUSAL when they disagree — but it is
        // never a fact on its own, because most listings share a floor by coincidence.
        if ($a->floor !== null && $b->floor !== null && $a->floor !== $b->floor) {
            return null;
        }

        if (count($facts) < self::MIN_CORROBORATING_FACTS) {
            return null;
        }

        return 'même bien que ' . $b->sourceName . ' : ' . implode(', ', $facts);
    }

    /**
     * Group listings into clusters of the same flat, keeping the FIRST of each as the survivor.
     *
     * First rather than best, deliberately. Ordering is the caller's business — it hands them in
     * whatever order it wants preserved — and a "best" rule here would silently prefer one portal
     * over another on criteria this class has no business knowing.
     *
     * Each cluster also carries its `members` as LISTINGS, survivor first. `duplicates` cannot
     * serve that purpose and is kept beside it rather than replaced: it holds
     * `sourceName:externalId` strings for the notification text, and every listing with an empty
     * `externalId` produces the same string — so parsing them back into store keys collides exactly
     * where the store's URL/title fallback key does not. Schema v4's group is assigned from
     * `members`.
     *
     * @param list<array{listing: RawListing, family: string}> $items
     *
     * @return list<array{listing: RawListing, family: string, duplicates: list<string>, members: list<RawListing>}>
     */
    public function cluster(array $items): array
    {
        /** @var list<array{listing: RawListing, family: string, duplicates: list<string>, members: list<RawListing>}> $kept */
        $kept = [];

        foreach ($items as $item) {
            $mergedInto = null;

            foreach ($kept as $index => $survivor) {
                $reason = $this->duplicateReason(
                    $item['listing'],
                    $survivor['listing'],
                    $item['family'],
                    $survivor['family'],
                );

                if ($reason !== null) {
                    // Recorded on the SURVIVOR, so the notification can say "also on leboncoin" —
                    // which is the actionable half. A dropped duplicate that leaves no trace is
                    // indistinguishable from a listing that was never fetched.
                    $kept[$index]['duplicates'][] = $item['listing']->sourceName
                        . ':' . $item['listing']->externalId;
                    $kept[$index]['members'][] = $item['listing'];
                    $mergedInto = $index;
                    break;
                }
            }

            if ($mergedInto === null) {
                $kept[] = [
                    'listing' => $item['listing'],
                    'family' => $item['family'],
                    'duplicates' => [],
                    'members' => [$item['listing']],
                ];
            }
        }

        return $kept;
    }

    /** Within an absolute bound OR a proportional one — whichever is more generous. */
    private static function within(float $a, float $b, float $absolute, float $ratio): bool
    {
        $delta = abs($a - $b);
        $scale = max(abs($a), abs($b));

        return $delta <= $absolute || ($scale > 0.0 && $delta / $scale <= $ratio);
    }

    private static function m2(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ' '), '0'), ',') . ' m²';
    }
}
