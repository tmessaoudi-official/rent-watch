<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\ListingSnapshot;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;

/**
 * A CHECK THAT COULD NOT BE MADE IS REPORTED AS ONE — the PAP colocation ruling, 2026-09-01.
 *
 * The developer reported colocations arriving from PAP. They do, and nothing available can stop
 * them: a room in a shared flat is advertised with the WHOLE flat's room count and surface, so every
 * numeric filter passes, and the words that would give it away are nowhere in the payload. PAP's
 * alert is header, the subscriber's own search criteria, four structured facts, a link and a legal
 * footer — `description` is the literal string `PAP.fr  De Particulier à Particulier ____` on all 57
 * stored rows and `title` is `Location appartement` or `Location maison` on every one.
 *
 * **Both routes out are closed, and both were measured rather than assumed** (see
 * `docs/plans/pap-detail-hydration.plan.md`). The detail page answers a Cloudflare bot challenge
 * from the deployment's own IP, which hard rule 5 refuses to work around — the A15 Val d'Oise
 * Habitat precedent. And rent-per-room, the last numeric candidate, has no gap to threshold on: over
 * the four private-market sources the low tail runs 63, 71, 78, 84, 85, 90, 90, 91, 92, 92, 93, 94,
 * 97×3, 98×3, 100×4 upward, and the colocation that motivated this sits at 130, inside the densest
 * part of it.
 *
 * So the answer is HONESTY, not a filter — the same shape as the `HORS CHARGES … plafond non
 * vérifiable` line the engine already emits, and the same discipline as hard rule 9 refusing to read
 * an unknown as a zero. It changes no verdict; it tells the reader which pushes they must open.
 */
