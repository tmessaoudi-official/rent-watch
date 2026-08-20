<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * What an adapter produced, before enrichment and before scoring.
 *
 * Two rules from `CLAUDE.md` are encoded in the SHAPE of this object rather than left to the
 * discipline of whoever writes the criteria engine:
 *
 * 1. **`null` is not zero** (hard rule 9). Every measurement is nullable, and null means *the source
 *    did not say*, never *below the minimum*. `$rooms = null` must not be compared against a
 *    minimum; `$floor = 0` is the rez-de-chaussée and is falsy but real; `$hasElevator = false`
 *    ("there is no lift") and `$hasElevator = null` ("the listing did not mention a lift") are
 *    different facts and the prototype conflated them.
 *
 * 2. **Rent is charges comprises or hors charges, and sources disagree** (hard rule 9, and the #1
 *    correctness trap in `docs/FILTERS.md`). Storing one `rent` field forces a guess at parse time
 *    that nothing downstream can recover from, so both are carried separately and explicitly. The
 *    criteria engine compares on CC; an adapter that only knows HC leaves `$rentCc` null and says so.
 */
final readonly class RawListing
{
    /**
     * @param string                $sourceName   key in `config/sources.json`
     * @param string                $externalId   the source's own id — the basis of within-source dedup
     * @param array<string, string> $fields       structured fields exactly as the adapter found them.
     *                                            Field names are NOT normalised here on purpose: the
     *                                            raw name is evidence, and normalising is the
     *                                            classifier's job ({@see Text::fieldKey()}).
     * @param int|null              $rentCc       monthly rent charges comprises, in euros
     * @param int|null              $rentHc       monthly rent hors charges, in euros
     * @param int|null              $charges      monthly charges, in euros
     * @param float|null            $surfaceM2    living surface
     * @param int|null              $rooms        pièces (not chambres)
     * @param int|null              $bedrooms     chambres
     * @param int|null              $floor        0 = rez-de-chaussée
     * @param bool|null             $hasElevator  null = the listing did not say
     */
    public function __construct(
        public string $sourceName,
        public string $externalId,
        public string $title = '',
        public string $description = '',
        public array $fields = [],
        public ?string $url = null,
        public ?string $commune = null,
        public ?string $postcode = null,
        public ?int $rentCc = null,
        public ?int $rentHc = null,
        public ?int $charges = null,
        public ?float $surfaceM2 = null,
        public ?int $rooms = null,
        public ?int $bedrooms = null,
        public ?int $floor = null,
        public ?bool $hasElevator = null,
    ) {}

    /**
     * Rent charges comprises, derived when it was not reported directly.
     *
     * RULED 2026-08-07 (Q32, amending Q2). Q2 said only *"normalise every source to CC"*, which left
     * three distinct cases collapsing into one "unknown":
     *
     * - **`rentCc` present** — use it.
     * - **`rentHc` and `charges` both present** — CC is their sum, and it is derivable. Calling this
     *   unknown throws away a hard filter on a listing whose total is right there.
     * - **`rentHc` only** — genuinely unknown, and it must STAY unknown. A 1750 € HC flat is roughly
     *   1900 € CC, so treating HC as CC notifies it against an 1800 € ceiling it does not meet.
     *
     * Returns `null` for the last case and for a listing with no rent at all. `null` here means *the
     * ceiling cannot be applied*, never *below the ceiling* — the criteria engine must not disqualify
     * on it (hard rule 9), and says so in `reasons[]` instead.
     */
    public function effectiveRentCc(): ?int
    {
        if ($this->rentCc !== null) {
            return $this->rentCc;
        }

        if ($this->rentHc !== null && $this->charges !== null) {
            return $this->rentHc + $this->charges;
        }

        return null;
    }

    /**
     * Does this listing carry any location evidence at all?
     *
     * RULED 2026-08-07 (Q32). F4/F5/F6 each say explicitly what an unknown measurement does; F2/F3
     * said nothing, and both readings are wrong. Rejecting on unknown silently drops every listing
     * from a source whose commune selector drifted — and source health does NOT fire, because the
     * fetch succeeded and the item count is non-zero. Passing on unknown stops filtering geography
     * altogether, and Leboncoin is a national portal.
     *
     * So: a listing with neither field is rejected (location is the one criterion with no score
     * fallback), and a listing with one of the two is judged on that one.
     */
    public function hasLocationEvidence(): bool
    {
        return $this->commune !== null || $this->postcode !== null;
    }

    /**
     * Every free-text surface the classifier reads, in one string.
     *
     * Title and description are joined rather than searched separately because landlords put the
     * financing label in either one — `LLI - T3 Le Vésinet` is a real shape of title.
     */
    public function text(): string
    {
        // `_text` is the adapter's whole-card text surface (see `HtmlSource`, which writes it and
        // calls it exactly that). It belongs HERE and not in the field scan: the field path matches
        // financing acronyms case-insensitively, on the stated grounds that a field value is an
        // identifier or a short declaration rather than prose. Fed a card's prose, that rule read
        // the French adverb in `implanté au plus près` as the excluded acronym `PLUS` and vetoed the
        // listing's own tier-1 badge [measured 2026-08-20 on CDC Habitat: identical text in
        // `description` gave LLI 0.97, in `_text` UNKNOWN 0.00]. `plus` is one of the commonest
        // words in the language, so that is close to every card carrying prose.
        //
        // Joined rather than scanned separately for the same reason title and description are: a
        // label sits in whichever surface the landlord chose.
        $cardText = $this->fields['_text'] ?? null;

        return trim(
            $this->title . "\n" . $this->description
            . (is_string($cardText) && $cardText !== '' ? "\n" . $cardText : ''),
        );
    }
}
