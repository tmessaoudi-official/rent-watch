<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Payload;

/**
 * Value extraction — the layer where `CLAUDE.md` hard rule 9 lives or dies.
 *
 * Categories: **paths** (dotted traversal, candidate lists) · **nullish** (absent vs zero vs empty
 * string) · **numbers** (the formats French listings actually use) · **booleans** (`false` vs `null`,
 * which are different facts) · **flatten** (the classifier's field surface).
 */
final class PayloadTest extends TestCase
{
    private const array ITEM = [
        'id' => 'x-1',
        'rent' => ['total' => 1450, 'charges' => 120],
        'address' => ['city' => 'Chatou', 'postalCode' => '78400'],
        'photos' => [['url' => 'a.jpg'], ['url' => 'b.jpg']],
        'blank' => '',
        'dash' => '-',
        'zero' => 0,
        'false' => false,
    ];

    // ---------------------------------------------------------------- paths

    public function testADottedPathTraversesNestedObjects(): void
    {
        self::assertSame(1450, Payload::at(self::ITEM, 'rent.total'));
        self::assertSame('Chatou', Payload::at(self::ITEM, 'address.city'));
    }

    public function testANumericSegmentIndexesAList(): void
    {
        self::assertSame('b.jpg', Payload::at(self::ITEM, 'photos.1.url'));
    }

    public function testAMissingSegmentYieldsNullRatherThanThrowing(): void
    {
        // A field map naming a path one item happens not to carry is ordinary. The systematic
        // version — a path NO item carries — is what the fixture test catches, loudly.
        self::assertNull(Payload::at(self::ITEM, 'rent.deposit'));
        self::assertNull(Payload::at(self::ITEM, 'nothing.at.all'));
    }

    public function testDescendingThroughAScalarYieldsNull(): void
    {
        self::assertNull(Payload::at(self::ITEM, 'id.nested'));
    }

    public function testFirstNonEmptyCandidateWins(): void
    {
        self::assertSame(1450, Payload::first(self::ITEM, ['price', 'rent.total']));
        self::assertSame('Chatou', Payload::first(self::ITEM, ['city', 'address.city']));
    }

    public function testACandidateWhoseValueIsNullishIsSkipped(): void
    {
        // Not merely "absent" — a source that fills a field with "" or "-" has told us nothing, and
        // treating that as data produces a listing claiming to know something it does not.
        self::assertSame('x-1', Payload::first(self::ITEM, ['blank', 'dash', 'id']));
    }

    // ---------------------------------------------------------------- nullish

    #[DataProvider('nullishValues')]
    public function testNullishRecognisesTheWaysASourceSaysNothing(mixed $value, bool $expected, string $why): void
    {
        self::assertSame($expected, Payload::isNullish($value), $why);
    }

    /** @return iterable<string, array{mixed, bool, string}> */
    public static function nullishValues(): iterable
    {
        yield 'null' => [null, true, 'the obvious one'];
        yield 'empty string' => ['', true, 'an unfilled field'];
        yield 'whitespace' => ['   ', true, 'trimmed first'];
        yield 'a dash' => ['-', true, 'the commonest placeholder in a French listing'];
        yield 'N/C' => ['N/C', true, 'non communiqué, case-insensitively'];
        yield 'empty array' => [[], true, 'an empty list carries nothing'];
        yield 'ZERO IS NOT NULLISH' => [0, false, 'hard rule 9 — floor 0 is the rez-de-chaussée and is REAL'];
        yield 'FALSE IS NOT NULLISH' => [false, false, 'hard rule 9 — "there is no lift" is a fact'];
        yield 'the string zero' => ['0', false, 'still a stated value'];
        yield 'an ordinary string' => ['Chatou', false, 'the baseline'];
    }

    public function testZeroSurvivesExtractionAsZero(): void
    {
        // The single most important assertion in this file. `floor: 0` is the ground floor, and a
        // pipeline that turns it into null loses the best floor there is.
        self::assertSame(0, Payload::int(self::ITEM, ['zero']));
        self::assertNotNull(Payload::int(self::ITEM, ['zero']));
    }

