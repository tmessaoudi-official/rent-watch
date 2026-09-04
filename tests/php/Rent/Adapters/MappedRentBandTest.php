<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\ListingMapper;
use Scout\Rent\Adapters\Payload;
use Scout\Rent\Config\ConfigLoader;

/**
 * TRACK 6-A3 HALF 3 — the rent plausibility band reaches the MAPPED path too.
 *
 * `EmailAlertSource` has had a band since the SeLoger price-drop fix: a figure outside 200–20 000 €
 * is not a rent. Without it `2024` from a date and `95240` from a postcode both parse as rents, and
 * the direction that hurts is the LOW one — **a rent of 95 € passes every ceiling with maximum
 * headroom**, so it is notified, whereas 2024 € merely fails everything quietly.
 *
 * The html and json sources go through `ListingMapper` and had NO band. Measured over the live
 * store: **7 price-history rows at 119–290 €** came through that path unbanded.
 *
 * **STATED LIMIT, because a first draft of this test asserted otherwise and was wrong.** The band
 * is 200–20 000, so applying it here catches the rows BELOW 200 and leaves the 290 € one standing —
 * and that is correct rather than a shortfall: on a MAPPED field the value is the portal's own rent
 * field, where 290 € is a plausible chambre. The email path's band exists for a first-match SCAN
 * over prose, in which a year and a postcode compete with the rent; that argument does not transfer
 * to a value the portal labelled. Catching a mis-mapped 290 € needs evidence this band does not
 * have — the price-per-m² floor in `CriteriaEngine` is the mechanism that reaches it.
 *
 * ## ONE IMPLEMENTATION, NOT A SECOND COPY
 *
 * The band moved to `Payload::plausibleRent()` and both readers call it. That is not tidiness: a
 * second copy is how the two drift, and this repo's whole recurring defect is a correct rule
 * applied to a subset of the surfaces it belongs on — three separate instances of it were found in
 * the certification rounds that preceded this change.
 *
 * ## THE BOUNDS ARE INCLUSIVE, AND THAT IS THE SAFE DIRECTION
 *
 * 200 € IS a plausible rent (a parking space, a chambre) and 20 000 € IS one (a very large flat).
 * Refusing a real figure at the boundary loses a listing silently, which is the failure this
 * project cares about most; admitting one costs nothing, because every other filter still runs.
 */
#[CoversClass(ListingMapper::class)]
#[CoversClass(Payload::class)]
final class MappedRentBandTest extends TestCase
{
    /** @return iterable<string, array{0: int}> */
    public static function implausibleRents(): iterable
    {
        // MEASURED AGAINST THE SHIPPED BAND, not against the stored rows I wanted it to catch.
        // A first draft listed 290 and 2024 here and both FAILED: they are inside 200–20 000, and
        // the code was right. Stating the limit is the honest version of the fix.
        yield 'the lowest stored history row' => [119];
        yield 'a stored row at 145' => [145];
        yield 'a postcode read as a rent' => [95240];
        yield 'just under the low bound' => [199];
        yield 'just over the high bound' => [20001];
    }

    #[DataProvider('implausibleRents')]
    public function testAnImplausibleMappedRentIsNotARent(int $figure): void
    {
        $listing = $this->mapper()->map(['id' => 'a1', 'titre' => 'T3', 'loyer' => $figure]);

        self::assertNull($listing->rentCc, $figure . ' € is not a rent — it is a figure read off the wrong thing');
        self::assertNull($listing->rentHc);
    }

    /**
     * THE COUNTERWEIGHT, and without it the band is satisfied by refusing every rent — a source
     * that matches nothing, which is indistinguishable from a quiet market.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function plausibleRents(): iterable
    {
        yield 'the low boundary, inclusive' => [200];
        yield 'an ordinary IdF rent' => [1150];
        yield 'the shipped ceiling' => [1200];
        yield 'the high boundary, inclusive' => [20000];
        // Both of these were in the REJECT list of a first draft and belong here: a mapped field is
        // the portal's own rent, so 2024 € is a large flat rather than a year, and 290 € is a real
        // chambre. The email path's "a year parses as a rent" note is about a SCAN over prose,
        // where many numbers compete; it does not transfer to a mapped value.
        yield 'a stored row at 290 — inside the band, and correctly so' => [290];
        yield 'a figure that would be a year in prose' => [2024];
    }

    #[DataProvider('plausibleRents')]
    public function testAPlausibleMappedRentStillReads(int $figure): void
    {
        $listing = $this->mapper()->map(['id' => 'a1', 'titre' => 'T3', 'loyer' => $figure]);

        self::assertSame($figure, $listing->rentCc);
    }

    /**
     * ONE IMPLEMENTATION: the band the email path applies is the same callable, not a copy with the
     * same numbers. Asserted so the two cannot drift — the failure would be silent on whichever
     * side was not edited.
     */
    public function testTheEmailPathAndTheMappedPathShareOneBand(): void
    {
        // COMMENT LINES ARE STRIPPED FIRST, and this is the mirror of a trap already paid for once:
        // the code's own docblock explains that the numbers moved to `Payload::plausibleRent()`, and
        // a naive grep reads that sentence as the guarantee being met. Measured — the sabotage that
        // gives the email reader its own inline band back came back UNDETECTED for exactly that
        // reason. A structural assertion has to read the CODE.
        $source = (string) file_get_contents(__DIR__ . '/../../../../src/php/Rent/Adapters/EmailAlertSource.php');
        $lines = preg_split('/\R/', $source) ?: [];
        $codeOnly = implode("\n", array_filter(
            $lines,
            static fn (string $l): bool => preg_match('~^\s*(\*|/\*|//|#)~', $l) !== 1,
        ));

        self::assertStringContainsString(
            'Payload::plausibleRent(',
            $codeOnly,
            'the email reader must call the shared band rather than carry its own copy of the numbers',
        );
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
