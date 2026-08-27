<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Core\Prose;

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

        // THE `\b` ON `etage`, and it is not redundant with the `au|en` anchor — which is what the
        // ledger's case for it asserted while no test exercised it, so the case was one of two
        // still reporting `ok` unconditionally through the broken harness until 2026-08-24.
        //
        // `en 4 étages` is a TRIPLEX spanning four floors, not the fourth floor. The anchor matches
        // (`en` + ordinal), so only the singular `étage` separates a duplex from a position — and
        // reading it as a position puts a ground-floor duplex on the 4th floor in the notification,
        // and feeds the high-floor penalty a number the listing never claimed.
        yield 'a flat spanning floors is not on that floor' => [
            'Appartement en duplex en 4 étages, calme.',
            null,
            'en 4 étages is a span, not a position — the singular is the only thing distinguishing them',
        ];
        yield 'a span, spelled' => [
            'Maison de ville en trois étages avec jardin.',
            null,
            'same distinction whether the count is spelled or in digits',
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

        // ── the `au|en` ANCHOR itself, which nothing exercised (round 7) ────────────────────
        //
        // `Prose::floor()`'s docblock states that "the `au|en` anchor is what keeps a bare count
        // out **even when singular**", and the ledger pinned only the other half — the `\b` on
        // `etage`. A reviewer made the anchor optional and the whole suite stayed green while a
        // building's height was read as the tenant's floor: the exact defect class this reader was
        // written to fix, on the half nobody wrote a case for.
        yield 'a singular count with no anchor is not a position' => [
            "Appartement T3 dans un immeuble de 4 étage.",
            null,
            'the count is singular here (a typo real copy makes), so ONLY the anchor keeps it out',
        ];
        yield 'an ordinal with no preposition is not extracted' => [
            'Bel appartement, 3 étage, lumineux.',
            null,
            'no au/en, so nothing says this is the position rather than a count',
        ];
        yield 'a residence height with no anchor stays out' => [
            'Résidence de 18 étage, gardien.',
            null,
            'unanchored, and a building height must never answer for the flat',
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

        // ── a denial that FOLLOWS the noun (round 7) ────────────────────────────────────────
        //
        // The scan only looked BACKWARD, and the code justified the bare-noun assertion with
        // "a denial always carries a marker, so a bare noun cannot be one" — true only of a marker
        // placed BEFORE the noun. `Ascenseur : non` is an ordinary French spec-block row, and it
        // read `true`: a bonus awarded for a lift that does not exist, the direction this class's
        // docblock forbids. The 20-capture ground truth contains only pre-positioned negations, so
        // it could not see this.
        yield 'spec row, colon' => ['Ascenseur : non', false, 'the denial follows the noun'];
        yield 'spec row, no space' => ['Ascenseur: Non', false, 'and case is folded'];
        yield 'spec row, em dash' => ['Ascenseur — non', false, 'real copy uses dashes as separators'];
        yield 'spec row, en dash' => ['Ascenseur – non', false, 'both dashes'];
        yield 'spec row among others' => ['Ascenseur : non | Balcon : oui', false, 'the row ends at the pipe'];
        yield 'spec row, aucun' => ['Ascenseur : aucun', false, 'the other denial word'];

        yield 'spec row, em dash both sides' => [
            'Ascenseur — non — Balcon — oui',
            false,
            'the dash is a row separator as well as a leading one — the denial still ends its row',
        ];
        yield 'spec row, full stop' => ['Ascenseur : non. Balcon : oui', false, 'so is a full stop'];

        // ── the OTHER safe answer: a trailing `non` followed by a word is UNKNOWN ────────────
        //
        // The first version of this reader required the denial to TERMINATE the phrase, which took
        // the commonest French "not stated" values with it and defaulted them to `true` — the one
        // direction this class's docblock forbids. Two review lenses found that independently.
        // Vocabulary would have been the wrong fix (the list is open, and `non conforme` is not in
        // it); the STRUCTURE decides instead, and both branches are safe.
        yield 'not stated is unknown, not absent' => [
            'Ascenseur : non renseigné',
            null,
            '"non renseigné" means nobody said — null, never a lift bonus',
        ];
        yield 'not communicated' => ['Ascenseur : non communiqué', null, 'same shape'];
        yield 'not available' => ['Ascenseur : non disponible', null, 'same shape'];
        yield 'out of service is not an absent lift' => [
            'Ascenseur non conforme, remise en service en mai.',
            null,
            'a lift that exists and is out of order is neither absent nor assertable',
        ];
        yield 'affirmed spec row' => ['Ascenseur : oui', true, 'the mirror of the denial'];

        // ── the two NARROWING rules the reader states, which nothing pinned ─────────────────
        //
        // Round 8: letting the leading class match `\s\S` (any character) instead of separators
        // left the whole suite green, while the docblock calls the adjacency rule load-bearing.
        //
        // Its sibling claim — that the 16-character window is what keeps the NEXT field's value
        // out — turned out to be FALSE, and no test is written for it here on purpose: widening
        // the window to 200 changes nothing, because the adjacency rule already excludes anything
        // with a word in it. The window bounds the scan; adjacency is the guarantee. Said plainly
        // in `Prose` rather than pinned with a case invented to make it look load-bearing.
        yield 'a denial with a WORD in between is not adjacent' => [
            'Ascenseur: cave non',
            true,
            'only separators may sit between the noun and its denial, or `cave non` denies the lift',
        ];

        yield 'silence' => ['Chauffage collectif. Commerces à proximité.', null, 'unmentioned is not absent'];
        yield 'empty' => ['', null, 'unknown stays unknown'];
    }
}