    // ---------------------------------------------------------------- numbers

    #[DataProvider('numberFormats')]
    public function testNumberParsing(mixed $raw, ?int $expected, string $why): void
    {
        self::assertSame($expected, Payload::int(['v' => $raw], ['v']), $why);
    }

    /** @return iterable<string, array{mixed, ?int, string}> */
    public static function numberFormats(): iterable
    {
        yield 'a bare integer' => [1450, 1450, 'the easy case'];
        yield 'a float' => [1450.0, 1450, 'JSON numbers are floats often enough'];
        yield 'a plain numeric string' => ['1450', 1450, ''];
        yield 'French thousands and currency' => ['1 450,00 €', 1450, 'the ordinary French rendering'];
        yield 'a narrow no-break space' => ["1\u{202F}450 €", 1450, 'French typography uses U+202F, which \\s does not match'];
        yield 'a non-breaking space' => ["1\u{00A0}450 €", 1450, 'and U+00A0'];
        yield 'a dotted thousands separator' => ['1.450 €', 1450, 'THE MONEY BUG: "rightmost separator wins" reads this as 1.450 and yields 1 — a 1450 € flat recorded as 1 €, passing every ceiling with maximum headroom. Three trailing digits is a thousands group'];
        yield 'two decimal places is a decimal' => ['1.45', 1, 'and one and a half rounds to 1 — the counterpart that proves the rule discriminates rather than always choosing thousands'];
        yield 'French decimal comma' => ['91,5', 92, 'rounds, and 91.5 rounds to 92'];
        yield 'English decimal point' => ['1450.50', 1451, 'rounds rather than truncates — truncation would put a listing under a ceiling it does not meet'];
        yield 'mixed English format' => ['1,450.50', 1451, 'comma thousands, dot decimal'];
        yield 'mixed French format' => ['1.450,50', 1451, 'dot thousands, comma decimal — the mirror image'];
        yield 'a suffix' => ['1450 €/mois', 1450, ''];

        // ── A UNIT OR A LABEL CARRYING ITS OWN DIGITS ─────────────────────────────────────────
        // Found against a real In'li capture, 2026-08-19. The old parser deleted every character
        // that was not a digit or a separator and then read whatever remained as ONE number, so any
        // second digit anywhere in the string silently fused into the first. Nothing rejected it and
        // nothing looked wrong: a surface, a room count and a rent are all plausible at almost any
        // magnitude, so the corruption reaches the criteria engine as ordinary data.
        yield 'a unit whose name contains a digit' => ['55,32 m2', 55, 'THE FUSION BUG: `m2` contributes an ASCII 2, so the strip left `55,322`, whose last three digits then read as a thousands group — 55322 m². `m²` happened to be safe only because U+00B2 is not an ASCII digit, which is luck, not design'];
        yield 'two quantities in one text node' => ['3 pièces · 55.32 m²', 3, 'the In\'li card packs rooms and surface into one node; the first token is the room count, and the surface is isolated by the html resolver\'s regex capture rather than by hoping this returns it'];
        yield 'a type label before the count' => ['T3 · 2 pièces', 3, 'first token wins, and here that is the T-number — same value a human reads first'];
        yield 'an id before the price' => ['Réf 12 — 1 450 €', 12, 'THE ACCEPTED COST of first-token: this yields the reference, not the rent. It is stated rather than hidden — but it beats the old behaviour, which fused them into 121450 and looked like a number'];

        yield 'no digits at all' => ['sur demande', null, 'null, never 0 — this is the disqualification trap'];
        yield 'an empty string' => ['', null, ''];
        yield 'a placeholder' => ['N/C', null, ''];
        yield 'a boolean' => [true, null, 'not a number'];
    }

    public function testAFloatKeepsItsFraction(): void
    {
        self::assertSame(91.5, Payload::float(['v' => '91,5'], ['v']));
    }

