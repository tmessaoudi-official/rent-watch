<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Outcome;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;

/**
 * THE SURFACE MATRIX — every excluded vocabulary item, on every surface a listing has.
 *
 * WHY THIS FILE EXISTS, stated plainly because it is a finding about how this module was built:
 * seven consecutive review rounds each found a P0, and every one had the same shape — a correct
 * rule applied to only a SUBSET of the surfaces it belongs on. Round 4 fixed the collocation
 * fail-open for `pls` and left `PLUS`; round 5 gave the doubt floor to structured fields and left
 * prose; round 5 ruled a newline is a phrase boundary and wired it into one of five rules that
 * consume whitespace adjacency; round 6 taught the §1 invariant the procedural literals on the
 * surface that was already safe; round 7 closed unrecognised fields for `PLAI` and left `PLUS`,
 * because the helper read `LABELS` and `PLUS` lives in `AMBIGUOUS_LABELS`.
 *
 * Every one of those was found by a human-equivalent reviewer reading code, and none by the suite.
 * The corpus could not find them: it is a list of listings someone thought to write, so it can only
 * cover the cells someone thought of. A per-fixture corpus is the wrong shape for a "did this rule
 * reach that surface" question.
 *
 * So this test does not ask what a listing says. It takes the CROSS PRODUCT of the classifier's own
 * excluded vocabulary — read out of its tables by reflection, so a literal added tomorrow joins
 * automatically — and every surface a `RawListing` presents, and asserts the one rule that matters
 * on each cell. An empty cell is a failing test, not a review finding.
 *
 * The source is deliberately the WORST case: pure (so the mixed-tenure fail-closed rule cannot
 * rescue anything) and declaring LLI (so the tier-5 default actively pushes toward MATCH). That is
 * In'li, a real configured source, and it is the shape every §1 breach of rounds 4–7 was
 * demonstrated on.
 */
