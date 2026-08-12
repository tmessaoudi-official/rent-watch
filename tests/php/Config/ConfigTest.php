<?php

declare(strict_types=1);

namespace RentWatch\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Config\ConfigError;
use RentWatch\Config\ConfigLoader;
use RentWatch\Config\Criteria;
use RentWatch\Config\NotifyPolicy;
use RentWatch\Config\Weights;
use RentWatch\Core\Tenure;

/**
 * The config layer's contract.
 *
 * Categories mirror the store's, for the same reason: the failure modes here are silent. A config
 * that loads with a misread `mixed_tenure` produces a tool that runs, reports healthy, and surfaces
 * social housing. A config that loads with a mis-scoped exclusion pattern produces a tool that
 * reports nothing and looks like a quiet market.
 *
 * - **strictness**  — an unknown key, a wrong type and a missing required key are all loud
 * - **§1 guards**   — `mixed_tenure` required, excluded `default_tenure` refused, corpus binding
 * - **hard rule 1** — an enabled source may not carry an unverified URL
 * - **legal**       — `browser` refused, scraping opt-in identified
 * - **filters**     — commune matching, and the two exclusion scopes
 * - **shipped**     — the committed files actually load and say what the docs claim
 */
final class ConfigTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @return array<string,mixed> */
    private static function minimalCriteria(array $overrides = []): array
    {
        return $overrides + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
        ];
    }

    /** @return array<string,mixed> */
    private static function minimalSource(array $overrides = []): array
    {
        return ['sources' => ['demo' => $overrides + [
            'enabled' => false,
            'family' => 'institutional',
            'type' => 'json',
            'mixed_tenure' => true,
            'map' => ['ref' => 'id'],
        ]]];
    }

    // ---------------------------------------------------------------- strictness

    public function testUnknownKeyIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key mixd_tenure~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['mixd_tenure' => true]));
    }

    public function testUnderscoreKeysAreCommentsAndAreIgnored(): void
    {
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            '_comment' => 'JSON has no comments, so this is the convention',
            '_min_rooms' => 'a note about the key below',
            'min_rooms' => 4,
        ]));

        self::assertSame(4, $c->minRooms);
    }

    public function testAPerKeyNoteIsAcceptedWhileItsKeyIsPresent(): void
    {
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            '_min_rooms' => 'a note sitting immediately above the key it explains',
            'min_rooms' => 4,
        ]));

        self::assertSame(4, $c->minRooms);
    }

    public function testAPerKeyNoteIsAcceptedRegardlessOfKeyOrder(): void
    {
        // The sibling check reads the ORIGINAL object, not what has been kept so far, so a note
        // written BELOW its key behaves the same as one written above it. Order-dependence here
        // would be a rule that works in the shipped file and fails in a hand-edited one.
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'min_rooms' => 4,
            '_min_rooms' => 'a note written after its key',
        ]));

        self::assertSame(4, $c->minRooms);
    }

    public function testAnUnderscoreKeyWithNoSiblingIsAnUnknownKey(): void
    {
        // The whole point of the sibling rule. Under the unbounded "any `_` prefix" rule this was
        // silently discarded, so a stray underscore disabled a setting with no message at all.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key _min_roooms~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['_min_roooms' => 'typo']));
    }

    public function testRenamingAKeyToDisableItProducesTwoLoudErrorsNotSilence(): void
    {
        // The failure a review found: `mixed_tenure` renamed to `_mixed_tenure` would, under a bare
        // prefix rule, silently disarm the fail-closed switch of CLAUDE.md §1. Now the note has no
        // sibling, so it is an unknown key AND the required key is missing.
        $source = self::minimalSource();
        $source['sources']['demo']['_mixed_tenure'] = $source['sources']['demo']['mixed_tenure'];
        unset($source['sources']['demo']['mixed_tenure']);

        try {
            ConfigLoader::sourcesFromArray($source);
            self::fail('expected a ConfigError');
        } catch (ConfigError $e) {
            self::assertStringContainsString('mixed_tenure: required', $e->getMessage());
        }
    }

    public function testFreeStandingCommentKeysNeedNoSibling(): void
    {
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            '_comment' => 'about the object as a whole',
            '_why' => 'the reasoning behind it',
            '_source' => 'where the values came from',
            '_verified_at' => '2026-08-07',
        ]));

        self::assertNotSame([], $c->communes);
    }

    public function testACommentKeyCannotSatisfyARequiredKey(): void
    {
        // The underscore convention is only safe because the strictness above exists. This pins the
        // other half: a `_communes` note does not stand in for `communes`.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~communes: required key is missing~');

        ConfigLoader::criteriaFromArray(['_communes' => 'a note', 'postcode_prefixes' => ['78']]);
    }

    public function testAStringThatLooksLikeABooleanIsRefused(): void
    {
        // "true" is truthy in PHP. `mixed_tenure: "false"` would be truthy too, which is how a
        // mixed-stock landlord ends up armed and a pure one ends up disarmed by the same mistake.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~mixed_tenure: expected true or false, got the string~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['mixed_tenure' => 'true']));
    }

    public function testAUserAgentHeaderInSourceConfigIsRefused(): void
    {
        // In cURL, a User-Agent entry in CURLOPT_HTTPHEADER silently overrides CURLOPT_USERAGENT,
        // so this one config key would disguise every request from the source — the browser
        // impersonation hard rule 5 forbids. Refused at load time, case-insensitively, because
        // HTTP header names are.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~User-Agent header is not configurable~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ['USER-AGENT' => 'Mozilla/5.0']]));
    }

    public function testAColonSmuggledHeaderNameIsRefusedInConfig(): void
    {
        // The round-2 bypass of the refusal above: libcurl reads the header NAME from the text
        // before the first colon, so a JSON key of "user-agent: Mozilla…" cleared the equality
        // guard and still disguised the request. A colon can never appear in an RFC 7230 token.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~not a valid HTTP token~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ['user-agent: Mozilla/5.0' => 'x']]));
    }

    public function testALineBreakInAHeaderValueIsRefusedInConfig(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~header injection~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ['Referer' => "https://x.test/\r\nUser-Agent: Mozilla"]]));
    }

    public function testATrailingNewlineInAHeaderNameIsRefusedInConfig(): void
    {
        // PHP's `$` matches before a single trailing newline, so `"user-agent\n"` would pass a
        // `$`-anchored token class and dodge the User-Agent equality guard. The `D` modifier on
        // HEADER_NAME_TOKEN refuses it; this pins the modifier in place.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~not a valid HTTP token~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ["user-agent\n" => 'Mozilla/5.0']]));
    }

    public function testAFloatIsNotAcceptedWhereAnIntegerIsSpecified(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~max_rent_cc: expected an integer~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['max_rent_cc' => 1800.5]));
    }

    public function testErrorMessagesNameTheFullPointer(): void
    {
        try {
            ConfigLoader::sourcesFromArray(self::minimalSource(['family' => 'municipal']));
            self::fail('expected a ConfigError');
        } catch (ConfigError $e) {
            self::assertStringContainsString('sources.json.sources.demo.family', $e->getMessage());
        }
    }

    public function testNestedObjectsAreAlsoStrict(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~weights: unknown key freshnes~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['weights' => ['freshnes' => 10]]));
    }

    // ---------------------------------------------------------------- §1 guards

    public function testMixedTenureIsRequiredEvenThoughTheCodeDefaultsIt(): void
    {
        $source = self::minimalSource();
        unset($source['sources']['demo']['mixed_tenure']);

        $this->expectException(ConfigError::class);
        // Matching only `mixed_tenure: required` was NOT enough, and a sabotage run proved it:
        // deleting the explicit guard falls through to `requireBool()`, whose generic "required key
        // is missing" ALSO matches that pattern. The test passed while the guard was gone. Asserting
        // the guidance text is what makes the guard load-bearing rather than decorative — it is the
        // sentence that tells whoever hit this WHY the flag matters.
        $this->expectExceptionMessageMatches('~arms the fail-closed rule~');

        ConfigLoader::sourcesFromArray($source);
    }

    #[DataProvider('excludedTenures')]
    public function testAnExcludedDefaultTenureIsRefused(string $tenure): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~excluded set~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['default_tenure' => $tenure]));
    }

    /** @return iterable<string, array{string}> */
    public static function excludedTenures(): iterable
    {
        foreach (Tenure::cases() as $case) {
            if ($case->isExcluded()) {
                yield $case->value => [$case->value];
            }
        }
    }

    public function testEveryExcludedTenureIsCoveredByThatProvider(): void
    {
        // The provider derives from the enum by reflection, so a tenure added to the excluded set
        // gets a test for free. This asserts the derivation is not empty — a provider that silently
        // yielded nothing would make the test above pass by doing nothing at all.
        $covered = array_keys(iterator_to_array(self::excludedTenures()));
        self::assertNotEmpty($covered);
        self::assertContains('PLAI', $covered);
        self::assertContains('PLUS', $covered);
        self::assertContains('PLS', $covered);
    }

    public function testUnknownIsRefusedAsADefaultTenure(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~UNKNOWN is what the classifier concludes~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['default_tenure' => 'UNKNOWN']));
    }

    public function testAnEligibleDefaultTenureIsAccepted(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['default_tenure' => 'LLI']));
        self::assertSame(Tenure::LLI, $sources['demo']->defaultTenure);
    }

    public function testThereIsNoConfigKeyThatCanReEnableAnExcludedTenure(): void
    {
        // Not a formality. §1 says the excluded set is not user-overridable, and the way that breaks
        // is a well-meaning key added later. If a future edit adds `tenures` or `allow_tenures` to
        // Criteria, this fails and the author has to argue for it.
        $forbidden = ['tenure', 'tenures', 'allow_tenures', 'allowed_tenures', 'exclude_tenures', 'social'];
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => strtolower($p->getName()),
            (new \ReflectionClass(Criteria::class))->getProperties(),
        );

        foreach ($forbidden as $name) {
            self::assertNotContains(
                str_replace('_', '', $name),
                array_map(static fn (string $p): string => str_replace('_', '', $p), $properties),
                'Criteria must not carry a tenure key — CLAUDE.md §1 is not user-overridable',
            );
        }
    }

    public function testEveryCorpusSourceAgreesWithConfig(): void
    {
        // The binding docs/OPEN-QUESTIONS.md Q20 asked for. `mixed_tenure` is the one flag that
        // disarms the fail-closed rule, and it is declared in TWO places — the classifier corpus and
        // config/sources.json. Two declarations of one fact drift, and this is the only thing that
        // makes the drift loud.
        $corpus = json_decode(
            (string) file_get_contents(self::ROOT . '/tests/fixtures/tenure/corpus.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $config = ConfigLoader::loadSources(self::ROOT . '/config/sources.json');

        $compared = 0;
        foreach ($corpus['sources'] as $name => $declared) {
            if (!isset($config[$name])) {
                continue;
            }

            ++$compared;
            self::assertSame(
                $declared['mixed_tenure'],
                $config[$name]->mixedTenure,
                "mixed_tenure for '{$name}' differs between the corpus and config/sources.json",
            );
            self::assertSame(
                $declared['family'],
                $config[$name]->family,
                "family for '{$name}' differs between the corpus and config/sources.json",
            );
            self::assertSame(
                $declared['default_tenure'],
                $config[$name]->defaultTenure?->value,
                "default_tenure for '{$name}' differs between the corpus and config/sources.json",
            );
        }

        // Without this the test passes when the two files share no source at all — which is exactly
        // what a rename would produce, and exactly the drift it exists to catch.
        self::assertGreaterThanOrEqual(
            5,
            $compared,
            'fewer than 5 sources are declared in both the corpus and config/sources.json — '
                . 'a rename on one side would make this test vacuous',
        );
    }

    // ---------------------------------------------------------------- hard rule 1

    public function testAnEnabledSourceCannotCarryTheUnverifiedUrlPlaceholder(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~hard rule 1~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'enabled' => true,
            'url' => 'https://example.test/REMPLACER',
        ]));
    }

    public function testADisabledSourceMayCarryThePlaceholder(): void
    {
        // The placeholder is the whole point of `enabled: false` — it is how a source is staged
        // before its endpoint has been captured. Refusing it here would make onboarding impossible.
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['url' => 'REMPLACER']));
        self::assertFalse($sources['demo']->enabled);
    }

    public function testAnEnabledSourceNeedsAUrl(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~needs a url~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['enabled' => true]));
    }

    public function testAnEnabledFixtureSourceNeedsAFixtureFileRatherThanAUrl(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~fixture: a fixture source must name its payload file~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['enabled' => true, 'type' => 'fixture']));
    }

    // ---------------------------------------------------------------- legal posture

    public function testBrowserTypeIsRefusedAtLoadRatherThanStubbed(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~browser automation is not permitted~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'browser']));
    }

    public function testPollingAPrivatePortalIsIdentifiedAsNeedingTheScrapingOptIn(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'family' => 'private',
            'type' => 'json',
        ]));

        self::assertTrue($sources['demo']->requiresScrapingOptIn());
    }

    public function testEmailAlertIngestionIsNotGated(): void
    {
        // Hard rule 4: email-alert ingestion is the PRIMARY path for a private portal, not a
        // workaround. Gating it would push the developer toward the scraping route it replaces.
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'family' => 'private',
            'type' => 'email_alert',
        ]));

        self::assertFalse($sources['demo']->requiresScrapingOptIn());
    }

    public function testPollingAnInstitutionalLandlordIsNotGated(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'json']));
        self::assertFalse($sources['demo']->requiresScrapingOptIn());
    }

    // ---------------------------------------------------------------- field map

    public function testARefPathIsRequiredBecauseWithoutOneEveryRunRenotifiesEverything(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~ref: required~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['map' => ['title' => 'name']]));
    }

    public function testChargesIncludedIsRequiredWheneverRentIsMapped(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~charges_included: required whenever `rent` is mapped~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'map' => ['ref' => 'id', 'rent' => 'price'],
        ]));
    }

    public function testASinglePathStringIsNormalisedToAOneElementList(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'map' => ['ref' => 'id', 'title' => 'name'],
        ]));

        self::assertSame(['name'], $sources['demo']->map->title);
    }

    public function testAPathListIsPreservedInOrder(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'map' => ['ref' => 'id', 'commune' => ['city', 'address.city']],
        ]));

        self::assertSame(['city', 'address.city'], $sources['demo']->map->commune);
    }

    // ---------------------------------------------------------------- commune filter

    public function testCommuneKeyNormalisesSeparatorsAndAccentsButNotWordBoundaries(): void
    {
        self::assertSame('maisons laffitte', Criteria::communeKey('Maisons-Laffitte'));
        self::assertSame('maisons laffitte', Criteria::communeKey('MAISONS LAFFITTE'));
        self::assertSame('le vesinet', Criteria::communeKey('Le Vésinet'));
        // Separators normalise; they do not vanish. Otherwise `levesinet` would match `Le Vésinet`.
        self::assertNotSame(Criteria::communeKey('Le Vésinet'), Criteria::communeKey('Levesinet'));
    }

    public function testTwoCommunesThatNormaliseTheSameAreRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~normalise to the same commune~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'communes' => ['Maisons-Laffitte', 'maisons laffitte'],
        ]));
    }

    public function testTheCommuneFilterReadsTheCommuneFieldNotTheDescription(): void
    {
        // The prototype searched `commune + cp + title + raw_text` as one haystack, so a Paris flat
        // whose description said "proche Chatou" passed the commune filter. There is no way to
        // reproduce that here, because the description is not an argument.
        $c = $this->shipped();
        self::assertFalse($c->matchesCommune('Paris 17e', '75017'));
        self::assertTrue($c->matchesCommune('Chatou', '78400'));
    }

    public function testAnUnknownPostcodeIsNotADisqualification(): void
    {
        self::assertTrue($this->shipped()->matchesCommune('Chatou', null));
    }

    public function testAPostcodeFromAnotherDepartementRejectsASameNamedCommune(): void
    {
        // This is the prefix filter's actual job. Argenteuil is 95100; Argenteuil-sur-Armançon is 89.
        self::assertFalse($this->shipped()->matchesCommune('Argenteuil', '89160'));
        self::assertTrue($this->shipped()->matchesCommune('Argenteuil', '95100'));
    }

    public function testEveryShippedCommuneIsInAConfiguredPostcodePrefix(): void
    {
        // The stated justification for removing prefix 92 was "every commune is in 78 or 95". If a
        // commune is ever added that is not, this fails — because the two keys would then silently
        // disagree and the new commune could never match.
        $c = $this->shipped();
        self::assertSame(['78', '95'], $c->postcodePrefixes);
        self::assertCount(10, $c->communes);
    }

    public function testARankedCommuneMustAlsoBeFiltered(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~ranked but not in `communes`~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'commune_rank' => ['Poissy' => 1],
        ]));
    }

    // ---------------------------------------------------------------- exclusion scope

    #[DataProvider('exclusionCases')]
    public function testExclusionScope(string $title, string $description, bool $expectExcluded, string $why): void
    {
        $fired = $this->shipped()->excludedBy($title, $description);

        $expectExcluded
            ? self::assertNotNull($fired, $why)
            : self::assertNull($fired, $why . ($fired === null ? '' : " — fired on: {$fired}"));
    }

    /** @return iterable<string, array{string, string, bool, string}> */
    public static function exclusionCases(): iterable
    {
        yield 'fitted kitchen is not a furnished let' => [
            'T4 Sartrouville 85m2',
            'Cuisine équipée et meublée, séjour lumineux.',
            false,
            "the prototype's `meubl` excluded this exact listing — it is the bug F9 was rewritten to fix",
        ];
        yield 'a furnished let is excluded' => [
            'Appartement meublé T4 Houilles',
            'Entièrement meublé, disponible immédiatement.',
            true,
            'a furnished let is genuinely out of scope',
        ];
        yield 'a flat with a parking space is wanted' => [
            'T4 Chatou 82m2 avec parking',
            'Box en sous-sol inclus, cave et ascenseur.',
            false,
            'parking, box and cave are amenities here, not the property type',
        ];
        yield 'a parking space for rent is excluded' => [
            'Parking en sous-sol - Bezons',
            'Emplacement sécurisé.',
            true,
            'here the same word IS the property type, and it sits at the front of the title',
        ];
        yield 'a garage let is excluded' => [
            'Garage fermé Argenteuil',
            '',
            true,
            'a garage ad states no rooms and no surface, so the size filters cannot catch it',
        ];
        yield 'a flat with a study is wanted' => [
            'T4 Montesson avec bureau',
            'Pièce supplémentaire utilisable en bureau.',
            false,
            'bureau is a room here; only a title STARTING with it is a commercial let',
        ];
        yield 'colocation is excluded' => [
            'Chambre en colocation - Houilles',
            '',
            true,
            'explicitly out of scope',
        ];
        yield 'a student residence is excluded' => [
            'Studio en résidence étudiante',
            '',
            true,
            'accent-folded matching: the pattern is written `etudiante`',
        ];
        yield 'a senior residence is excluded' => [
            'T2 en résidence senior',
            '',
            true,
            'same folding path',
        ];
        // The next two are lifted verbatim from the classifier corpus, where a review showed that
        // adopting F9's suggested `bureau` and `parking` as bare stems would delete them before the
        // classifier's verdict was ever consulted. `trap-010` is asserted to MATCH there.
        yield 'trap-010 corpus text: a study is not a commercial let' => [
            'T4 Croissy-sur-Seine',
            'Trois chambres, plus un bureau ferme et une buanderie.',
            false,
            'a bare `bureau` pattern would silently delete a corpus case asserted to MATCH',
        ];
        yield 'unknown-001 corpus text: a parking space is not a parking ad' => [
            'Appartement 3 pieces',
            'Appartement 3 pieces, 65 m2, parking.',
            false,
            'a bare `parking` pattern would delete this before the classifier ever ran',
        ];
        // THE PAIR THAT MAKES THE TITLE-ONLY SCOPE FALSIFIABLE. A sabotage run showed that while
        // every title pattern was `^`-anchored, widening the scope to the description was a
        // semantic no-op — a `^` with no `m` flag cannot match inside appended text either way — so
        // the two-list design was protecting nothing a test could disprove. These two use an
        // UNANCHORED title pattern, and they differ only in which field carries the phrase.
        yield 'a parking phrase in the DESCRIPTION is an amenity' => [
            'T4 Sartrouville 88m2',
            'Emplacement de stationnement inclus, cave et ascenseur.',
            false,
            'if the exclusion ever reads the description, this good flat vanishes silently',
        ];
        yield 'the same phrase in the TITLE is the property type' => [
            'Emplacement de stationnement - Sartrouville',
            'Acces badge.',
            true,
            'and if the title scope is dropped entirely, this parking ad gets notified',
        ];
        yield 'loue parking in a title is excluded even unanchored' => [
            'Particulier loue parking - Houilles',
            '',
            true,
            'the anchored pattern misses this: the title does not START with the property type',
        ];
        yield 'an ordinary listing is not excluded' => [
            'Bel appartement T4 - Maisons-Laffitte',
            'Proche RER A, 88m2, 4 pièces, 3e étage avec ascenseur.',
            false,
            'the baseline — if this ever fires, an exclusion pattern has become too broad',
        ];
    }

    public function testNoExclusionPatternMentionsATenure(): void
    {
        // Ruled 2026-08-07: tenure exclusion lives in Tenure::isExcluded() only. A second copy in a
        // user-editable file would be a weaker one, since editing that file is enough to unmake it.
        $c = $this->shipped();
        $all = strtolower(implode(' ', array_merge($c->excludePatterns, $c->excludeTitlePatterns)));

        foreach (['plai', 'plus', 'pls', 'social', 'conventionne', 'anru', 'anah', 'hlm'] as $term) {
            self::assertStringNotContainsString(
                $term,
                $all,
                "'{$term}' belongs to the classifier, not to exclude_patterns — see CLAUDE.md §1",
            );
        }
    }

    public function testAnAccentedPatternIsRefusedRatherThanSilentlyNeverMatching(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~non-ASCII~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'exclude_patterns' => ['meublé'],
        ]));
    }

    public function testAnUncompilablePatternIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~not a valid regular expression~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'exclude_patterns' => ['(unclosed'],
        ]));
    }

    // ---------------------------------------------------------------- weights & routing

    public function testDisabledCommuteMustNotCarryAWeight(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~`commute.enabled` is false~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'weights' => ['commute' => 25],
        ]));
    }

    public function testEnabledCommuteMustCarryAWeight(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~weighted 0~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'commute' => ['enabled' => true, 'station' => 'La Défense', 'max_minutes' => 45],
            'weights' => ['commute' => 0],
        ]));
    }

    public function testEnabledCommuteNeedsSomethingToMeasureAgainst(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~station` or `max_minutes` is missing~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'commute' => ['enabled' => true],
            'weights' => ['commute' => 25],
        ]));
    }

    public function testAPositiveHighFloorWeightIsRefused(): void
    {
        // A positive "high floor, no lift" weight would be a bonus for the exact thing being escaped.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~high_floor_no_lift~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'weights' => ['high_floor_no_lift' => 20],
        ]));
    }

    public function testThePenaltyIsExcludedFromTheNormalisingTotal(): void
    {
        $w = new Weights(commune: 25, commute: 0, rentHeadroom: 15, surface: 10, lift: 15, highFloorNoLift: -20, freshness: 10);
        self::assertSame(75, $w->positiveTotal());
    }

    #[DataProvider('rentDrops')]
    public function testNotableRentDrops(int $previous, int $current, bool $expected, string $why): void
    {
        self::assertSame($expected, (new NotifyPolicy())->isNotableDrop($previous, $current), $why);
    }

    /** @return iterable<string, array{int,int,bool,string}> */
    public static function rentDrops(): iterable
    {
        yield 'a 5 euro correction is noise' => [1800, 1795, false, 'below both thresholds'];
        yield 'a 20 euro drop clears the absolute threshold' => [1800, 1780, true, '240 euros a year'];
        yield 'a small drop on a cheap flat clears the percentage' => [500, 490, true, '2% of 500'];
        yield 'either threshold is enough, not both' => [1800, 1780, true, 'requiring both would silence a 1.1% drop of 20 euros'];
        yield 'a rise is not a drop' => [1700, 1800, false, 'the event is a drop'];
        yield 'no change is not a drop' => [1700, 1700, false, 'equality is not a drop'];
        yield 'a zero previous rent fabricates nothing' => [0, 0, false, 'a previous rent of 0 is not a rent'];
    }

    // ---------------------------------------------------------------- the shipped files

    public function testTheShippedCriteriaFileLoads(): void
    {
        $c = $this->shipped();

        self::assertSame(4, $c->minRooms);
        self::assertSame(75.0, $c->minSurfaceM2);
        self::assertSame(1800, $c->maxRentCc);
        self::assertFalse($c->commuteEnabled);
        self::assertSame(70, $c->notify->highPriorityScore);
        self::assertSame(['console'], $c->notify->channels);
    }

    public function testTheShippedCriteriaHasNoFloorOrElevatorDisqualifier(): void
    {
        // Ruled 2026-08-07 (Q5). There is no key to set, so the prototype's silent drop cannot be
        // reintroduced by editing config — this asserts the key genuinely does not exist rather
        // than merely being unset.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key max_floor~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['max_floor' => 1]));
    }

    public function testRequireElevatorIsAlsoNotAKey(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key require_elevator~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['require_elevator' => true]));
    }

    public function testTheShippedSourcesFileLoads(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/sources.json');

        self::assertArrayHasKey('inli', $sources);
        self::assertFalse($sources['inli']->mixedTenure, "In'li is Action Logement's pure-LLI arm");
        self::assertTrue($sources['cdc_habitat']->mixedTenure);
        self::assertTrue($sources['icf_novedis']->mixedTenure, 'Q20: stays true until a Novedis payload can be inspected');
    }

    public function testEveryShippedSourceIsEitherDisabledOrHasNoPlaceholderUrl(): void
    {
        // The loader already refuses this combination, so what this really pins is that the shipped
        // file has not been "helpfully" enabled with an invented endpoint (hard rule 1).
        foreach (ConfigLoader::loadSources(self::ROOT . '/config/sources.json') as $name => $s) {
            if ($s->enabled && $s->type !== 'fixture') {
                self::fail("source '{$name}' is enabled but no endpoint in this repo has been verified");
            }
        }

        self::assertTrue(true);
    }

    public function testCdcHabitatStaysDisabledPendingTheRobotsTxtQuestion(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/sources.json');
        self::assertFalse(
            $sources['cdc_habitat']->enabled,
            'Q15: robots.txt disallows /Recherche/show/ and the real endpoint path is unknown',
        );
    }

    // ---------------------------------------------------------------- local override

    public function testALocalOverrideMergesFieldByFieldAndReplacesArrays(): void
    {
        $dir = sys_get_temp_dir() . '/rentwatch-config-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir . '/criteria.json', json_encode([
                'communes' => ['Sartrouville', 'Houilles'],
                'postcode_prefixes' => ['78'],
                'max_rent_cc' => 1800,
                'notify' => ['high_priority_score' => 70, 'digest_hour' => 8],
            ], JSON_THROW_ON_ERROR));

            file_put_contents($dir . '/criteria.local.json', json_encode([
                'max_rent_cc' => 2100,
                'communes' => ['Chatou'],
                'notify' => ['digest_hour' => 6],
            ], JSON_THROW_ON_ERROR));

            $c = ConfigLoader::loadCriteria($dir . '/criteria.json', $dir . '/criteria.local.json');

            self::assertSame(2100, $c->maxRentCc, 'a scalar is replaced');
            self::assertSame(['chatou'], $c->communes, 'an array is replaced wholesale, not merged');
            self::assertSame(6, $c->notify->digestHour, 'an object merges key by key');
            self::assertSame(70, $c->notify->highPriorityScore, 'an unmentioned key survives the merge');
        } finally {
            @unlink($dir . '/criteria.json');
            @unlink($dir . '/criteria.local.json');
            @rmdir($dir);
        }
    }

    public function testAnAbsentLocalOverrideIsNotAnError(): void
    {
        $c = ConfigLoader::loadCriteria(
            self::ROOT . '/config/criteria.json',
            self::ROOT . '/config/criteria.local.json',
        );

        self::assertSame(1800, $c->maxRentCc);
    }

    public function testAMissingConfigFileIsALoudRefusal(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~no such config file~');

        ConfigLoader::loadCriteria('/nonexistent/criteria.json');
    }

    public function testMalformedJsonIsALoudRefusal(): void
    {
        $path = sys_get_temp_dir() . '/rentwatch-bad-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '{"communes": [');

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~not valid JSON~');
            ConfigLoader::loadCriteria($path);
        } finally {
            @unlink($path);
        }
    }

    private function shipped(): Criteria
    {
        return ConfigLoader::loadCriteria(self::ROOT . '/config/criteria.json');
    }
}