    /**
     * The float half of the fusion bug, kept separate because `int` rounds and could hide it.
     *
     * A surface is the field where this did the most damage: `55,32 m2` became 55322 m², and a
     * 55 m² flat with a surface two orders of magnitude too large passes every `surface_min` and
     * scores as though it were a mansion. `CLAUDE.md` hard rule 9 says `null` is not zero; this is
     * its neighbour — a fabricated number is not a missing one, and it is worse, because a `null`
     * is visible as unknown and a wrong figure is not.
     */
    public function testAUnitCarryingItsOwnDigitDoesNotFuseIntoTheValue(): void
    {
        self::assertSame(55.32, Payload::float(['v' => '55,32 m2'], ['v']));
        self::assertSame(55.32, Payload::float(['v' => '55.32 m²'], ['v']));
        self::assertSame(3.0, Payload::float(['v' => '3 pièces · 55.32 m²'], ['v']));
    }

    // ---------------------------------------------------------------- booleans

    #[DataProvider('booleanForms')]
    public function testBooleanParsing(mixed $raw, ?bool $expected, string $why): void
    {
        self::assertSame($expected, Payload::bool(['v' => $raw], ['v']), $why);
    }

    /** @return iterable<string, array{mixed, ?bool, string}> */
    public static function booleanForms(): iterable
    {
        yield 'true' => [true, true, ''];
        yield 'false' => [false, false, 'THE fact that must survive: "there is no lift"'];
        yield 'oui' => ['oui', true, ''];
        yield 'non' => ['non', false, ''];
        yield 'Oui capitalised' => ['Oui', true, 'case-insensitive'];
        yield 'sans' => ['sans', false, 'as in "sans ascenseur"'];
        yield 'avec' => ['avec', true, ''];
        yield '1' => [1, true, ''];
        yield '0' => [0, false, ''];
        yield 'an unrecognised string' => ['peut-etre', null, 'null, not false — guessing here is the whole failure mode'];
        yield 'a number that is not 0 or 1' => [7, null, 'unrecognised'];
        yield 'absent' => [null, null, 'the listing did not mention a lift'];
    }

    public function testAbsentAndFalseAreDifferentFacts(): void
    {
        // Hard rule 9, stated as an assertion rather than as a docblock. The high-floor penalty
        // fires on `false` and must NOT fire on `null`, and this is what makes them distinguishable
        // by the time the criteria engine sees them.
        self::assertNull(Payload::bool(['other' => 1], ['elevator']));
        self::assertFalse(Payload::bool(['elevator' => 'non'], ['elevator']));
        self::assertNotSame(Payload::bool(['other' => 1], ['elevator']), Payload::bool(['elevator' => 'non'], ['elevator']));
    }

    // ---------------------------------------------------------------- flatten

    public function testFlattenExposesNestedFieldsUnderTheirDottedPath(): void
    {
        $flat = Payload::flatten(self::ITEM);

        self::assertSame('1450', $flat['rent.total']);
        self::assertSame('Chatou', $flat['address.city']);
        self::assertSame('a.jpg', $flat['photos.0.url']);
    }

    public function testFlattenKeepsBooleansAndZerosAsText(): void
    {
        $flat = Payload::flatten(self::ITEM);

        self::assertSame('false', $flat['false']);
        self::assertSame('0', $flat['zero']);
    }

    public function testFlattenDropsNullsRatherThanEmittingTheWordNull(): void
    {
        // A field whose value is null must not present the classifier with the string "null" as
        // evidence — it would read as a stated value.
        $flat = Payload::flatten(['financement' => null, 'kept' => 'LLI']);

        self::assertArrayNotHasKey('financement', $flat);
        self::assertSame('LLI', $flat['kept']);
    }

