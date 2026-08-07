<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Outcome;
use RentWatch\Core\PlafondBands;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;

/**
 * Structural invariants — the properties that must hold for EVERY input, not just the corpus ones.
 *
 * The corpus proves the classifier gets every case in `tests/fixtures/tenure/corpus.json` right. This file proves the shape of
 * the module cannot drift: that the excluded set is fixed in code, that the floor is where the
 * rule says, that an unlabelled source fails closed, and that tier 4 is still honestly inert.
 */
#[CoversClass(TenureClassifier::class)]
#[CoversClass(Tenure::class)]
final class TenureClassifierTest extends TestCase
{
    private const string MIXED = 'cdc_habitat';

    /** `CLAUDE.md` §1: the excluded set is exactly this, and it is not extensible. */
    public function testExcludedSetIsExactlyTheDocumentedOne(): void
    {
        $excluded = array_values(array_map(
            static fn (Tenure $t): string => $t->value,
            array_filter(Tenure::cases(), static fn (Tenure $t): bool => $t->isExcluded()),
        ));

        sort($excluded);

        self::assertSame(
            ['ANAH', 'ANRU', 'CONVENTIONNE', 'PLAI', 'PLS', 'PLUS', 'SOCIAL'],
            $excluded,
        );
    }

    /** Eligible and excluded must partition every case except the deliberate abstention. */
    public function testEveryTenureIsEligibleOrExcludedOrExplicitlyUndetermined(): void
    {
        foreach (Tenure::cases() as $tenure) {
            if ($tenure === Tenure::UNKNOWN) {
                self::assertFalse($tenure->isEligible());
                self::assertFalse($tenure->isExcluded());

                continue;
            }

            self::assertNotSame(
                $tenure->isEligible(),
                $tenure->isExcluded(),
                sprintf('%s is both or neither', $tenure->value),
            );
        }
    }

    /**
     * The classifier takes no argument that could re-admit an excluded tenure.
     *
     * `CLAUDE.md` §1 calls a config key that re-enables the excluded set a P0 finding "even if
     * nothing currently sets it". The only injectable collaborator is the plafond band table, and
     * this asserts it cannot reach {@see Tenure::isExcluded()}.
     */
    public function testExcludedSetIsNotReachableThroughAnyConstructorArgument(): void
    {
        $constructor = (new \ReflectionClass(TenureClassifier::class))->getConstructor();

        self::assertNotNull($constructor);

        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        self::assertSame(['bands'], $names);

        // And the excluded set survives a band table that tries to claim PLAI is fine.
        $classifier = new TenureClassifier(new PlafondBands(['A' => ['max' => 999_999, 'tenure' => Tenure::LLI]]));
        $result = $classifier->classify($this->listing(fields: ['financement' => 'PLAI']), $this->source());

        self::assertSame(Tenure::PLAI, $result->tenure);
        self::assertSame(Outcome::REJECT, $result->outcome);
    }

    /** The documented floor, asserted as a number so a silent edit is caught. */
    public function testFailClosedFloorIsSixtyPoints(): void
    {
        self::assertSame(60, TenureClassifier::FLOOR_BP);
    }

    /**
     * A source declared without saying whether it mixes stock must behave as though it does.
     *
     * This is the config-omission case: someone adds a landlord to `config/sources.yaml`, forgets
     * the flag, and the §1 protection must engage anyway.
     */
    public function testAnUndeclaredSourceFailsClosed(): void
    {
        $bare = new SourceProfile(name: 'newly_added_landlord');

        self::assertTrue($bare->mixedTenure);

        $result = (new TenureClassifier())->classify($this->listing(description: 'Bel appartement.'), $bare);

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame(Outcome::DIGEST, $result->outcome);
    }

    /**
     * Tier 4 is still inert. When someone loads real ceiling figures, THIS test tells them the rung
     * woke up and needs its own fixtures — see {@see PlafondBands}.
     */
    public function testPlafondTierIsInertUntilRealBandsAreSourced(): void
    {
        self::assertTrue(
            (new TenureClassifier())->plafondBands()->isEmpty(),
            'plafond bands are no longer empty: tier 4 now fires, and needs corpus coverage of its own',
        );
    }

    /**
     * The source default must stay below the floor on its own.
     *
     * `CLAUDE.md`: "An absent signal must lower confidence, never silently inherit `default_tenure`
     * at full confidence." Asserted for every tenure a source could declare.
     *
     * @return iterable<string, array{Tenure}>
     */
    public static function everyTenure(): iterable
    {
        foreach (Tenure::cases() as $tenure) {
            yield $tenure->value => [$tenure];
        }
    }

