<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * Signal tier 4 — income-ceiling bands, compared against the ceiling a listing quotes.
 *
 * **ARMED 2026-08-26.** This shipped EMPTY for as long as the figures were missing, because
 * `CLAUDE.md` hard rule 1 forbids writing them from memory and invented numbers would have been
 * worse than an absent tier — it would have appeared to work while dropping eligible listings in
 * silence. They are committed now, from two dated official publications, each carried beside the
 * table it fills: see {@see LLI_2026} and {@see SOCIAL_2026}.
 *
 * **The figures then refuted the rule this rung was expected to hold**, and the narrow shape of
 * {@see classifyCeiling()} is the consequence rather than a simplification — read its docblock
 * before widening anything here. In one line: the social and intermediate bands OVERLAP, so a bare
 * quoted figure supports exactly one conclusion, in one direction, and the tier says nothing at all
 * the rest of the time.
 *
 * The table stays injectable, and an empty one still disarms the tier completely — which is what the
 * surface-matrix and differential suites use to isolate the other rungs.
 */
final readonly class PlafondBands
{
    /**
     * Intermediate-housing tenant income ceilings, metropolitan France, 2026.
     *
     * Source: BOFiP **BOI-BAREME-000017**, published 2026-03-10, ceilings set by CGI annexe III
     * art. 2 terdecies H. https://bofip.impots.gouv.fr/bofip/10130-PGP.html — cross-checked against
     * a second publication of the same table before being committed. Revenu fiscal de référence
     * N-2, so 2024 income for a 2026 lease.
     *
     * Only zones A bis / A / B1 are carried: LLI exists only in those (ordonnance 2014-159), which
     * `CLAUDE.md`'s tenure glossary already states as a ruling. B2/C exist in the barème and are
     * deliberately absent — including them would drop the threshold to 32 530 € and lose the
     * PLS-one-person case (34 996 €) for zones where LLI cannot be let anyway.
     *
     * Index is household size minus one: [1 personne, couple, +1 à charge, +2, +3, +4].
     */
    public const array LLI_2026 = [
        'A bis' => [44344, 66276, 86878, 103727, 123415, 138874],
        'A' => [44344, 66276, 79666, 95427, 112968, 127122],
        'B1' => [36144, 48268, 58043, 70073, 82432, 92900],
    ];

    /**
     * Social-housing income ceilings for Île-de-France, 2026.
     *
     * Source: DRIHL Île-de-France, *"Annexe 4 : grille des plafonds de ressources 2026"*, set by the
     * arrêté du 19 décembre 2025, publié au Journal officiel le 24 décembre 2025 ; revenu fiscal 2024.
     * https://www.drihl.ile-de-france.developpement-durable.gouv.fr/IMG/pdf/annexe_4.pdf
     *
     * **Not read by {@see classifyCeiling()} — committed because it is what PROVES the threshold.**
     * Without it the boundary looks like an arbitrary number instead of the measured edge of an
     * overlap. Its zoning is also not the intermediate zoning: social housing splits Île-de-France
     * into *Paris et communes limitrophes* and *the rest*, where LLI uses A bis / A / B1. Two
     * regimes, two incompatible maps — a second reason a bare figure cannot be placed.
     *
     * Same index convention as {@see LLI_2026}.
     */
    public const array SOCIAL_2026 = [
        'Paris' => [
            'PLAI' => [14811, 24140, 31643, 34637, 41203, 46369],
            'PLUS' => [26920, 40233, 52740, 62968, 74919, 84304],
            'PLS' => [34996, 52303, 68562, 81858, 97395, 109595],
        ],
        'IdF hors Paris' => [
            'PLAI' => [14811, 24140, 29018, 31860, 37719, 42444],
            'PLUS' => [26920, 40233, 48362, 57930, 68577, 77171],
            'PLS' => [34996, 52303, 62871, 75309, 89150, 100322],
        ],
    ];

    /**
     * The zone key used when a listing's zone is unknown — which, today, is always.
     *
     * Nothing maps a commune to A bis / A / B1 yet, so the tier asks for the safest threshold across
     * every Île-de-France zone. Keeping the per-zone table anyway means the finer rule arms itself
     * for free the day such a map exists.
     */
    public const string IDF = 'IDF';

    /**
     * @param array<string, array{max: int, tenure: Tenure}> $bands keyed by zone code
     *
     * @throws \InvalidArgumentException if any band asserts an intermediate tenure — see below
     */
    public function __construct(public array $bands = [])
    {
        foreach ($this->bands as $zone => $band) {
            if ($band['tenure'] !== Tenure::SOCIAL) {
                // §1, STRUCTURALLY. This tier answers SOCIAL or nothing. Reading a NUMBER as proof
                // that a listing is ELIGIBLE is the dangerous direction — and the measured overlap
                // (see `ileDeFrance2026()`) means such a reading would be wrong across a 73 451 €
                // range. Refused at construction so it cannot be reintroduced by editing a table.
                throw new \InvalidArgumentException(
                    'tier 4 ne peut conclure qu\'à SOCIAL ou à rien : la zone `' . $zone
                    . '` prétend établir ' . $band['tenure']->name . ' à partir d\'un simple montant',
                );
            }
        }
    }

    /**
     * The one rule the real figures support, for Île-de-France in 2026.
     *
     * **THE OBVIOUS RULE DOES NOT WORK, and the measurement is why this one is so narrow.** The
     * assumption everyone starts from — at or below the highest social ceiling means social, above
     * it means intermediate — fails twice against the committed tables:
     *
     * - **Even at the same household size**, zone B1's intermediate ceilings sit BELOW the Paris PLS
     *   ceilings for every size from two upward (B1 couple 48 268 € against PLS 52 303 €). Only
     *   13 of the 18 (zone, size) pairs separate at all.
     * - **A listing quotes a bare figure with no household size**, so the sizes must be collapsed —
     *   and then the bands overlap from 36 144 € (B1, one person) to 109 595 € (PLS Paris, six),
     *   a 73 451 € range in which a figure could belong to either regime.
     *
     * Under the assumed rule every genuine intermediate ceiling would read SOCIAL. That is not a §1
     * breach — over-rejecting is the safe direction — but it is the tool switched off on the source
     * producing most matches, and zone B1 is exactly where the current matches are. It is also the
     * numeric echo of a lesson {@see TenureClassifier} already learned in the vocabulary domain:
     * `plafond de ressources` was rejected as a text signal because *LLI has income ceilings too*.
     *
     * So: **strictly below the lowest intermediate ceiling in the zone, a figure cannot be an
     * intermediate ceiling** — that is the whole verdict. Above it, nothing: never an intermediate
     * verdict (the §1-dangerous direction), and never a doubt either, because a numeric doubt would
     * contradict a correct tier-2 label into the digest exactly as `loyer plafonné` did to `lli-004`
     * and `lli-011`.
     *
     * **Stated cost:** this catches social listings quoting small-household ceilings without naming
     * their scheme — PLAI at every size, PLUS for one to two people, PLS for one. It cannot catch a
     * large-household social ceiling, and that is a property of the French figures rather than of
     * this implementation.
     */
    public static function ileDeFrance2026(): self
    {
        $bands = [];

        foreach (self::LLI_2026 as $zone => $ceilings) {
            $bands[$zone] = ['max' => min($ceilings), 'tenure' => Tenure::SOCIAL];
        }

        // DERIVED, never written. A literal here and the committed table drift apart at the next
        // January revaluation, and the tier would keep applying last year's boundary while the
        // figures beside it said otherwise.
        $bands[self::IDF] = [
            'max' => min(array_map(min(...), self::LLI_2026)),
            'tenure' => Tenure::SOCIAL,
        ];

        return new self($bands);
    }

    public function isEmpty(): bool
    {
        return $this->bands === [];
    }

    /**
     * Which tenure does an annual income ceiling of `$ceilingEur` in `$zone` indicate?
     *
     * `null` whenever nothing can be concluded: an unknown zone, an empty table, or — the ordinary
     * case — a figure inside the overlap {@see ileDeFrance2026()} measures.
     *
     * **The comparison is STRICT, and it shipped as `<=`.** The threshold is itself an intermediate
     * ceiling (36 144 € is zone B1's one-person figure), so `<=` would read a genuine LLI listing
     * quoting its own ceiling as social — precisely the false positive this tier must never produce.
     */
    public function classifyCeiling(int $ceilingEur, string $zone): ?Tenure
    {
        $band = $this->bands[$zone] ?? null;

        if ($band === null) {
            return null;
        }

        return $ceilingEur < $band['max'] ? $band['tenure'] : null;
    }
}
