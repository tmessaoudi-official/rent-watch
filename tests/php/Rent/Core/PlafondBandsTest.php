<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\PlafondBands;
use Scout\Rent\Core\Tenure;

/**
 * Signal tier 4 — the income-ceiling band, and the measurement that decided its shape.
 *
 * The tier was scaffolded empty because the figures were missing and `CLAUDE.md` hard rule 1 forbids
 * writing them from memory. They are now committed, from two dated official publications, and the
 * first thing they did was REFUTE the rule everyone assumed would be built.
 *
 * The assumption was: a quoted ceiling at or below the highest social ceiling means social, above it
 * means intermediate. Measured against the real tables that fails twice over.
 *
 * **Even at the SAME household size, LLI zone B1 sits BELOW PLS for every size from two upward** —
 * B1 two-person 48 268 € against PLS Paris 52 303 €, and the gap widens with size. Only 13 of the 18
 * (zone, size) pairs separate at all. And a listing quotes a bare figure with NO household size, so
 * collapsing the sizes is forced — at which point the bands overlap across a 73 451 € range, from
 * the lowest intermediate ceiling (B1, one person, 36 144 €) to the highest social one (PLS Paris,
 * six people, 109 595 €). Under the assumed rule every genuine LLI ceiling reads SOCIAL.
 *
 * That is not a §1 breach — over-rejecting is the safe direction — but it is the tool switched off
 * on the source that produces most of the matches, and zone B1 is precisely where the current
 * matches are (Dourdan, Dammarie-les-Lys). It is also the numeric echo of a lesson
 * `TenureClassifier` already learned in the vocabulary domain: `plafond de ressources` was rejected
 * as a text signal because *LLI has income ceilings too*. The figures say the same thing.
 *
 * **What survives is one direction only:** strictly below the lowest intermediate ceiling in
 * Île-de-France, a figure cannot be an intermediate ceiling, so it indicates social. Above that,
 * nothing is emitted — never an intermediate verdict, because manufacturing eligibility from a
 * number is the §1-dangerous direction, and never a doubt, because a numeric doubt would contradict
 * a correct tier-2 label into the digest exactly as `loyer plafonné` did to `lli-004` and `lli-011`.
 */
#[CoversClass(PlafondBands::class)]
final class PlafondBandsTest extends TestCase
{
    // ── the committed figures ────────────────────────────────────────────────────────────────────

    public function testTheIntermediateTableMatchesTheOfficialBareme(): void
    {
        // BOFiP BOI-BAREME-000017, published 2026-03-10; ceilings set by CGI annexe III
        // art. 2 terdecies H. Spot-checked at both ends of every zone, because a transcription slip
        // in the MINIMUM silently moves the only threshold this class computes.
        self::assertSame(44344, PlafondBands::LLI_2026['A bis'][0]);
        self::assertSame(138874, PlafondBands::LLI_2026['A bis'][5]);
        self::assertSame(44344, PlafondBands::LLI_2026['A'][0]);
        self::assertSame(127122, PlafondBands::LLI_2026['A'][5]);
        self::assertSame(36144, PlafondBands::LLI_2026['B1'][0]);
        self::assertSame(92900, PlafondBands::LLI_2026['B1'][5]);
    }

    public function testTheSocialTableMatchesTheOfficialGrid(): void
    {
        // DRIHL "Annexe 4 : grille des plafonds de ressources 2026", arrêté du 19 décembre 2025
        // (JO 24 décembre 2025), revenu fiscal de référence 2024. Committed because it is what
        // PROVES the overlap below — without it the threshold looks like an arbitrary number.
        self::assertSame(14811, PlafondBands::SOCIAL_2026['Paris']['PLAI'][0]);
        self::assertSame(26920, PlafondBands::SOCIAL_2026['Paris']['PLUS'][0]);
        self::assertSame(34996, PlafondBands::SOCIAL_2026['Paris']['PLS'][0]);
        self::assertSame(109595, PlafondBands::SOCIAL_2026['Paris']['PLS'][5]);
        self::assertSame(100322, PlafondBands::SOCIAL_2026['IdF hors Paris']['PLS'][5]);
    }

    public function testTheBandsOverlapSoOnlyOneDirectionIsDerivable(): void
    {
        // THE MEASUREMENT, asserted as data rather than left in prose — a number in a docblock
        // drifts and nothing notices. If a future revaluation ever separates these bands, this test
        // goes red and somebody gets to widen the tier deliberately.
        $lowestIntermediate = min(array_map(min(...), PlafondBands::LLI_2026));
        $highestSocial = max(array_map(
            static fn (array $z): int => max($z['PLS']),
            PlafondBands::SOCIAL_2026,
        ));

        self::assertSame(36144, $lowestIntermediate);
        self::assertSame(109595, $highestSocial);
        self::assertGreaterThan(
            $lowestIntermediate,
            $highestSocial,
            'the bands overlap: a bare figure between them could be either regime, so only BELOW the '
            . 'lowest intermediate ceiling is a verdict derivable',
        );

        // And the same-size comparison, which is the half that surprises: zone B1's intermediate
        // ceilings sit BELOW the Paris PLS ceilings for every household size from two upward.
        for ($size = 1; $size < 6; $size++) {
            self::assertLessThan(
                PlafondBands::SOCIAL_2026['Paris']['PLS'][$size],
                PlafondBands::LLI_2026['B1'][$size],
                "zone B1 intermediate at size " . ($size + 1) . ' is below Paris PLS — not separable even with the household size known',
            );
        }
    }

