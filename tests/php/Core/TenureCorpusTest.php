<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Classification;
use RentWatch\Core\Outcome;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\Text;
use RentWatch\Core\TenureClassifier;

/**
 * The corpus suite. `spec/PROJECT_BRIEF.md` §4: "The suite must go red if the classifier regresses."
 *
 * `CLAUDE.md`: never weaken a fixture to make a change pass. If one of these goes red, the
 * classifier regressed — fix the classifier. A skipped, xfailed, deleted or relabelled fixture is a
 * P0 finding unless the old label was demonstrably wrong AND the evidence is in the commit message.
 */
#[CoversClass(TenureClassifier::class)]
final class TenureCorpusTest extends TestCase
{
    /** @param array<string,mixed> $expect */
    #[DataProviderExternal(Corpus::class, 'provider')]
    public function testCorpusCaseClassifiesAsLabelled(
        RawListing $listing,
        SourceProfile $source,
        array $expect,
        string $why,
    ): void {
        $result = (new TenureClassifier())->classify($listing, $source);

        $context = sprintf("fixture %s\nwhy: %s\ngot: %s", $listing->externalId, $why, json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        self::assertSame(Tenure::from((string) $expect['tenure']), $result->tenure, $context);
        self::assertSame(Outcome::from((string) $expect['outcome']), $result->outcome, $context);

        if (isset($expect['min_confidence'])) {
            self::assertGreaterThanOrEqual((int) $expect['min_confidence'], $result->confidenceBp, $context);
        }

        if (isset($expect['max_confidence'])) {
            self::assertLessThanOrEqual((int) $expect['max_confidence'], $result->confidenceBp, $context);
        }
    }

    /**
     * §1 asserted against the LISTING, not against the verdict — the invariant that was missing.
     *
     * {@see testNoExcludedTenureEverReachesAMatch()} branches on `$result->tenure->isExcluded()`.
     * Every §1 breach found so far had the verdict `LLI`, so that assertion never fired: a listing
     * whose own description said `logements conventionnés` and one whose `financement` field said
     * `PLUS CD` both reached MATCH with the whole suite green, including 26/26 sabotages. §1 is
     * about what the LISTING says; that test is about what the classifier concluded.
     *
     * So: no corpus case may reach MATCH while its own text or fields name an excluded tenure —
     * unless it is on the allow-list below, which exists because a handful of fixtures deliberately
     * contain the FRENCH ADVERB `plus` and are the whole point of the collocation guard.
     */
    public function testNoListingNamingAnExcludedTenureEverReachesAMatch(): void
    {
        $exempt = self::exclusionExemptions();
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            if ($result->outcome !== Outcome::MATCH) {
                continue;
            }

            $haystack = Text::fold($listing->text() . ' ' . implode(' ', $listing->fields));

            foreach (self::excludedTokens() as $token) {
                if (isset($exempt[$id][$token])) {
                    continue;
                }

                self::assertNull(
                    Text::inflectedTokenPosition($haystack, $token),
                    sprintf(
                        "fixture %s reached MATCH while its own listing says '%s'.\n"
                        . "That is CLAUDE.md §1. If this is a legitimate exception (the French "
                        . "adverb, or conventionne qualifying an intermediate label), add it to "
                        . '$exempt as %s => [%s => reason] — do not delete the assertion.',
                        $id,
                        $token,
                        $id,
                        $token,
                    ),
                );
            }
        }
    }

    /**
     * The §1 exemption table, keyed `fixture id => token => reason`.
     *
     * KEYED BY TOKEN, NOT BY FIXTURE ALONE. A blanket per-fixture exemption switches the invariant
     * OFF for that listing entirely: a fixture excused for containing the French adverb `plus` would
     * also stop being checked for `plai`, `hlm` and every other token, so the §1 assertion could be
     * silenced on any listing by giving it one innocent reason. Naming the token keeps the rest live.
     *
     * @return array<string, array<string, string>>
     */
    private static function exclusionExemptions(): array
    {
        return [
            // The `plus` adverb traps — the guard exists precisely so these MATCH.
            'trap-001-plus-de-chambres' => ['plus' => 'the adverb in "plus de 3 chambres"'],
            'trap-002-au-plus-tard' => ['plus' => 'the adverb in "au plus tard" and "plus lumineux"'],
            'trap-003-plus-tier-uppercase-boundary' => ['plus' => 'a SHOUTED title, "PLUS DE 70 M2"'],
            'trap-003b-shouted-logement-plus-grand' => ['plus' => 'a SHOUTED "LOGEMENT PLUS GRAND"'],
            // NOTE: `trap-005-surplus-not-plus` is deliberately NOT here. It was, until the
            // earned-exemption test above rejected it: `inflectedTokenPosition` is word-bounded, so
            // the `plus` inside `surplus` never matched and the exemption never excused anything.
            // A no-op exemption is worth deleting — it reads as evidence that a real hole was
            // considered and waved through.
            'trap-005b-plus-with-no-comparative-and-no-collocation' => ['plus' => '"PLUS UN BUREAU"'],
            // The same adverb/scheme-name problem, in a STRUCTURED FIELD rather than in prose.
            'regress-027-scheme-name-in-a-field-is-not-the-scheme' => [
                'plus' => '"Pinel Plus", a 2023 scheme name that is not the PLUS financing scheme',
            ],
            'regress-028-typology-in-a-field-is-not-a-code-list' => ['plus' => '"T3 PLUS", a typology'],
            // `conventionné` QUALIFYING an explicit intermediate label — the glossary's exception.
            'lli-011-conventionne-with-intermediate-label' => [
                'conventionne' => 'CLAUDE.md glossary: conventionne qualifying an explicit '
                    . 'intermediate label is not excluded',
            ],
            'regress-030-inflected-label-then-conventionne' => [
                'conventionne' => 'the same glossary exception, reached through an INFLECTED label '
                    . '("logements locatifs intermédiaires conventionnés") — the case that pins the '
                    . 'adjacency window to the matched text rather than to the table literal',
            ],
        ];
    }

