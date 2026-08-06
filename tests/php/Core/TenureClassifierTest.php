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
 * The corpus proves the classifier gets 56 specific listings right. This file proves the shape of
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
