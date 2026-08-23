<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\TestCase;
use RentWatch\Core\ListingSnapshot;
use RentWatch\Core\RawListing;

/**
 * `Core\ListingSnapshot` is the evidence half of schema v7.
 *
 * It exists so `scout reclassify` can re-run the classifier on **the evidence the original verdict
 * was formed from, never less**. That invariant is what keeps reclassify from being a §1 breach:
 * a card whose field says `PLS` while its title says *logement intermédiaire* classifies `UNKNOWN`
 * today by conflict, and re-run on the title alone it becomes a MATCH. The feature meant to reduce
 * misses would introduce the one thing §1 forbids.
 *
 * So the round trip is not a convenience — it is a correctness surface, and every case below is
 * hard rule 9 ("`None` is not zero") applied to serialisation:
 *
 * - `floor = 0` is the rez-de-chaussée and REAL; decoded as `null` it becomes "the source did not
 *   say", which is a different fact.
 * - `hasElevator = false` ("there is no lift") and `null` ("the listing did not mention one") are
 *   different facts, and only the explicit `false` may drive the high-floor penalty.
 * - `detailRead` decides whether weak evidence on a mixed source digests or matches. Lost in the
 *   round trip it reads as `false`, which is the SAFE direction — but it would silently re-digest
 *   every hydrated listing, so it is asserted rather than left to luck.
 */
final class ListingSnapshotTest extends TestCase
{
    /**
     * Every field survives, including the three whose falsy-but-real values a careless encoder eats.
     */
    public function testRoundTripPreservesEveryField(): void
    {
        $original = new RawListing(
            sourceName: 'inli',
            externalId: 'ABC-123',
            title: 'T4 lumineux',
            description: 'Au 3e étage, sans ascenseur.',
            fields: ['financement' => 'LLI', '_text' => '1 005 € cc · 55.32 m²'],
            url: 'https://example.test/a/1',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            rentHc: 1300,
            charges: 150,
            surfaceM2: 88.5,
            rooms: 4,
            bedrooms: 3,
            floor: 0,
            hasElevator: false,
            detailRead: true,
        );

        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($original));

        self::assertEquals($original, $decoded);

        // Spelled out separately from assertEquals, because the three below are the ones a null
        // coercion eats and an object comparison failure would not say which.
        self::assertSame(0, $decoded->floor, 'RDC is a floor, not an unknown floor');
        self::assertFalse($decoded->hasElevator, 'an explicit "no lift" is not an unmentioned lift');
        self::assertTrue($decoded->detailRead, 'a hydrated listing must not decode as unread');
    }

    /**
     * The absent case, which must stay absent rather than becoming a zero.
     */
    public function testRoundTripPreservesUnknownsAsNull(): void
    {
        $original = new RawListing(sourceName: 'cdc_habitat', externalId: 'X1');

        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($original));

        self::assertEquals($original, $decoded);
        self::assertNull($decoded->floor, 'no floor stated is not the ground floor');
        self::assertNull($decoded->hasElevator, 'no lift mentioned is not "there is no lift"');
        self::assertNull($decoded->rentCc);
        self::assertNull($decoded->surfaceM2);
        self::assertFalse($decoded->detailRead);
        self::assertSame([], $decoded->fields);
    }

    /**
     * A float surface must not come back as an int, and an int rent must not come back as a float.
     *
     * JSON has one number type, so this is a real hazard rather than a theoretical one: `88.0`
     * round-trips through `json_decode` as a float but `1450.0` handed to an `?int` parameter is a
     * deprecation at best and a TypeError under strict types.
     */
    public function testNumericTypesSurviveJsonsSingleNumberType(): void
    {
        $original = new RawListing(
            sourceName: 'logirep',
            externalId: 'L9',
            surfaceM2: 88.0,
            rooms: 4,
            rentCc: 1200,
        );

        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($original));

        self::assertIsFloat($decoded->surfaceM2);
        self::assertSame(88.0, $decoded->surfaceM2);
        self::assertIsInt($decoded->rentCc);
        self::assertIsInt($decoded->rooms);
    }

    /**
     * THE GUARD THAT KEEPS THIS HONEST AS `RawListing` GROWS.
     *
     * A field added to `RawListing` and not to the encoder is silently dropped from every snapshot
     * written afterwards — and the loss is invisible, because decode still succeeds and the missing
     * field reads as "the source did not say". Reclassify would then run on LESS evidence than the
     * original, which is exactly the §1 breach the whole mechanism exists to prevent.
     *
     * Asserted by reflection over the constructor itself, so tomorrow's field is covered without
     * anyone remembering this test exists. Same technique as the classifier's letter invariant: if
     * this goes red, add the field to the encoder — never relax the assertion.
     */
    public function testEncoderCoversEveryConstructorParameter(): void
    {
        $parameters = (new \ReflectionClass(RawListing::class))->getConstructor()?->getParameters() ?? [];
        self::assertNotSame([], $parameters, 'reflection found no constructor — the guard would pass vacuously');

        $encoded = json_decode(ListingSnapshot::encode(new RawListing('s', 'e')), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($encoded);

        foreach ($parameters as $parameter) {
            self::assertArrayHasKey(
                $parameter->getName(),
                $encoded,
                sprintf(
                    'RawListing::$%s is not persisted by ListingSnapshot. Add it to the encoder — a '
                    . 'field missing from the snapshot makes reclassify run on less evidence than '
                    . 'the original verdict saw, which is the §1 breach v7 exists to prevent.',
                    $parameter->getName(),
                ),
            );
        }
    }

    /**
     * A snapshot that cannot be read is a LOUD failure, never a bare listing.
     *
     * Hard rule 3: an exception must not become an empty result. Degrading to a listing with no
     * fields is the silent-shrink case — it would classify as `UNKNOWN` and read as an honest
     * doubt rather than as a corrupt row.
     */
    public function testMalformedSnapshotIsRefusedLoudly(): void
    {
        $this->expectException(\JsonException::class);

        ListingSnapshot::decode('{not json');
    }

    /**
     * Well-formed JSON that is not a snapshot is refused too — the shape is checked, not just the
     * syntax. A `null` decode is valid JSON and would otherwise reach the constructor as nothing.
     */
    public function testWellFormedButWrongShapeIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ListingSnapshot::decode('"a string is valid JSON and is not a listing"');
    }
}