    /**
     * The literals the classifier itself treats as naming an excluded tenure.
     *
     * DERIVED FROM THE CLASSIFIER'S OWN TABLES, not hand-listed beside them. The hand-written
     * version held eight tokens while the tables held fifteen, so `pret locatif a usage social`,
     * `logement locatif social`, `habitation a loyer modere` and four more were absent from the one
     * assertion that checks §1 against the LISTING rather than against the verdict — a listing whose
     * description spelled the scheme out in full was never checked at all.
     *
     * BOTH tables, and that is not a detail: `plus` lives in `AMBIGUOUS_LABELS` alone, so a first
     * version of this method that read only `LABELS` silently dropped the single most dangerous
     * token in the set and left six now-dead `plus` exemptions behind. That is why
     * {@see testEveryExclusionExemptionIsStillEarned()} exists — a dead exemption is the visible
     * symptom of exactly this mistake.
     *
     * @return list<string>
     */
    private static function excludedTokens(): array
    {
        $class = new \ReflectionClass(TenureClassifier::class);
        $tokens = [];

        foreach (['LABELS', 'AMBIGUOUS_LABELS'] as $table) {
            /** @var array<string, Tenure> $labels */
            $labels = $class->getConstant($table);

            self::assertNotSame([], $labels, sprintf('%s is empty — §1 lost its vocabulary', $table));

            foreach ($labels as $literal => $tenure) {
                if ($tenure->isExcluded()) {
                    // Folded, because the haystack is `Text::fold()` output. `AMBIGUOUS_LABELS`
                    // keys are UPPERCASE — case is evidence in the classifier's own prose guard —
                    // and an unfolded `PLUS` would match nothing at all here.
                    $tokens[] = Text::fold($literal);
                }
            }
        }

        self::assertContains('plus', $tokens, 'the excluded-token list lost `plus` — see this method');

        return array_values(array_unique($tokens));
    }

    /**
     * Every §1 exemption must still be doing work.
     *
     * An exemption that no longer matches anything is not harmless: it is the fingerprint of the
     * assertion having stopped looking. When {@see excludedTokens()} briefly read only `LABELS`, the
     * whole `plus` vocabulary vanished from the invariant and the suite stayed green — the only
     * visible trace was six exemptions that no longer excused anything. This test turns that trace
     * into a failure.
     */
    public function testEveryExclusionExemptionIsStillEarned(): void
    {
        $classifier = new TenureClassifier();
        $tokens = self::excludedTokens();

        foreach (self::exclusionExemptions() as $id => $byToken) {
            foreach ($byToken as $token => $reason) {
                self::assertContains(
                    $token,
                    $tokens,
                    sprintf('fixture %s is exempted for "%s", which is no longer an excluded token', $id, $token),
                );

                $found = false;

                foreach (Corpus::provider() as $caseId => [$listing, $source]) {
                    if ($caseId !== $id) {
                        continue;
                    }

                    $found = true;
                    $haystack = Text::fold($listing->text() . ' ' . implode(' ', $listing->fields));

                    self::assertSame(
                        Outcome::MATCH,
                        $classifier->classify($listing, $source)->outcome,
                        sprintf('fixture %s no longer reaches MATCH, so its exemption is dead — remove it', $id),
                    );
                    self::assertNotNull(
                        Text::inflectedTokenPosition($haystack, $token),
                        sprintf('fixture %s no longer contains "%s" — remove the exemption', $id, $token),
                    );
                    self::assertNotSame('', trim($reason), sprintf('fixture %s exempts "%s" with no reason', $id, $token));
                }

                self::assertTrue($found, sprintf('exemption names fixture %s, which is not in the corpus', $id));
            }
        }
    }

