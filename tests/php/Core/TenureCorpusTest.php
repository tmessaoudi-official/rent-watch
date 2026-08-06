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