    #[DataProvider('everyTenure')]
    public function testSourceDefaultAloneStaysBelowTheFloor(Tenure $declared): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Appartement 3 pieces, 65 m2.'),
            new SourceProfile(name: 'x', defaultTenure: $declared, mixedTenure: true),
        );

        self::assertLessThan(TenureClassifier::FLOOR_BP, $result->confidenceBp);
        self::assertNotSame(Outcome::MATCH, $result->outcome);
    }

    /**
     * The asymmetry of the conflict rule, stated as a property.
     *
     * An eligible verdict contradicted by an excluded signal withholds; an excluded verdict
     * contradicted by an eligible signal does not soften. Both directions asserted together so a
     * future "let's make this symmetric for consistency" refactor goes red.
     */
    public function testConflictRuleOnlyEverMovesTowardWithholding(): void
    {
        $classifier = new TenureClassifier();

        $eligibleContradicted = $classifier->classify(
            $this->listing(description: 'Logement PLAI.', fields: ['financement' => 'LLI']),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $eligibleContradicted->tenure);
        self::assertSame(Outcome::DIGEST, $eligibleContradicted->outcome);

        $excludedContradicted = $classifier->classify(
            $this->listing(description: 'Logement intermediaire.', fields: ['financement' => 'PLAI']),
            $this->source(),
        );

        self::assertSame(Tenure::PLAI, $excludedContradicted->tenure);
        self::assertSame(Outcome::REJECT, $excludedContradicted->outcome);
    }

    /** A higher tier decides; a lower one may only move confidence. */
    public function testLowerTierNeverOverridesHigherTier(): void
    {
        $result = (new TenureClassifier())->classify(
            // Tier 2 says LIBRE; the source default (tier 5) says LLI and must not win.
            $this->listing(description: 'Logement a loyer libre.'),
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        self::assertSame(Tenure::LIBRE, $result->tenure);
    }

    /** Tier 5 is consulted only when nothing else fires. */
    public function testSourceDefaultIsSilentWhenRealEvidenceExists(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Logement a loyer libre.'),
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        $tiers = array_map(static fn ($s): int => $s->tier, $result->signals);

        self::assertNotContains(5, $tiers);
    }

    /** An empty structured field is an absent signal, not a verdict. */
    public function testEmptyStructuredFieldDoesNotFireTierOne(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Appartement.', fields: ['financement' => '   ']),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame([], $result->signals);
    }

    /**
     * A financing CODE in a tenure field is read as a code whatever its case; PROSE is read as prose.
     *
     * The predecessor of this test used CASE as the whole discriminator — an uppercase `PLUS` in a
     * tenure field was a code, a lowercase one was a word. Both halves were wrong. A feed that
     * lowercases its own field values (`financement: plus`) emitted NO signal and NO doubt, and the
     * listing then matched on its description: the strongest rung of the ladder, silent, on an
     * explicitly social financing code. In the other direction `PINEL PLUS` — a real 2023 scheme,
     * routinely shouted in a `categorie` field — became tenure PLUS at confidence 97 and a silent
     * REJECT of a listing that has nothing to do with social housing.
     *
     * The discriminator is the SHAPE of the value, not its case: a value made only of financing
     * acronyms and short uppercase code fragments is a code list; anything carrying a real French
     * word is prose, and prose goes through the same collocation guard the description does.
     *
     * @param array{0:string,1:?Tenure} $expect tenure, or null for "no tier-1 signal at all"
     */
    #[DataProvider('fieldValueShapes')]
    public function testTenureFieldValuesAreReadByShapeNotByCase(string $value, ?Tenure $expect): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Appartement renove.', fields: ['categorie' => $value]),
            $this->source(),
        );

        $tierOne = array_values(array_filter($result->signals, static fn ($s): bool => $s->tier === 1));

        if ($expect === null) {
            self::assertSame([], $tierOne, sprintf('"%s" should produce no tier-1 signal', $value));

            return;
        }

        self::assertNotSame([], $tierOne, sprintf('"%s" produced no tier-1 signal — a fail-open', $value));
        // Containment, not `$tierOne[0]`: `PLUS / PLAI` legitimately yields two tier-1 signals and
        // their order is the resolver's business, not this test's.
        self::assertContains(
            $expect,
            array_map(static fn ($s): Tenure => $s->tenure, $tierOne),
            sprintf('"%s"', $value),
        );
    }

    /** @return iterable<string, array{string, ?Tenure}> */
    public static function fieldValueShapes(): iterable
    {
        // Codes — every one of these must reach a determinate PLUS, regardless of case.
        yield 'bare code, uppercase' => ['PLUS', Tenure::PLUS];
        yield 'bare code, lowercase — the feed that lowercases everything' => ['plus', Tenure::PLUS];
        yield 'bare code, title case' => ['Plus', Tenure::PLUS];
        yield 'code with a modifier' => ['PLUS CD', Tenure::PLUS];
        yield 'enumerated codes' => ['PLUS / PLAI', Tenure::PLUS];
        yield 'lowercase enumeration' => ['plai, plus', Tenure::PLAI];

        // Prose — a real French word is present, so the collocation guard decides, and it says no.
        yield 'Pinel Plus is a scheme name, not the PLUS scheme' => ['Pinel Plus', null];
        yield 'shouted scheme name' => ['PINEL PLUS', null];
        yield 'the adverb, shouted' => ['MAISON DE PLUS DE 100 M2', null];
        yield 'the adverb, lowercase' => ['maison de plus de 100 m2', null];

        // A typology token carries a digit, so it is not a code fragment and the value is prose.
        yield 'T3 PLUS is a typology, not a financing list' => ['T3 PLUS', null];

        // Prose that IS in a financing collocation stays decidable — the guard still runs.
        yield 'prose in collocation' => ['Logement PLUS.', Tenure::PLUS];
    }

    /**
     * `LOGEMENT PLUS MODERNE` in a tenure field is indécidable, and must digest rather than vanish.
     *
     * The prose branch is not a licence to go quiet: when the collocation guard reaches its third
     * answer, the field produces a DOUBT exactly as the description would.
     */
    public function testAnIndecidableTenureFieldProducesADoubtNotSilence(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Loyer intermediaire.', fields: ['categorie' => 'LOGEMENT PLUS MODERNE']),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame(Outcome::DIGEST, $result->outcome);
    }

    /**
     * A field value that is not a string must not crash the run, and must not go silent either.
     *
     * `RawListing::$fields` is annotated `array<string,string>`, and an annotation is not a runtime
     * guarantee: a JSON feed with `"financement": ["PLUS", "PLAI"]` or `{"code": "PLUS"}` decodes to
     * an array, and an adapter that forwards it verbatim reaches here. `(string) $value` turned the
     * array into the literal `"Array"` — no signal, no doubt, tier 1 blind on a field that names an
     * excluded scheme — and turned a stdClass into an uncaught `Error` that kills the whole run.
     *
     * Neither is acceptable, so an unreadable tenure field is a DOUBT: the field claims to carry the
     * one fact this project exists to check, and it could not be read.
     *
     * @param array<string,mixed> $fields
     */
    #[DataProvider('unreadableFieldValues')]
    public function testAnUnreadableTenureFieldDigestsRatherThanCrashingOrGoingSilent(array $fields): void
    {
        /** @var array<string,string> $fields intentionally violated — that is the point of this test */
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Loyer intermediaire, proche RER.', fields: $fields),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame(Outcome::DIGEST, $result->outcome);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function unreadableFieldValues(): iterable
    {
        yield 'a list, as a JSON feed would decode it' => [['financement' => ['PLUS', 'PLAI']]];
        yield 'an object with no __toString' => [['categorie' => new \stdClass()]];
        yield 'a nested map' => [['dispositif' => ['code' => 'PLUS']]];
    }

    /**
     * `conventionné` is excused only by an intermediate label that PRECEDES it.
     *
     * Asserted here rather than left to the span arithmetic. The rule used to carry an explicit
     * `position > $s->position → skip`, and sabotage-verification proved that clause unreachable:
     * a label starting after `conventionné` also ends after it, so it already fails the adjacency
     * test. The clause was deleted as dead code — but the PROPERTY it expressed is still a real
     * requirement, and deleting the only place that stated it would have left it holding by
     * accident. `conventionné logement intermédiaire` is two noun phrases, not a qualifier.
     */
    public function testConventionneIsOnlyExcusedByALabelThatPrecedesIt(): void
    {
        $classifier = new TenureClassifier();

        $qualifier = $classifier->classify(
            $this->listing(description: 'Logement intermediaire conventionne.'),
            $this->source(),
        );
        $reversed = $classifier->classify(
            $this->listing(description: 'Conventionne, logement intermediaire.'),
            $this->source(),
        );

        self::assertSame(Tenure::LLI, $qualifier->tenure, 'the adjective after its noun IS the exception');
        self::assertSame(Outcome::MATCH, $qualifier->outcome);

        self::assertNotSame(Tenure::LLI, $reversed->tenure, 'conventionne BEFORE a label is not qualified by it');
        self::assertNotSame(Outcome::MATCH, $reversed->outcome);
    }

    /** `SOCIAL` corroborates any excluded tenure rather than contradicting it. */
    public function testSocialAgreesWithAnyExcludedTenure(): void
    {
        self::assertTrue(Tenure::SOCIAL->agreesWith(Tenure::PLAI));
        self::assertTrue(Tenure::PLUS->agreesWith(Tenure::SOCIAL));
        self::assertFalse(Tenure::SOCIAL->agreesWith(Tenure::LLI));
        self::assertFalse(Tenure::LLI->agreesWith(Tenure::LIBRE));
        self::assertTrue(Tenure::LLI->agreesWith(Tenure::LLI));
    }

    /** @param array<string,string> $fields */
    private function listing(string $title = 'T3', string $description = '', array $fields = []): RawListing
    {
        return new RawListing(
            sourceName: self::MIXED,
            externalId: 'unit',
            title: $title,
            description: $description,
            fields: $fields,
        );
    }

    private function source(): SourceProfile
    {
        return new SourceProfile(name: self::MIXED, defaultTenure: null, mixedTenure: true);
    }
}