    /**
     * The one rule this project exists to enforce, asserted over the whole corpus at once rather
     * than case by case — so it holds even if someone adds a fixture and forgets what it implies.
     */
    public function testNoExcludedTenureEverReachesAMatch(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            if ($result->tenure->isExcluded()) {
                self::assertSame(
                    Outcome::REJECT,
                    $result->outcome,
                    sprintf('fixture %s: %s reached %s', $id, $result->tenure->value, $result->outcome->value),
                );
            }

            self::assertNotSame(
                Outcome::MATCH,
                $result->tenure === Tenure::UNKNOWN ? $result->outcome : Outcome::DIGEST,
                sprintf('fixture %s: an undetermined tenure was routed to the notification channel', $id),
            );
        }
    }

    /**
     * The corpus knows how many of its own entries are synthetic, and the suite checks it.
     *
     * `spec/PROJECT_BRIEF.md` §4 asks for >=30 REAL listing texts. Real texts cannot be captured
     * until a source endpoint or a browser session exists. Rather than let that gap live in a
     * comment nobody re-reads, the corpus declares its own composition and this assertion keeps the
     * declaration honest: the day real payloads are frozen in, the counts have to be updated
     * deliberately.
     */
    public function testCorpusDeclaresItsOwnProvenanceHonestly(): void
    {
        $corpus = Corpus::load();

        $actual = ['synthetic' => 0, 'captured' => 0];

        foreach ($corpus['cases'] as $case) {
            /** @var array{provenance:string} $case */
            ++$actual[$case['provenance']];
        }

        self::assertSame(
            $corpus['declared_counts'],
            $actual,
            'the corpus declares a provenance mix it does not have — update declared_counts deliberately',
        );
    }

    /** `spec/PROJECT_BRIEF.md` §4 sets the floor at 30 cases. */
    public function testCorpusMeetsTheSpecifiedMinimumSize(): void
    {
        self::assertGreaterThanOrEqual(30, count(Corpus::load()['cases']));
    }

    /** Fixture ids are how a failure is identified; two cases sharing one would hide a regression. */
    public function testCorpusIdsAreUnique(): void
    {
        $ids = array_column(Corpus::load()['cases'], 'id');

        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    /**
     * The five cases `spec/PROJECT_BRIEF.md` §4 names by hand must all be present.
     *
     * Named explicitly because "30 cases" is satisfiable with 30 easy ones. These five are the
     * shapes the spec author knew were hard.
     */
    public function testCorpusCoversEveryShapeTheSpecNamed(): void
    {
        $classifier = new TenureClassifier();
        $seen = [
            'pure-LLI source' => false,
            'mixed-tenure source' => false,
            'explicit PLAI' => false,
            'explicit PLS' => false,
            'ambiguous case' => false,
        ];

        foreach (Corpus::provider() as [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            $seen['pure-LLI source'] = $seen['pure-LLI source'] || (!$source->mixedTenure && $result->tenure === Tenure::LLI);
            $seen['mixed-tenure source'] = $seen['mixed-tenure source'] || $source->mixedTenure;
            $seen['explicit PLAI'] = $seen['explicit PLAI'] || $result->tenure === Tenure::PLAI;
            $seen['explicit PLS'] = $seen['explicit PLS'] || $result->tenure === Tenure::PLS;
            $seen['ambiguous case'] = $seen['ambiguous case'] || $result->tenure === Tenure::UNKNOWN;
        }

        foreach ($seen as $shape => $covered) {
            self::assertTrue($covered, sprintf('corpus has no case covering: %s', $shape));
        }
    }

    /** Every notification carries `reasons[]` (`spec/PROJECT_BRIEF.md` §5) — so they must exist. */
    public function testEveryVerdictWithEvidenceExplainsItself(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            if ($result->signals === []) {
                continue;
            }

            self::assertNotEmpty($result->reasons(), sprintf('fixture %s produced signals but no reasons', $id));

            foreach ($result->reasons() as $reason) {
                self::assertNotSame('', trim($reason), sprintf('fixture %s produced an empty reason', $id));
            }
        }
    }

    /** The differential-test contract: the same input always yields the same bytes. */
    public function testClassificationIsDeterministic(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $first = $classifier->classify($listing, $source)->toArray();
            $second = (new TenureClassifier())->classify($listing, $source)->toArray();

            self::assertSame($first, $second, sprintf('fixture %s is not deterministic', $id));
        }
    }

    /** Confidence is an integer 0..100 internally and a 0..1 float at the boundary. */
    public function testConfidenceStaysInRange(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            self::assertGreaterThanOrEqual(0, $result->confidenceBp, $id);
            self::assertLessThanOrEqual(100, $result->confidenceBp, $id);
            // Exact, not within-epsilon: `confidence()` is defined as this division, so any
            // difference at all would mean the boundary conversion had grown logic of its own.
            // Cast because PHP's `/` returns int when the division is exact (0/100 === int 0)
            // while `confidence()` is declared `: float`.
            self::assertSame((float) ($result->confidenceBp / 100), $result->confidence(), $id);
            self::assertInstanceOf(Classification::class, $result);
        }
    }
}