    // ── the threshold ────────────────────────────────────────────────────────────────────────────

    public function testTheThresholdIsDerivedFromTheCommittedTableNotWritten(): void
    {
        // Otherwise the constant and the data drift apart at next January's revaluation, and the
        // tier keeps applying last year's boundary while the committed table says otherwise.
        $bands = PlafondBands::ileDeFrance2026();

        foreach (PlafondBands::LLI_2026 as $zone => $ceilings) {
            self::assertSame(min($ceilings), $bands->bands[$zone]['max'], "zone {$zone}");
        }

        self::assertSame(
            min(array_map(min(...), PlafondBands::LLI_2026)),
            $bands->bands[PlafondBands::IDF]['max'],
            'the unknown-zone threshold is the safest one across every Île-de-France zone',
        );
    }

    public function testTheBoundaryIsStrictBecauseTheBoundaryItselfIsAnIntermediateCeiling(): void
    {
        // 36 144 € IS zone B1's one-person intermediate ceiling. `<=` would read a genuine LLI
        // listing quoting its own ceiling as social — the exact false positive this tier must never
        // produce, and the scaffolding shipped with `<=`.
        $bands = PlafondBands::ileDeFrance2026();

        self::assertNull($bands->classifyCeiling(36144, PlafondBands::IDF), 'the boundary is an INTERMEDIATE ceiling');
        self::assertSame(Tenure::SOCIAL, $bands->classifyCeiling(36143, PlafondBands::IDF));
    }

    #[DataProvider('realCeilings')]
    public function testRealPublishedCeilingsLandWhereTheyShould(int $ceiling, ?Tenure $expected, string $why): void
    {
        self::assertSame($expected, PlafondBands::ileDeFrance2026()->classifyCeiling($ceiling, PlafondBands::IDF), $why);
    }

    /** @return iterable<string, array{int, ?Tenure, string}> */
    public static function realCeilings(): iterable
    {
        yield 'PLAI Paris, one person' => [14811, Tenure::SOCIAL, 'well below every intermediate ceiling'];
        yield 'PLUS Paris, one person' => [26920, Tenure::SOCIAL, 'below every intermediate ceiling'];
        yield 'PLS Paris, one person' => [34996, Tenure::SOCIAL, 'the highest social ceiling still under the boundary'];

        // Everything from here up is INSIDE the overlap and must stay silent, whichever regime it
        // actually belongs to. Each of these is a real published figure.
        yield 'LLI B1, one person' => [36144, null, 'an intermediate ceiling — a verdict here is the false positive'];
        yield 'PLUS IdF, three persons' => [48362, null, 'genuinely social, and indistinguishable from LLI B1 two-person'];
        yield 'LLI A bis, one person' => [44344, null, 'intermediate, inside the overlap'];
        yield 'PLS Paris, six persons' => [109595, null, 'social, but above intermediate ceilings for small households'];
        yield 'LLI A bis, six persons' => [138874, null, 'above every social ceiling for six — but social is unbounded upward'];
    }

    // ── §1: the tier may never manufacture eligibility ───────────────────────────────────────────

    public function testABandMayNeverAssertAnIntermediateTenure(): void
    {
        // STRUCTURAL, not a convention. Tier 4 answers SOCIAL or nothing: reading a NUMBER as proof
        // that a listing is eligible is the §1-dangerous direction, and the overlap above means such
        // a reading would be wrong across a 73 451 € range. Refused at construction so it cannot be
        // reintroduced by editing a table.
        $this->expectException(\InvalidArgumentException::class);
        new PlafondBands(['A' => ['max' => 50000, 'tenure' => Tenure::LLI]]);
    }

    public function testTheShippedBandsOnlyEverSaySocial(): void
    {
        foreach (PlafondBands::ileDeFrance2026()->bands as $zone => $band) {
            self::assertSame(Tenure::SOCIAL, $band['tenure'], "zone {$zone}");
        }
    }

    public function testAnEmptyTableStaysInertAndAnUnknownZoneSaysNothing(): void
    {
        self::assertTrue((new PlafondBands())->isEmpty());
        self::assertNull((new PlafondBands())->classifyCeiling(1000, PlafondBands::IDF));
        self::assertFalse(PlafondBands::ileDeFrance2026()->isEmpty());
        self::assertNull(PlafondBands::ileDeFrance2026()->classifyCeiling(1000, 'Zone Z'));
    }
}
