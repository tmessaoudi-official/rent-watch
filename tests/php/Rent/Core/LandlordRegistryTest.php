<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\LandlordRegistry;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;

/**
 * Track 5a — WHO is advertising decides which profile a listing is judged under.
 *
 * Measured 2026-09-01 over the live store: **23 SeLoger rows whose advertiser is an institutional
 * landlord were judged `LIBRE` at 50bp and 21 were pushed as a MATCH**, while the same flat on that
 * landlord's own site digests under `mixed_tenure: true`. The verdict depended on the route.
 */
#[CoversClass(LandlordRegistry::class)]
final class LandlordRegistryTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * THE PINNING TEST, and it is the whole reason an explicit registry is defensible.
     *
     * Deriving the table from `sources.json` was the first design and the data refuted it three
     * ways — RIVP has no source block, *Immobilière 3F* advertises through a block named
     * `cityloger`, and *IN'LI* does not fold to `inli`. So the table is written by hand, and the
     * real risk of a hand-written table is DRIFT: a new institutional landlord source added while
     * this file is forgotten, which reads as "that landlord is not one we know" rather than as a
     * gap. `Criteria::communeLabels` is the scar — a vocabulary built from one place went empty and
     * a whole extraction stopped, silently.
     *
     * A derived map would have covered only what happens to be ENABLED, which is worse and looks
     * better. Pinning covers everything the config declares, enabled or not.
     */
    public function testEveryInstitutionalSourceBlockIsInTheRegistry(): void
    {
        $keys = LandlordRegistry::sourceKeys();
        $missing = [];

        foreach (ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json') as $definition) {
            if ($definition->family !== 'institutional') {
                continue;
            }

            // The fixture and demo blocks are not landlords; they exist to exercise the adapters.
            if (str_contains($definition->name, 'demo') || str_contains($definition->name, 'fixture')) {
                continue;
            }

            if (!in_array($definition->name, $keys, true)) {
                $missing[] = $definition->name;
            }
        }

        self::assertSame(
            [],
            $missing,
            "institutional source(s) absent from LandlordRegistry: " . implode(', ', $missing)
                . ".\nAdd the landlord's advertised NAME (and any alias a portal writes it under) to "
                . 'LandlordRegistry::NAMES, mapped to this source key. Without it, that landlord '
                . "advertising through a private portal keeps the portal's own default — which is "
                . 'the §1 hole this class closes.',
        );
    }

    /** Every registry entry maps to a real source block, or explicitly to none. */
    public function testEveryMappedSourceKeyExists(): void
    {
        $defined = array_keys(ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json'));

        foreach (LandlordRegistry::sourceKeys() as $key) {
            self::assertContains(
                $key,
                $defined,
                sprintf('LandlordRegistry maps to `%s`, which is not a source block — a landlord '
                    . 'pointing at a missing key silently falls back to the unknown-landlord '
                    . 'profile, so the mapping would look present and do nothing', $key),
            );
        }
    }

    /**
     * NO ENTRY IS SHORT ENOUGH TO MATCH INSIDE MACHINE TEXT, and this test exists because the
     * measurement behind this whole feature was briefly wrong by exactly that mistake.
     *
     * The audit scanned for the alias `i3f` and matched it inside a base64url JWT signature —
     * `…vgdb-d5xbqjfxsvc5tsiri3fo0ug2y4b…` — on a Century 21 listing, reporting a Bien'ici landlord
     * row that does not exist. That is the acronym-in-machine-text class a sixth time, committed in
     * the audit that found the bug it exists to prevent.
     *
     * Four characters is the floor: `rivp` is the shortest real name here, and a four-character
     * run of letters is still rare enough in base64 that combined with the structural extraction
     * (this class is only ever handed a subject-line capture, never a body) it cannot fire. A
     * three-character entry would be a genuine hazard and is refused.
     */
    public function testNoRegistryEntryIsShortEnoughToMatchInsideOpaqueText(): void
    {
        foreach (LandlordRegistry::names() as $name) {
            self::assertGreaterThanOrEqual(
                4,
                strlen($name),
                sprintf('`%s` is too short to be matched by containment — `i3f` inside a JWT '
                    . 'signature is how this rule\'s own measurement was wrong by one row', $name),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string|false|null}>
     */
    public static function advertisers(): iterable
    {
        // The real subject-line captures, verbatim from the live mailbox.
        yield 'CDC HABITAT' => ['CDC HABITAT', 'cdc_habitat'];
        yield "IN'LI" => ["IN'LI", 'inli'];
        // Containment, because SeLoger writes regional suffixes.
        yield 'landlord with a regional suffix' => ["IN'LI PARIS EST", 'inli'];
        yield 'accents and case folded' => ['Immobilière 3F', 'cityloger'];
        // A recognised landlord this project has no source for.
        yield 'RIVP — no source block' => ['RIVP', null];
        // Ordinary agencies, all real subject lines. None may be recognised.
        yield 'NESTENN IGNY' => ['NESTENN IGNY', false];
        yield 'Century 21 G.T.I' => ['Century 21 G.T.I', false];
        yield 'iad France  Ronan Guenole' => ['iad France  Ronan Guenole', false];
        yield 'L ADRESSE - AGENCE DE LA VENERIE' => ['L ADRESSE - AGENCE DE LA VENERIE', false];
        yield 'LAFORET VAL D\'EUROPE' => ["LAFORET VAL D'EUROPE", false];
        // THE REGRESSION CASE. This exact string is the JWT signature fragment that produced a
        // phantom landlord row in the audit.
        yield 'a JWT signature fragment' => ['vgdb-d5xbqjfxsvc5tsiri3fo0ug2y4bnpj7efmst6a', false];
        yield 'nothing extracted' => ['', false];
    }

    #[DataProvider('advertisers')]
    public function testMatching(string $advertiser, string|false|null $expected): void
    {
        self::assertSame($expected, LandlordRegistry::match($advertiser));
    }

    /** A null advertiser is the ordinary case for every source that publishes no such surface. */
    public function testANullAdvertiserIsNotALandlord(): void
    {
        self::assertFalse(LandlordRegistry::match(null));
    }

    /**
     * LONGEST WINS, so the answer never depends on array order.
     *
     * `cdc habitat` and `cdc-habitat` both fire on the hyphenated spelling once folding is done;
     * first-match-wins would make the result an artefact of declaration order, which is the same
     * shape as the rent reader that returned a 100 € reduction by stopping at the first figure.
     */
    public function testTheLongestMatchingEntryWins(): void
    {
        self::assertSame('cdc_habitat', LandlordRegistry::match('Agence CDC Habitat Ile de France'));
    }

    /**
     * THE SUBSTITUTION ONLY EVER TIGHTENS — the property that makes `profileFor` safe to inject.
     *
     * @return iterable<string, array{0: SourceProfile, 1: SourceProfile, 2: bool, 3: ?Tenure}>
     */
    public static function tighteningCases(): iterable
    {
        $portal = new SourceProfile(name: 'seloger', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false);

        yield 'a lax portal takes the landlord\'s mixed flag' => [
            $portal,
            new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true),
            true,
            null,
        ];

        // A landlord block that is somehow LAXER cannot loosen the portal. No configured block is
        // like this today; the guarantee must not depend on that staying true (§1: a P0 "even if
        // nothing currently sets it").
        yield 'a lax landlord cannot loosen a strict portal' => [
            new SourceProfile(name: 'portal', family: 'private', defaultTenure: null, mixedTenure: true),
            new SourceProfile(name: 'x', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false),
            true,
            null,
        ];

        // Between two ELIGIBLE hints neither is stricter, so the landlord's wins on accuracy.
        yield 'two eligible hints: the landlord knows' => [
            $portal,
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: true),
            true,
            Tenure::LLI,
        ];
    }

    #[DataProvider('tighteningCases')]
    public function testTheSubstitutionOnlyTightens(
        SourceProfile $source,
        SourceProfile $landlord,
        bool $expectMixed,
        ?Tenure $expectDefault,
    ): void {
        $effective = LandlordRegistry::effectiveProfile(
            $source,
            'CDC HABITAT',
            static fn (string $key): SourceProfile => $landlord,
        );

        self::assertSame($expectMixed, $effective->mixedTenure);
        self::assertSame($expectDefault, $effective->defaultTenure);
    }

    /** An unrecognised or absent advertiser leaves the caller exactly where it was. */
    public function testAnUnrecognisedAdvertiserChangesNothing(): void
    {
        $source = new SourceProfile(name: 'seloger', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false);
        $never = static fn (string $key): ?SourceProfile => null;

        foreach ([null, '', 'NESTENN IGNY', 'Century 21 G.T.I'] as $advertiser) {
            self::assertSame($source, LandlordRegistry::effectiveProfile($source, $advertiser, $never));
        }
    }

    /**
     * A landlord naming ITSELF on its own source is a no-op, not a config re-read.
     *
     * Otherwise a force-run or a fixture-built profile would be silently replaced by one loaded
     * from `sources.json`, which is a different object with different flags — the kind of
     * substitution that makes a test pass for a reason nobody chose.
     */
    public function testALandlordOnItsOwnSourceIsUnchanged(): void
    {
        $own = new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true);
        $explode = static function (string $key): ?SourceProfile {
            self::fail('the resolver must not be consulted for a landlord on its own source');
        };

        self::assertSame($own, LandlordRegistry::effectiveProfile($own, 'CDC HABITAT', $explode));
    }

    /**
     * END TO END, and this is the assertion the developer's observation asked for.
     *
     * A card with NO tenure statement at all — which is every live SeLoger card — advertised by a
     * mixed-tenure bailleur, on a source declaring `mixed_tenure: false, default_tenure: LIBRE`.
     * Before Track 5a this was a MATCH at 50bp, 21 times in production.
     */
    public function testALandlordAdvertisedCardOnAPrivatePortalNoLongerMatches(): void
    {
        $portal = new SourceProfile(name: 'seloger', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false);
        $resolve = static fn (string $key): ?SourceProfile
            => ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')[$key]?->profile();

        $bare = new RawListing(
            sourceName: 'seloger',
            externalId: '1',
            title: 'Appartement T3',
            description: "Appartement / 3 pièces / 65.20 m2\n1 150 € CC\nSartrouville",
        );

        $control = (new TenureClassifier(profileFor: $resolve))->classify($bare, $portal);
        self::assertSame(
            Outcome::MATCH,
            $control->outcome,
            'the counterweight: an anonymous private-portal card must still match, or this fix is '
                . 'just the source switched off',
        );

        $advertised = new RawListing(
            sourceName: 'seloger',
            externalId: '1',
            title: 'Appartement T3',
            description: "Appartement / 3 pièces / 65.20 m2\n1 150 € CC\nSartrouville",
            advertiser: 'CDC HABITAT',
        );

        $result = (new TenureClassifier(profileFor: $resolve))->classify($advertised, $portal);

        self::assertNotSame(
            Outcome::MATCH,
            $result->outcome,
            'a CDC Habitat card routed through SeLoger must be judged as CDC Habitat stock — it '
                . 'reached MATCH, which is the §1 breach Track 5a exists to close',
        );
    }

    /**
     * AN UNMEASURED LANDLORD FAILS CLOSED — the RIVP case, which no other test reaches.
     *
     * `testALandlordAdvertisedCardOnAPrivatePortalNoLongerMatches` uses CDC Habitat, which HAS a
     * source block, so it travels through `stricterOf()` with a config-loaded profile and never
     * touches {@see LandlordRegistry::unknownLandlordProfile()}. Every landlord in the registry
     * mapped to `null` — RIVP, Paris Habitat, Seqens, Batigère and eight more — is judged by that
     * method alone, and until 2026-09-01 nothing asserted what it returns.
     *
     * Both fields are asserted SEPARATELY even though neither alone can breach §1. Measured over the
     * four combinations on a bare card: only `defaultTenure: LIBRE` *with* `mixedTenure: false`
     * reaches a MATCH — with the default left null there is nothing for the mixed-tenure branch to
     * fall back to, so either field holding is enough. They are belt-and-braces, and pinning them one
     * at a time is what keeps the second brace from being removed as dead weight once someone
     * notices the first is load-bearing on its own.
     */
    public function testAnUnmeasuredLandlordFailsClosed(): void
    {
        $profile = LandlordRegistry::unknownLandlordProfile('RIVP');

        self::assertNull(
            $profile->defaultTenure,
            'an unmeasured bailleur gets NO tenure hint — guessing LIBRE is the §1-dangerous '
                . 'direction and guessing LLI would be worse',
        );
        self::assertTrue(
            $profile->mixedTenure,
            'fail-closed: absent an explicit label the listing must digest, not match',
        );
        self::assertSame('institutional', $profile->family);

        // End to end: the outcome those two fields exist to produce.
        $resolve = static fn (string $key): ?SourceProfile
            => ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')[$key]?->profile();

        $advertised = new RawListing(
            sourceName: 'seloger',
            externalId: '1',
            title: 'Appartement T3',
            description: "Appartement / 3 pièces / 65.20 m2\n1 150 € CC\nSartrouville",
            advertiser: 'RIVP',
        );

        $verdict = (new TenureClassifier(profileFor: $resolve))->classify(
            $advertised,
            new SourceProfile(name: 'seloger', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        self::assertNotSame(
            Outcome::MATCH,
            $verdict->outcome,
            'a card advertised by a bailleur this project has never measured must not be notified '
                . 'as a match on the portal default',
        );
    }

    /**
     * AN EXPLICIT LABEL STILL WINS, in both directions.
     *
     * The advertiser is a profile substitution, not a signal: it cannot out-rank tier 1–3, and it
     * cannot manufacture eligibility either. Without this, the change could be "read" as making the
     * advertiser the decider, which is precisely what the classifier's own history rejected when it
     * removed `sans commission` from tier 3.
     */
    public function testAnExplicitLabelStillDecides(): void
    {
        $portal = new SourceProfile(name: 'seloger', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false);
        $resolve = static fn (string $key): ?SourceProfile
            => ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')[$key]?->profile();

        $excluded = new RawListing(
            sourceName: 'seloger',
            externalId: '1',
            title: 'Appartement T3',
            description: "Appartement 3 pièces. Le logement est soumis au plafond de ressources PLS.",
            advertiser: 'CDC HABITAT',
        );

        $verdict = (new TenureClassifier(profileFor: $resolve))->classify($excluded, $portal);

        self::assertTrue(
            $verdict->tenure->isExcluded(),
            'an explicit excluded label must still decide, whoever advertised the flat',
        );
        self::assertSame(Outcome::REJECT, $verdict->outcome);
    }
}