#[CoversClass(CriteriaEngine::class)]
final class ProseAbsentCaveatTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private const string CAVEAT = 'annonce sans texte';

    /** The declaration is the SHIPPED config's, not one written for the test. */
    public function testPapIsTheOnlySourceThatShipsTheDeclaration(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $declared = array_keys(array_filter($sources, static fn ($d): bool => $d->proseAbsent));

        self::assertSame(['pap'], $declared);
    }

    public function testAListingFromAProselessSourceSaysTheCheckCouldNotBeMade(): void
    {
        $verdict = $this->judge($this->listing(proseAbsent: true));

        self::assertSame(Outcome::MATCH, $verdict->outcome, 'the caveat rejects nothing — hard rule 8');
        self::assertNotSame(
            [],
            array_filter($verdict->reasons, static fn (string $r): bool => str_contains($r, self::CAVEAT)),
            'the push must say the exclusion lists could not run, or it claims a clean bill of health it never had',
        );
    }

    /**
     * THE COUNTERWEIGHT, and it carries as much weight as the guarantee.
     *
     * A line on every push, everywhere, is furniture — this repo has already paid for that with an
     * alert nobody retracted. The caveat is worth reading only because it appears on the one source
     * where it is true, so a source that publishes real prose must not get it.
     */
    public function testAnOrdinarySourceGetsNoCaveat(): void
    {
        $verdict = $this->judge($this->listing(proseAbsent: false));

        self::assertSame([], array_filter(
            $verdict->reasons,
            static fn (string $r): bool => str_contains($r, self::CAVEAT),
        ), 'a source with a description says nothing — the line means something only where it is true');
    }

    /**
     * IT ADJUSTS NO SCORE. A caveat that moved the score would be a disqualifier in disguise, and
     * hard rule 8 keeps those two mechanisms apart. Asserted against the identical listing rather
     * than against a constant, so a rebalance of the weights cannot make this pass vacuously.
     */
    public function testTheCaveatChangesNeitherTheScoreNorTheOutcome(): void
    {
        $bare = $this->judge($this->listing(proseAbsent: true));
        $ordinary = $this->judge($this->listing(proseAbsent: false));

        self::assertSame($ordinary->score, $bare->score);
        self::assertSame($ordinary->outcome, $bare->outcome);
        self::assertCount(count($ordinary->reasons) + 1, $bare->reasons, 'exactly one line more');
    }

    /**
     * A DECLARATION CONTRADICTED BY THE CONFIG BESIDE IT IS REFUSED AT LOAD.
     *
     * `prose_absent` says the payload holds no listing text; a `description` selector exists
     * precisely to extract that text. Left to load, every push on the source would carry a caveat
     * that is false — worse than no caveat, because it reads as considered.
     */
    public function testASourceThatMapsADescriptionMayNotDeclareProseAbsent(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~does publish listing text~');

        ConfigLoader::sourcesFromArray($this->sourcesDoc(proseAbsent: true, mapDescription: true));
    }

    /** The counterweight: the guard fires on the CONTRADICTION, not on the key. */
    public function testTheSameDeclarationLoadsWhenNoDescriptionIsMapped(): void
    {
        $sources = ConfigLoader::sourcesFromArray($this->sourcesDoc(proseAbsent: true, mapDescription: false));

        self::assertTrue($sources['probe']->proseAbsent);
    }

    /**
     * THE GUARD SITS OUTSIDE THE `enabled` BRANCH, for the reason the `card_separator` +
     * `mixed_tenure` refusal does: `--source=<name>` force-runs a disabled source, so a drafted
     * block reaches a real notification without ever having been enabled.
     */
    public function testTheContradictionIsRefusedEvenOnADisabledSource(): void
    {
        $this->expectException(ConfigError::class);

        ConfigLoader::sourcesFromArray(
            $this->sourcesDoc(proseAbsent: true, mapDescription: true, enabled: false),
        );
    }

    /**
     * IT SURVIVES THE SNAPSHOT ROUND TRIP, which is what makes `scout reclassify` keep the caveat.
     *
     * The flag lives on the listing rather than being handed to `judge()` for exactly this reason —
     * a value passed alongside would be absent on every re-judge, and a caveat that silently
     * disappears the second time a row is looked at is worse than never having had one.
     */
    public function testTheFlagSurvivesTheEvidenceSnapshot(): void
    {
        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($this->listing(proseAbsent: true)));

        self::assertTrue($decoded->proseAbsent);
        self::assertTrue($this->hasCaveat($this->judge($decoded)), 're-judging must reach the same reason line');
    }

    /** A pre-v7 row said nothing about this, and absence is not an assertion that the check ran. */
    public function testASnapshotWrittenBeforeTheFieldExistedDecodesToFalse(): void
    {
        /** @var array<string,mixed> $data */
        $data = json_decode(ListingSnapshot::encode($this->listing(proseAbsent: true)), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('proseAbsent', $data, 'the encoder must carry it, or this test proves nothing');
        unset($data['proseAbsent']);

        $older = ListingSnapshot::decode(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertFalse($older->proseAbsent);
        self::assertFalse($this->hasCaveat($this->judge($older)), 'an older row must not gain a caveat it never earned');
    }

    /**
     * IT SURVIVES HYDRATION. `RawListing::mergedWith()` rebuilds field by field, and its own
     * comment warns that a constructor parameter it forgets is silently DROPPED — the reflection
     * guard covers the snapshot encoder, not the merge. A caveat that vanished the moment a listing
     * gained a detail page would be exactly that class of loss.
     */
    public function testTheFlagSurvivesADetailMerge(): void
    {
        $card = $this->listing(proseAbsent: true);
        $detail = new RawListing(sourceName: 'pap', externalId: 'x', description: 'Un texte de détail.');

        self::assertTrue($card->mergedWith($detail)->proseAbsent);
    }

    private function hasCaveat(Verdict $verdict): bool
    {
        return array_filter($verdict->reasons, static fn (string $r): bool => str_contains($r, self::CAVEAT)) !== [];
    }

    private function listing(bool $proseAbsent): RawListing
    {
        return new RawListing(
            sourceName: 'pap',
            externalId: 'https://www.pap.fr/annonces/-r465002950',
            title: 'Location appartement',
            description: 'PAP.fr De Particulier à Particulier',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1100,
            surfaceM2: 82.0,
            rooms: 3,
            proseAbsent: $proseAbsent,
        );
    }

    private function judge(RawListing $listing): Verdict
    {
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');
        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'pap', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        return (new CriteriaEngine($criteria))->judge($listing, $classification);
    }

    /**
     * @return array<string,mixed>
     */
    private function sourcesDoc(bool $proseAbsent, bool $mapDescription, bool $enabled = true): array
    {
        return ['sources' => ['probe' => [
            'enabled' => $enabled,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'default_tenure' => 'LIBRE',
            'prose_absent' => $proseAbsent,
            'params' => ['from' => 'alerts@example.test'],
            'map' => $mapDescription ? ['ref' => 'url', 'description' => 'body'] : ['ref' => 'url'],
        ]]];
    }
}
