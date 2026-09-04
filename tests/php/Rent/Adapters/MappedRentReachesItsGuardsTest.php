<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\ListingMapper;
use Scout\Rent\Adapters\Payload;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;

/**
 * A MAPPED RENT REACHES EVERY GUARD THAT JUDGES IT, WHATEVER ITS SIZE.
 *
 * Which is to say the mapped path applies no band — stated as what it BUYS rather than as what
 * it lacks, because a round-5 lens read an earlier name (`MappedRentIsUnbandedTest`) as though
 * it announced a gap. The absence is the mechanism; the guarantee is that nothing is erased
 * before `max_rent_cc` and the price-per-m² floor have seen it.
 *
 * A band was added here on 2026-09-04 (Track 6-A3 half 3) and removed the same day, after failing
 * twice on opposite bounds. The second failure was written by the fix for the first, which is why
 * the reasoning is pinned by tests rather than left in a commit message.
 *
 * ## WHY NULLING IS THE WRONG INSTRUMENT HERE
 *
 * Every downstream guard reads a rent as `!== null`. On a SINGLE labelled value that makes
 * *"outside the band"* and *"not stated"* the same input — so nulling does not reject a listing, it
 * DELETES THE EVIDENCE the guard needed, and the listing proceeds with one fewer filter:
 *
 * | bound | guard disabled | measured effect |
 * |---|---|---|
 * | ceiling (20 000) | `max_rent_cc`, guarded `$rentCc !== null` | 25 000 € REJECT became MATCH, push saying *"loyer non communiqué"*. **Shipped** |
 * | floor (200) | the price-per-m² branch, whose `pricePerM2()` returns null on a null rent | `{119 €, 60 m²}` DIGEST became MATCH. Caught in review |
 *
 * The band belongs to `EmailAlertSource::rentIn()`, where it sits inside a loop over CANDIDATES:
 * there *refused* means **keep looking**, and discarding one implausible figure costs nothing
 * because the real rent is on the next line. Same numbers, opposite safety direction.
 *
 * ## THE MOTIVATING MEASUREMENT DID NOT SURVIVE RE-MEASUREMENT
 *
 * The band was justified by "7 price-history rows at 119–290 € came through unbanded". Re-read
 * against the shipped floor, that set contains **one** row below 200 €, already digested on tenure
 * — zero rows changed in either direction. The mechanism for a mis-mapped low rent is the
 * price-per-m² floor, which the band switched off.
 */
#[CoversClass(ListingMapper::class)]
#[CoversClass(Payload::class)]
final class MappedRentReachesItsGuardsTest extends TestCase
{
    private const string CRITERIA = __DIR__ . '/../../../../config/rent/criteria.json';

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function figuresOutsideTheScanBand(): iterable
    {
        yield 'the lowest stored history row' => [119];
        yield 'just under the scan floor' => [199];
        yield 'one euro over the scan ceiling' => [20001];
        yield 'the figure the round-4 panel used' => [25000];
        yield 'a postcode, if a selector ever drifted onto one' => [95240];
    }

    /**
     * A MAPPED RENT IS PASSED THROUGH WHATEVER ITS SIZE. The guards downstream do the judging.
     */
    #[DataProvider('figuresOutsideTheScanBand')]
    public function testAMappedRentIsNeverErasedForBeingOutOfBand(int $figure): void
    {
        $listing = $this->mapper()->map(['id' => 'a1', 'titre' => 'T3', 'loyer' => $figure]);

        self::assertSame(
            $figure,
            $listing->rentCc,
            'erasing it does not reject the listing — it removes the evidence the ceiling and the '
            . 'price-per-m² floor each need, and both directions were proven to flip a verdict',
        );
    }

    /**
     * THE FLOOR HALF, END TO END — and this is the case the band's own tests could not reach.
     *
     * `MappedRentBandTest` mapped only `titre` and `loyer`, so every listing it built had a null
     * surface and null rooms. The price-per-m² branch needs both, so it could never fire: the tests
     * were constructed inside the blind spot of the branch the band was disabling, which is why
     * 2 757 of them stayed green while the regression was live.
     */
    public function testASubFloorRentStillReachesThePricePerM2Branch(): void
    {
        // 119 € for 60 m² is 1.98 €/m², far under the derived 5.50 floor.
        $verdict = $this->judge(rentCc: 119, surfaceM2: 60.0, rooms: 3);

        self::assertSame(Outcome::DIGEST, $verdict->outcome, 'a banded floor made this a MATCH');
        self::assertNotSame(
            [],
            array_filter($verdict->reasons, static fn (string $r): bool => str_contains($r, '€/m²')),
            'and it must say why, or the digest entry is a bare link',
        );
    }

