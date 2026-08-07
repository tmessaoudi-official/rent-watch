<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * One piece of evidence that fired, with enough context to argue about it later.
 *
 * Signals are not just an implementation detail of the confidence arithmetic — they become the
 * `reasons[]` that ride along with every notification (`spec/PROJECT_BRIEF.md` §5). A notification
 * that says "LLI, 0.9" is not actionable; one that says "structured field financement = LLI" lets
 * the developer decide whether to trust it in three seconds.
 */
final class TenureSignal
{
    /**
     * @param int    $tier     1 = structured field, 2 = explicit label, 3 = procedural tell,
     *                         4 = plafonds band, 5 = source default. Lower number wins.
     * @param string $evidence the literal that matched, or the field name and value
     * @param int    $position byte offset of the match within the searched text. Ties inside a tier
     *                         are broken on this, so the verdict does not depend on the iteration
     *                         order of a pattern table — which is what makes the PHP and phorj
     *                         implementations comparable fixture-by-fixture.
     */
    public function __construct(
        public int $tier,
        public Tenure $tenure,
        public string $reason,
        public string $evidence,
        public int $position = 0,
        /**
         * Byte length of the text that ACTUALLY matched, which is not `strlen($evidence)` once
         * inflection is in play: the literal `logement locatif intermediaire` is 30 bytes and the
         * matched `logements locatifs intermediaires` is 33. A rule that measured the literal
         * mis-placed the end of the span by exactly the inflection, which is how the ordinary
         * French plural broke the `conventionné` adjacency rule. Defaults to the evidence length
         * for signals with no matched text of their own (source defaults, conflict markers).
         */
        public int $length = 0,
    ) {
        if ($this->length === 0) {
            $this->length = strlen($this->evidence);
        }
    }
}
