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

    /**
     * A NON-SCALAR FIELD VALUE SURVIVES THE ROUND TRIP, and this is the §1 case of the whole class.
     *
     * `decode()` used to keep a key only `if (is_scalar($item))`, so an array-valued field was
     * written by `encode()` and silently dropped on the way back. That is precisely the value
     * {@see \RentWatch\Core\TenureClassifier} raises its tier-1 DOUBT on, and the doubt is the only
     * thing withholding a match — so the round trip quietly disarmed the guard. A review panel
     * demonstrated the consequence end to end on 2026-08-24: `UNKNOWN`/`DIGEST` before,
     * `LLI`/`MATCH` and pushed after, on a listing whose own field named two excluded regimes.
     *
     * @see \RentWatch\Tests\Core\TenureSnapshotEvidenceTest for the classifier-level assertion
     */
    public function testANonScalarFieldValueSurvivesTheRoundTrip(): void
    {
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'x-1',
            fields: ['gamme' => ['PLAI', 'PLUS']],
        );

        $back = ListingSnapshot::decode(ListingSnapshot::encode($listing));

        self::assertArrayHasKey('gamme', $back->fields, 'the key must survive — a field NAME is evidence in its own right');
        self::assertSame(['PLAI', 'PLUS'], $back->fields['gamme']);
    }

    /**
     * A `null`-valued field keeps its KEY, which is the half that is easy to miss.
     *
     * `numeroUniqueEnregistrement` and `demandeLogementSocial` are literal `PROCEDURAL` entries, so
     * the name alone is the strongest social discriminator the domain offers — dropping the key
     * because its value is empty throws away the signal while looking like tidying.
     */
    public function testANullValuedFieldKeepsItsKey(): void
    {
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'x-2',
            fields: ['numeroUniqueEnregistrement' => null],
        );

        $back = ListingSnapshot::decode(ListingSnapshot::encode($listing));

        self::assertArrayHasKey('numeroUniqueEnregistrement', $back->fields);
        self::assertNull($back->fields['numeroUniqueEnregistrement']);
    }

    /**
     * The counterweight: an ordinary string field is still a string afterwards. Without this, a
     * "preserve everything" fix that stopped stringifying scalars would pass the two cases above
     * while changing what every real adapter's listing looks like to the classifier.
     */
    public function testAnOrdinaryScalarFieldIsStillAString(): void
    {
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'x-3',
            fields: ['financement' => 'LLI', 'etage' => 3],
        );

        $back = ListingSnapshot::decode(ListingSnapshot::encode($listing));

        self::assertSame('LLI', $back->fields['financement']);
        self::assertSame('3', $back->fields['etage'], 'a scalar is normalised to its string form, as an adapter would have produced');
    }

    /**
     * `fields` that is not an object at all is CORRUPTION, not sparseness, and must be refused.
     *
     * Degrading it to `[]` would hand the classifier a listing with no structured evidence and no
     * way to tell that apart from a listing that genuinely had none — hard rule 3, in the one place
     * where the difference decides whether a social listing can be notified.
     */
    public function testANonObjectFieldsMapIsRefusedRatherThanEmptied(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ListingSnapshot::decode('{"sourceName":"demo","externalId":"x-4","fields":"not a map"}');
    }
}