    /**
     * THE CEILING HALF, END TO END — the regression that actually shipped.
     */
    public function testAnOverCeilingRentStillReachesTheRentCeiling(): void
    {
        self::assertSame(
            Outcome::REJECT,
            $this->judge(rentCc: 25000, surfaceM2: 80.0, rooms: 4)->outcome,
            'a banded ceiling made this a MATCH',
        );

        // THE RENT IS THE ONLY THING REJECTING IT, isolated by varying that alone. A rejection
        // carries NO reasons — hard rule 8, disqualifiers reject silently and are logged only — so
        // the cause cannot be read off the verdict, and without this arm the assertion above is
        // satisfied by any unrelated disqualifier and would still pass with the ceiling deleted.
        self::assertSame(
            Outcome::MATCH,
            $this->judge(rentCc: 1150, surfaceM2: 80.0, rooms: 4)->outcome,
            'the same flat under the ceiling must match, or the case above proves nothing about rent',
        );
    }

    /**
     * THE COUNTERWEIGHT. Without it this whole file is satisfied by deleting the band everywhere,
     * and the scan — where "refused" means keep looking — genuinely needs both bounds.
     */
    public function testTheEmailScanStillBandsInBothDirections(): void
    {
        self::assertNull(Payload::plausibleRent(119), 'the scan floor must survive');
        self::assertNull(Payload::plausibleRent(25000), 'and so must the scan ceiling');
        self::assertSame(1150, Payload::plausibleRent(1150));

        // Structural, and comment lines are stripped first: this file's own prose names
        // `plausibleRent`, and a naive grep reads the documentation of a guarantee as the guarantee.
        // A trap already paid for twice in this repo.
        self::assertStringContainsString(
            'Payload::plausibleRent(',
            self::codeOf(__DIR__ . '/../../../../src/php/Rent/Adapters/EmailAlertSource.php'),
            'the email reader must keep the band the scan needs',
        );
    }

    /**
     * AND THE MAPPED PATH MUST NOT GROW ONE BACK. Stated as code rather than as a comment, because
     * the comment explaining this was in place when the band was added the second time.
     */
    public function testTheMappedPathCarriesNoBandAtAll(): void
    {
        $code = self::codeOf(__DIR__ . '/../../../../src/php/Rent/Adapters/ListingMapper.php');

        self::assertStringNotContainsString('plausibleRent', $code, 'the scan band must not reach the mapped path');
        self::assertStringNotContainsString('mappedRent', $code, 'nor a mapped variant of it');
    }

    private static function codeOf(string $path): string
    {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return implode("\n", array_filter(
            $lines,
            static fn (string $l): bool => preg_match('~^\s*(\*|/\*|//|#)~', $l) !== 1,
        ));
    }

    private function judge(int $rentCc, float $surfaceM2, int $rooms): Verdict
    {
        $listing = new RawListing(
            sourceName: 'seloger',
            externalId: 'https://www.seloger.com/annonces/1',
            title: 'Appartement',
            description: 'Bel appartement proche gare.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: $rentCc,
            surfaceM2: $surfaceM2,
            rooms: $rooms,
        );

        // The REAL classifier rather than a fabricated Classification, so this cannot drift from
        // what the pipeline actually feeds `judge()`.
        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'seloger', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        return (new CriteriaEngine(ConfigLoader::loadCriteria(self::CRITERIA)))->judge($listing, $classification);
    }

    private function mapper(): ListingMapper
    {
        $definitions = ConfigLoader::sourcesFromArray([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'institutional',
                    'type' => 'json',
                    'default_tenure' => 'LLI',
                    'mixed_tenure' => true,
                    'url' => 'https://example.test/api',
                    'items_path' => 'items',
                    'map' => [
                        'ref' => 'id',
                        'title' => 'titre',
                        'rent' => 'loyer',
                        'charges_included' => true,
                    ],
                ],
            ],
        ]);

        return new ListingMapper($definitions['probe']);
    }
}