    public function testFlattenIsDepthBoundedWithoutLosingShallowFields(): void
    {
        // The bound is not a formality: this runs on the synchronous poll path, and a cyclic or
        // pathologically deep payload would otherwise recurse until the stack gives out. What it
        // must NOT do is discard the shallow fields alongside the deep ones — `financement` at the
        // top level is tier-1 classifier evidence, and losing it would be silent.
        $deep = 'leaf';
        for ($i = 0; $i < 40; ++$i) {
            $deep = ['down' => $deep];
        }

        $flat = Payload::flatten(['financement' => 'LLI', 'nested' => $deep]);

        self::assertSame('LLI', $flat['financement'], 'a shallow field survives a deep sibling');
        self::assertLessThan(40, count($flat), 'the deep branch is bounded');
    }
    // ---------------------------------------------------------------- floor, which is French prose

    /**
     * `RDC` is the ground floor, and the ground floor is `0` — not `null`, and not "no answer".
     *
     * This is hard rule 9's own example (`floor == 0` is falsy but real) meeting the first French
     * source that actually prints it: five of the thirteen distinct floor strings on CDC Habitat's
     * Yvelines page are `RDC`. Parsed with the generic number reader they all come back `null`, so a
     * ground-floor flat would be indistinguishable from one whose floor the ad never stated — and
     * the two feed DIFFERENT score components (a stated ground floor is fine; an unknown floor is
     * simply unknown).
     *
     * @param mixed $raw
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('floorVocabulary')]
    public function testFloorReadsTheWayFrenchListingsWriteIt(mixed $raw, ?int $expected, string $why): void
    {
        self::assertSame($expected, Payload::floor(['v' => $raw], ['v']), $why);
    }

    /** @return iterable<string, array{mixed, int|null, string}> */
    public static function floorVocabulary(): iterable
    {
        yield 'RDC' => ['RDC', 0, 'the ground floor is zero, not unknown'];
        yield 'rdc lowercase' => ['rdc', 0, 'case is not a fact'];
        yield 'rez-de-chaussee accented' => ['Rez-de-chaussée', 0, 'the spelled-out form is the same floor'];
        yield 'rez de chaussee unaccented' => ['rez de chaussee', 0, 'accents are stripped before matching'];
        yield 'first' => ['1er étage', 1, 'the ordinal is the floor'];
        yield 'second' => ['2ème étage', 2, 'ordinal suffixes vary; the digit does not'];
        yield 'fourth' => ['4ème étage', 4, 'ordinal suffixes vary; the digit does not'];
        yield 'bare int' => [3, 3, 'a source with a clean numeric field still works'];
        yield 'bare numeric string' => ['3', 3, 'a clean numeric string still works'];
        yield 'zero' => [0, 0, 'an explicit zero survives, because it is a real floor'];

        // The trap this parser exists to avoid. `Payload::int` on the card's whole heading returns
        // the FIRST number it finds, which is the room count or the surface — a plausible-looking
        // floor that is entirely fabricated. Silence is the only correct answer here.
        yield 'surface is not a floor' => ['82m²', null, 'a surface must never be read as a floor'];
        yield 'rooms are not a floor' => ['3 pièces', null, 'a room count must never be read as a floor'];
        yield 'unstated' => [null, null, 'unknown stays unknown'];
        yield 'empty' => ['', null, 'unknown stays unknown'];
        yield 'prose' => ['dernier étage', null, 'a floor that is not stated as a number is not guessed'];

        // A BUILDING's height is not a tenant's floor, and French states the two differently: a
        // position is `au N<ordinal> étage` (singular), a count is `de N étages` (plural). Measured
        // on a live In'li page whose own copy carries a typo (`au 3? étage`): the ordinal failed to
        // parse, the scan continued, and `d'un immeuble de 4 étages` answered instead — a flat on
        // the 3rd floor reported as being on the 4th. Wrong beats unknown in exactly the direction
        // hard rule 9 forbids, and it is a DISPLAYED fact, so nobody would catch it downstream.
        yield 'building height is not a floor' => [
            'immeuble de 6 étages',
            null,
            'a plural count is the building, never the tenant',
        ];
        yield 'building height after an unparseable floor' => [
            "au 3? étage d'un immeuble de 4 étages",
            null,
            'when the flat own floor fails to parse, the building height must not answer for it',
        ];
        yield 'the flat floor wins over the building height' => [
            'se situe au 12e étage d\'un immeuble des années 60 de 18 étages',
            12,
            'the singular position is the answer even with a plural count in the same sentence',
        ];
    }

