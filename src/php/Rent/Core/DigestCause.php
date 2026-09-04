<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * Why a listing landed in the *à vérifier* bin.
 *
 * The bin is §1's only landing zone and for most of this project's life it had exactly one
 * entrance, so the rollup title could speak for every entry: *"N annonce(s) au régime indéterminé"*.
 * Track 1f's price-per-m² plausibility branch opened a second entrance, and that title then
 * asserted something untrue about a subset of its own entries — a listing digested for an
 * implausible rent is typically `LLI` at full confidence, its regime as determined as it gets.
 *
 * This enum exists so the title can be COMPOSED from what is in the batch rather than assumed.
 * It is deliberately two cases rather than one per route:
 *
 * - {@see self::TENURE_UNDETERMINED} is the §1 landing zone proper — the classifier could not
 *   resolve the regime, or resolved it to `UNKNOWN` on a source that also publishes social stock.
 * - {@see self::OTHER} is *everything else*, including a cause the store never recorded. Naming
 *   the price branch specifically would be a claim the drain path cannot make: `pendingDigest()`
 *   reads a row whose `outcome` is `DIGEST` and whose tenure is determined, which says the entry
 *   is not a tenure doubt and says nothing at all about which other route put it there. A third
 *   route added tomorrow lands in `OTHER` without anyone having to remember this file, and the
 *   title degrades to saying less rather than to saying something false.
 *
 * The asymmetry is the point, and it is hard rule 9 one layer up: the specific clause is earned by
 * every entry or by none.
 */
enum DigestCause
{
    /** The classifier could not determine the regime — the fail-closed landing zone. */
    case TENURE_UNDETERMINED;

    /** Some other doubt, or one the store did not record. Never announced as a regime. */
    case OTHER;
}
