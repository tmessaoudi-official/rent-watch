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
     * Every free-text surface the classifier reads, in one string.
     *
     * Title and description are joined rather than searched separately because landlords put the
     * financing label in either one — `LLI - T3 Le Vésinet` is a real shape of title.
     */
    public function text(): string
    {
        return trim($this->title . "\n" . $this->description);
    }
}
