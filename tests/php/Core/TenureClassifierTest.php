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
use RentWatch\Core\TenureSignal;

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
     * This is the config-omission case: someone adds a landlord to `config/sources.json`, forgets
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

        // Prose that names the word without a financing collocation — a DOUBT, never silence.
        //
        // These four returned NO tier-1 signal at all until review round 5, which is how
        // `financement: "Prêt PLUS"` reached MATCH at confidence 50 on In'li with `reasons[]`
        // saying "aucun signal dans l'annonce". Inside a TENURE FIELD the field name is already the
        // financing collocation, so the guard's "no context found" is not an answer here. The cost
        // is that a scheme name and a typology now digest instead of matching: one glance each,
        // against an application for the alternative.
        yield 'Pinel Plus is a scheme name, but the field cannot say so' => ['Pinel Plus', Tenure::UNKNOWN];
        yield 'shouted scheme name' => ['PINEL PLUS', Tenure::UNKNOWN];
        yield 'a financing noun outside the closed COLLOCATION list' => ['Prêt PLUS', Tenure::UNKNOWN];
        yield 'a separator the code-list splitter does not know' => ['PLUS|CD', Tenure::UNKNOWN];

        // A typology token carries a digit, so it is not a code fragment and the value is prose.
        yield 'T3 PLUS is a typology, but the field cannot say so' => ['T3 PLUS', Tenure::UNKNOWN];

        // The ONE case that stays silent: a known comparative proves the adverb. That tail is the
        // adverb's own grammar — a closed set — unlike the open set of nouns a scheme name follows.
        yield 'the adverb, shouted' => ['MAISON DE PLUS DE 100 M2', null];
        yield 'the adverb, lowercase' => ['maison de plus de 100 m2', null];

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

    /**
     * The evidence trail behind a verdict cannot be rewritten by whoever holds it.
     *
     * `reasons[]` is what a notification is built from (`spec/PROJECT_BRIEF.md` §5), so a mutable
     * `TenureSignal` means a caller can make a PLAI rejection describe itself as an LLI match.
     * `TenureSignal` was `final readonly` until `$length` gained a computed default — promoting the
     * parameter forced the assignment into the constructor body, which is illegal in a readonly
     * class, and the class keyword was dropped instead of the promotion. All six properties went
     * writable, silently, and no test noticed. A non-promoted readonly property does the same job.
     */
    public function testASignalCannotBeRewrittenAfterTheVerdictIsFormed(): void
    {
        $signal = new TenureSignal(tier: 2, tenure: Tenure::PLAI, reason: 'r', evidence: 'plai', position: 5);

        self::assertSame(4, $signal->length, 'the computed default must survive the readonly rewrite');

        foreach (['tier', 'tenure', 'reason', 'evidence', 'position', 'length'] as $property) {
            try {
                $signal->{$property} = $property === 'tenure' ? Tenure::LLI : 1;
                self::fail(sprintf('TenureSignal::$%s is writable — the §1 evidence trail is forgeable', $property));
            } catch (\Error $e) {
                self::assertStringContainsString('readonly', $e->getMessage());
            }
        }
    }

    /**
     * `reasons[]` names the text that ACTUALLY matched, not the table literal it matched against.
     *
     * `reasons[]` is the product's only user-facing output (`spec/PROJECT_BRIEF.md` §5) and the
     * whole suite asserted exactly two things about it: that it is non-empty, and that no entry is
     * blank. Reverting `reason:` to `$hit['literal']` — so an inflected match reports a phrase the
     * listing does not contain — left the whole suite green (183 tests at the time). A notification that says
     * « logement locatif intermediaire » for a listing reading *"logements locatifs intermédiaires
     * conventionnés"* is not wrong enough to fail a test and is exactly wrong enough to erode trust.
     */
    public function testReasonsQuoteTheMatchedTextNotTheTableLiteral(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Logements locatifs intermediaires, loyer plafonne.'),
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        $reasons = implode(' | ', $result->reasons());

        self::assertStringContainsString('logements locatifs intermediaires', $reasons);
        self::assertStringNotContainsString(
            '« logement locatif intermediaire »',
            $reasons,
            'the reason quotes the singular table literal, which this listing does not say',
        );
    }

    /**
     * A doubt explains itself as a doubt, so the digest entry is actionable.
     *
     * The three tier-1 doubt reasons — unreadable field, indécidable value, and the round-5 prose
     * floor — are the only text a human sees when deciding whether to open a digested listing.
     */
    #[DataProvider('doubtReasons')]
    public function testEveryDoubtSaysWhatCouldNotBeDecided(mixed $value, string $expected): void
    {
        /** @var array<string,string> $fields */
        $fields = ['categorie' => $value];

        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Loyer intermediaire, proche RER.', fields: $fields),
            $this->source(),
        );

        self::assertSame(Outcome::DIGEST, $result->outcome);
        self::assertStringContainsString($expected, implode(' | ', $result->reasons()));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function doubtReasons(): iterable
    {
        yield 'unreadable field names its type' => [new \stdClass(), 'illisible (stdClass)'];
        yield 'indecidable value says so' => ['LOGEMENT PLUS MODERNE', 'indécidable'];
        yield 'unplaceable acronym says so' => ['Prêt PLUS', 'indécidable'];
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
    // ------------------------------------------------- the config's own tenure_field must be tier 1

    /**
     * `tenure_field` is the field map's declaration of "THIS is the financing field" — so the
     * classifier has to recognise the name the mapper stores it under.
     *
     * It did not. `FieldMap::tenure_field` writes its value into `RawListing::$fields` under the key
     * `tenureField`, and `TENURE_FIELDS` lists the names sources use in their own payloads
     * (`financement`, `typeProduit`, `categorie`) — not that one. So the mapping documented in
     * `/add-source` Step 4 as "the highest-value mapping, look hard for it" produced NO tier-1
     * signal at all, and fell through to the unrecognised-field path, which only raises a doubt when
     * the value contains EXCLUDED vocabulary.
     *
     * Found against CDC Habitat's real payload [2026-08-20]: all 16 cards on the frozen page
     * classified `UNKNOWN / 0.00 / DIGEST`, including the 14 whose own badge reads
     * `Logement intermédiaire`. §1 held — nothing reached a match — but it held by accident, and
     * the visible half of the bug is over-rejection: a correctly-labelled intermediate source
     * emitting zero matches looks exactly like a quiet market.
     */
    public function testTheConfiguredTenureFieldIsReadAsAFinancingField(): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '1',
            title: '2 pièces - RDC - 48m²',
            description: 'Ascenseur, Eau froide comprise, Parking',
            fields: ['tenureField' => 'Logement intermédiaire'],
        );

        $verdict = (new TenureClassifier())->classify($listing, new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true));

        self::assertSame(Tenure::LLI, $verdict->tenure);
        self::assertGreaterThanOrEqual(0.6, $verdict->confidence(), 'a tier-1 field is not a doubt');
    }

    /**
     * The same path, pointed the other way — and this is the half that matters for §1.
     *
     * A source that declares its financing field and says `Logement social` must REJECT, not merely
     * raise a doubt. Before the fix this produced an `UNKNOWN` digest entry, which is safe but wrong
     * for the wrong reason: safe because the value happened to contain excluded vocabulary, not
     * because the field was understood.
     */
    public function testAConfiguredTenureFieldSayingSocialIsExcludedOutright(): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '2',
            title: '4 pièces - 2ème étage - 78m²',
            description: 'Ascenseur',
            fields: ['tenureField' => 'Logement social'],
        );

        $verdict = (new TenureClassifier())->classify($listing, new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true));

        self::assertNotSame(Tenure::LLI, $verdict->tenure);
        self::assertNotSame(Tenure::LIBRE, $verdict->tenure);
        self::assertSame(Outcome::REJECT, $verdict->outcome, 'an explicit social declaration is not a maybe');
    }

    // ------------------------------------------------- `_text` is PROSE, not an identifier field

    /**
     * The card's own text must not veto its own badge because French uses the word *plus*.
     *
     * `HtmlSource` writes the whole card into `fields['_text']` and its docblock calls that "the
     * classifier's text surface". It arrived through the FIELD door, though, and the field path
     * matches `AMBIGUOUS_LABELS` case-INSENSITIVELY — deliberately, on the stated grounds that
     * "neither of these haystacks is prose". `_text` is prose, so *"implanté au plus près des
     * bassins d'emploi"* — CDC Habitat's own tooltip defining logement intermédiaire — matched the
     * excluded financing acronym `PLUS` and contradicted the tier-1 badge down to UNKNOWN.
     *
     * Measured 2026-08-20: identical text in `description` classified LLI 0.97 MATCH, in `_text`
     * UNKNOWN 0.00 DIGEST. `plus` is one of the commonest words in French, so this vetoes almost any
     * card that carries prose; In'li escaped it by luck, not design. Fail-closed, and useless —
     * the over-rejection hard rule 8 names.
     */
    public function testTheCardsOwnProseDoesNotVetoItsBadgeOverTheAdverbPlus(): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '1',
            title: '2 pièces - RDC - 48m²',
            description: 'Ascenseur, Eau froide comprise, Parking',
            fields: [
                'tenureField' => 'Logement intermédiaire',
                '_text' => 'Logement intermédiaire Le logement intermédiaire est 10 à 15 % inférieur '
                    . "au prix du marché et implanté au plus près des bassins d'emploi. L'attribution "
                    . 'est soumise à plafonds de ressources.',
            ],
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true),
        );

        self::assertSame(Tenure::LLI, $verdict->tenure);
        self::assertGreaterThanOrEqual(0.6, $verdict->confidence(), 'the adverb "plus" is not the acronym PLUS');
    }

    /**
     * `description` and `title` are PROSE, wherever the adapter also leaves a copy of them.
     *
     * `ListingMapper` passes the WHOLE structured surface as `fields`, so an HTML source's mapped
     * `description` arrives BOTH as the property and as `fields['description']`. The property goes
     * through `RawListing::text()` and its prose rules; the field copy went through the identifier
     * discipline, which reads the French adverb `plus` as the acronym `PLUS`.
     *
     * Measured on live In'li data (2026-08-23), where Phase 2's detail map first gave that source a
     * description: 4 of 40 hydrated listings flipped to UNKNOWN on `de plus de 20 m²` and `encore
     * plus d'espace`. In'li carries no explicit label, so a tier-1 doubt is the ONLY tier-1 signal
     * there is and it dominates — a correct LLI match silently demoted to the *à vérifier* digest.
     * Same failure class as the CDC `au plus près` fix, one surface over: `plus` is one of the
     * commonest words in French.
     *
     * This is a RE-ROUTE, not a narrowing — see the PLS half, which must keep failing closed.
     */
    public function testProseFieldsAreReadAsProseEvenWhenTheAdapterAlsoCopiesThemIntoFields(): void
    {
        $prose = 'un espace de vie principal de plus de 20 m² (cuisine ouverte et séjour)';

        $listing = new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-1',
            title: 'Appartement de 41 m² à SCEAUX',
            description: $prose,
            // Exactly what ListingMapper produces for a `type: html` source with a detail map.
            fields: ['title' => 'Appartement de 41 m² à SCEAUX', 'description' => $prose],
            url: 'https://www.inli.fr/x',
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        self::assertSame(Tenure::LLI, $verdict->tenure, 'the adverb "plus" is not the acronym PLUS');
    }

    /**
     * The counterweight to the re-route above: a REAL exclusion in the description still fails closed.
     *
     * Not hypothetical. In'li is documented as pure LLI, and hydrating its detail pages turned up
     * `Le logement est soumis au plafond de ressources PLS.` on two live listings whose CARDS said
     * nothing of the kind. Under §1 that is a reject, and it must stay one — if the fix above ever
     * becomes "stop scanning the description", this test goes red.
     */
    public function testARealExclusionInTheDescriptionStillFailsClosed(): void
    {
        $listing = new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-317130',
            title: 'Appartement de 62 m²',
            description: 'Le logement est soumis au plafond de ressources PLS.',
            fields: ['description' => 'Le logement est soumis au plafond de ressources PLS.'],
            url: 'https://www.inli.fr/y',
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        self::assertNotSame(Tenure::LLI, $verdict->tenure, 'an explicit PLS is never an eligible match');
    }

    /**
     * The counterweight, and the reason the fix above is a re-route rather than a skip.
     *
     * If `_text` simply stopped being scanned, excluded vocabulary living in card regions that no
     * field map names would stop being seen at all — the "correct rule applied to a subset of the
     * surfaces it belongs on" P0 that eight review rounds each found one of. `_text` must still be
     * read; it must be read as PROSE.
     *
     * @param string $text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('excludedProseInText')]
    public function testExcludedVocabularyInTheCardTextIsStillCaught(string $text, string $seen, string $why): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '2',
            title: '4 pièces - 2ème étage - 78m²',
            description: 'Ascenseur',
            fields: ['_text' => $text],
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true),
        );

        self::assertNotSame(Outcome::MATCH, $verdict->outcome, $why);
        self::assertNotSame(Tenure::LLI, $verdict->tenure, $why);

        // "Not a match" is NOT the guarantee — a listing whose card text was never scanned at all
        // also fails to match, by falling through to the sub-floor tier-5 default. A sabotage run
        // proved exactly that: deleting `_text` from `RawListing::text()` left this test green
        // [measured 2026-08-20]. What must be pinned is that the vocabulary was SEEN, so the
        // assertion is on the reason the classifier gives.
        self::assertStringContainsStringIgnoringCase(
            $seen,
            implode(' | ', $verdict->reasons()),
            'the card text was not scanned at all — silence here is indistinguishable from safety',
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function excludedProseInText(): iterable
    {
        yield 'logement social' => [
            '4 pièces - 78m² Logement social CERGY (95000) 612,40 €',
            'logement social',
            'an explicit social badge in the card text must never reach a match',
        ];
        yield 'PLAI' => [
            '4 pièces - 78m² financement PLAI CERGY (95000)',
            'plai',
            'an excluded acronym in the card text must never reach a match',
        ];
        yield 'PLUS in financing context' => [
            '4 pièces - 78m² financement PLUS CERGY (95000)',
            'PLUS',
            'the real acronym, uppercase and in context, is still the real acronym',
        ];
        yield 'numero unique' => [
            "4 pièces - 78m² numéro unique d'enregistrement requis CERGY",
            'numero unique',
            'a procedural social tell in the card text must never reach a match',
        ];
    }

}
