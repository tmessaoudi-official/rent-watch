<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Prose;

/**
 * `Core\Prose` reads a floor and a lift out of French listing PROSE, which is a different job from
 * `Payload::floor()` / `Payload::bool()` reading a structured field.
 *
 * It exists because In'li — about two thirds of all matches — states both facts only in the
 * description: 18 of 20 captured pages mention `ascenseur`, 19 state a floor, and neither is a
 * field. The corpus below is those 20 real pages, hand-labelled.
 */
final class ProseTest extends TestCase
{
    // ------------------------------------------------------------------ the captured corpus

    /**
     * @param array{id: string, description: string, floor: ?int, elevator: ?bool} $case
     */
    #[DataProvider('capturedPages')]
    public function testTheCapturedPagesReadAsLabelled(array $case): void
    {
        self::assertSame(
            $case['floor'],
            Prose::floor($case['description']),
            $case['id'] . ': floor',
        );
        self::assertSame(
            $case['elevator'],
            Prose::elevator($case['description']),
            $case['id'] . ': elevator',
        );
    }

    public static function capturedPages(): iterable
    {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/inli/descriptions.json');
        self::assertIsString($raw, 'the captured corpus must exist — it IS the ground truth');
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /**
     * The corpus cannot pass by being empty, and it cannot pass by containing only easy cases.
     *
     * The two facts that make it worth having are that some pages state NO lift (so `false` is
     * reachable, which is the only verdict the high-floor penalty can fire on) and that some state
     * only the building's height (so `null` is reachable for a page that does mention `étage`).
     */
    public function testTheCorpusCoversTheVerdictsThatMatter(): void
    {
        $cases = [];
        foreach (self::capturedPages() as $row) {
            $cases[] = $row[0];
        }

        self::assertCount(20, $cases, 'the captured corpus is 20 live pages');

        $liftFalse = array_filter($cases, static fn (array $c): bool => $c['elevator'] === false);
        $liftTrue = array_filter($cases, static fn (array $c): bool => $c['elevator'] === true);
        $floorNull = array_filter($cases, static fn (array $c): bool => $c['floor'] === null);

        self::assertGreaterThanOrEqual(5, count($liftFalse), 'an explicitly absent lift is reachable');
        self::assertGreaterThanOrEqual(10, count($liftTrue), 'a present lift is reachable');
        self::assertGreaterThanOrEqual(3, count($floorNull), 'a page that states no flat floor is reachable');
    }

    // ------------------------------------------------------------------ floor: position, not count

    #[DataProvider('floorProse')]
    public function testFloorReadsAPositionAndNeverACount(string $text, ?int $expected, string $why): void
    {
        self::assertSame($expected, Prose::floor($text), $why);
    }

    public static function floorProse(): iterable
    {
        yield 'digit ordinal' => ['situé au 2ème étage avec ascenseur', 2, 'the flat position is the answer'];
        yield 'bare e ordinal' => ['Le bien est situé au 11e étage.', 11, 'the short ordinal is the same fact'];
        yield 'spelled ordinal' => ["Situé au troisième étage d'un immeuble", 3, 'French spells small ordinals'];
        yield 'rdc' => ['2 pièces de 57 m² en rez-de-chaussée.', 0, 'the ground floor is zero, not unknown'];
        yield 'last floor' => ["Il est situé au 9e et dernier étage d'un immeuble", 9, 'dernier does not hide the ordinal'];

        // The whole reason this reader is not `Payload::floor()`. A count is the BUILDING.
        yield 'building height alone' => [
            "Le bâtiment de trois étages ne dispose pas d'ascenseur.",
            null,
            'a building height is never a tenant floor',
        ];
        yield 'building height, digits' => [
            "Il s'élève sur 3 étages et ne dispose pas d'ascenseur.",
            null,
            'plural is a count whether spelled or in digits',
        ];
        yield 'position beats the count in the same sentence' => [
            "se situe au 12e étage d'un immeuble des années 60 de 18 étages avec ascenseur",
            12,
            'the anchored position wins; 18 is the building',
        ];
        yield 'position beats the count, spelled' => [
            "Situé au troisième étage d'un immeuble de trois étages",
            3,
            'ordinal is the position, cardinal is the count — French does the discriminating',
        ];

        // Deliberately not extracted. Under-extraction is the safe direction (hard rule 9).
        yield 'bare ordinal without the noun' => [
            'Il compte trois étages, et cet appartement est situé au deuxième.',
            null,
            'a bare ordinal is not parsed — null is safer than a guess',
        ];
        yield 'site typo is not encoded' => [
            "au 3? étage d'un immeuble de 4 étages",
            null,
            'a typo is one listing; the building height must NOT answer instead',
        ];

        // Page furniture. `des commerces en rez-de-chaussée` is ordinary French listing copy, and
        // reporting RDC for a 5th-floor flat is a wrong DISPLAYED fact.
        yield 'commerce rdc does not become the flat floor' => [
            "appartement au 5e étage. Des commerces en rez-de-chaussée.",
            5,
            'the anchored étage wins over a furniture rdc',
        ];
        yield 'commerce rdc alone states nothing about the flat' => [
            'Des commerces en rez-de-chaussée et une supérette.',
            null,
            'a shop on the ground floor is not the tenant floor',
        ];

        yield 'silence' => ['Chauffage collectif. Commerces à proximité.', null, 'unknown stays unknown'];
        yield 'empty' => ['', null, 'unknown stays unknown'];
    }

    // ------------------------------------------------------------------ lift: negation FIRST

    #[DataProvider('liftProse')]
    public function testLiftReadsNegationBeforeAssertion(string $text, ?bool $expected, string $why): void
    {
        self::assertSame($expected, Prose::elevator($text), $why);
    }

    public static function liftProse(): iterable
    {
        yield 'avec' => ['situé au 6e étage avec ascenseur', true, 'the plain assertion'];
        yield 'possede' => ['Le bâtiment possède un ascenseur et un(e) régisseur(se).', true, 'possède asserts it'];
        yield 'dispose' => ["L'immeuble dispose d'un ascenseur", true, 'dispose asserts it'];
        yield 'equipe' => ["Ce dernier est équipé d'un ascenseur.", true, 'équipé asserts it'];
        yield 'presence' => ["La présence d'un gardien et d'un ascenseur assure un confort", true, 'présence asserts it'];
        yield 'securise par' => ['sécurisé par un ascenseur', true, 'the passive still asserts it'];

        // The five real negations across the 20 captured pages. A wrong `true` awards a bonus for a
        // lift that does not exist; a wrong `false` only lowers the score. Negation wins.
        yield 'sans' => ['sans ascenseur ni régisseur', false, 'sans is a negation'];
        yield 'aucun' => ['Aucun ascenseur dans la résidence.', false, 'aucun is a negation'];
        yield 'ne dispose pas' => ["ne dispose pas d'ascenseur mais bénéficie d'un régisseur", false, 'the verb is negated'];
        yield 'ne dispose pas, curly' => ["Il s’élève sur trois étages et ne dispose pas d’ascenseur.", false, 'U+2019 is folded'];
        yield 'pas de' => ["Pas d'ascenseur.", false, 'the elliptical negation counts'];

        // A negation anywhere in the text must not be borrowed by a lift that is asserted elsewhere,
        // and an unrelated `sans` must not be borrowed by the lift.
        yield 'unrelated sans does not negate the lift' => [
            'EXCLUSIVITE SANS FRAIS DE DOSSIER. A louer, 2 pièces situé au 2ème étage avec ascenseur.',
            true,
            'sans frais de dossier is not about the lift',
        ];
        yield 'negation wins when both appear' => [
            "L'immeuble voisin dispose d'un ascenseur. Aucun ascenseur dans la résidence.",
            false,
            'ambiguity resolves to the safe direction',
        ];

        yield 'silence' => ['Chauffage collectif. Commerces à proximité.', null, 'unmentioned is not absent'];
        yield 'empty' => ['', null, 'unknown stays unknown'];
    }
}