#[CoversClass(TenureClassifier::class)]
final class SurfaceMatrixTest extends TestCase
{
    /**
     * No excluded vocabulary item, on any surface, may reach `MATCH`.
     *
     * @param callable(string): RawListing $build
     */
    #[DataProvider('excludedTokenOnEverySurface')]
    public function testNoExcludedTokenReachesMatchOnAnySurface(string $token, string $surface, callable $build): void
    {
        $result = (new TenureClassifier())->classify($build($token), self::worstCaseSource());

        self::assertNotSame(
            Outcome::MATCH,
            $result->outcome,
            sprintf(
                "\"%s\" on the %s surface reached MATCH.\n"
                . "That is CLAUDE.md §1: the listing names an excluded tenure and was notified.\n"
                . "reasons[]: %s\n"
                . 'Fix the rule so it reads this surface — do not narrow this matrix.',
                $token,
                $surface,
                implode(' | ', $result->reasons()) ?: '(none — the surface is not read at all)',
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string, callable(string): RawListing}>
     */
    public static function excludedTokenOnEverySurface(): iterable
    {
        foreach (self::excludedVocabulary() as $token) {
            foreach (self::surfaces() as $surface => $build) {
                yield sprintf('%s on %s', $token, $surface) => [$token, $surface, $build];
            }
        }
    }

    /**
     * THE COUNTERWEIGHT, and it is not symmetry for its own sake.
     *
     * Every other corpus-level invariant in this repo is one-directional: they all assert that
     * MATCH is not reached. A review pointed out what that costs — across three commits the corpus
     * went MATCH 33.3% → 30.1% → 28.0%, every relabel ran MATCH→DIGEST and none ever ran the other
     * way, and nothing could have noticed if the classifier had simply started rejecting
     * everything. A classifier that digests the whole market looks exactly like a quiet market
     * (`CLAUDE.md` hard rule 8), and the cheapest way to make any change pass a one-directional
     * suite is to move a fixture to DIGEST.
     *
     * So: an ordinary eligible listing must still MATCH with the excluded token nowhere in sight,
     * on every one of the same surfaces. This is the assertion that makes over-rejection visible.
     *
     * @param callable(string): RawListing $build
     */
    #[DataProvider('everySurface')]
    public function testAnOrdinaryEligibleListingStillMatchesOnEverySurface(string $surface, callable $build): void
    {
        $result = (new TenureClassifier())->classify($build('residence calme'), self::worstCaseSource());

        self::assertSame(
            Outcome::MATCH,
            $result->outcome,
            sprintf(
                "an ordinary listing with NO excluded vocabulary stopped matching on the %s surface.\n"
                . "reasons[]: %s\n"
                . 'This is over-rejection: nothing arrives, which is indistinguishable from a quiet '
                . 'market. Fix the rule — moving this to DIGEST would delete the only assertion in '
                . 'the suite that points this direction.',
                $surface,
                implode(' | ', $result->reasons()) ?: '(none)',
            ),
        );
    }

    /**
     * The counterweight runs over every surface EXCEPT the two that are doubts by construction.
     *
     * A non-scalar field value is unreadable whatever it contains, so digesting it is the rule
     * working, not over-rejection — `CLAUDE.md` hard rule 3, a breakage is never an absence. Every
     * other surface must still be able to produce a match. Exclusion by name rather than by a flag
     * on the surface list, so that adding a surface opts INTO the counterweight by default: the
     * failure mode this whole file exists for is a check that quietly covers less than it looks.
     *
     * @return iterable<string, array{string, callable(string): RawListing}>
     */
    public static function everySurface(): iterable
    {
        $unreadableByConstruction = ['non-scalar field value', 'non-scalar recognised field'];

        foreach (self::surfaces() as $surface => $build) {
            if (in_array($surface, $unreadableByConstruction, true)) {
                continue;
            }

            yield $surface => [$surface, $build];
        }
    }

    /**
     * Every surface a `RawListing` presents to the classifier.
     *
     * Adding a surface here is how a future reader makes the matrix cover new ground; the point is
     * that the list lives in ONE place instead of being re-derived by whoever writes the next rule.
     *
     * @return array<string, callable(string): RawListing>
     */
    private static function surfaces(): array
    {
        return [
            'title' => static fn (string $t): RawListing => self::listing(title: 'T3 Le Vesinet ' . $t),
            'description' => static fn (string $t): RawListing => self::listing(description: 'Bel appartement. ' . $t . '.'),
            // The join is its own surface: folding preserves the newline as a phrase boundary, and
            // four separate rules have to agree about that.
            'title/description join' => static fn (string $t): RawListing => self::listing(
                title: 'T3 Le Vesinet ' . $t,
                description: 'Grand sejour, proche RER A.',
            ),
            'recognised tenure field' => static fn (string $t): RawListing => self::listing(fields: ['financement' => $t]),
            // `TENURE_FIELDS` is an exact-match list, so a feed spelling a key any other way lands
            // here. This is the cell round 7 found open for `PLUS`.
            'unrecognised field value' => static fn (string $t): RawListing => self::listing(fields: ['gamme' => $t]),
            'unrecognised field value, in a phrase' => static fn (string $t): RawListing => self::listing(
                fields: ['programme' => 'Programme neuf ' . $t . ' livre en 2025'],
            ),
            // A field NAME carries evidence too: `numeroUnique` and `demandeLogementSocial` are
            // ordinary bailleur-social JSON keys and both are procedural literals.
            'field name' => static fn (string $t): RawListing => self::listing(fields: [$t => 'oui']),
            'multi-line field value' => static fn (string $t): RawListing => self::listing(
                fields: ['categorie' => "Residence Les Tilleuls\n" . $t . "\n2025"],
            ),
            // Annotated `array<string,string>`; an annotation is not a runtime guarantee, and a JSON
            // feed decodes a repeated key to a list. Round 7 found this silently dropped.
            'non-scalar field value' => static fn (string $t): RawListing => self::listing(fields: ['gamme' => [$t]]),
            'non-scalar recognised field' => static fn (string $t): RawListing => self::listing(fields: ['financement' => [$t]]),
        ];
    }

    /**
     * The excluded vocabulary, read from the classifier's own tables.
     *
     * ALL THREE TABLES. Reading only `LABELS` is exactly the round-7 P0 — `PLUS` is the sole entry
     * of `AMBIGUOUS_LABELS`, and it is the acronym this whole bug class has been about. `PROCEDURAL`
     * contributes the social tells, filtered to the excluded ones so that `attribution directe`
     * (intermediate) does not end up asserted as if it were social.
     *
     * @return list<string>
     */
    private static function excludedVocabulary(): array
    {
        $class = new \ReflectionClass(TenureClassifier::class);
        $tokens = [];

        foreach (['LABELS', 'AMBIGUOUS_LABELS', 'PROCEDURAL'] as $table) {
            /** @var array<string, Tenure> $entries */
            $entries = $class->getConstant($table);

            self::assertNotSame([], $entries, sprintf('%s is empty — §1 lost its vocabulary', $table));

            foreach ($entries as $literal => $tenure) {
                if ($tenure->isExcluded()) {
                    $tokens[] = $literal;
                }
            }
        }

        self::assertContains('plus', array_map('strtolower', $tokens), 'the matrix lost `plus` — see this method');
        self::assertGreaterThan(15, count($tokens), 'the excluded vocabulary shrank unexpectedly');

        return array_values(array_unique($tokens));
    }

    /**
     * Pure AND declaring LLI — the worst case, not a neutral one.
     *
     * Pure means `mixedTenure: false`, so the fail-closed downgrade cannot rescue a bad verdict.
     * `defaultTenure: LLI` means tier 5 actively pushes toward an eligible answer. Every §1 breach
     * demonstrated in rounds 4 through 7 was reproduced on exactly this profile, and it is a real
     * configured source (In'li) rather than a contrived one.
     */
    private static function worstCaseSource(): SourceProfile
    {
        return new SourceProfile(name: 'inli', family: 'institutional', defaultTenure: Tenure::LLI, mixedTenure: false);
    }

    /** @param array<string, mixed> $fields */
    private static function listing(string $title = 'T3 Le Vesinet', string $description = 'Bel appartement lumineux.', array $fields = []): RawListing
    {
        /** @var array<string, string> $fields intentionally violated for the non-scalar surfaces */
        return new RawListing(
            sourceName: 'inli',
            externalId: 'matrix',
            title: $title,
            description: $description,
            fields: $fields,
        );
    }
}