    /**
     * A French amenity list asserts a lift with the noun, not with a yes.
     *
     * And the asymmetry is the point: the word present means `true`, the word ABSENT means the
     * capture failed, which is `null` — never `false`. The high-floor penalty fires only on an
     * explicit `false`, so nothing here can invent one (hard rule 9).
     */
    public function testTheAmenityNounAssertsALiftButItsAbsenceAssertsNothing(): void
    {
        self::assertTrue(Payload::bool(['v' => 'Ascenseur'], ['v']), 'the noun is the assertion');
        self::assertTrue(Payload::bool(['v' => 'ascenseur'], ['v']), 'case is not a fact');
        self::assertNull(
            Payload::bool(['v' => null], ['v']),
            'a card that does not mention a lift says nothing — it does not say no',
        );
    }

    // ── single-element lists, which Solr-backed payloads use for every text field ─────────────────

    public function testASingleElementListYieldsItsOneValue(): void
    {
        // Logirep/Polylogis (Drupal + Solr) returns EVERY text field as a one-element list:
        // `"tcngramm_X3b_fr_address_locality": ["AVON"]`. Before this, `string()` saw an array and
        // returned null, so `commune` and `cp` mapped to null for all 113 listings — and the failure
        // is the silent kind hard rule 2 exists for: `matchesCommune()` refuses a null commune, so
        // the source could never match a single listing while `SourceHealth` reported 113 items and
        // a green status. Nothing would have looked wrong.
        self::assertSame('AVON', Payload::string(['city' => ['AVON']], ['city']));
        self::assertSame('77210', Payload::string(['cp' => ['77210']], ['cp']));
    }

    public function testAListOfSeveralYieldsTheFirstUsableOne(): void
    {
        // Same convention, more than one value. The first is the one the source ranked first; there
        // is no better rule available and inventing a join would fabricate a commune name.
        self::assertSame('AVON', Payload::string(['city' => ['AVON', 'Avon Cedex']], ['city']));
    }

    public function testALeadingNullishEntryIsSkippedRatherThanWinning(): void
    {
        // A list whose first entry is empty must not resolve to null: that is the same "unknown vs
        // absent" confusion hard rule 9 is about, one container deeper.
        self::assertSame('AVON', Payload::string(['city' => ['', null, 'AVON']], ['city']));
    }

    public function testAListOfOnlyNullishEntriesIsUnknownNotEmptyString(): void
    {
        self::assertNull(Payload::string(['city' => ['', '  ', null]], ['city']));
    }

    public function testANestedListIsNotFlattenedIntoAValue(): void
    {
        // Guard against "unwrap until something sticks". A list of lists is a shape nobody mapped,
        // and inventing a value from it would put an arbitrary token into a commune field.
        self::assertNull(Payload::string(['city' => [['AVON']]], ['city']));
    }

    public function testAnIntegerInAListIsStillReadAsItsNumber(): void
    {
        // Solr returns numeric fields boxed the same way. Zero must survive as "0", not vanish —
        // it is a real floor number, and hard rule 9 turns on exactly that.
        self::assertSame('0', Payload::string(['floor' => [0]], ['floor']));
        self::assertSame(0, Payload::int(['floor' => [0]], ['floor']));
        self::assertSame(35, Payload::int(['surface' => [35]], ['surface']));
    }

    public function testAnAssociativeArrayIsNotTreatedAsAList(): void
    {
        // `{"city": {"name": "AVON"}}` is an object to descend into with a dotted path, not a value
        // to unwrap. Unwrapping it would silently pick whichever key happened to come first.
        self::assertNull(Payload::string(['city' => ['name' => 'AVON']], ['city']));
        self::assertSame('AVON', Payload::string(['city' => ['name' => 'AVON']], ['city.name']));
    }
}
